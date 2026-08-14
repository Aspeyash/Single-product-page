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

$store_rating           = isset( $store_reviews['avg_rating'] ) ? (float) $store_reviews['avg_rating'] : 0.0;
$rating_count           = isset( $store_reviews['review_count'] ) ? (int) $store_reviews['review_count'] : 0;
$store_review_total     = isset( $store_reviews['total_reviews'] ) ? (int) $store_reviews['total_reviews'] : 0;
$store_review_list      = isset( $store_reviews['reviews'] ) && is_array( $store_reviews['reviews'] ) ? $store_reviews['reviews'] : array();
$rating_bars            = isset( $store_reviews['breakdown'] ) && is_array( $store_reviews['breakdown'] ) ? $store_reviews['breakdown'] : array();
$rating_counts          = isset( $store_reviews['rating_counts'] ) && is_array( $store_reviews['rating_counts'] ) ? $store_reviews['rating_counts'] : array();
$store_reviews_has_more  = ! empty( $store_reviews['has_more'] );
$store_reviews_pages     = isset( $store_reviews['total_pages'] ) ? max( 1, (int) $store_reviews['total_pages'] ) : 1;
$store_reviews_base_url  = remove_query_arg( 'zy_reviews_page' );

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
     PRODUCTS & CATEGORIES LAYOUT
============================================================ -->
<div id="products-layout-container" class="mx-auto max-w-7xl px-4 pt-12 sm:px-6 lg:px-8">
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
        <label class="flex items-center gap-2 text-sm">
          <span class="text-zy-body/70"><?php esc_html_e( 'Sort', 'zymarg-store-page' ); ?></span>
          <select data-sort-select class="rounded-xl border border-zy-border bg-zy-surface px-3 py-2 text-sm font-medium text-zy-dark shadow-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-zy-secondary">
            <option value="popular"   ><?php esc_html_e( 'Most Popular',        'zymarg-store-page' ); ?></option>
            <option value="newest"    ><?php esc_html_e( 'Newest',              'zymarg-store-page' ); ?></option>
            <option value="price-asc" ><?php esc_html_e( 'Price: Low to High', 'zymarg-store-page' ); ?></option>
            <option value="price-desc"><?php esc_html_e( 'Price: High to Low', 'zymarg-store-page' ); ?></option>
            <option value="rating"    ><?php esc_html_e( 'Top Rated',          'zymarg-store-page' ); ?></option>
          </select>
        </label>
      </div>

      <?php
      /*
       * v1.18.1: a div, not a ul.
       *
       * Cards are no longer built in JavaScript. store-page.js sends the
       * product IDs it has resolved to the engine and injects the ZYMARG card
       * markup that comes back, and that markup carries the engine's own grid
       * wrapper. A <ul> parent would make the returned <div> invalid markup,
       * and the grid columns now come from the engine's stylesheet rather than
       * the Tailwind classes that used to live on this element.
       */
      ?>
      <div id="product-grid" class="zy-product-grid mt-6">
        <!-- Rendered by store-page.js via ZYMARG_SP_Grid_Bridge -->
      </div>

      <!-- Infinite scroll loader -->
      <div class="zy-infinite-loader-area">
        <div id="zy-infinite-loader" class="zy-infinite-loader" role="status" aria-live="polite" aria-atomic="true"></div>
      </div>

      <!-- Invisible sentinel — IntersectionObserver watches this to trigger next load -->
      <div id="zy-scroll-sentinel" aria-hidden="true" style="width:100%;height:2px;"></div>

      <p id="zy-scroll-a11y" class="sr-only" aria-live="polite"></p>
    </section>

  </div>
</div>

<!-- ============================================================
     REVIEWS
============================================================ -->
<?php if ( $show_reviews && ! empty( $store_review_list ) ) : ?>
<section aria-labelledby="reviews-heading" class="mx-auto max-w-7xl px-4 pt-14 pb-16 sm:px-6 lg:px-8">
  <p class="text-xs font-semibold uppercase tracking-[0.2em] text-zy-secondary"><?php esc_html_e( 'Customer Reviews', 'zymarg-store-page' ); ?></p>
  <h2 id="reviews-heading" class="mt-2 text-xl font-bold tracking-tight text-zy-dark sm:text-2xl"><?php esc_html_e( 'What buyers are saying', 'zymarg-store-page' ); ?></h2>

  <div class="mt-6 grid gap-6 <?php echo $has_rating ? 'lg:grid-cols-3' : 'lg:grid-cols-1'; ?>">

<?php if ( $has_rating ) : ?>
    <!-- Rating summary. Rendered only when the store actually has rated reviews. -->
    <div class="rounded-2xl bg-zy-surface p-6 shadow-lg">
      <div class="flex items-end gap-3">
        <p class="text-5xl font-extrabold text-zy-dark" data-reviews-score><?php echo esc_html( number_format( $store_rating, 1 ) ); ?></p>
        <div class="pb-1.5">
          <div class="flex text-amber-400" aria-label="<?php echo esc_attr( sprintf( __( '%s out of 5 stars', 'zymarg-store-page' ), number_format( $store_rating, 1 ) ) ); ?>">
            <?php
            // Solid stars reflect the real average. The old template drew five
            // solid stars unconditionally, so every store looked perfect.
            $zy_filled = (int) round( $store_rating );
            for ( $zy_i = 1; $zy_i <= 5; $zy_i++ ) :
            ?>
            <svg class="h-4 w-4<?php echo $zy_i <= $zy_filled ? '' : ' text-zy-border'; ?>" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10.868 2.884c-.321-.772-1.415-.772-1.736 0l-1.83 4.401-4.753.381c-.833.067-1.171 1.107-.536 1.651l3.62 3.102-1.106 4.637c-.194.813.691 1.456 1.405 1.02L10 15.591l4.069 2.485c.713.436 1.598-.207 1.404-1.02l-1.106-4.637 3.62-3.102c.635-.544.297-1.584-.536-1.65l-4.752-.382-1.831-4.401Z" clip-rule="evenodd"/></svg>
            <?php endfor; ?>
          </div>
          <p class="mt-1 text-xs text-zy-body/70" data-reviews-count><?php echo esc_html( sprintf( _n( '%s rating', '%s ratings', $rating_count, 'zymarg-store-page' ), number_format( $rating_count ) ) ); ?></p>
        </div>
      </div>
      <dl class="mt-5 space-y-2.5">
      <?php
      // Real distribution from the engine, not a fixed 82/11/4/2/1 curve.
      foreach ( array( 5, 4, 3, 2, 1 ) as $zy_star ) :
        $zy_pct = isset( $rating_bars[ $zy_star ] ) ? (float) $rating_bars[ $zy_star ] : 0;
        $zy_n   = isset( $rating_counts[ $zy_star ] ) ? (int) $rating_counts[ $zy_star ] : 0;
      ?>
      <div class="flex items-center gap-3 text-xs">
        <dt class="w-8 font-medium text-zy-dark"><?php echo esc_html( $zy_star ); ?> ★</dt>
        <dd class="h-2 flex-1 overflow-hidden rounded-full bg-zy-container"><div class="h-full rounded-full bg-zy-gradient" style="width:<?php echo esc_attr( $zy_pct ); ?>%"></div></dd>
        <span class="w-10 text-right text-zy-body/70" title="<?php echo esc_attr( sprintf( _n( '%s review', '%s reviews', $zy_n, 'zymarg-store-page' ), number_format( $zy_n ) ) ); ?>"><?php echo esc_html( $zy_pct ); ?>%</span>
      </div>
      <?php endforeach; ?>
      </dl>
    </div>
<?php endif; // has_rating ?>

    <!--
      Read-only review feed. A store page displays reviews, it never collects
      them: buyers write reviews from My Account, against an order they placed.
      There is deliberately no form in this section.
    -->
    <div class="space-y-4<?php echo $has_rating ? ' lg:col-span-2' : ''; ?>" data-reviews-list data-reviews-page="1" data-reviews-total="<?php echo esc_attr( $store_review_total ); ?>" data-store-id="<?php echo esc_attr( $store_id ); ?>">
<?php foreach ( $store_review_list as $zy_review ) : ?>
      <article class="rounded-2xl bg-zy-surface p-5 shadow-lg" data-review-id="<?php echo esc_attr( $zy_review['id'] ); ?>">
        <div class="flex items-start gap-3">
          <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-zy-container text-sm font-bold text-zy-primary" aria-hidden="true"><?php echo esc_html( $zy_review['initials'] ); ?></span>
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
              <p class="text-sm font-semibold text-zy-dark"><?php echo esc_html( $zy_review['name'] ); ?></p>
<?php if ( ! empty( $zy_review['verified'] ) ) : ?>
              <span class="rounded-full bg-zy-container px-2 py-0.5 text-[10px] font-semibold text-zy-primary"><?php esc_html_e( 'Verified Purchase', 'zymarg-store-page' ); ?></span>
<?php endif; ?>
              <time class="text-xs text-zy-body/60"><?php echo esc_html( $zy_review['date'] ); ?></time>
            </div>
<?php if ( ! empty( $zy_review['product_title'] ) ) : ?>
            <p class="mt-0.5 truncate text-xs text-zy-body/70">
              <a class="transition hover:text-zy-primary" href="<?php echo esc_url( $zy_review['product_url'] ); ?>"><?php echo esc_html( $zy_review['product_title'] ); ?></a>
            </p>
<?php endif; ?>
<?php if ( ! empty( $zy_review['rating'] ) ) : ?>
            <div class="mt-1 flex text-amber-400" aria-label="<?php echo esc_attr( sprintf( __( '%d out of 5 stars', 'zymarg-store-page' ), (int) $zy_review['rating'] ) ); ?>">
              <?php for ( $zy_s = 1; $zy_s <= 5; $zy_s++ ) : ?><svg class="h-3.5 w-3.5<?php echo $zy_s <= (int) $zy_review['rating'] ? '' : ' text-zy-border'; ?>" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.868 2.884c-.321-.772-1.415-.772-1.736 0l-1.83 4.401-4.753.381c-.833.067-1.171 1.107-.536 1.651l3.62 3.102-1.106 4.637c-.194.813.691 1.456 1.405 1.02L10 15.591l4.069 2.485c.713.436 1.598-.207 1.404-1.02l-1.106-4.637 3.62-3.102c.635-.544.297-1.584-.536-1.65l-4.752-.382-1.831-4.401Z"/></svg><?php endfor; ?>
            </div>
<?php endif; ?>
<?php if ( ! empty( $zy_review['title'] ) ) : ?>
            <p class="mt-2 text-sm font-semibold text-zy-dark"><?php echo esc_html( $zy_review['title'] ); ?></p>
<?php endif; ?>
            <p class="mt-2 text-sm leading-relaxed"><?php echo esc_html( $zy_review['body'] ); ?></p>
<?php if ( ! empty( $zy_review['media'] ) ) : ?>
            <div class="mt-3 flex flex-wrap gap-2">
<?php foreach ( (array) $zy_review['media'] as $zy_media ) : ?>
<?php if ( isset( $zy_media['type'] ) && 'video' === $zy_media['type'] ) : ?>
              <video src="<?php echo esc_url( $zy_media['url'] ); ?>" controls preload="metadata" class="h-16 w-16 rounded-xl object-cover shadow-lg"></video>
<?php else : ?>
              <img src="<?php echo esc_url( ! empty( $zy_media['thumb'] ) ? $zy_media['thumb'] : $zy_media['url'] ); ?>" alt="<?php esc_attr_e( 'Customer photo', 'zymarg-store-page' ); ?>" loading="lazy" class="h-16 w-16 rounded-xl object-cover shadow-lg transition duration-300 ease-in-out hover:-translate-y-1 hover:shadow-xl" />
<?php endif; ?>
<?php endforeach; ?>
            </div>
<?php endif; ?>
<?php foreach ( (array) ( isset( $zy_review['replies'] ) ? $zy_review['replies'] : array() ) as $zy_reply ) : ?>
            <div class="mt-3 rounded-2xl bg-zy-alt p-3.5">
              <p class="flex items-center gap-1.5 text-xs font-bold text-zy-primary">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3"/></svg>
                <?php echo esc_html( sprintf( __( 'Reply from %s', 'zymarg-store-page' ), ! empty( $zy_reply['is_owner'] ) ? $store_name : $zy_reply['author'] ) ); ?>
              </p>
              <p class="mt-1 text-sm"><?php echo esc_html( $zy_reply['body'] ); ?></p>
            </div>
<?php endforeach; ?>
          </div>
        </div>
      </article>
<?php endforeach; ?>
<?php if ( $store_reviews_pages > 1 ) : ?>
      <nav class="flex items-center justify-between gap-3 pt-2" aria-label="<?php esc_attr_e( 'Review pages', 'zymarg-store-page' ); ?>">
<?php if ( $reviews_page > 1 ) : ?>
        <a class="rounded-full border border-zy-border px-5 py-2.5 text-sm font-semibold text-zy-dark transition hover:border-zy-primary hover:text-zy-primary" href="<?php echo esc_url( add_query_arg( 'zy_reviews_page', $reviews_page - 1, $store_reviews_base_url ) . '#reviews-heading' ); ?>" rel="prev"><?php esc_html_e( '← Newer reviews', 'zymarg-store-page' ); ?></a>
<?php else : ?>
        <span></span>
<?php endif; ?>
        <span class="text-xs text-zy-body/70"><?php echo esc_html( sprintf( __( 'Page %1$s of %2$s', 'zymarg-store-page' ), number_format( $reviews_page ), number_format( $store_reviews_pages ) ) ); ?></span>
<?php if ( $store_reviews_has_more ) : ?>
        <a class="rounded-full border border-zy-border px-5 py-2.5 text-sm font-semibold text-zy-dark transition hover:border-zy-primary hover:text-zy-primary" href="<?php echo esc_url( add_query_arg( 'zy_reviews_page', $reviews_page + 1, $store_reviews_base_url ) . '#reviews-heading' ); ?>" rel="next"><?php esc_html_e( 'Older reviews →', 'zymarg-store-page' ); ?></a>
<?php else : ?>
        <span></span>
<?php endif; ?>
      </nav>
<?php endif; ?>
    </div>
  </div>
</section>
<?php endif; // show_reviews ?>

<!-- ============================================================
     STORE STORY
============================================================ -->
<?php if ( $has_story ) : ?>
<section aria-labelledby="story-heading" class="mx-auto max-w-7xl px-4 pt-10 pb-16 sm:px-6 lg:px-8">
  <div class="rounded-2xl bg-zy-surface p-6 shadow-lg sm:p-8">
    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-zy-secondary"><?php esc_html_e( 'Our Story', 'zymarg-store-page' ); ?></p>
    <?php if ( '' !== trim( $story_headline ) ) : ?>
      <h2 id="story-heading" class="mt-2 text-xl font-bold tracking-tight text-zy-dark sm:text-2xl"><?php echo esc_html( $story_headline ); ?></h2>
    <?php else : ?>
      <?php /* No headline written, but the landmark still needs a name. */ ?>
      <h2 id="story-heading" class="sr-only"><?php
        /* translators: %s: store name. */
        printf( esc_html__( 'About %s', 'zymarg-store-page' ), esc_html( $store_name ) );
      ?></h2>
    <?php endif; ?>
    <?php if ( '' !== trim( $store_description ) ) : ?>
      <p class="mt-3 max-w-3xl leading-relaxed" data-store-desc><?php echo esc_html( $store_description ); ?></p>
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
<?php endif; // has_story ?>

</main>

<?php
get_footer(); // outputs wp_footer(), </body>, </html>
?>
