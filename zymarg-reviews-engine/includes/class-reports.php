<?php
/**
 * Embedded Reviews — Report moderation (admin side).
 *
 * The front-end report flow (modal + wp_ajax_zymarg_report_review) stores:
 *   _zymarg_report_count        int  total reports for the review
 *   _zymarg_reported_{user_id}  '1'  per-user duplicate guard
 * and fires do_action( 'zymarg_review_reported', $comment_id, $user_id ).
 *
 * This class is the moderation layer built on top of that data:
 *   1. "Reports" column in WP Admin → Comments (sortable)
 *   2. "Reported (n)" filter view next to Pending / Approved / Spam
 *   3. Optional auto-unapprove once a report threshold is reached
 *   4. Admin email on the first report, and on auto-unapprove
 *   5. "Clear reports" row action
 *
 * @version 1.0.0
 * @package ZymargReviewsEngine
 */

namespace ZymargReviewsEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reports {

	/** @var self|null */
	private static $instance = null;

	const META_COUNT   = '_zymarg_report_count';
	const META_HELD    = '_zymarg_report_autoheld';
	const META_PREFIX  = '_zymarg_reported_';
	const COLUMN       = 'zymarg_reports';
	const COUNT_CACHE  = 'zymarg_sp_reported_total';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Reports arrive through admin-ajax.php, so this listener must always bind.
		add_action( 'zymarg_review_reported', [ $this, 'on_reported' ], 10, 2 );

		if ( ! is_admin() ) {
			return;
		}

		add_filter( 'manage_edit-comments_columns',          [ $this, 'add_column' ] );
		add_filter( 'manage_edit-comments_sortable_columns', [ $this, 'sortable_column' ] );
		add_action( 'manage_comments_custom_column',         [ $this, 'render_column' ], 10, 2 );
		add_filter( 'comment_status_links',                  [ $this, 'add_status_link' ] );
		add_action( 'pre_get_comments',                      [ $this, 'filter_query' ] );
		add_filter( 'comments_clauses',                      [ $this, 'sort_clauses' ], 10, 2 );
		add_filter( 'comment_row_actions',                   [ $this, 'add_row_action' ], 10, 2 );
		add_action( 'admin_post_zymarg_sp_clear_reports',    [ $this, 'handle_clear' ] );
	}

	// ── Settings helpers ─────────────────────────────────────────────────────

	private function opt( string $key, $default ) {
		// v1.0.0 - report moderation settings now come from the engine's own store.
		$val = Settings::get( $key, null );
		if ( null !== $val ) {
			return $val;
		}
		return $default;
	}

	private function threshold(): int {
		return max( 2, (int) $this->opt( 'reviews_reports_threshold', 3 ) );
	}

	private function notify_address(): string {
		$addr = (string) $this->opt( 'reviews_reports_notify_address', '' );
		$addr = sanitize_email( $addr );
		return $addr ? $addr : (string) get_option( 'admin_email' );
	}

	public static function count_for( int $comment_id ): int {
		return (int) get_comment_meta( $comment_id, self::META_COUNT, true );
	}

	// ── 3 + 4. Auto-unapprove and notifications ──────────────────────────────

	/**
	 * Runs on every accepted report.
	 *
	 * @param int $comment_id Reported review.
	 * @param int $user_id    Reporting user.
	 */
	public function on_reported( $comment_id, $user_id = 0 ): void {
		$comment_id = (int) $comment_id;
		$comment    = $comment_id ? get_comment( $comment_id ) : null;
		if ( ! $comment ) {
			return;
		}

		delete_transient( self::COUNT_CACHE );

		$count  = self::count_for( $comment_id );
		$notify = (bool) $this->opt( 'reviews_reports_notify_email', true );

		// First report only — later reports on the same review stay silent so a
		// brigaded review cannot flood the inbox.
		if ( $notify && 1 === $count ) {
			$this->send_mail( $comment, $count, false );
		}

		if ( ! $this->opt( 'reviews_reports_auto_unapprove', false ) ) {
			return;
		}
		if ( $count < $this->threshold() ) {
			return;
		}
		// Only ever hold an approved review, and never re-hold one that an admin
		// has deliberately re-approved after looking at it.
		if ( '1' !== (string) $comment->comment_approved ) {
			return;
		}
		if ( get_comment_meta( $comment_id, self::META_HELD, true ) ) {
			return;
		}

		wp_set_comment_status( $comment_id, 'hold' );
		update_comment_meta( $comment_id, self::META_HELD, '1' );

		if ( $notify ) {
			$this->send_mail( $comment, $count, true );
		}
	}

	private function send_mail( \WP_Comment $comment, int $count, bool $held ): void {
		$product = get_post( (int) $comment->comment_post_ID );
		$name    = $product ? get_the_title( $product ) : __( '(unknown product)', 'zymarg-reviews-engine' );
		$rating  = (int) get_comment_meta( $comment->comment_ID, 'rating', true );
		$link    = admin_url( 'comment.php?action=editcomment&c=' . (int) $comment->comment_ID );
		$excerpt = wp_trim_words( wp_strip_all_tags( (string) $comment->comment_content ), 40 );

		$subject = $held
			/* translators: %s: product name. */
			? sprintf( __( '[%1$s] Review unapproved after %2$d reports', 'zymarg-reviews-engine' ), wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ), $count )
			: sprintf( __( '[%s] A product review was reported', 'zymarg-reviews-engine' ), wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) );

		$lines = [];
		if ( $held ) {
			$lines[] = __( 'A review reached the report threshold and has been moved back to Pending.', 'zymarg-reviews-engine' );
		} else {
			$lines[] = __( 'A shopper reported a product review.', 'zymarg-reviews-engine' );
		}
		$lines[] = '';
		$lines[] = sprintf( __( 'Product: %s', 'zymarg-reviews-engine' ), $name );
		$lines[] = sprintf( __( 'Reviewer: %s', 'zymarg-reviews-engine' ), $comment->comment_author );
		if ( $rating ) {
			$lines[] = sprintf( __( 'Rating: %d / 5', 'zymarg-reviews-engine' ), $rating );
		}
		$lines[] = sprintf( __( 'Reports: %d', 'zymarg-reviews-engine' ), $count );
		$lines[] = '';
		$lines[] = __( 'Review excerpt:', 'zymarg-reviews-engine' );
		$lines[] = $excerpt;
		$lines[] = '';
		$lines[] = __( 'Moderate this review:', 'zymarg-reviews-engine' );
		$lines[] = $link;

		wp_mail( $this->notify_address(), $subject, implode( "\n", $lines ) );
	}

	// ── 1. Reports column ────────────────────────────────────────────────────

	public function add_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}
		$out = [];
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'response' === $key ) {
				$out[ self::COLUMN ] = __( 'Reports', 'zymarg-reviews-engine' );
			}
		}
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'Reports', 'zymarg-reviews-engine' );
		}
		return $out;
	}

	public function sortable_column( $columns ) {
		if ( is_array( $columns ) ) {
			$columns[ self::COLUMN ] = self::COLUMN;
		}
		return $columns;
	}

	public function render_column( $column, $comment_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$comment_id = (int) $comment_id;
		$comment    = get_comment( $comment_id );

		// Only meaningful for product reviews.
		if ( ! $comment || 'product' !== get_post_type( (int) $comment->comment_post_ID ) ) {
			echo '<span aria-hidden="true">&#8212;</span>';
			return;
		}

		$count = self::count_for( $comment_id );
		if ( $count < 1 ) {
			echo '<span aria-hidden="true">&#8212;</span>';
			return;
		}

		printf(
			'<span style="display:inline-block;min-width:22px;padding:1px 7px;border-radius:9999px;background:#ba1a1a;color:#fff;font-weight:600;text-align:center">%s</span>',
			esc_html( number_format_i18n( $count ) )
		);

		if ( get_comment_meta( $comment_id, self::META_HELD, true ) ) {
			echo '<br><span style="color:#ba1a1a;font-size:11px">' . esc_html__( 'Auto-unapproved', 'zymarg-reviews-engine' ) . '</span>';
		}
	}

	// ── 2. "Reported" filter view ────────────────────────────────────────────

	private function reported_total(): int {
		$cached = get_transient( self::COUNT_CACHE );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		$total = (int) get_comments( [
			'count'        => true,
			'status'       => 'all',
			'type'         => '',
			'meta_key'     => self::META_COUNT,
			'meta_value'   => 1,
			'meta_compare' => '>=',
			'meta_type'    => 'NUMERIC',
		] );
		set_transient( self::COUNT_CACHE, $total, 5 * MINUTE_IN_SECONDS );
		return $total;
	}

	public function add_status_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}
		$total = $this->reported_total();
		if ( $total < 1 ) {
			return $links;
		}

		$active = ! empty( $_GET['zymarg_reported'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$url    = add_query_arg(
			[ 'comment_status' => 'all', 'zymarg_reported' => 1 ],
			admin_url( 'edit-comments.php' )
		);

		$links['zymarg_reported'] = sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( $url ),
			$active ? ' class="current" aria-current="page"' : '',
			esc_html__( 'Reported', 'zymarg-reviews-engine' ),
			esc_html( number_format_i18n( $total ) )
		);

		return $links;
	}

	public function filter_query( $query ): void {
		if ( ! is_admin() || empty( $_GET['zymarg_reported'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'edit-comments' !== $screen->id ) {
			return;
		}

		$meta_query   = (array) ( $query->query_vars['meta_query'] ?? [] );
		$meta_query[] = [
			'key'     => self::META_COUNT,
			'value'   => 1,
			'compare' => '>=',
			'type'    => 'NUMERIC',
		];
		$query->query_vars['meta_query'] = $meta_query;
	}

	/**
	 * Sort by report count with a LEFT JOIN, so reviews with no reports are
	 * still included in the list instead of being filtered out.
	 */
	public function sort_clauses( $clauses, $query ) {
		if ( ! is_array( $clauses ) || self::COLUMN !== ( $query->query_vars['orderby'] ?? '' ) ) {
			return $clauses;
		}

		global $wpdb;
		$order = 'ASC' === strtoupper( (string) ( $query->query_vars['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';

		$clauses['join']   .= " LEFT JOIN {$wpdb->commentmeta} AS zsp_rc"
			. " ON ( zsp_rc.comment_id = {$wpdb->comments}.comment_ID AND zsp_rc.meta_key = '" . self::META_COUNT . "' )";
		$clauses['orderby'] = "CAST( COALESCE( zsp_rc.meta_value, 0 ) AS UNSIGNED ) {$order}, {$wpdb->comments}.comment_date_gmt DESC";

		if ( empty( $clauses['groupby'] ) ) {
			$clauses['groupby'] = "{$wpdb->comments}.comment_ID";
		}

		return $clauses;
	}

	// ── 5. "Clear reports" row action ────────────────────────────────────────

	public function add_row_action( $actions, $comment ) {
		if ( ! is_array( $actions ) || ! $comment ) {
			return $actions;
		}
		$comment_id = (int) $comment->comment_ID;
		if ( self::count_for( $comment_id ) < 1 ) {
			return $actions;
		}
		if ( ! current_user_can( 'edit_comment', $comment_id ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				[ 'action' => 'zymarg_sp_clear_reports', 'c' => $comment_id ],
				admin_url( 'admin-post.php' )
			),
			'zymarg_sp_clear_reports_' . $comment_id
		);

		$actions['zymarg_clear_reports'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Clear reports', 'zymarg-reviews-engine' )
		);

		return $actions;
	}

	public function handle_clear(): void {
		$comment_id = isset( $_GET['c'] ) ? absint( $_GET['c'] ) : 0;

		if ( ! $comment_id || ! get_comment( $comment_id ) ) {
			wp_die( esc_html__( 'Invalid review.', 'zymarg-reviews-engine' ) );
		}
		check_admin_referer( 'zymarg_sp_clear_reports_' . $comment_id );

		if ( ! current_user_can( 'edit_comment', $comment_id ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'zymarg-reviews-engine' ) );
		}

		self::clear( $comment_id );

		$redirect = wp_get_referer();
		wp_safe_redirect( $redirect ? $redirect : admin_url( 'edit-comments.php' ) );
		exit;
	}

	/**
	 * Remove the report counter, the auto-hold flag, and every per-user guard
	 * row. Clearing reports does NOT re-approve the review — approval stays a
	 * separate, deliberate admin action.
	 *
	 * @param int $comment_id Review to clear.
	 */
	public static function clear( int $comment_id ): void {
		global $wpdb;

		delete_comment_meta( $comment_id, self::META_COUNT );
		delete_comment_meta( $comment_id, self::META_HELD );

		// Per-user guard keys are dynamic (_zymarg_reported_{user_id}), so they
		// have to be looked up directly before they can be deleted.
		$like = $wpdb->esc_like( self::META_PREFIX ) . '%';
		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_key FROM {$wpdb->commentmeta} WHERE comment_id = %d AND meta_key LIKE %s",
				$comment_id,
				$like
			)
		);

		foreach ( (array) $keys as $key ) {
			delete_comment_meta( $comment_id, $key );
		}

		delete_transient( self::COUNT_CACHE );
	}
}
