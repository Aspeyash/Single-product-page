<?php
/**
 * Renderer — produces the full header HTML.
 *
 * v1.1.0: render_cart() now uses ZymargHeader\Cart_Ajax and Settings
 * exclusively. No dependency on ZymargThemeBuilder\Cart_Ajax. All cart
 * behaviour (snapshot, fragload, items HTML, panel) is fully self-contained.
 *
 * The rendered cart structure uses the same CSS classes as the Theme Builder
 * Cart widget (.zymarg-tb-cart, .zymarg-tb-cart__*, etc.) so that
 * zymarg-cart.js — which is a direct port of cart.js — works without
 * modification.
 *
 * @package ZymargHeader
 */

namespace ZymargHeader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Renderer {

	/* ── SVG icon set ─────────────────────────────────────────── */

	private static function icon_storefront(): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9h18v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9z"/><path d="M3 9l1.5-5h15L21 9"/><path d="M9 9v1a3 3 0 0 1-6 0V9"/><path d="M15 9v1a3 3 0 0 1-6 0V9"/><path d="M21 9v1a3 3 0 0 1-6 0V9"/></svg>';
	}

	private static function icon_account(): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>';
	}

	private static function icon_wishlist(): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';
	}

	private static function icon_cart(): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="6" cy="19" r="2"/><circle cx="17" cy="19" r="2"/><path d="M17 17H6V3H4"/><path d="m6 5 14 1-1 7H6"/></svg>';
	}

	/* ── Data helpers ──────────────────────────────────────────── */

	private static function wishlist_count(): int {
		if ( class_exists( '\\Zymarg\\WCPG\\Wishlist\\Wishlist_Store' ) ) {
			return count( \Zymarg\WCPG\Wishlist\Wishlist_Store::get_ids() );
		}
		return 0;
	}

	/* ── Badge HTML ────────────────────────────────────────────── */

	private static function badge( int $count, string $extra_classes = '' ): string {
		$hidden = $count === 0 ? ' hidden' : '';
		$class  = trim( 'z-hdr-badge ' . $extra_classes );
		return sprintf(
			'<span class="%s" data-prev="%d"%s>%d</span>',
			esc_attr( $class ),
			$count,
			$hidden,
			$count
		);
	}

	/* ── Top bar ───────────────────────────────────────────────── */

	private static function render_topbar(): string {
		$seller_url   = esc_url( Settings::get( 'seller_url' ) );
		$seller_label = esc_html( Settings::get( 'seller_label' ) );
		$login_url    = esc_url( Settings::login_url() );
		$register_url = esc_url( Settings::register_url() );

		ob_start();
		?>
		<div class="z-hdr-topbar" id="zHdrTopbar">
			<div class="z-hdr-topbar__inner">
				<div class="z-hdr-topbar__left">
					<a href="<?php echo $seller_url; ?>" class="z-hdr-seller-btn">
						<?php echo self::icon_storefront(); ?>
						<span><?php echo $seller_label; ?></span>
					</a>
				</div>
				<div class="z-hdr-topbar__right">
					<a href="<?php echo $login_url; ?>" class="z-hdr-topbar__link">
						<?php esc_html_e( 'Login', 'zymarg-header' ); ?>
					</a>
					<span class="z-hdr-topbar__divider" aria-hidden="true"></span>
					<a href="<?php echo $register_url; ?>" class="z-hdr-topbar__link">
						<?php esc_html_e( 'Register', 'zymarg-header' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ── Logo ──────────────────────────────────────────────────── */

	private static function render_logo(): string {
		$logo_url = esc_url( Settings::get( 'logo_url', home_url( '/' ) ) );

		ob_start();
		?>
		<div class="z-hdr-logo">
			<?php
			$custom_logo_id = (int) get_theme_mod( 'custom_logo' );
			if ( $custom_logo_id > 0 ) {
				$logo_html = get_custom_logo();
				// Use quoted attribute values so 'custom-logo' inside
				// 'custom-logo-link' is never accidentally replaced.
				$logo_html = str_replace( 'class="custom-logo-link"', 'class="custom-logo-link z-hdr-logo__link"', $logo_html );
				$logo_html = str_replace( 'class="custom-logo"',      'class="custom-logo z-hdr-logo__img"',      $logo_html );
				echo $logo_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( (int) Settings::get( 'logo_image_id' ) > 0 ) {
				$logo_id  = (int) Settings::get( 'logo_image_id' );
				$logo_alt = esc_attr( Settings::get( 'logo_alt', get_bloginfo( 'name' ) ) );
				$img_url  = (string) wp_get_attachment_image_url( $logo_id, 'full' );
				echo '<a href="' . $logo_url . '" class="z-hdr-logo__link">';
				echo '<img src="' . esc_url( $img_url ) . '" alt="' . $logo_alt . '" class="z-hdr-logo__img">';
				echo '</a>';
			} else {
				echo '<a href="' . $logo_url . '" class="z-hdr-logo__link">';
				echo '<span class="z-hdr-logo__text">' . esc_html( get_bloginfo( 'name' ) ) . '</span>';
				echo '</a>';
			}
			?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ── Search bar ────────────────────────────────────────────── */

	private static function render_search(): string {
		if ( class_exists( 'Zymarg_Algolia_Frontend' ) ) {
			$html = \Zymarg_Algolia_Frontend::render_html( array( 'stretch' => true ) );
			if ( ! empty( $html ) ) {
				return '<div class="z-hdr-search">' . $html . '</div>';
			}
		}

		$action      = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
		$placeholder = esc_attr__( 'Search products, vendors, categories…', 'zymarg-header' );

		ob_start();
		?>
		<div class="z-hdr-search">
			<div class="z-hdr-search__bar">
				<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.35-4.35"/></svg>
				<form role="search" action="<?php echo esc_url( $action ); ?>" method="get">
					<input type="search" name="s" placeholder="<?php echo $placeholder; ?>" autocomplete="off">
				</form>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ── Action icons ──────────────────────────────────────────── */

	private static function render_actions(): string {
		$wishlist_count = self::wishlist_count();
		$account_url    = esc_url( Settings::account_url() );
		$wishlist_url   = esc_url( Settings::get( 'wishlist_url', '/wishlist/' ) );
		$wl_anim        = Settings::get( 'wishlist_badge_animation', 'none' );

		ob_start();
		?>
		<div class="z-hdr-actions">

			<a href="<?php echo $account_url; ?>" class="z-hdr-action z-hdr-action--account z-hdr-desktop-only"
			   aria-label="<?php esc_attr_e( 'My Account', 'zymarg-header' ); ?>">
				<span class="z-hdr-action__icon"><?php echo self::icon_account(); ?></span>
				<span class="z-hdr-action__label"><?php esc_html_e( 'Account', 'zymarg-header' ); ?></span>
			</a>

			<a href="<?php echo $wishlist_url; ?>" class="z-hdr-action z-hdr-action--wishlist z-hdr-desktop-only z-hdr-wishlist--badge-anim-<?php echo esc_attr( $wl_anim ); ?>"
			   data-wl-badge-anim="<?php echo esc_attr( $wl_anim ); ?>"
			   aria-label="<?php echo esc_attr( sprintf( _n( 'Wishlist — %d item', 'Wishlist — %d items', $wishlist_count, 'zymarg-header' ), $wishlist_count ) ); ?>">
				<span class="z-hdr-action__icon">
					<?php echo self::icon_wishlist(); ?>
					<?php echo self::badge( $wishlist_count, 'z-hdr-wishlist-count' ); ?>
				</span>
				<span class="z-hdr-action__label"><?php esc_html_e( 'Wishlist', 'zymarg-header' ); ?></span>
			</a>

			<?php echo self::render_cart(); ?>

		</div>
		<?php
		return ob_get_clean();
	}

	/* ── Cart ──────────────────────────────────────────────────── */

	/**
	 * Render the full cart trigger + mini-cart panel.
	 *
	 * Uses ZymargHeader\Cart_Ajax exclusively — zero Theme Builder dependency.
	 * The HTML structure mirrors the Theme Builder Cart widget exactly so that
	 * zymarg-cart.js (the ported cart.js) works without changes:
	 *  - .zymarg-tb-cart         → initRoot(), syncAll() root selector
	 *  - .zymarg-tb-cart-fragload → MutationObserver target
	 *  - .zymarg-tb-cart__count  → syncInstance() badge target
	 *  - .zymarg-tb-cart__items  → WC fragment swap target
	 *  - .zymarg-tb-cart__panel  → panel open/close
	 *
	 * Additional class .z-hdr-cart-root is used as the scope for the
	 * admin-generated inline CSS (Settings::cart_inline_css()).
	 *
	 * @return string
	 */
	public static function render_cart(): string {
		// Graceful fallback: WooCommerce not active.
		if ( ! function_exists( 'WC' ) ) {
			return '';
		}

		$count_type = Settings::get( 'cart_count_type', 'total_qty' );
		$action     = Settings::get( 'cart_click_action', 'dropdown' );
		$action     = in_array( $action, array( 'dropdown', 'offcanvas', 'popup', 'cart', 'checkout' ), true ) ? $action : 'dropdown';
		$has_panel  = in_array( $action, array( 'dropdown', 'offcanvas', 'popup' ), true );

		$data     = Cart_Ajax::get_cart_snapshot( $count_type );
		$count    = (int) $data['count'];
		$cart_url = Cart_Ajax::cart_url();
		$panel_id = 'z-hdr-cart-panel';

		/* Inner .zymarg-tb-cart classes — NOT including z-hdr-cart-root.
		 * z-hdr-cart-root is the OUTER wrapper div (see below).
		 * zymarg-cart.css scopes all rules as:
		 *   .z-hdr-cart-root .zymarg-tb-cart { ... }   ← descendant, not same element
		 * so the two classes must be on separate elements. */
		$classes = array( 'zymarg-tb-cart', 'zymarg-tb-cart--action-' . $action );
		if ( $has_panel ) {
			$open_anim = Settings::get( 'cart_open_animation', 'slide' );
			$classes[] = 'zymarg-tb-cart--open-' . $open_anim;
			if ( 'offcanvas' === $action ) {
				$pos       = Settings::get( 'cart_offcanvas_position', 'right' );
				$classes[] = 'zymarg-tb-cart--pos-' . $pos;
			}
			if ( Settings::cart_bool( 'cart_mobile_bottom_sheet' ) ) {
				$classes[] = 'zymarg-tb-cart--mobile-sheet';
			}
			if ( ! Settings::cart_bool( 'cart_show_product_image' ) ) { $classes[] = 'zymarg-tb-cart--hide-image'; }
			if ( Settings::cart_bool( 'cart_show_sku', false ) )       { $classes[] = 'zymarg-tb-cart--show-sku'; }
			if ( ! Settings::cart_bool( 'cart_show_attributes' ) )     { $classes[] = 'zymarg-tb-cart--hide-attrs'; }
			if ( ! Settings::cart_bool( 'cart_allow_qty_update' ) )    { $classes[] = 'zymarg-tb-cart--no-qty'; }
			if ( ! Settings::cart_bool( 'cart_allow_remove' ) )        { $classes[] = 'zymarg-tb-cart--no-remove'; }
		}
		$badge_anim = Settings::get( 'cart_badge_animation', 'bounce' );
		$classes[]  = 'zymarg-tb-cart--badge-anim-' . $badge_anim;

		/* Badge visibility */
		$show_badge    = Settings::cart_bool( 'cart_show_badge' );
		$show_zero     = Settings::cart_bool( 'cart_show_zero', false );
		$badge_hidden  = ( ! $show_badge || ( 0 === $count && ! $show_zero ) ) ? ' style="display:none;"' : '';
		$badge_pos_cls = 'zymarg-tb-cart__badge-' . Settings::get( 'cart_badge_position', 'top-right' );

		$cart_text = Settings::get( 'cart_text', __( 'Cart', 'zymarg-header' ) );

		/* Href fallback for progressive enhancement */
		$href = ( 'checkout' === $action ) ? Cart_Ajax::checkout_url() : $cart_url;

		/* Aria label for trigger */
		$qty        = (int) $data['qty'];
		$aria_label = sprintf( _n( 'View cart, %d item', 'View cart, %d items', $qty, 'zymarg-header' ), $qty );

		ob_start();
		?>
		<div class="z-hdr-cart-root">
		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
		     data-action="<?php echo esc_attr( $action ); ?>"
		     data-count-type="<?php echo esc_attr( $count_type ); ?>"
		     data-show-zero="<?php echo $show_zero ? '1' : '0'; ?>"
		     data-badge-anim="<?php echo esc_attr( $badge_anim ); ?>">

			<!-- Trigger -->
			<a class="zymarg-tb-cart__trigger z-hdr-action z-hdr-action--cart"
			   href="<?php echo esc_url( $href ); ?>"
			   <?php if ( $has_panel ) : ?>
			   aria-haspopup="dialog"
			   aria-expanded="false"
			   aria-controls="<?php echo esc_attr( $panel_id ); ?>"
			   <?php endif; ?>
			   aria-label="<?php echo esc_attr( $aria_label ); ?>">

				<span class="zymarg-tb-cart__icon-wrap z-hdr-action__icon">
					<span class="zymarg-tb-cart__icon"><?php echo self::icon_cart(); ?></span>
					<?php if ( $show_badge ) : ?>
					<span class="zymarg-tb-cart__count <?php echo esc_attr( $badge_pos_cls ); ?>"<?php echo $badge_hidden; ?>>
						<?php echo esc_html( (string) $count ); ?>
					</span>
					<?php endif; ?>
				</span>

				<span class="zymarg-tb-cart__text z-hdr-action__label">
					<?php echo esc_html( $cart_text ); ?>
				</span>

				<?php if ( Settings::cart_bool( 'cart_show_subtotal', false ) ) : ?>
				<span class="zymarg-tb-cart__subtotal">
					<?php echo wp_kses_post( $data['subtotal'] ); ?>
				</span>
				<?php endif; ?>

			</a><!-- /.trigger -->

			<?php if ( $has_panel ) : ?>
			<?php echo self::render_cart_panel( $data, $action, $panel_id ); ?>
			<?php endif; ?>

			<!-- Fragload — zymarg-cart.js MutationObserver watches this span.
			     Cart_Ajax::cart_fragments() replaces it on every add-to-cart. -->
			<?php echo Cart_Ajax::fragload_html( $data ); // phpcs:ignore ?>

		</div><!-- /.zymarg-tb-cart -->
		</div><!-- /.z-hdr-cart-root -->
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the mini-cart panel (dropdown / off-canvas / popup).
	 * Positioning is pure CSS keyed off the wrapper action class.
	 *
	 * @param array  $data     Cart snapshot.
	 * @param string $action   Click action.
	 * @param string $panel_id Panel DOM id.
	 * @return string
	 */
	private static function render_cart_panel( array $data, string $action, string $panel_id ): string {
		$panel_title = Settings::get( 'cart_panel_title', __( 'Your Cart', 'zymarg-header' ) );
		$is_empty    = ( 0 === (int) $data['qty'] );

		ob_start();

		?>
		<div class="zymarg-tb-cart__panel zymarg-tb-cart__panel--<?php echo esc_attr( $action ); ?>"
		     id="<?php echo esc_attr( $panel_id ); ?>"
		     role="dialog"
		     aria-modal="<?php echo 'dropdown' === $action ? 'false' : 'true'; ?>"
		     aria-label="<?php echo esc_attr( $panel_title ); ?>"
		     hidden>

			<!-- Header -->
			<div class="zymarg-tb-cart__panel-head">
				<span class="zymarg-tb-cart__panel-title"><?php echo esc_html( $panel_title ); ?></span>
				<button type="button" class="zymarg-tb-cart__close"
				        aria-label="<?php esc_attr_e( 'Close cart', 'zymarg-header' ); ?>">
					&times;
				</button>
			</div>

			<!-- Body -->
			<div class="zymarg-tb-cart__body<?php echo $is_empty ? ' is-empty' : ''; ?>">

				<!-- Loading skeleton -->
				<div class="zymarg-tb-cart__skeleton" aria-hidden="true">
					<span></span><span></span><span></span>
				</div>

				<!-- Item list (fragment target) -->
				<ul class="zymarg-tb-cart__items" aria-live="polite">
					<?php echo Cart_Ajax::items_html( $data['items'] ); // phpcs:ignore ?>
				</ul>

				<!-- Empty state -->
				<?php echo self::render_cart_empty(); ?>

			</div><!-- /.body -->

			<!-- Footer -->
			<div class="zymarg-tb-cart__foot">
				<div class="zymarg-tb-cart__foot-subtotal">
					<span><?php esc_html_e( 'Subtotal', 'zymarg-header' ); ?></span>
					<span class="zymarg-tb-cart__subtotal"><?php echo wp_kses_post( $data['subtotal'] ); ?></span>
				</div>
				<div class="zymarg-tb-cart__actions">
					<?php if ( Settings::cart_bool( 'cart_show_continue', false ) ) : ?>
					<?php
					$continue_url  = Settings::get( 'cart_continue_url', '' );
					$continue_url  = '' !== $continue_url ? $continue_url : Cart_Ajax::shop_url();
					$continue_text = Settings::get( 'cart_continue_text', __( 'Continue Shopping', 'zymarg-header' ) );
					?>
					<a class="zymarg-tb-cart__btn zymarg-tb-cart__btn--ghost zymarg-tb-cart__continue"
					   href="<?php echo esc_url( $continue_url ); ?>">
						<?php echo esc_html( $continue_text ); ?>
					</a>
					<?php endif; ?>

					<?php if ( Settings::cart_bool( 'cart_show_view_cart' ) ) : ?>
					<a class="zymarg-tb-cart__btn zymarg-tb-cart__btn--outline"
					   href="<?php echo esc_url( Cart_Ajax::cart_url() ); ?>">
						<?php echo esc_html( Settings::get( 'cart_view_cart_text', __( 'View Cart', 'zymarg-header' ) ) ); ?>
					</a>
					<?php endif; ?>

					<?php if ( Settings::cart_bool( 'cart_show_checkout' ) ) : ?>
					<a class="zymarg-tb-cart__btn zymarg-tb-cart__btn--primary"
					   href="<?php echo esc_url( Cart_Ajax::checkout_url() ); ?>">
						<span class="zymarg-tb-cart__btn-label">
							<?php echo esc_html( Settings::get( 'cart_checkout_text', __( 'Checkout', 'zymarg-header' ) ) ); ?>
						</span>
					</a>
					<?php endif; ?>
				</div><!-- /.actions -->
			</div><!-- /.foot -->

		</div><!-- /.panel -->
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the empty-cart state block.
	 *
	 * @return string
	 */
	private static function render_cart_empty(): string {
		$empty_title  = Settings::get( 'cart_empty_title', __( 'Your cart is empty', 'zymarg-header' ) );
		$empty_text   = Settings::get( 'cart_empty_text', __( 'Looks like you have not added anything yet.', 'zymarg-header' ) );
		$btn_text     = Settings::get( 'cart_empty_button_text', __( 'Start Shopping', 'zymarg-header' ) );
		$btn_url      = Settings::get( 'cart_empty_button_url', '' );
		$btn_url      = '' !== $btn_url ? $btn_url : Cart_Ajax::shop_url();

		ob_start();
		?>
		<div class="zymarg-tb-cart__empty">
			<span class="zymarg-tb-cart__empty-icon"><?php echo self::icon_cart(); ?></span>
			<p class="zymarg-tb-cart__empty-title"><?php echo esc_html( $empty_title ); ?></p>
			<?php if ( '' !== $empty_text ) : ?>
			<p class="zymarg-tb-cart__empty-text"><?php echo esc_html( $empty_text ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $btn_text ) : ?>
			<a class="zymarg-tb-cart__btn zymarg-tb-cart__btn--primary"
			   href="<?php echo esc_url( $btn_url ); ?>">
				<?php echo esc_html( $btn_text ); ?>
			</a>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ── Header bar ────────────────────────────────────────────── */

	private static function render_headerbar(): string {
		ob_start();
		?>
		<div class="z-hdr-bar" id="zHdrBar">
			<div class="z-hdr-bar__inner">
				<?php echo self::render_logo(); ?>
				<?php echo self::render_search(); ?>
				<?php echo self::render_actions(); ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ── Full header ───────────────────────────────────────────── */

	public static function render(): void {
		?>
		<header class="z-hdr-wrap" id="zHdrWrap" role="banner">
			<?php echo self::render_topbar(); ?>
			<?php echo self::render_headerbar(); ?>
		</header>
		<?php
	}
}
