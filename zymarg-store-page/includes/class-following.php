<?php
/**
 * ZYMARG Store Page — User Following List (My Account integration)
 *
 * Registers a "Following" section in the ZYMARG OS My Account page via the
 * theme's official extension API (zymarg_os_account_sections filter).
 *
 * The section only appears when this plugin is active. No theme files are
 * modified. The section loads through the theme's SPA AJAX system automatically
 * because it is registered through the proper API.
 *
 * @package ZYMARG_Store_Page
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZYMARG_SP_Following {

	public static function init() {
		// Register into the theme's My Account extension API.
		add_filter( 'zymarg_os_account_sections', [ __CLASS__, 'register_section' ] );

		// Enqueue the unfollow JS only on My Account pages.
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Theme extension API
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Register the Following section with the ZYMARG OS My Account API.
	 *
	 * Hooked onto: zymarg_os_account_sections
	 *
	 * @param array $sections Existing plugin-registered sections.
	 * @return array
	 */
	public static function register_section( array $sections ): array {
		// Person-with-heart SVG — matches the Feather-icon style used throughout
		// the theme (18×18, stroke-only, stroke-width 2).
		$icon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M20.84 4.61a5.5 5.5 0 0 1 0 7.78L17 16.22l-3.84-3.83a5.5 5.5 0 0 1 7.68-7.78z"/></svg>';

		$sections['following'] = [
			'label'     => __( 'Following', 'zymarg-store-page' ),
			'icon'      => $icon,
			'endpoint'  => 'following',
			'after'     => 'wishlist',        // Appears right after Wishlist — logical for a shopper
			'render_cb' => [ __CLASS__, 'render_section' ],
			'enabled'   => true,
		];

		return $sections;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Assets
	// ─────────────────────────────────────────────────────────────────────────

	public static function enqueue_assets() {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! is_user_logged_in() ) {
			return;
		}

		wp_enqueue_script(
			'zymarg-sp-following',
			ZYMARG_SP_URL . 'assets/js/following.js',
			[],
			ZYMARG_SP_VERSION,
			true
		);

		wp_localize_script(
			'zymarg-sp-following',
			'ZYMARG_FOLLOWING',
			[
				'apiBase'  => esc_url_raw( rest_url() ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'followNs' => defined( 'ZYMARG_VD_API_NS' ) ? ZYMARG_VD_API_NS : 'zymarg/v1',
				'i18n'     => [
					'unfollowing' => __( 'Unfollowing…',                      'zymarg-store-page' ),
					'error'       => __( 'Something went wrong. Try again.',   'zymarg-store-page' ),
				],
			]
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Section renderer  (called by the theme's zymarg_os_account_render_section)
	// ─────────────────────────────────────────────────────────────────────────

	public static function render_section(): void {
		$user_id  = get_current_user_id();
		$followed = self::get_followed_store_ids( $user_id );
		$stores   = array_values( array_filter( array_map( [ __CLASS__, 'build_store_data' ], $followed ) ) );
		?>

		<!-- Following section header — matches theme's panel-header pattern -->
		<div class="panel-header">
			<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M20.84 4.61a5.5 5.5 0 0 1 0 7.78L17 16.22l-3.84-3.83a5.5 5.5 0 0 1 7.68-7.78z"/></svg>
			<h2><?php esc_html_e( 'Following', 'zymarg-store-page' ); ?></h2>
		</div>
		<p class="panel-desc"><?php esc_html_e( 'Stores you follow — visit, browse, or unfollow anytime.', 'zymarg-store-page' ); ?></p>

		<?php if ( empty( $stores ) ) : ?>

			<!-- Empty state — matches theme's empty-state class -->
			<div class="empty-state">
				<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 1 0 7.78L12 21.23l-8.84-8.84a5.5 5.5 0 0 1 7.78-7.78l1.06 1.06 1.06-1.06a5.5 5.5 0 0 1 7.78 0z"/></svg>
				<p><?php esc_html_e( 'You aren\'t following any stores yet. Tap Follow on any store page to add it here.', 'zymarg-store-page' ); ?></p>
			</div>

		<?php else : ?>

			<p style="font-size:13px;color:var(--color-text-muted,#6b7280);margin:-4px 0 20px;">
				<?php printf(
					/* translators: %d: number of stores */
					esc_html( _n( 'Following %d store', 'Following %d stores', count( $stores ), 'zymarg-store-page' ) ),
					(int) count( $stores )
				); ?>
			</p>

			<!-- Store grid -->
			<div class="zsp-following-grid">
				<?php foreach ( $stores as $s ) : ?>

					<div class="zsp-following-card" data-store-id="<?php echo esc_attr( $s['id'] ); ?>">

						<!-- Clickable store info -->
						<a href="<?php echo esc_url( $s['url'] ); ?>" class="zsp-following-card__link">
							<div class="zsp-following-card__avatar">
								<?php if ( $s['avatar_url'] ) : ?>
									<img src="<?php echo esc_url( $s['avatar_url'] ); ?>" alt="<?php echo esc_attr( $s['name'] ); ?>">
								<?php else : ?>
									<span><?php echo esc_html( $s['initial'] ); ?></span>
								<?php endif; ?>
							</div>
							<div class="zsp-following-card__info">
								<strong><?php echo esc_html( $s['name'] ); ?></strong>
								<?php if ( $s['tagline'] ) : ?>
									<em><?php echo esc_html( $s['tagline'] ); ?></em>
								<?php endif; ?>
								<div class="zsp-following-card__stats">
									<?php if ( $s['followers'] ) : ?>
										<span>
											<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
											<?php echo esc_html( self::format_number( $s['followers'] ) ); ?>
										</span>
									<?php endif; ?>
									<?php if ( $s['rating'] > 0 ) : ?>
										<span>
											<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 15.1 8.3 22 9.2 17 14.1 18.2 21 12 17.8 5.8 21 7 14.1 2 9.2 8.9 8.3 12 2"/></svg>
											<?php echo esc_html( number_format( $s['rating'], 1 ) ); ?>
										</span>
									<?php endif; ?>
								</div>
							</div>
						</a>

						<!-- Action buttons -->
						<div class="zsp-following-card__actions">
							<a href="<?php echo esc_url( $s['url'] ); ?>" class="btn btn-primary btn-sm">
								<?php esc_html_e( 'Visit Store', 'zymarg-store-page' ); ?>
							</a>
							<button type="button"
								class="btn btn-outline btn-sm"
								data-unfollow="<?php echo esc_attr( $s['id'] ); ?>"
								aria-label="<?php echo esc_attr( sprintf( __( 'Unfollow %s', 'zymarg-store-page' ), $s['name'] ) ); ?>">
								<?php esc_html_e( 'Unfollow', 'zymarg-store-page' ); ?>
							</button>
						</div>

					</div>

				<?php endforeach; ?>
			</div>

		<?php endif;

		// Scoped styles — injected once, uses the theme's own CSS variables
		// so colors, fonts, and radii all inherit the active theme/dark-mode.
		static $styles_printed = false;
		if ( ! $styles_printed ) :
			$styles_printed = true;
			?>
			<style>
			.zsp-following-grid {
				display: grid;
				grid-template-columns: repeat( auto-fill, minmax( 260px, 1fr ) );
				gap: 14px;
				margin-top: 4px;
			}
			.zsp-following-card {
				border: 1px solid var(--color-border, #e5e7eb);
				border-radius: var(--radius-lg, 12px);
				background: var(--color-surface, #fff);
				overflow: hidden;
				display: flex;
				flex-direction: column;
				transition: box-shadow .2s, transform .2s;
			}
			.zsp-following-card:hover {
				box-shadow: 0 4px 20px rgba(0,0,0,.07);
				transform: translateY(-2px);
			}
			.zsp-following-card__link {
				display: flex;
				align-items: center;
				gap: 13px;
				padding: 15px 15px 12px;
				text-decoration: none;
				color: inherit;
				flex: 1;
			}
			.zsp-following-card__link:hover { text-decoration: none; color: inherit; }

			.zsp-following-card__avatar {
				width: 52px; height: 52px;
				border-radius: 10px;
				flex-shrink: 0;
				overflow: hidden;
				background: var(--color-gradient, linear-gradient(135deg,#9500A5,#BD00D1 60%,#FEA9FF));
				display: flex; align-items: center; justify-content: center;
			}
			.zsp-following-card__avatar img {
				width: 100%; height: 100%; object-fit: cover; display: block;
			}
			.zsp-following-card__avatar span {
				color: #fff; font-size: 1.3rem; font-weight: 800; line-height: 1;
			}

			.zsp-following-card__info { min-width: 0; flex: 1; }
			.zsp-following-card__info strong {
				display: block;
				font-size: .9375rem;
				font-weight: 700;
				color: var(--color-text-heading, #131b2e);
				white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
			}
			.zsp-following-card__info em {
				display: block;
				font-style: normal;
				font-size: .8rem;
				color: var(--color-text-muted, #6b7280);
				margin-top: 2px;
				white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
			}
			.zsp-following-card__stats {
				display: flex; align-items: center; gap: 10px; margin-top: 6px;
			}
			.zsp-following-card__stats span {
				display: flex; align-items: center; gap: 4px;
				font-size: .78rem;
				color: var(--color-text-muted, #6b7280);
			}
			.zsp-following-card__stats svg {
				width: 12px; height: 12px; flex-shrink: 0;
			}

			.zsp-following-card__actions {
				display: flex; gap: 8px;
				padding: 10px 15px;
				border-top: 1px solid var(--color-border, #f3f4f6);
				background: var(--color-surface-alt, #fafafa);
			}
			.zsp-following-card__actions .btn { flex: 1; text-align: center; }
			.zsp-following-card__actions .btn:disabled { opacity: .5; cursor: wait; }
			</style>
			<?php
		endif;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Data helpers
	// ─────────────────────────────────────────────────────────────────────────

	private static function get_followed_store_ids( int $user_id ): array {
		$raw = get_user_meta( $user_id, '_zymarg_followed_stores', true );
		return is_array( $raw ) ? array_map( 'intval', array_filter( $raw ) ) : [];
	}

	private static function build_store_data( int $store_id ): ?array {
		$vendor = get_userdata( $store_id );
		if ( ! $vendor ) {
			return null;
		}

		$info       = function_exists( 'dokan_get_store_info' ) ? dokan_get_store_info( $store_id ) : [];
		$store_name = ! empty( $info['store_name'] ) ? $info['store_name'] : $vendor->display_name;
		$tagline    = ! empty( $info['store_tagline'] ) ? $info['store_tagline'] : '';
		$store_url  = function_exists( 'dokan_get_store_url' ) ? dokan_get_store_url( $store_id ) : home_url( '/' );

		// Avatar: Dokan gravatar attachment ID → URL → cached meta.
		$gravatar_raw = ! empty( $info['gravatar'] ) ? $info['gravatar'] : '';
		$avatar_url   = '';
		if ( $gravatar_raw ) {
			if ( is_numeric( $gravatar_raw ) ) {
				$avatar_url = (string) wp_get_attachment_image_url( (int) $gravatar_raw, 'thumbnail' );
				if ( ! $avatar_url ) {
					$avatar_url = (string) get_user_meta( $store_id, '_zymarg_store_avatar_url', true );
				}
			} else {
				$avatar_url = esc_url( $gravatar_raw );
			}
		}
		if ( ! $avatar_url ) {
			$avatar_url = (string) get_user_meta( $store_id, '_zymarg_store_avatar_url', true );
		}

		$followers    = class_exists( 'ZYMARG_SP_Follow' )
			? ZYMARG_SP_Follow::get_count( $store_id )
			: (int) get_user_meta( $store_id, '_zymarg_followers_count', true );
		$rating       = isset( $info['rating']['rating'] ) ? (float) $info['rating']['rating'] : 0.0;
		$rating_count = isset( $info['rating']['count'] )  ? (int)   $info['rating']['count']  : 0;

		return [
			'id'          => $store_id,
			'name'        => $store_name,
			'tagline'     => $tagline,
			'url'         => $store_url,
			'avatar_url'  => $avatar_url,
			'initial'     => mb_strtoupper( mb_substr( $store_name, 0, 1 ) ),
			'followers'   => $followers,
			'rating'      => $rating,
			'rating_count'=> $rating_count,
		];
	}

	private static function format_number( int $n ): string {
		return $n >= 1000 ? number_format( $n / 1000, 1 ) . 'K' : number_format_i18n( $n );
	}
}
