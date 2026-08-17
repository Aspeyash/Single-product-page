<?php
/**
 * ZYMARG Store Page Template
 *
 * Replaces Dokan's default store.php. Outputs the full ZYMARG store
 * page design — identical markup to the original hand-coded HTML.
 *
 * Available variables (from Dokan's template loader):
 *   $store_user  — WP_User object of the vendor
 *   $store_info  — array of Dokan store meta
 *
 * @package ZYMARG_Store_Page
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Resolve store user & info ────────────────────────────────────────────────
if ( ! isset( $store_user ) ) {
	$store_user = get_user_by( 'slug', get_query_var( 'author_name' ) );
}
if ( ! $store_user ) {
	wp_die( esc_html__( 'Store not found.', 'zymarg-store-page' ) );
}

$store_id   = $store_user->ID;
$store_info = function_exists( 'dokan_get_store_info' ) ? dokan_get_store_info( $store_id ) : [];

// ── Store meta helpers ───────────────────────────────────────────────────────
$store_name        = ! empty( $store_info['store_name'] )   ? $store_info['store_name']   : $store_user->display_name;
// Tagline and story are written by the seller in Vendor Dashboard ->
// Settings -> Store Profile. There is deliberately NO sample text here: a
// placeholder describing a fictional brand reads as though it were this
// seller's own copy, and every store that had not filled the fields in was
// advertising the same invented company.
$store_tagline     = ! empty( $store_info['store_tagline'] ) ? $store_info['store_tagline'] : '';
$store_description = ! empty( $store_info['store_description'] ) ? $store_info['store_description'] : '';
$story_headline    = (string) get_user_meta( $store_id, '_zymarg_vd_story_headline', true );
$story_more        = (string) get_user_meta( $store_id, '_zymarg_vd_story_more', true );
$has_story         = ( '' !== trim( $store_description ) || '' !== trim( $story_headline ) || '' !== trim( $story_more ) );
/*
 * Store location.
 *
 * Built from the address the seller saves in the Vendor Dashboard, which
 * writes to the same `dokan_profile_settings` meta this template reads.
 *
 * There is deliberately no fallback string. The old fallback printed
 * "Dhaka, Bangladesh" for anyone who had not filled in an address, which
 * told buyers something untrue about the seller. With no address saved the
 * whole location row is hidden instead -- see $has_location below.
 *
 * The vendor form stores the country as a two-letter code and pre-fills
 * "BD", so the code is expanded to a real country name before display,
 * otherwise the page would read "Dhaka, BD".
 */
$loc_city    = ! empty( $store_info['address']['city'] ) ? trim( $store_info['address']['city'] ) : '';
$loc_country = ! empty( $store_info['address']['country'] ) ? trim( $store_info['address']['country'] ) : '';

if ( '' !== $loc_country && function_exists( 'WC' ) && WC()->countries ) {
	$zy_country_names = WC()->countries->get_countries();
	if ( isset( $zy_country_names[ $loc_country ] ) ) {
		$loc_country = $zy_country_names[ $loc_country ];
	}
}

$store_location = $loc_city;
if ( '' !== $loc_country ) {
	$store_location .= ( $store_location ? ', ' : '' ) . $loc_country;
}
$has_location = ( '' !== $store_location );

// Store rating comes from the ZYMARG Reviews Engine, which aggregates the real
// product reviews across every product this vendor owns.
//
// There is deliberately no fallback number here. If the engine is inactive, or
// the store has genuinely never been rated, the rating is zero and every
// rating surface below hides itself. A marketplace that invents a score for a
// store with no reviews is lying to both the buyer and the seller.
// Which page of the review feed to show. There is no JavaScript endpoint for
// store reviews, so paging is a plain crawlable link that works with scripts
// disabled.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination, no state change.
$reviews_page = isset( $_GET['zy_reviews_page'] ) ? max( 1, (int) $_GET['zy_reviews_page'] ) : 1;

$store_reviews = function_exists( 'zymarg_reviews_get_data' )
	? zymarg_reviews_get_data(
		array(
			'vendor_id' => $store_id,
			'page'      => $reviews_page,
		)
	)
	: array();

$store_rating = isset( $store_reviews['avg_rating'] ) ? (float) $store_reviews['avg_rating'] : 0.0;
$rating_count = isset( $store_reviews['review_count'] ) ? (int) $store_reviews['review_count'] : 0;

// The single gate every rating surface on this page checks.
$has_rating = ( $rating_count > 0 && $store_rating > 0 );
if ( class_exists( 'ZYMARG_SP_Follow' ) ) {
	$followers = ZYMARG_SP_Follow::get_count( $store_id );
} elseif ( function_exists( 'dokan_get_store_followers' ) ) {
	$followers = dokan_get_store_followers( $store_id );
} else {
	$followers = (int) get_user_meta( $store_id, '_zymarg_followers_count', true );
}
$is_following_store = class_exists( 'ZYMARG_SP_Follow' ) ? ZYMARG_SP_Follow::current_user_follows( $store_id ) : false;
// No stock-photo fallback. A random landscape from picsum, seeded with a
// fictional brand name, was being presented as this seller's own cover image,
// and every store that had not uploaded one showed the same borrowed photo.
// With no banner the hero falls back to the brand gradient below, which claims
// nothing about the store.
//
// Dokan stores this value in two different shapes depending on which upload
// path the seller used: admin-side media library edits typically leave a URL
// string in place, while the vendor dashboard's own uploader has been
// observed saving a bare attachment ID instead. Resolve both the same way
// $gravatar_url already does a few lines below — without this, a vendor who
// uploaded through the dashboard uploader gets a numeric string passed
// straight into an <img src>, which the browser cannot load, so the banner
// silently falls back to looking like no banner was set at all.
$banner_raw = ! empty( $store_info['banner'] ) ? $store_info['banner'] : '';
if ( $banner_raw ) {
	if ( is_numeric( $banner_raw ) ) {
		// Vendor dashboard stores an attachment ID — resolve it to a URL.
		$banner_url = (string) wp_get_attachment_image_url( (int) $banner_raw, 'full' );
	} else {
		// Admin/media-library edits leave a URL string in place — use it as-is.
		$banner_url = esc_url( $banner_raw );
	}
} else {
	$banner_url = '';
}
// Use the vendor's custom uploaded photo from Dokan (stored as 'gravatar' in store_info).
// Do NOT fall back to Gravatar.com — it requires an external CDN that may be unavailable
// and returns broken images for vendors without a Gravatar account.
// If no custom photo, the initial-letter avatar renders instead (see template below).
$gravatar_raw = ! empty( $store_info['gravatar'] ) ? $store_info['gravatar'] : '';
if ( $gravatar_raw ) {
	if ( is_numeric( $gravatar_raw ) ) {
		// Vendor dashboard stores an attachment ID — resolve it to a URL.
		$gravatar_url = (string) wp_get_attachment_image_url( (int) $gravatar_raw, 'full' );
		if ( ! $gravatar_url ) {
			// Attachment unreachable — fall back to the cached URL meta.
			$gravatar_url = (string) get_user_meta( $store_id, '_zymarg_store_avatar_url', true );
		}
	} else {
		// Dokan's own dashboard stores a URL string directly — use it as-is.
		$gravatar_url = esc_url( $gravatar_raw );
	}
} else {
	// gravatar key missing — try the vendor dashboard's cached URL meta directly.
	$gravatar_url = (string) get_user_meta( $store_id, '_zymarg_store_avatar_url', true );
}
$member_since   = date_i18n( 'Y', strtotime( $store_user->user_registered ) );
$shop_url       = function_exists( 'dokan_get_store_url' ) ? dokan_get_store_url( $store_id ) : home_url( '/' );

/*
 * Seller online/offline status — Hero Store Card redesign.
 *
 * Driven by Dokan's own "Enable Selling" / "Disable Selling" vendor toggle
 * (dokan_is_seller_enabled()), NOT invented. If Dokan is not active at all
 * the function will not exist, and per this plugin's existing "never claim
 * data we can't verify" rule (see $has_location / $has_rating above), the
 * indicator is hidden entirely rather than defaulting to a guessed state —
 * see $has_seller_status below, checked at the template output site.
 */
$seller_status_known = function_exists( 'dokan_is_seller_enabled' );
$seller_enabled       = $seller_status_known ? (bool) dokan_is_seller_enabled( $store_id ) : false;

/*
 * Products count — Hero Store Card redesign.
 *
 * Same query shape already used later in this file to build the category
 * sidebar (published products owned by this vendor), kept as its own
 * lightweight ids-only lookup here since it's needed earlier in the markup
 * (inside the Hero section) than the sidebar's own query runs.
 */
$vendor_products_count = (int) count(
	get_posts(
		array(
			'author'         => $store_id,
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	)
);

// ── Admin options ────────────────────────────────────────────────────────────
$_opts          = get_option( 'zymarg_sp_options', [] );
$show_reviews   = isset( $_opts['show_reviews'] ) ? (bool) $_opts['show_reviews'] : true;

// ── Output the full WordPress theme shell (header runs wp_head internally) ────
get_header();
?>

<!-- Tailwind v4 design tokens — must be present anywhere in the document for the
     browser build to pick them up; outputting after get_header() is intentional. -->
<style type="text/tailwindcss">
  @theme {
    --font-sans: "Inter Variable", ui-sans-serif, system-ui, sans-serif;
    --color-zy-primary:   #9500A5;
    --color-zy-secondary: #BD00D1;
    --color-zy-accent:    #FEA9FF;
    --color-zy-dark:      #36003D;
    --color-zy-body:      #534152;
    --color-zy-border:    #D8BFD3;
    --color-zy-bg:        #FAF5FB;
    --color-zy-surface:   #FFFFFF;
    --color-zy-alt:       #F8F9FF;
    --color-zy-container: #EAEDFF;
  }
  @utility bg-zy-gradient {
    background: linear-gradient(135deg, #9500A5 0%, #BD00D1 60%, #FEA9FF 130%);
  }
  @utility text-zy-gradient {
    background: linear-gradient(135deg, #9500A5 0%, #BD00D1 60%, #FEA9FF 130%);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
  }
  @utility scrollbar-hide {
    scrollbar-width: none;
    &::-webkit-scrollbar { display: none; }
  }
</style>

<!-- ============================================================
     STICKY HEADER
============================================================ -->
<header id="sticky-header"
  class="fixed inset-x-0 top-0 z-50 -translate-y-full bg-zy-surface/95 shadow-lg backdrop-blur transition-transform duration-300 ease-in-out"
  aria-label="<?php esc_attr_e( 'Store quick navigation', 'zymarg-store-page' ); ?>">
  <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 py-2.5 sm:px-6 lg:px-8">

    <div class="flex min-w-0 items-center gap-2.5">
      <span class="<?php echo $gravatar_url ? 'block' : 'flex items-center justify-center text-sm font-extrabold text-white'; ?> relative h-9 w-9 shrink-0 overflow-hidden rounded-xl bg-zy-gradient shadow-lg" aria-hidden="true" data-store-logo>
        <?php if ( $gravatar_url ) : ?>
          <img src="<?php echo esc_url( $gravatar_url ); ?>" style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;display:block;" alt="<?php echo esc_attr( $store_name ); ?>" />
        <?php else : ?>
          <?php echo esc_html( mb_substr( $store_name, 0, 1 ) ); ?>
        <?php endif; ?>
      </span>
      <div class="min-w-0 flex flex-col gap-2">
        <span class="flex items-center gap-1 truncate text-sm font-bold text-zy-dark leading-none" data-store-name>
          <?php echo esc_html( $store_name ); ?>
          <?php
          if ( function_exists( 'zymarg_sp_store_badge_row' ) ) {
            // Compact scroll bar: tick only, pills would crowd the search box.
            echo zymarg_sp_store_badge_row( $store_id, array( 'tick_size' => 'h-4 w-4', 'show_pills' => false ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
          }
          ?>
        </span>
        <span class="block truncate text-xs text-zy-body/70 leading-none" data-sticky-meta>
          <?php if ( $has_rating ) : ?><?php echo esc_html( number_format( $store_rating, 1 ) ); ?> ★ · <?php endif; ?><?php echo esc_html( $followers ); ?> <?php esc_html_e( 'followers', 'zymarg-store-page' ); ?>
        </span>
      </div>
    </div>

    <!-- AURA Studio Search Bar -->
    <div class="flex-1 flex justify-center mx-4 hidden sm:block">
      <div class="aura-search" id="aura-search-root">
        <form role="search" onsubmit="return false;" style="width:100%;margin:0;padding:0;">
        <div class="aura-search__field">
          <span class="aura-search__icon" aria-hidden="true">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
          </span>
          <input type="text" id="aura-input"
            placeholder="<?php echo esc_attr( sprintf( __( 'Search inside %s\'s store…', 'zymarg-store-page' ), $store_name ) ); ?>"
            autocomplete="off"
            aria-label="<?php esc_attr_e( 'Search products', 'zymarg-store-page' ); ?>"
            aria-haspopup="listbox" aria-expanded="false" aria-controls="aura-dropdown" role="combobox">
          <button id="aura-clear" type="button" aria-label="<?php esc_attr_e( 'Clear search', 'zymarg-store-page' ); ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
          <div id="aura-dropdown" role="listbox" aria-label="<?php esc_attr_e( 'Search results', 'zymarg-store-page' ); ?>"></div>
        </div>
        </form>
        <div id="aura-status" aria-live="polite" aria-atomic="true" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);border:0;"></div>
        <div class="aura-error-bar" id="aura-error">
          ⚠ <?php esc_html_e( 'Could not reach the store API. Check your Store ID and that the Dokan REST API is enabled.', 'zymarg-store-page' ); ?>
        </div>
        <div class="aura-pills" id="aura-pills" style="display:none;">
          <!-- Populated dynamically by JS from dokan/v1/stores/{id}/categories -->
        </div>
      </div>
    </div>

    <div class="ml-auto flex items-center gap-2">
      <button type="button"
        data-chat-btn
        data-seller-id="<?php echo esc_attr( $store_id ); ?>"
        aria-label="<?php esc_attr_e( 'Chat with seller', 'zymarg-store-page' ); ?>"
        class="zy-header-chat-btn rounded-xl border border-zy-border bg-zy-surface text-zy-dark transition duration-300 ease-in-out hover:bg-zy-container focus:outline-none focus-visible:ring-2 focus-visible:ring-zy-secondary">
        <?php /* Icon shown on mobile; hidden on sm+ */ ?>
        <svg class="zy-header-chat-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 0 1 1.037-.443 48.282 48.282 0 0 0 5.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z"/>
        </svg>
        <?php /* Label shown on sm+; hidden on mobile */ ?>
        <span class="zy-header-chat-btn__label"><?php esc_html_e( 'Chat', 'zymarg-store-page' ); ?></span>
      </button>
      <button type="button" data-follow-btn
        class="rounded-xl px-4 py-2 text-sm font-semibold shadow-lg transition duration-300 ease-in-out hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-zy-accent <?php echo $is_following_store ? 'border border-zy-primary text-zy-primary bg-zy-surface' : 'bg-zy-gradient text-white'; ?>"
        aria-pressed="<?php echo $is_following_store ? 'true' : 'false'; ?>">
        <span data-follow-label><?php echo $is_following_store ? esc_html__( 'Following', 'zymarg-store-page' ) : esc_html__( 'Follow', 'zymarg-store-page' ); ?></span>
      </button>
    </div>
  </div>
</header>

<main id="top">

<!-- ============================================================
     HERO + STORE CARD (v2 — card moved INSIDE the banner, glass style)

     The card is now an absolutely-positioned child of the banner
     container, bottom-anchored, so it can never exceed the banner's own
     height. The purple/dark overlays that used to sit on every banner are
     now rendered ONLY as part of the no-photo fallback background — a real
     uploaded banner photo is shown with no tint/overlay at all.
============================================================ -->
<section aria-labelledby="store-name" data-hero-section>
  <div class="relative h-56 w-full overflow-hidden sm:h-72 lg:h-80">
    <?php if ( $banner_url ) : ?>
      <img src="<?php echo esc_url( $banner_url ); ?>"
        alt="<?php echo esc_attr( $store_name ); ?> <?php esc_attr_e( 'store cover', 'zymarg-store-page' ); ?>"
        class="h-full w-full object-cover" fetchpriority="high" data-store-cover />
    <?php else : ?>
      <!-- No banner uploaded: the brand gradient + overlays ARE the
           background here, not a tint sitting on top of a real photo. -->
      <div class="h-full w-full bg-zy-gradient" data-store-cover aria-hidden="true"></div>
      <div class="absolute inset-0 bg-zy-gradient opacity-60 mix-blend-multiply" aria-hidden="true"></div>
      <div class="absolute inset-0 bg-gradient-to-t from-zy-dark/80 via-transparent" aria-hidden="true"></div>
    <?php endif; ?>

    <div class="zsp-hc-wrap">
      <div class="zsp-hc-inner">
        <div class="zsp-hc-card" data-store-card>
          <div class="zsp-hc-row">

            <div class="zsp-hc-toprow">
              <span class="<?php echo $gravatar_url ? 'block' : 'flex items-center justify-center font-extrabold text-white'; ?> zsp-hc-logo"
                aria-label="<?php echo esc_attr( $store_name ); ?> <?php esc_attr_e( 'logo', 'zymarg-store-page' ); ?>" data-store-logo>
                <?php if ( $gravatar_url ) : ?>
                  <img src="<?php echo esc_url( $gravatar_url ); ?>" alt="<?php echo esc_attr( $store_name ); ?>" />
                <?php else : ?>
                  <?php echo esc_html( mb_substr( $store_name, 0, 1 ) ); ?>
                <?php endif; ?>
              </span>

              <div class="zsp-hc-identity">
                <h1 id="store-name" class="zsp-hc-name" data-store-name>
                  <?php echo esc_html( $store_name ); ?>
                  <?php
                  // Admin-granted badges only, in a fixed order: tick, OFFICIAL
                  // STORE, VERIFIED SELLER. Nothing renders unless the marketplace
                  // admin granted it to this vendor, so a new seller shows none.
                  if ( function_exists( 'zymarg_sp_store_badge_row' ) ) {
                    echo zymarg_sp_store_badge_row( $store_id, array( 'tick_size' => 'h-4 w-4' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
                  }
                  ?>
                </h1>
                <?php if ( $seller_status_known ) : ?>
                <div class="zsp-hc-status <?php echo $seller_enabled ? 'is-online' : 'is-offline'; ?>">
                  <span class="zsp-hc-status-dot" aria-hidden="true"></span>
                  <?php echo $seller_enabled ? esc_html__( 'Online', 'zymarg-store-page' ) : esc_html__( 'Offline', 'zymarg-store-page' ); ?>
                </div>
                <?php endif; // seller_status_known ?>
              </div>
            </div>

            <div class="zsp-hc-stats-slot" id="zsp-hc-stats-slot">
              <div class="zsp-hc-stats" data-store-stats>
                <div class="zsp-hc-stat">
                  <b data-store-followers><?php echo esc_html( number_format( $followers ) ); ?></b>
                  <span><?php esc_html_e( 'Followers', 'zymarg-store-page' ); ?></span>
                </div>
                <div class="zsp-hc-stat">
                  <b data-store-rating><?php echo esc_html( $has_rating ? number_format( $store_rating, 1 ) : '—' ); ?></b>
                  <span><?php esc_html_e( 'Rating', 'zymarg-store-page' ); ?></span>
                </div>
                <div class="zsp-hc-stat">
                  <b data-store-review-count><?php echo esc_html( number_format( $rating_count ) ); ?></b>
                  <span><?php esc_html_e( 'Reviews', 'zymarg-store-page' ); ?></span>
                </div>
                <div class="zsp-hc-stat">
                  <b data-store-products><?php echo esc_html( number_format( $vendor_products_count ) ); ?></b>
                  <span><?php esc_html_e( 'Products', 'zymarg-store-page' ); ?></span>
                </div>
              </div>

              <div class="zsp-hc-actions" role="group" aria-label="<?php esc_attr_e( 'Store actions', 'zymarg-store-page' ); ?>" data-store-actions>
                <button type="button" data-follow-btn
                  class="zsp-hc-btn-follow <?php echo $is_following_store ? 'is-following' : ''; ?>"
                  aria-pressed="<?php echo $is_following_store ? 'true' : 'false'; ?>">
                  <span data-follow-label><?php echo $is_following_store ? esc_html__( 'Following', 'zymarg-store-page' ) : esc_html__( 'Follow', 'zymarg-store-page' ); ?></span>
                </button>
                <button type="button"
                  data-chat-btn
                  data-seller-id="<?php echo esc_attr( $store_id ); ?>"
                  class="zsp-hc-btn-chat">
                  <?php esc_html_e( 'Chat', 'zymarg-store-page' ); ?>
                </button>
                <span class="relative inline-flex">
                <?php
                /*
                 * Share button.
                 *
                 * Opens the device share sheet where that is supported and
                 * copies the link everywhere else. The URL is the canonical
                 * Dokan store URL rather than window.location, so a buyer who
                 * arrived on a filtered or searched view still shares a
                 * clean link to the store.
                 */
                ?>
                <button type="button" aria-label="<?php esc_attr_e( 'Share this store', 'zymarg-store-page' ); ?>"
                  data-share-btn
                  data-share-url="<?php echo esc_url( $shop_url ); ?>"
                  data-share-title="<?php echo esc_attr( $store_name ); ?>"
                  class="zsp-hc-btn-share">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z"/></svg>
                </button>
                <span data-share-note hidden
                  class="pointer-events-none absolute -top-9 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-lg bg-zy-primary px-2.5 py-1 text-xs font-semibold text-white shadow-lg"></span>
                <span data-share-status class="sr-only" aria-live="polite"></span>
                </span>
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>
  </div>
</section>



<!-- ============================================================
     PREMIUM SECTIONS (Flash Sale / Featured Items)

     Rendered only for vendors the marketplace admin has approved.
     Both functions return nothing when this vendor is not approved,
     so an unapproved store outputs no markup here at all.
============================================================ -->
<?php
if ( function_exists( 'zymarg_sp_premium_render_all' ) ) {
	zymarg_sp_premium_render_all( $store_id );
}
?>

<!-- ============================================================
     ADMIN-MANAGED PRODUCT SECTIONS (Trending / Best Selling / etc.)

     Every enabled row from ZYMARG_SP_Store_Sections EXCEPT the one that
     resolves to the engine's current_vendor "all" subset -- that row
     renders further down, inside the existing category-sidebar layout
     (see PRODUCTS & CATEGORIES LAYOUT below), not as a section here.

     Each row's shortcode runs through the Product Grid engine. When a
     row's query legitimately matches nothing (e.g. a brand-new vendor
     with no trending signal yet), the engine renders its own "No
     products found" wrapper rather than an empty string -- that wrapper
     is suppressed here so the whole section disappears (heading, link
     and all) instead of leaving a heading over blank space. This mirrors
     ZYMARG Single Product's own section repeater, and ZYMARG Homepage's
     ProductGridBridge, which both apply the same rule for the same
     reason.
============================================================ -->
<?php
if ( class_exists( 'ZYMARG_SP_Store_Sections' ) && shortcode_exists( 'zymarg_products' ) ) {
	foreach ( ZYMARG_SP_Store_Sections::get_generic_rows() as $zy_section_row ) {
		$zy_section_code = trim( (string) ( $zy_section_row['shortcode'] ?? '' ) );
		if ( '' === $zy_section_code ) {
			continue;
		}

		$zy_section_code = ZYMARG_SP_Store_Sections::force_no_heading( $zy_section_code );
		$zy_section_html = trim( (string) do_shortcode( $zy_section_code ) );

		if ( '' === $zy_section_html || false !== strpos( $zy_section_html, 'zymarg-wcpg__empty' ) ) {
			continue;
		}

		$zy_section_heading = trim( (string) ( $zy_section_row['heading'] ?? '' ) );
		$zy_section_link    = ZYMARG_SP_Store_Sections::link( $zy_section_row );
		$zy_section_anchor  = sanitize_key( (string) $zy_section_row['id'] ) . '-heading';
		?>
		<section class="zy-store-section zy-section mx-auto max-w-7xl px-4 sm:px-6 lg:px-8" data-zy-section-id="<?php echo esc_attr( $zy_section_row['id'] ); ?>"<?php echo ( '' !== $zy_section_heading ) ? ' aria-labelledby="' . esc_attr( $zy_section_anchor ) . '"' : ''; ?>>
			<?php if ( '' !== $zy_section_heading || ! empty( $zy_section_link ) ) : ?>
			<div class="zy-section-heading-row flex flex-wrap items-end justify-between gap-3">
				<?php if ( '' !== $zy_section_heading ) : ?>
					<div>
						<?php
						/*
						 * v1.25.0: eyebrow label + sr-only real heading.
						 *
						 * This is the exact markup/class pair used by the Limited
						 * Time, Handpicked, and All Products sections (see
						 * includes/premium-sections.php and the "All Products"
						 * block further down this template) -- a small uppercase
						 * label carries the visible text, and the actual <h2>
						 * stays in the DOM (for aria-labelledby) but visually
						 * hidden. Previously this generic-rows loop rendered a
						 * bold, dark, larger visible <h2 class="zy-section-heading">
						 * instead, which is why Trending/Best Selling never
						 * matched the other sections' heading style. Because this
						 * loop is what every admin-managed section (current and
						 * future) renders through, fixing it here is a single
						 * change that applies everywhere automatically.
						 */
						?>
						<p class="text-xs font-semibold uppercase tracking-[0.2em] text-zy-secondary"><?php echo esc_html( $zy_section_heading ); ?></p>
						<h2 id="<?php echo esc_attr( $zy_section_anchor ); ?>" class="sr-only"><?php echo esc_html( $zy_section_heading ); ?></h2>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $zy_section_link ) ) : ?>
					<a href="<?php echo esc_url( $zy_section_link['url'] ); ?>" class="text-sm font-semibold text-zy-primary hover:underline whitespace-nowrap">
						<?php echo esc_html( $zy_section_link['text'] ); ?> &rarr;
					</a>
				<?php endif; ?>
			</div>
			<?php endif; ?>
			<?php echo $zy_section_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup comes from the Product Grid engine, which escapes its own values. ?>
		</section>
		<?php
	}
}
?>

<!-- ============================================================
     PRODUCTS & CATEGORIES LAYOUT
============================================================ -->
<div id="products-layout-container" class="zy-section mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
  <div class="flex flex-col lg:flex-row gap-8">

    <!-- LEFT SIDEBAR -->
    <?php
        /*
         * Fetch only the product_cat terms that belong to this vendor's products.
         *
         * Strategy:
         *  1. Query all published product IDs owned by this vendor using
         *     WP_Query (post_author) — this is the most reliable cross-version
         *     way to get vendor products without depending on a specific Dokan
         *     helper that may not exist in all Dokan builds.
         *  2. Collect the unique product_cat term IDs used across those posts.
         *  3. Fetch those terms sorted by count (desc) so the most-stocked
         *     categories appear first.
         *  4. Cap at 8 items so the sidebar doesn't become unwieldy.
         *
         * The thumbnail for each category comes from the term meta key
         * 'thumbnail_id' (set by WooCommerce when an admin assigns a category
         * image). If none exists, we fall back to a deterministic Picsum seed
         * built from the term slug so the placeholder always looks different
         * per category rather than being the same generic image.
         *
         * The product count shown is the number of this vendor's products in
         * that category, not the global WooCommerce term count (which would
         * include other vendors' products).
         */

        // ── Step 1: collect all published product post IDs for this vendor ──
        $vendor_product_ids = get_posts( [
            'author'         => $store_id,
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',   // only return IDs — much lighter
            'no_found_rows'  => true,    // skip SQL_CALC_FOUND_ROWS for speed
        ] );

        // ── Step 2: tally term usage across the vendor's products ───────────
        $term_counts = []; // term_id => count of vendor products in that term

        if ( ! empty( $vendor_product_ids ) ) {
            foreach ( $vendor_product_ids as $pid ) {
                $terms = get_the_terms( $pid, 'product_cat' );
                if ( ! $terms || is_wp_error( $terms ) ) {
                    continue;
                }
                foreach ( $terms as $term ) {
                    // Skip the built-in "Uncategorized" catch-all (slug: uncategorized).
                    if ( 'uncategorized' === $term->slug ) {
                        continue;
                    }
                    $term_counts[ $term->term_id ] = ( $term_counts[ $term->term_id ] ?? 0 ) + 1;
                }
            }
        }

        // ── Step 3: sort by count desc, fetch ALL (JS handles "show more") ──
        arsort( $term_counts );
        $top_term_ids = array_keys( $term_counts ); // no cap — all vendor categories

        // ── Step 4: fetch full term objects for display ──────────────────────
        $sidebar_cats = [];
        if ( ! empty( $top_term_ids ) ) {
            $raw_terms = get_terms( [
                'taxonomy'   => 'product_cat',
                'include'    => $top_term_ids,
                'hide_empty' => false, // we already know they have products
                'orderby'    => 'include', // preserve our custom sort order
            ] );

            if ( ! is_wp_error( $raw_terms ) ) {
                // Re-apply our custom count from $term_counts (global term count
                // would include other vendors' products, which is misleading).
                foreach ( $raw_terms as $term ) {
                    $sidebar_cats[] = [
                        'term'  => $term,
                        'count' => $term_counts[ $term->term_id ] ?? 0,
                    ];
                }
                // Restore our count-based sort order (get_terms with
                // orderby=include preserves ID order, not count order, so we
                // sort again on the reassembled array).
                usort( $sidebar_cats, static function ( $a, $b ) {
                    return $b['count'] - $a['count'];
                } );
            }
        }
        // ── Who may see the "no categories" notice? ─────────────────────────
        //
        // The empty-state copy is an instruction to the seller ("assign
        // categories to your products"), so it must never reach shoppers: a
        // buyer cannot act on it and it makes the store look unfinished.
        //
        // Owner OR shop manager sees the notice. Everyone else gets no sidebar
        // at all when there are no categories -- an empty navigation rail is
        // pure cost, and #products is already flex-1 min-w-0 so the product
        // grid reflows to full width on its own.
        $zy_is_store_owner  = ( is_user_logged_in() && get_current_user_id() === (int) $store_id );
        $zy_can_manage_shop = ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) );
        $zy_show_cat_nudge  = ( $zy_is_store_owner || $zy_can_manage_shop );

        $zy_render_sidebar  = ( ! empty( $sidebar_cats ) || $zy_show_cat_nudge );
        ?>

        <?php if ( $zy_render_sidebar ) : ?>
    <aside class="w-full lg:w-64 shrink-0">
      <div class="lg:sticky lg:top-20">
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-zy-secondary"><?php esc_html_e( 'Shop by Category', 'zymarg-store-page' ); ?></p>

        <?php if ( ! empty( $sidebar_cats ) ) :
          $total_cats = count( $sidebar_cats );
        ?>
        <!-- Scrollable category box — always visible by default -->
        <div id="sidebar-cats-drawer" class="sidebar-cats-drawer sidebar-cats-drawer--always-open">
          <ul class="sidebar-cats-drawer__list grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-1">
            <?php foreach ( $sidebar_cats as $cat_index => $sidebar_cat ) :
              $term      = $sidebar_cat['term'];
              $cat_count = $sidebar_cat['count'];
              $thumb_id  = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
              // A category with no thumbnail gets a neutral initial, not a
              // random stock photo that implies the category looks like that.
              $thumb_src = $thumb_id > 0 ? wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '';
              $term_link = get_term_link( $term );
              if ( is_wp_error( $term_link ) ) { $term_link = '#'; }
            ?>
            <li>
              <a href="<?php echo esc_url( $term_link ); ?>"
                data-cat-name="<?php echo esc_attr( $term->name ); ?>"
                data-cat-slug="<?php echo esc_attr( $term->slug ); ?>"
                data-cat-id="<?php echo esc_attr( $term->term_id ); ?>"
                class="zy-sidebar-cat group flex items-center gap-3 rounded-2xl bg-zy-surface p-2 shadow-lg transition duration-300 ease-in-out hover:bg-zy-container hover:-translate-y-0.5 hover:shadow-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-zy-secondary">
                <?php if ( $thumb_src ) : ?>
                  <img src="<?php echo esc_url( $thumb_src ); ?>"
                    alt="<?php echo esc_attr( $term->name ); ?> <?php esc_attr_e( 'category', 'zymarg-store-page' ); ?>"
                    class="h-10 w-10 sm:h-12 sm:w-12 rounded-xl object-cover transition-transform duration-300 ease-in-out group-hover:scale-105"
                    loading="lazy" />
                <?php else : ?>
                  <span class="flex h-10 w-10 sm:h-12 sm:w-12 shrink-0 items-center justify-center rounded-xl bg-zy-alt text-sm font-bold uppercase text-zy-body/60 transition-transform duration-300 ease-in-out group-hover:scale-105" aria-hidden="true"><?php echo esc_html( mb_substr( $term->name, 0, 1 ) ); ?></span>
                <?php endif; ?>
                <div class="min-w-0 flex flex-col gap-2">
                  <span class="block text-xs sm:text-sm font-semibold text-zy-dark group-hover:text-zy-primary truncate leading-none"><?php echo esc_html( $term->name ); ?></span>
                  <span class="block text-[10px] sm:text-xs text-zy-body/70 leading-none">
                    <?php echo esc_html( $cat_count . ' ' . _n( 'product', 'products', $cat_count, 'zymarg-store-page' ) ); ?>
                  </span>
                </div>
              </a>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>

        <?php else : ?>
        <!-- Owner-only nudge. Unreachable for buyers: the enclosing
             $zy_render_sidebar gate means we only get here with no categories
             when the viewer is the store owner or a shop manager. -->
        <div class="mt-6 rounded-2xl bg-zy-surface p-5 shadow-lg text-center">
          <svg class="mx-auto h-10 w-10 text-zy-border" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 0 0-1.883 2.542l.857 6a2.25 2.25 0 0 0 2.227 1.932H19.05a2.25 2.25 0 0 0 2.227-1.932l.857-6a2.25 2.25 0 0 0-1.883-2.542m-16.5 0V6A2.25 2.25 0 0 1 6 3.75h3.879a1.5 1.5 0 0 1 1.06.44l2.122 2.12a1.5 1.5 0 0 0 1.06.44H18A2.25 2.25 0 0 1 20.25 9v.776"/>
          </svg>
          <p class="mt-3 text-sm font-semibold text-zy-dark"><?php esc_html_e( 'No categories yet', 'zymarg-store-page' ); ?></p>
          <p class="mt-1 text-xs text-zy-body/70"><?php esc_html_e( 'Assign categories to your products and they will show up here as shop navigation.', 'zymarg-store-page' ); ?></p>
          <p class="mt-3 inline-flex items-center gap-1.5 rounded-full bg-zy-container px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-zy-secondary">
            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
            </svg>
            <?php esc_html_e( 'Only visible to you', 'zymarg-store-page' ); ?>
          </p>
        </div>
        <?php endif; ?>

      </div>
    </aside>
        <?php endif; // $zy_render_sidebar ?>

    <!-- RIGHT: PRODUCT GRID -->
    <section id="products" aria-labelledby="products-heading" class="flex-1 min-w-0">
      <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p class="text-xs font-semibold uppercase tracking-[0.2em] text-zy-secondary"><?php esc_html_e( 'All Products', 'zymarg-store-page' ); ?></p>
          <?php
          /*
           * Hidden visually, kept in the DOM on purpose.
           *
           * The "All Products" label above already names this section, so the
           * second line read as a duplicate. This heading still has to exist:
           * the section's aria-labelledby points at it, and store-page.js
           * writes the live result count and the "No results" message into it
           * while searching and filtering.
           */
          ?>
          <h2 id="products-heading" class="sr-only">
            <?php esc_html_e( 'Products in store', 'zymarg-store-page' ); ?>
          </h2>
        </div>
        <label class="zy-products-sort flex items-center gap-2 text-sm">
          <span class="text-zy-body/70"><?php esc_html_e( 'Sort', 'zymarg-store-page' ); ?></span>
          <?php
          // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort preference, no state change.
          $zy_sort = isset( $_GET['zy_sort'] ) ? sanitize_key( wp_unslash( $_GET['zy_sort'] ) ) : 'popular';
          if ( ! in_array( $zy_sort, [ 'popular', 'newest', 'price-asc', 'price-desc', 'rating' ], true ) ) {
              $zy_sort = 'popular';
          }
          ?>
          <select data-sort-select class="rounded-xl border border-zy-border bg-zy-surface px-3 py-2 text-sm font-medium text-zy-dark shadow-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-zy-secondary">
            <option value="popular"    <?php selected( $zy_sort, 'popular' ); ?>><?php esc_html_e( 'Most Popular',        'zymarg-store-page' ); ?></option>
            <option value="newest"     <?php selected( $zy_sort, 'newest' ); ?>><?php esc_html_e( 'Newest',              'zymarg-store-page' ); ?></option>
            <option value="price-asc"  <?php selected( $zy_sort, 'price-asc' ); ?>><?php esc_html_e( 'Price: Low to High', 'zymarg-store-page' ); ?></option>
            <option value="price-desc" <?php selected( $zy_sort, 'price-desc' ); ?>><?php esc_html_e( 'Price: High to Low', 'zymarg-store-page' ); ?></option>
            <option value="rating"     <?php selected( $zy_sort, 'rating' ); ?>><?php esc_html_e( 'Top Rated',          'zymarg-store-page' ); ?></option>
          </select>
        </label>
      </div>

      <?php
      /*
       * v1.23.0: server-rendered by the Product Grid engine.
       *
       * The admin-managed "All Products" row (ZYMARG_SP_Store_Sections::
       * get_all_products_row(), identified by its current_vendor_subset
       * being "all" rather than by a hardcoded row id) now renders here
       * directly via do_shortcode(), with the engine's own native infinite
       * scroll taking over from the Dokan-REST-driven JS that used to own
       * this container. store-page.js no longer builds this grid's initial
       * page at all.
       *
       * The Sort control above still works, but only by round-tripping
       * through ?zy_sort= (see $zy_sort just above): the shortcode has no
       * live re-sort of its own, so a sort change reloads the page with the
       * chosen orderby/order folded into the shortcode below. This does NOT
       * apply while a category filter is active -- store-page.js keeps its
       * existing client-side re-sort of the already-fetched filtered list
       * for that case, completely unchanged.
       *
       * #product-grid-filtered is a SIBLING, not a replacement: clicking a
       * category in the sidebar hides this container and shows that one
       * instead (see store-page.js), so the engine's widget here --
       * including its scroll position and its own infinite-scroll state --
       * is never destroyed and simply reappears untouched when the filter
       * is cleared.
       */
      $zy_all_products_row  = class_exists( 'ZYMARG_SP_Store_Sections' ) ? ZYMARG_SP_Store_Sections::get_all_products_row() : null;
      $zy_all_products_html = '';

      if ( null !== $zy_all_products_row && shortcode_exists( 'zymarg_products' ) ) {
          $zy_all_products_code = trim( (string) ( $zy_all_products_row['shortcode'] ?? '' ) );

          if ( '' !== $zy_all_products_code ) {
              $zy_all_products_code = ZYMARG_SP_Store_Sections::force_no_heading( $zy_all_products_code );

              // Fold the Sort control's choice into the shortcode, only when
              // no orderby/order was already hand-set by the admin -- an
              // admin-chosen order always wins over the shopper's dropdown.
              if ( 'popular' !== $zy_sort
                  && ! preg_match( '/\borderby=/', $zy_all_products_code )
                  && ! preg_match( '/\border=/', $zy_all_products_code )
              ) {
                  $zy_sort_map = [
                      'newest'     => [ 'orderby' => 'date',  'order' => 'DESC' ],
                      'price-asc'  => [ 'orderby' => 'price', 'order' => 'ASC' ],
                      'price-desc' => [ 'orderby' => 'price', 'order' => 'DESC' ],
                      'rating'     => [ 'orderby' => 'rating', 'order' => 'DESC' ],
                  ];
                  if ( isset( $zy_sort_map[ $zy_sort ] ) ) {
                      $zy_pos = strrpos( $zy_all_products_code, ']' );
                      if ( false !== $zy_pos ) {
                          $zy_all_products_code = substr( $zy_all_products_code, 0, $zy_pos )
                              . ' orderby="' . esc_attr( $zy_sort_map[ $zy_sort ]['orderby'] ) . '"'
                              . ' order="' . esc_attr( $zy_sort_map[ $zy_sort ]['order'] ) . '"'
                              . substr( $zy_all_products_code, $zy_pos );
                      }
                  }
              }

              $zy_all_products_html = trim( (string) do_shortcode( $zy_all_products_code ) );

              if ( false !== strpos( $zy_all_products_html, 'zymarg-wcpg__empty' ) ) {
                  $zy_all_products_html = '';
              }
          }
      }
      ?>
      <div id="product-grid" class="zy-product-grid mt-6">
        <?php echo $zy_all_products_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup comes from the Product Grid engine, which escapes its own values. ?>
      </div>

      <?php if ( '' === $zy_all_products_html ) : ?>
      <p class="zy-products-empty mt-6 text-sm text-zy-body/70">
          <?php esc_html_e( 'No products to show right now.', 'zymarg-store-page' ); ?>
      </p>
      <?php endif; ?>

      <!--
        Category-filtered and search results. Same fetch/render pipeline as
        before (fetchByCategory() / fetchSearchPage() -> renderProducts() ->
        ZYMARG_SP_Grid_Bridge's ajax_render_cards, all unchanged) -- only the
        mount point moved here from #product-grid, so that container's
        engine-rendered widget is never torn down by a category click or a
        search. Hidden until a category or search is active; store-page.js
        toggles visibility between this and #product-grid.
      -->
      <div id="product-grid-filtered" class="zy-product-grid mt-6" style="display:none;"></div>

      <!-- Infinite scroll loader — used by the category/search load-more
           path above, NOT by #product-grid (the engine has its own). -->
      <div class="zy-infinite-loader-area">
        <div id="zy-infinite-loader" class="zy-infinite-loader" role="status" aria-live="polite" aria-atomic="true"></div>
      </div>

      <!-- Invisible sentinel — IntersectionObserver watches this to trigger the next page of category/search results. -->
      <div id="zy-scroll-sentinel" aria-hidden="true" style="width:100%;height:2px;"></div>

      <p id="zy-scroll-a11y" class="sr-only" aria-live="polite"></p>
    </section>

  </div>
</div>

<!-- ============================================================
     REVIEWS + OUR STORY

     v1.28.0: when a vendor has BOTH a rated review feed AND story content
     filled in, these two sections combine into a single "split panel"
     block -- Our Story (~38%) and Customer Reviews (~62%) side-by-side on
     desktop (>=1024px), stacking to full width below that breakpoint.
     Collapse/expand is identical and fully active at every breakpoint --
     only the outer layout (grid vs. stacked) changes by screen size. Both
     panels default to COLLAPSED on load, everywhere; clicking a panel's
     header row expands it independently of the other (no "only one open"
     restriction).

     If only ONE of the two exists, that section falls back to its
     original, unwrapped, non-collapsible markup -- pixel-identical to the
     v1.27.0 behaviour for that section. If NEITHER exists, nothing renders,
     same as always. The two single-section code paths below are therefore
     left completely intact rather than forcing one template to cover
     every case.

     v1.27.0 history preserved: the Reviews panel still delegates entirely
     to the ZYMARG Reviews Engine's own renderer (zymarg_reviews_render()),
     giving this page the engine's full feature set -- media strip,
     lightbox, filters, sort, AJAX Load More. The engine's vendor scope
     (Data_Builder::build_vendor()) returns every approved review left on
     any product this vendor owns, aggregated into one feed.
============================================================ -->
<?php
$zy_show_reviews_panel = $show_reviews && $has_rating && function_exists( 'zymarg_reviews_render' );
$zy_show_story_panel   = $has_story;
$zy_combine_panels      = $zy_show_reviews_panel && $zy_show_story_panel;

/*
 * The engine's own summary card carries its own "Customer Reviews" title
 * by default (Settings -> reviews_summary_heading), which would print a
 * second, visually redundant "Customer Reviews" directly under the
 * eyebrow label this template already renders above it. Blanked out here,
 * scoped to this exact render call only via vendor_id -- the admin's
 * saved setting, and every other consumer of the engine (including this
 * store's own Single Product pages), is untouched. Shared by both the
 * combined panel and the standalone-Reviews fallback below so the fix
 * applies no matter which path renders.
 */
$zy_blank_summary_heading = function ( $settings, $args ) use ( $store_id ) {
	if ( (int) ( $args['vendor_id'] ?? 0 ) === $store_id ) {
		$settings['summary_heading'] = '';
	}
	return $settings;
};
?>

<?php if ( $zy_combine_panels ) : ?>
<section aria-label="<?php esc_attr_e( 'Store story and customer reviews', 'zymarg-store-page' ); ?>" class="zy-section mx-auto max-w-7xl px-4 pb-16 sm:px-6 lg:px-8">
  <div class="zy-rs-combo zy-rs-combo--split" id="zy-rs-combo">

    <!-- STORY PANEL -->
    <div class="zy-rs-combo__panel" id="zy-rs-combo-story">
      <div class="zy-rs-combo__head" data-combo-toggle role="button" tabindex="0" aria-expanded="false" aria-controls="zy-rs-combo-story-collapse">
        <div>
          <p class="text-xs font-semibold uppercase tracking-[0.2em] text-zy-secondary"><?php esc_html_e( 'Our Story', 'zymarg-store-page' ); ?></p>
          <?php if ( '' !== trim( $story_headline ) ) : ?>
            <h2 id="story-heading" class="zy-section-heading mt-2 font-bold tracking-tight text-zy-dark"><?php echo esc_html( $story_headline ); ?></h2>
          <?php else : ?>
            <?php /* No headline written, but the landmark still needs a name. */ ?>
            <h2 id="story-heading" class="zy-section-heading mt-2 font-bold tracking-tight text-zy-dark"><?php
              /* translators: %s: store name. */
              printf( esc_html__( 'About %s', 'zymarg-store-page' ), esc_html( $store_name ) );
            ?></h2>
          <?php endif; ?>
        </div>
        <span class="zy-rs-combo__toggle" aria-hidden="true">
          <svg class="zy-rs-combo__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
        </span>
      </div>
      <div class="zy-collapse" id="zy-rs-combo-story-collapse" data-state="closed">
        <div class="zy-collapse__inner">
          <?php if ( '' !== trim( $store_description ) ) : ?>
            <p class="zy-section-content max-w-3xl leading-relaxed" data-store-desc><?php echo esc_html( $store_description ); ?></p>
          <?php endif; ?>
          <?php if ( '' !== trim( $story_more ) ) : ?>
            <p data-story-more class="mt-3 hidden max-w-3xl leading-relaxed"><?php echo esc_html( $story_more ); ?></p>
            <button type="button" data-story-toggle aria-expanded="false"
              class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-zy-primary transition duration-300 ease-in-out hover:text-zy-secondary focus:outline-none focus-visible:ring-2 focus-visible:ring-zy-secondary rounded-xl">
              <span data-story-label><?php esc_html_e( 'Read More', 'zymarg-store-page' ); ?></span>
              <svg data-story-chevron class="h-4 w-4 transition-transform duration-300 ease-in-out" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
            </button>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- REVIEWS PANEL -->
    <div class="zy-rs-combo__panel" id="zy-rs-combo-reviews">
      <div class="zy-rs-combo__head" data-combo-toggle role="button" tabindex="0" aria-expanded="false" aria-controls="zy-rs-combo-reviews-collapse">
        <div>
          <p class="text-xs font-semibold uppercase tracking-[0.2em] text-zy-secondary"><?php esc_html_e( 'Customer Reviews', 'zymarg-store-page' ); ?></p>
          <h2 id="reviews-heading" class="zy-section-heading mt-2 font-bold tracking-tight text-zy-dark"><?php esc_html_e( 'What buyers are saying', 'zymarg-store-page' ); ?></h2>
          <span class="zy-rs-combo__panel-meta"><?php
            printf(
              /* translators: 1: average rating (one decimal), 2: total review count. */
              esc_html__( '%1$s ★ · %2$s reviews', 'zymarg-store-page' ),
              esc_html( number_format( $store_rating, 1 ) ),
              esc_html( number_format( $rating_count ) )
            );
          ?></span>
        </div>
        <span class="zy-rs-combo__toggle" aria-hidden="true">
          <svg class="zy-rs-combo__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
        </span>
      </div>
      <div class="zy-collapse" id="zy-rs-combo-reviews-collapse" data-state="closed">
        <div class="zy-collapse__inner">
          <?php
          add_filter( 'zymarg_reviews_render_settings', $zy_blank_summary_heading, 10, 2 );

          zymarg_reviews_render(
            array(
              'vendor_id'    => $store_id,
              'page'         => $reviews_page,
              // Read-only by design: buyers write reviews from My Account
              // against an order they placed, never from the store page.
              'show_form'    => false,
              'show_summary' => true,
              'show_filters' => true,
            )
          );

          remove_filter( 'zymarg_reviews_render_settings', $zy_blank_summary_heading, 10 );
          ?>
        </div>
      </div>
    </div>

  </div>
</section>
<?php endif; // zy_combine_panels ?>

<?php if ( ! $zy_combine_panels && $zy_show_reviews_panel ) : ?>
<section aria-labelledby="reviews-heading" class="zy-section mx-auto max-w-7xl px-4 pb-16 sm:px-6 lg:px-8">
  <p class="text-xs font-semibold uppercase tracking-[0.2em] text-zy-secondary"><?php esc_html_e( 'Customer Reviews', 'zymarg-store-page' ); ?></p>
  <h2 id="reviews-heading" class="zy-section-heading mt-2 font-bold tracking-tight text-zy-dark"><?php esc_html_e( 'What buyers are saying', 'zymarg-store-page' ); ?></h2>

  <div class="zy-section-content mt-6">
    <?php
    add_filter( 'zymarg_reviews_render_settings', $zy_blank_summary_heading, 10, 2 );

    zymarg_reviews_render(
      array(
        'vendor_id'    => $store_id,
        'page'         => $reviews_page,
        // Read-only by design: buyers write reviews from My Account against
        // an order they placed, never from the store page. zymarg_reviews_
        // render() already forces this off for any vendor-scoped call, but
        // it is passed explicitly here too so that guarantee is visible at
        // the call site, not only inside the engine.
        'show_form'    => false,
        'show_summary' => true,
        'show_filters' => true,
      )
    );

    remove_filter( 'zymarg_reviews_render_settings', $zy_blank_summary_heading, 10 );
    ?>
  </div>
</section>
<?php endif; // zy_show_reviews_panel standalone fallback ?>

<?php if ( ! $zy_combine_panels && $zy_show_story_panel ) : ?>
<section aria-labelledby="story-heading" class="zy-section mx-auto max-w-7xl px-4 pb-16 sm:px-6 lg:px-8">
  <div class="rounded-2xl bg-zy-surface p-6 shadow-lg sm:p-8">
    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-zy-secondary"><?php esc_html_e( 'Our Story', 'zymarg-store-page' ); ?></p>
    <?php if ( '' !== trim( $story_headline ) ) : ?>
      <h2 id="story-heading" class="zy-section-heading mt-2 font-bold tracking-tight text-zy-dark"><?php echo esc_html( $story_headline ); ?></h2>
    <?php else : ?>
      <?php /* No headline written, but the landmark still needs a name. */ ?>
      <h2 id="story-heading" class="sr-only"><?php
        /* translators: %s: store name. */
        printf( esc_html__( 'About %s', 'zymarg-store-page' ), esc_html( $store_name ) );
      ?></h2>
    <?php endif; ?>
    <?php if ( '' !== trim( $store_description ) ) : ?>
      <p class="zy-section-content max-w-3xl leading-relaxed" data-store-desc><?php echo esc_html( $store_description ); ?></p>
    <?php endif; ?>
    <?php if ( '' !== trim( $story_more ) ) : ?>
      <p data-story-more class="mt-3 hidden max-w-3xl leading-relaxed"><?php echo esc_html( $story_more ); ?></p>
    <?php endif; ?>
    <?php if ( '' !== trim( $story_more ) ) : ?>
    <button type="button" data-story-toggle aria-expanded="false"
      class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-zy-primary transition duration-300 ease-in-out hover:text-zy-secondary focus:outline-none focus-visible:ring-2 focus-visible:ring-zy-secondary rounded-xl">
      <span data-story-label><?php esc_html_e( 'Read More', 'zymarg-store-page' ); ?></span>
      <svg data-story-chevron class="h-4 w-4 transition-transform duration-300 ease-in-out" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
    </button>
    <?php endif; ?>
  </div>
</section>
<?php endif; // zy_show_story_panel standalone fallback ?>

</main>

<?php
get_footer(); // outputs wp_footer(), </body>, </html>
?>
