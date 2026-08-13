<?php
/**
 * Seller Card renderer.
 *
 * Uses Dokan Pro store data when available.
 * Falls back to WP user data (product author) when Dokan is absent.
 *
 * Chat button integration (v2.4.7) — ZSP side of the same contract the
 * ZYMARG Store Page plugin already implements against the ZYMARG
 * Communication plugin (see Store Page's class-chat.php):
 *
 *   - This plugin renders [data-chat-btn data-seller-id="{vendor_user_id}"]
 *     when the ZYMARG Communication plugin is active and the shopper is
 *     logged in. `data-seller-id` is the vendor's WP user ID — the same
 *     value StoreChatService::openForStore() falls back to treating a
 *     'store' context id as when no `zymarg_comm_marketplace_resolve_vendor`
 *     filter overrides it, so no filter is required for this to resolve
 *     correctly out of the box.
 *   - The Communication plugin's live-chat.js listens for clicks on any
 *     [data-chat-btn] on the page (document-level delegation, bound once)
 *     and opens/creates the buyer<->vendor conversation via
 *     POST /marketplace/store-chat, automatically resolving to whichever
 *     vendor's product is currently being viewed.
 *   - Communication only enqueues its chat JS for logged-in users, so a
 *     logged-out shopper gets a plain login link (redirecting back to this
 *     product) instead of a dead button.
 *   - When Communication is not active at all, behaviour is unchanged from
 *     pre-2.4.7: the legacy `chat_url` option / Dokan store `#chat` anchor.
 *   - Detected via defined( 'ZYMARG_COMM_VERSION' ), not
 *     ZYMARG_COMM_API_NAMESPACE — that constant is never defined by the
 *     Communication plugin (checked against v1.33.0 source), so any future
 *     code should not rely on it either.
 *
 * @package ZymargSingleProduct
 */

namespace ZymargSP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seller_Card {

	/**
	 * Render the seller card block.
	 *
	 * @param \WC_Product $product
	 * @return void
	 */
	public static function render( \WC_Product $product ): void {
		if ( ! Options::get( 'show_seller_card' ) ) {
			return;
		}

		$data = self::get_seller_data( $product );
		if ( ! $data ) {
			return;
		}

		$show_visit = Options::get( 'show_visit_store' );
		$show_chat  = Options::get( 'show_chat_btn' );

		// v2.4.7 - when the ZYMARG Communication plugin is active, the chat
		// button is wired against its [data-chat-btn] contract instead of the
		// legacy chat_url/Dokan-anchor link. See class docblock above.
		$comm_active = self::is_comm_active();

		$chat_url = Options::get( 'chat_url', '' );

		// If no chat URL configured, try Dokan store URL with #chat.
		if ( ! $chat_url && $data['store_url'] ) {
			$chat_url = $data['store_url'] . '#chat';
		}

		?>
		<div class="zymarg-sp-seller-card">
			<div class="zymarg-sp-seller-card__inner">

				<!-- Avatar -->
				<div class="zymarg-sp-seller-card__avatar">
					<?php if ( $data['avatar'] ) : ?>
						<img src="<?php echo esc_url( $data['avatar'] ); ?>"
							alt="<?php echo esc_attr( $data['name'] ); ?>"
							class="zymarg-sp-seller-card__avatar-img"
							loading="lazy">
					<?php else : ?>
						<div class="zymarg-sp-seller-card__avatar-initials">
							<?php echo esc_html( self::initials( $data['name'] ) ); ?>
						</div>
					<?php endif; ?>
					<?php if ( $data['verified'] ) : ?>
						<span class="zymarg-sp-seller-card__verified" title="<?php esc_attr_e( 'Verified Seller', 'zymarg-single-product' ); ?>">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
								<path d="M23 12l-2.44-2.79.34-3.69-3.61-.82-1.89-3.2L12 2.96 8.6 1.5 6.71 4.69 3.1 5.5l.34 3.7L1 12l2.44 2.79-.34 3.7 3.61.82L8.6 22.5l3.4-1.47 3.4 1.46 1.89-3.19 3.61-.82-.34-3.69L23 12zm-12.91 4.72l-3.8-3.81 1.48-1.48 2.32 2.33 5.85-5.87 1.48 1.48-7.33 7.35z"/>
							</svg>
						</span>
					<?php endif; ?>
				</div>

				<!-- Info -->
				<div class="zymarg-sp-seller-card__info">
					<div class="zymarg-sp-seller-card__name-row">
						<span class="zymarg-sp-seller-card__name"><?php echo esc_html( $data['name'] ); ?></span>
					</div>

					<?php
					// v1.1.15 - rating and product count share one row so the card
					// never breaks into two stacked lines.
					$has_rating = $data['rating'] > 0;
					$has_count  = $data['product_count'] > 0;
					?>
					<?php if ( $has_rating || $has_count ) : ?>
					<div class="zymarg-sp-seller-card__meta">

						<?php if ( $has_rating ) : ?>
							<span class="zymarg-sp-seller-card__rating">
								<span class="zymarg-sp-seller-card__stars">
									<?php self::render_stars( $data['rating'] ); ?>
								</span>
								<?php // Mobile only - one filled star as a plain rating icon. ?>
								<svg class="zymarg-sp-star zymarg-sp-star--filled zymarg-sp-seller-card__star-single" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.81 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
								<span class="zymarg-sp-seller-card__rating-val"><?php echo esc_html( number_format( $data['rating'], 1 ) ); ?></span>
								<?php if ( $data['rating_count'] > 0 ) : ?>
									<span class="zymarg-sp-seller-card__rating-count">(<?php echo esc_html( number_format_i18n( $data['rating_count'] ) ); ?>)</span>
								<?php endif; ?>
							</span>
						<?php endif; ?>

						<?php if ( $has_rating && $has_count ) : ?>
							<span class="zymarg-sp-seller-card__sep" aria-hidden="true">&middot;</span>
						<?php endif; ?>

						<?php if ( $has_count ) : ?>
							<span class="zymarg-sp-seller-card__stats"><?php echo esc_html( sprintf(
								/* translators: %s number of products */
								_n( '%s product', '%s products', $data['product_count'], 'zymarg-single-product' ),
								number_format_i18n( $data['product_count'] )
							) ); ?></span>
						<?php endif; ?>

					</div>
					<?php endif; ?>
				</div>

				<!-- Actions -->
				<div class="zymarg-sp-seller-card__actions">
					<?php if ( $show_visit && $data['store_url'] ) : ?>
						<a href="<?php echo esc_url( $data['store_url'] ); ?>"
							class="zymarg-sp-seller-card__btn zymarg-sp-seller-card__btn--visit">
							<?php esc_html_e( 'Visit Store', 'zymarg-single-product' ); ?>
						</a>
					<?php endif; ?>

					<?php if ( $show_chat && $comm_active ) : ?>
						<?php if ( is_user_logged_in() ) : ?>
							<button type="button"
								data-chat-btn
								data-seller-id="<?php echo esc_attr( $data['vendor_user_id'] ); ?>"
								class="zymarg-sp-seller-card__btn zymarg-sp-seller-card__btn--chat">
								<?php // v1.1.16 - emoji glyph to match the design mockup (the old inline SVG inherited the button's purple). ?>
								<span class="zymarg-sp-seller-card__btn-emoji" aria-hidden="true">&#128172;</span>
								<?php esc_html_e( 'Chat', 'zymarg-single-product' ); ?>
							</button>
						<?php else : ?>
							<?php
							// v2.4.7 - Communication only enqueues its chat JS for
							// logged-in users, so a logged-out shopper is sent to log
							// in and returned to this exact product afterward, rather
							// than clicking a button that silently does nothing.
							$login_url = wp_login_url( get_permalink( $product->get_id() ) );
							?>
							<a href="<?php echo esc_url( $login_url ); ?>"
								class="zymarg-sp-seller-card__btn zymarg-sp-seller-card__btn--chat">
								<span class="zymarg-sp-seller-card__btn-emoji" aria-hidden="true">&#128172;</span>
								<?php esc_html_e( 'Chat', 'zymarg-single-product' ); ?>
							</a>
						<?php endif; ?>
					<?php elseif ( $show_chat && $chat_url ) : ?>
						<a href="<?php echo esc_url( $chat_url ); ?>"
							class="zymarg-sp-seller-card__btn zymarg-sp-seller-card__btn--chat">
							<?php // v1.1.16 - emoji glyph to match the design mockup (the old inline SVG inherited the button's purple). ?>
							<span class="zymarg-sp-seller-card__btn-emoji" aria-hidden="true">&#128172;</span>
							<?php esc_html_e( 'Chat', 'zymarg-single-product' ); ?>
						</a>
					<?php endif; ?>
				</div>

			</div>
		</div>
		<?php
	}

	// ── Data layer ────────────────────────────────────────────────────────────

	/**
	 * Fetch seller data — Dokan first, WP user fallback.
	 *
	 * @param \WC_Product $product
	 * @return array|null
	 */
	private static function get_seller_data( \WC_Product $product ): ?array {
		$author_id = (int) get_post_field( 'post_author', $product->get_id() );
		if ( ! $author_id ) {
			return null;
		}

		// ── Dokan path ───────────────────────────────────────────────────────
		if ( function_exists( 'dokan_get_store_info' ) ) {
			$store_info = dokan_get_store_info( $author_id );

			$name        = ! empty( $store_info['store_name'] ) ? $store_info['store_name'] : get_user_meta( $author_id, 'nickname', true );
			$avatar_id   = ! empty( $store_info['gravatar'] )   ? (int) $store_info['gravatar'] : 0;
			$avatar      = $avatar_id ? wp_get_attachment_url( $avatar_id ) : get_avatar_url( $author_id, [ 'size' => 80 ] );
			$verified    = ! empty( $store_info['dokan_verification_status'] ) && 'approved' === $store_info['dokan_verification_status'];
			$store_url   = function_exists( 'dokan_get_store_url' ) ? dokan_get_store_url( $author_id ) : '';

			// Rating.
			$rating       = 0;
			$rating_count = 0;
			if ( function_exists( 'dokan_get_seller_rating' ) ) {
				$r            = dokan_get_seller_rating( $author_id );
				$rating       = (float) ( $r['rating'] ?? 0 );
				$rating_count = (int)   ( $r['count']  ?? 0 );
			}

			// Product count.
			$product_count = 0;
			if ( function_exists( 'dokan_count_posts' ) ) {
				$counts        = dokan_count_posts( 'product', $author_id );
				$product_count = (int) ( $counts->publish ?? 0 );
			}

			// v2.4.7 - the vendor's WP user ID, exposed so the chat button can
			// pass it straight through as data-seller-id. This is $author_id,
			// unchanged, but named for what the Communication plugin does with
			// it rather than for how it was looked up.
			$vendor_user_id = $author_id;

			return compact( 'name', 'avatar', 'verified', 'store_url', 'rating', 'rating_count', 'product_count', 'vendor_user_id' );
		}

		// ── WP User fallback ─────────────────────────────────────────────────
		$user = get_user_by( 'id', $author_id );
		if ( ! $user ) {
			return null;
		}

		return [
			'name'           => $user->display_name,
			'avatar'         => get_avatar_url( $author_id, [ 'size' => 80 ] ),
			'verified'       => false,
			'store_url'      => '',
			'rating'         => 0,
			'rating_count'   => 0,
			'product_count'  => 0,
			'vendor_user_id' => $author_id,
		];
	}

	/**
	 * Whether the ZYMARG Communication plugin is active on this site.
	 *
	 * Detected via ZYMARG_COMM_VERSION, which the plugin's main file defines
	 * unconditionally on load. NOT ZYMARG_COMM_API_NAMESPACE — that constant
	 * does not exist anywhere in the Communication plugin's source (verified
	 * against v1.33.0), so checking it always resolves to false. The ZYMARG
	 * Store Page plugin's ZYMARG_SP_Chat::is_comm_active() currently checks
	 * that constant and should be corrected the same way in a future change.
	 *
	 * @return bool
	 */
	private static function is_comm_active(): bool {
		return defined( 'ZYMARG_COMM_VERSION' );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private static function initials( string $name ): string {
		$parts    = preg_split( '/\s+/', trim( $name ) );
		$initials = '';
		foreach ( $parts as $p ) {
			if ( '' === $p ) continue;
			$initials .= mb_strtoupper( mb_substr( $p, 0, 1 ) );
			if ( mb_strlen( $initials ) >= 2 ) break;
		}
		return $initials ?: '?';
	}

	private static function render_stars( float $rating ): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			if ( $rating >= $i ) {
				echo '<svg class="zymarg-sp-star zymarg-sp-star--filled" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.81 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>'; // phpcs:ignore
			} elseif ( $rating >= $i - 0.5 ) {
				echo '<svg class="zymarg-sp-star zymarg-sp-star--half" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 9.24l-7.19-.62L12 2v15.27L18.18 21l-1.63-7.03L22 9.24zM12 15.4V6.1l1.71 4.04 4.38.38-3.32 2.88 1 4.28L12 15.4z"/></svg>'; // phpcs:ignore
			} else {
				echo '<svg class="zymarg-sp-star zymarg-sp-star--empty" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 9.24l-7.19-.62L12 2 9.19 8.62 2 9.24l5.46 4.73L5.82 21 12 17.27 18.18 21l-1.63-7.03L22 9.24zM12 15.4l-3.76 2.27 1-4.28-3.32-2.88 4.38-.38L12 6.1l1.71 4.04 4.38.38-3.32 2.88 1 4.28L12 15.4z"/></svg>'; // phpcs:ignore
			}
		}
	}
}
