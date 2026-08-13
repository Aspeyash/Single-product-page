<?php
/**
 * Store Listing
 *
 * Powers the marketplace store directory at /store-listing/ (Dokan's
 * `store-lists.php` template, output by the [dokan-stores] shortcode).
 *
 * Three jobs:
 *
 *   1. Detection.  Tell the rest of the plugin when we are on that page.
 *      Dokan ships `dokan_is_store_listing()`, but that function is not
 *      guaranteed on every Dokan build, so there are two fallbacks.
 *
 *   2. A cached count layer.  Sorting hundreds of vendors by "most
 *      products" cannot be done by counting posts per vendor at request
 *      time -- that is one query per vendor, every page load. Instead the
 *      count is written to user meta and refreshed when a product changes
 *      plus once nightly, so the sort becomes a plain indexed meta sort.
 *
 *   3. The vendor query itself -- search, sort, paging.
 *
 * Read-only toward vendor data. Nothing here writes to a vendor profile;
 * the only meta written is our own cached counters.
 *
 * @package ZYMARG_Store_Page
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZYMARG_SP_Store_Listing {

	/** Cached number of published products, stored on the vendor. */
	const META_PRODUCT_COUNT = '_zymarg_sp_product_count';

	/** Unix timestamp of the last successful count refresh. */
	const META_COUNT_SYNCED = '_zymarg_sp_count_synced';

	/** Follower counter written by ZYMARG_SP_Follow. Read only here. */
	const META_FOLLOWERS = '_zymarg_followers_count';

	/** Nightly sweep hook. */
	const CRON_HOOK = 'zymarg_sp_sync_store_counts';

	/** Set once the very first count sweep has run. */
	const OPTION_BOOTSTRAPPED = 'zymarg_sp_counts_bootstrapped';

	/** Vendors shown per page. */
	const PER_PAGE = 12;

	// ──────────────────────────────────────────────────────────────────────
	// Boot
	// ──────────────────────────────────────────────────────────────────────

	public static function init() {
		// Keep the cached product count honest.
		add_action( 'save_post_product', array( __CLASS__, 'on_product_change' ), 20, 1 );
		add_action( 'trashed_post', array( __CLASS__, 'on_product_change' ), 20, 1 );
		add_action( 'untrashed_post', array( __CLASS__, 'on_product_change' ), 20, 1 );
		add_action( 'deleted_post', array( __CLASS__, 'on_product_change' ), 20, 1 );

		// Nightly sweep catches anything the hooks missed (bulk imports,
		// direct SQL, products removed by another plugin).
		add_action( self::CRON_HOOK, array( __CLASS__, 'sync_all_counts' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}

		// Infinite scroll. Logged out shoppers browse the directory too, so
		// the nopriv variant is not optional.
		add_action( 'wp_ajax_zymarg_sp_load_stores', array( __CLASS__, 'ajax_load_stores' ) );
		add_action( 'wp_ajax_nopriv_zymarg_sp_load_stores', array( __CLASS__, 'ajax_load_stores' ) );
	}

	/**
	 * Clear the schedule on deactivation so we do not leave a ghost event.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	// ──────────────────────────────────────────────────────────────────────
	// 1. Detection
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * Are we rendering the store directory?
	 *
	 * @return bool
	 */
	public static function is_store_listing() {
		// Preferred: Dokan's own conditional.
		if ( function_exists( 'dokan_is_store_listing' ) && dokan_is_store_listing() ) {
			return true;
		}

		// Fallback 1: the page holds the [dokan-stores] shortcode. This is how
		// Dokan itself builds the page, so it is reliable even when the
		// conditional above is unavailable.
		if ( is_page() ) {
			$post = get_post();

			if ( $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'dokan-stores' ) ) {
				return true;
			}
		}

		// Fallback 2: the slug the marketplace actually uses. Filterable so a
		// site with a translated or renamed page can still be matched.
		$slugs = apply_filters( 'zymarg_sp_store_listing_slugs', array( 'store-listing', 'store-lists', 'stores' ) );

		if ( is_page( $slugs ) ) {
			return true;
		}

		return false;
	}

	// ──────────────────────────────────────────────────────────────────────
	// 2. Cached counts
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * A product was created, edited, trashed or deleted -- refresh that
	 * vendor's count only. Cheap: one COUNT for one vendor.
	 *
	 * @param int $post_id Post that changed.
	 * @return void
	 */
	public static function on_product_change( $post_id ) {
		$post_id = (int) $post_id;

		if ( ! $post_id || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
			return;
		}

		$vendor_id = (int) $post->post_author;

		if ( $vendor_id ) {
			self::refresh_product_count( $vendor_id );
		}
	}

	/**
	 * Recount one vendor's published products and store the result.
	 *
	 * @param int $vendor_id Vendor user ID.
	 * @return int The freshly counted total.
	 */
	public static function refresh_product_count( $vendor_id ) {
		global $wpdb;

		$vendor_id = (int) $vendor_id;

		if ( ! $vendor_id ) {
			return 0;
		}

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(ID) FROM {$wpdb->posts}
				 WHERE post_author = %d
				   AND post_type = 'product'
				   AND post_status = 'publish'",
				$vendor_id
			)
		);

		update_user_meta( $vendor_id, self::META_PRODUCT_COUNT, $count );
		update_user_meta( $vendor_id, self::META_COUNT_SYNCED, time() );

		return $count;
	}

	/**
	 * Nightly sweep -- recount every vendor in one grouped query rather than
	 * one query per vendor.
	 *
	 * @return void
	 */
	public static function sync_all_counts() {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT post_author AS vendor_id, COUNT(ID) AS total
			   FROM {$wpdb->posts}
			  WHERE post_type = 'product'
			    AND post_status = 'publish'
			  GROUP BY post_author"
		);

		$counted = array();

		if ( $rows ) {
			foreach ( $rows as $row ) {
				$vendor_id = (int) $row->vendor_id;
				$counted[ $vendor_id ] = true;

				update_user_meta( $vendor_id, self::META_PRODUCT_COUNT, (int) $row->total );
				update_user_meta( $vendor_id, self::META_COUNT_SYNCED, time() );
			}
		}

		// Vendors whose last product was removed will not appear in the query
		// above at all, so their stale count has to be zeroed explicitly.
		$vendors = get_users(
			array(
				'role'   => 'seller',
				'fields' => 'ID',
				'number' => -1,
			)
		);

		foreach ( $vendors as $vendor_id ) {
			$vendor_id = (int) $vendor_id;

			if ( isset( $counted[ $vendor_id ] ) ) {
				continue;
			}

			update_user_meta( $vendor_id, self::META_PRODUCT_COUNT, 0 );
			update_user_meta( $vendor_id, self::META_COUNT_SYNCED, time() );
		}
	}

	/**
	 * Build every vendor's count once, the first time the directory is
	 * viewed.
	 *
	 * The flag is written BEFORE the sweep, not after. If the sweep were to
	 * fail or time out on a very large catalogue, writing afterwards would
	 * mean retrying the whole thing on every single page view for every
	 * visitor. One attempt, then leave it to the nightly cron.
	 *
	 * @return void
	 */
	public static function maybe_bootstrap_counts() {
		if ( get_option( self::OPTION_BOOTSTRAPPED ) ) {
			return;
		}

		update_option( self::OPTION_BOOTSTRAPPED, time(), false );

		self::sync_all_counts();
	}

	/**
	 * Read a vendor's cached product count. Falls back to a live recount the
	 * first time a vendor is seen, so a freshly installed site is correct
	 * immediately rather than showing zero until the first cron run.
	 *
	 * @param int $vendor_id Vendor user ID.
	 * @return int
	 */
	public static function get_product_count( $vendor_id ) {
		$vendor_id = (int) $vendor_id;
		$synced    = get_user_meta( $vendor_id, self::META_COUNT_SYNCED, true );

		if ( '' === $synced ) {
			return self::refresh_product_count( $vendor_id );
		}

		return (int) get_user_meta( $vendor_id, self::META_PRODUCT_COUNT, true );
	}

	/**
	 * Read a vendor's follower count. The Follow module owns this meta.
	 *
	 * @param int $vendor_id Vendor user ID.
	 * @return int
	 */
	public static function get_follower_count( $vendor_id ) {
		if ( class_exists( 'ZYMARG_SP_Follow' ) ) {
			return ZYMARG_SP_Follow::get_count( (int) $vendor_id );
		}

		return (int) get_user_meta( (int) $vendor_id, self::META_FOLLOWERS, true );
	}

	// ──────────────────────────────────────────────────────────────────────
	// 3. The vendor query
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * The sort options offered in the dropdown.
	 *
	 * `rating` is deliberately absent: there is no trustworthy rating source
	 * yet. Add it here the day the ratings plugin lands and the dropdown,
	 * the query and the URL handling all pick it up together.
	 *
	 * @return array key => label
	 */
	public static function sort_options() {
		return apply_filters(
			'zymarg_sp_listing_sorts',
			array(
				'products'  => __( 'Most products', 'zymarg-store-page' ),
				'popular'   => __( 'Most popular', 'zymarg-store-page' ),
				'newest'    => __( 'Newest stores', 'zymarg-store-page' ),
				'alpha'     => __( 'Store name A-Z', 'zymarg-store-page' ),
			)
		);
	}

	/**
	 * Default sort key.
	 *
	 * @return string
	 */
	public static function default_sort() {
		return apply_filters( 'zymarg_sp_listing_default_sort', 'products' );
	}

	/**
	 * Read, validate and return the current request state.
	 *
	 * @return array { search, sort, paged }
	 */
	public static function current_request() {
		$sorts = self::sort_options();

		$sort = isset( $_GET['store_sort'] ) ? sanitize_key( wp_unslash( $_GET['store_sort'] ) ) : '';
		if ( ! isset( $sorts[ $sort ] ) ) {
			$sort = self::default_sort();
		}

		$search = isset( $_GET['store_search'] ) ? sanitize_text_field( wp_unslash( $_GET['store_search'] ) ) : '';
		$search = trim( $search );

		$paged = isset( $_GET['store_page'] ) ? absint( wp_unslash( $_GET['store_page'] ) ) : 1;
		if ( $paged < 1 ) {
			$paged = 1;
		}

		return array(
			'search' => $search,
			'sort'   => $sort,
			'paged'  => $paged,
		);
	}

	/**
	 * Run the vendor query.
	 *
	 * @param array $args { search, sort, paged }
	 * @return array { vendors (WP_User[]), total (int), pages (int), paged (int) }
	 */
	public static function query( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'search' => '',
				'sort'   => self::default_sort(),
				'paged'  => 1,
			)
		);

		// The default sort reads a cached count. On a fresh install nothing
		// has written one yet and the nightly sweep has not run, so the counts
		// are built here on the first view rather than leaving the directory
		// empty until tomorrow.
		self::maybe_bootstrap_counts();

		$per_page = (int) apply_filters( 'zymarg_sp_listing_per_page', self::PER_PAGE );
		if ( $per_page < 1 ) {
			$per_page = self::PER_PAGE;
		}

		$query_args = array(
			'role'   => 'seller',
			'number' => $per_page,
			'offset' => ( $args['paged'] - 1 ) * $per_page,
			'count_total' => true,
		);

		// ── Sorting ───────────────────────────────────────────────────────
		// Both count sorts read a cached meta value, so this stays a single
		// query no matter how many vendors exist.
		switch ( $args['sort'] ) {
			case 'popular':
				$query_args['meta_key'] = self::META_FOLLOWERS;
				$query_args['orderby']  = 'meta_value_num';
				$query_args['order']    = 'DESC';
				 break;

			case 'newest':
				$query_args['orderby'] = 'registered';
				$query_args['order']   = 'DESC';
				break;

			case 'alpha':
				$query_args['orderby'] = 'display_name';
				$query_args['order']   = 'ASC';
				break;

			case 'products':
			default:
				$query_args['meta_key'] = self::META_PRODUCT_COUNT;
				$query_args['orderby']  = 'meta_value_num';
				$query_args['order']    = 'DESC';
				break;
		}

		// NOTE: do not try to "rescue" vendors with no cached count by adding
		// a meta_query of EXISTS OR NOT EXISTS. An EXISTS clause compiles to
		// an INNER JOIN, which throws those vendors straight back out again --
		// the OR never gets a chance to help. That combination returned an
		// empty directory on a site that plainly had vendors. The real answer
		// is to make sure every vendor has a row, which bootstrap_counts()
		// below guarantees, backed by the fallback re-query after this.

		// ── Search ────────────────────────────────────────────────────────
		// Dokan keeps the store name inside a serialised profile array, so it
		// cannot be searched with a plain meta comparison. Dokan also mirrors
		// the name into `dokan_store_name`, which can be. Searching both that
		// and the account fields covers vendors whose store name was never
		// changed from their account name.
		if ( '' !== $args['search'] ) {
			$query_args['search']         = '*' . $args['search'] . '*';
			$query_args['search_columns'] = array( 'display_name', 'user_login', 'user_nicename' );

			add_action( 'pre_user_query', array( __CLASS__, 'widen_search_to_store_name' ) );
		}

		self::$search_term = $args['search'];

		$user_query = new WP_User_Query( $query_args );

		// Safety net. Sorting by a meta value silently hides every vendor that
		// has no row for that key. An empty store directory is a far worse
		// outcome than an imperfectly ordered one, so if a meta sort comes back
		// with nothing at all, run it again without the sort. The shopper sees
		// the stores; only the ordering degrades.
		if ( isset( $query_args['meta_key'] ) && 0 === (int) $user_query->get_total() ) {
			unset( $query_args['meta_key'], $query_args['meta_query'] );

			$query_args['orderby'] = 'display_name';
			$query_args['order']   = 'ASC';

			$user_query = new WP_User_Query( $query_args );
		}

		if ( '' !== $args['search'] ) {
			remove_action( 'pre_user_query', array( __CLASS__, 'widen_search_to_store_name' ) );
		}

		$vendors = (array) $user_query->get_results();
		$total   = (int) $user_query->get_total();

		return array(
			'vendors' => $vendors,
			'total'   => $total,
			'pages'   => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
			'paged'   => (int) $args['paged'],
			'per_page' => $per_page,
		);
	}

	/** Current search term, shared with the pre_user_query callback. */
	private static $search_term = '';

	/**
	 * Extend the user search to Dokan's `dokan_store_name` meta so that a
	 * shopper searching for the store name finds it even when the vendor's
	 * account name is different.
	 *
	 * @param WP_User_Query $query Query being prepared.
	 * @return void
	 */
	public static function widen_search_to_store_name( $query ) {
		global $wpdb;

		$term = self::$search_term;

		if ( '' === $term ) {
			return;
		}

		if ( empty( $query->query_where ) ) {
			return;
		}

		$like = '%' . $wpdb->esc_like( $term ) . '%';

		$store_name_sql = $wpdb->prepare(
			"OR {$wpdb->users}.ID IN (
				SELECT user_id FROM {$wpdb->usermeta}
				 WHERE meta_key = 'dokan_store_name'
				   AND meta_value LIKE %s
			)",
			$like
		);

		// WP_User_Query wraps its search clause in its own parentheses; append
		// ours just inside the final closing bracket of that clause.
		$query->query_where = preg_replace(
			'/\)\s*$/',
			' ' . $store_name_sql . ' )',
			$query->query_where,
			1
		);
	}

	// ──────────────────────────────────────────────────────────────────────
	// Per-vendor display data
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * Everything one card needs, resolved once.
	 *
	 * @param WP_User $vendor Vendor user object.
	 * @return array
	 */
	public static function card_data( $vendor ) {
		$store_id   = (int) $vendor->ID;
		$store_info = function_exists( 'dokan_get_store_info' ) ? dokan_get_store_info( $store_id ) : array();
		$store_info = is_array( $store_info ) ? $store_info : array();

		$name = ! empty( $store_info['store_name'] ) ? $store_info['store_name'] : $vendor->display_name;

		// ── Location. Never invent one: if the vendor never filled the
		// address in, the row is omitted entirely rather than guessed.
		$address    = isset( $store_info['address'] ) && is_array( $store_info['address'] ) ? $store_info['address'] : array();
		$city       = isset( $address['city'] ) ? trim( (string) $address['city'] ) : '';
		$country_cd = isset( $address['country'] ) ? trim( (string) $address['country'] ) : '';
		$country    = '';

		if ( $country_cd && function_exists( 'WC' ) && WC()->countries ) {
			$countries = WC()->countries->get_countries();
			$country   = isset( $countries[ $country_cd ] ) ? $countries[ $country_cd ] : $country_cd;
		}

		$location = trim( implode( ', ', array_filter( array( $city, $country ) ) ), ', ' );

		// ── Vacation.
		$on_vacation = isset( $store_info['setting_go_vacation'] ) && 'yes' === $store_info['setting_go_vacation'];
		$vacation_msg = isset( $store_info['setting_vacation_message'] ) ? trim( (string) $store_info['setting_vacation_message'] ) : '';

		// ── Banner and logo. A missing banner is fine -- the card falls back
		// to the brand gradient, which is deliberate, not a placeholder.
		$banner = ! empty( $store_info['banner'] ) ? $store_info['banner'] : '';

		if ( $banner && is_numeric( $banner ) ) {
			$banner = (string) wp_get_attachment_image_url( (int) $banner, 'large' );
		}

		$logo = ! empty( $store_info['gravatar'] ) ? $store_info['gravatar'] : '';

		if ( $logo && is_numeric( $logo ) ) {
			$logo = (string) wp_get_attachment_image_url( (int) $logo, 'thumbnail' );
		}

		if ( ! $logo ) {
			$logo = (string) get_user_meta( $store_id, '_zymarg_store_avatar_url', true );
		}

		$tagline = isset( $store_info['store_tagline'] ) ? trim( (string) $store_info['store_tagline'] ) : '';

		return array(
			'id'            => $store_id,
			'name'          => $name,
			'tagline'       => $tagline,
			'url'           => function_exists( 'dokan_get_store_url' ) ? dokan_get_store_url( $store_id ) : home_url( '/' ),
			'banner'        => $banner,
			'logo'          => $logo,
			'initial'       => mb_substr( $name, 0, 1 ),
			'location'      => $location,
			'followers'     => self::get_follower_count( $store_id ),
			'products'      => self::get_product_count( $store_id ),
			'member_since'  => date_i18n( 'Y', strtotime( $vendor->user_registered ) ),
			'on_vacation'   => $on_vacation,
			'vacation_msg'  => $vacation_msg,
			'badges'        => function_exists( 'zymarg_sp_store_badge_row' ) ? zymarg_sp_store_badge_row( $store_id, array( 'tick_size' => 'zsl-tick' ) ) : '',
			'is_following'  => class_exists( 'ZYMARG_SP_Follow' ) ? ZYMARG_SP_Follow::current_user_follows( $store_id ) : false,
			'response_rate' => zymarg_sp_store_response_rate( $store_id ),
		);
	}

	// ──────────────────────────────────────────────────────────────────────
	// Card rendering and infinite scroll
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * Render one card.
	 *
	 * The first page printed by the template and every page appended by
	 * infinite scroll both come through here, so an appended card is the same
	 * markup as the ones above it by construction rather than by discipline.
	 *
	 * @param WP_User $vendor Vendor user object.
	 * @return string Card HTML, or an empty string if it cannot be rendered.
	 */
	public static function render_card( $vendor ) {
		if ( ! $vendor instanceof WP_User ) {
			return '';
		}

		$c = self::card_data( $vendor );

		$template = apply_filters(
			'zymarg_sp_store_card_template',
			ZYMARG_SP_TEMPLATES . 'partials/store-card.php',
			$c
		);

		if ( ! $template || ! is_readable( $template ) ) {
			return '';
		}

		ob_start();
		include $template;

		return (string) ob_get_clean();
	}

	/**
	 * Return the next page of store cards, already rendered.
	 *
	 * Public data, so there is no capability check -- but the request is
	 * still nonced and every input is validated through the same helpers the
	 * page load uses, so the Ajax path cannot be coaxed into a query the
	 * normal page could not run.
	 *
	 * When a page comes back empty it is reported as empty. It is never
	 * padded, wrapped around to page one, or filled with anything invented.
	 *
	 * @return void
	 */
	public static function ajax_load_stores() {
		check_ajax_referer( 'zymarg_sp_listing', 'nonce' );

		$sorts = self::sort_options();

		$sort = isset( $_POST['sort'] ) ? sanitize_key( wp_unslash( $_POST['sort'] ) ) : '';
		if ( ! isset( $sorts[ $sort ] ) ) {
			$sort = self::default_sort();
		}

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$search = trim( $search );

		$paged = isset( $_POST['paged'] ) ? absint( wp_unslash( $_POST['paged'] ) ) : 1;
		if ( $paged < 1 ) {
			$paged = 1;
		}

		$results = self::query(
			array(
				'search' => $search,
				'sort'   => $sort,
				'paged'  => $paged,
			)
		);

		$vendors = isset( $results['vendors'] ) ? (array) $results['vendors'] : array();
		$html    = '';

		foreach ( $vendors as $vendor ) {
			$html .= self::render_card( $vendor );
		}

		wp_send_json_success(
			array(
				'html'  => $html,
				'paged' => (int) $results['paged'],
				'pages' => (int) $results['pages'],
				'total' => (int) $results['total'],
				'count' => count( $vendors ),
			)
		);
	}
}

// ──────────────────────────────────────────────────────────────────────────────
// Response rate -- single source of truth
// ──────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'zymarg_sp_calculate_response_rate' ) ) {
	/**
	 * A vendor's message response rate, as a whole percentage.
	 *
	 * DELIBERATELY NOT WIRED YET. The Communication plugin can compute this,
	 * but as it stands the calculation filters the numerator by vendor while
	 * leaving the denominator counting every conversation on the whole
	 * marketplace -- so a vendor who answers all ten of their messages would
	 * be shown as 2% on a busy site. Publishing that number would be worse
	 * than publishing none.
	 *
	 * There is also a privacy boundary: that plugin's per-user report service
	 * refuses cross-user reads by design, so a public listing has to go around
	 * a guard someone put there on purpose.
	 *
	 * Everything that shows a response rate calls this one function, so the
	 * day the denominator is fixed upstream, this body changes once and every
	 * card on the site is correct. Nothing else needs touching.
	 *
	 * Return null to hide the badge entirely, which is what a vendor with too
	 * few conversations should get -- a quiet new seller should not be shown
	 * as 0% and look negligent.
	 *
	 * @param int $store_id Vendor user ID.
	 * @return int|null Whole percentage, or null when unavailable.
	 */
	function zymarg_sp_calculate_response_rate( $store_id ) {
		global $wpdb;

		$store_id = (int) $store_id;

		if ( $store_id < 1 ) {
			return null;
		}

		/**
		 * Only conversations started inside this window count. A rate built
		 * from every message a vendor ever received would be dominated by
		 * ancient history and would barely move when they improved.
		 */
		$window_days = (int) apply_filters( 'zymarg_sp_response_rate_window_days', 90 );

		/**
		 * Below this many inbound conversations no figure is shown at all.
		 * "Responds to 100%% of messages" off a single conversation is not a
		 * statistic, it is noise -- and it is unfair to established sellers.
		 */
		$min_sample = (int) apply_filters( 'zymarg_sp_response_rate_min_sample', 3 );

		$participants = $wpdb->prefix . 'zc_participants';
		$messages     = $wpdb->prefix . 'zc_messages';

		// The Communication plugin may not be installed, or may have been
		// removed after this cache was warmed. Never assume its tables exist.
		foreach ( array( $participants, $messages ) as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			if ( $found !== $table ) {
				return null;
			}
		}

		$since = gmdate( 'Y-m-d H:i:s', time() - ( $window_days * DAY_IN_SECONDS ) );

		/*
		 * Denominator: conversations this vendor is in where SOMEONE ELSE
		 * actually wrote something. Numerator: how many of those the vendor
		 * wrote back in.
		 *
		 * This is the part the upstream report gets wrong -- it filters the
		 * numerator by the responder but not the denominator, so every
		 * conversation on the whole site drags a vendor's score down. Counting
		 * both halves against the same set is the entire fix.
		 *
		 * Only counts are read. No message body is touched.
		 */
		$sql = "SELECT COUNT(*) AS inbound, COALESCE( SUM( answered ), 0 ) AS answered FROM (
				SELECT p.conversation_id,
					MAX( CASE WHEN m.sender_id = %d THEN 1 ELSE 0 END ) AS answered,
					SUM( CASE WHEN m.sender_id <> %d THEN 1 ELSE 0 END ) AS from_others
				FROM {$participants} p
				INNER JOIN {$messages} m ON m.conversation_id = p.conversation_id
				WHERE p.user_id = %d
					AND m.created_at >= %s
					AND m.lifecycle_status = 'active'
					AND m.deleted_at IS NULL
				GROUP BY p.conversation_id
				HAVING from_others > 0
			) counted";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( $sql, $store_id, $store_id, $store_id, $since ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		$inbound = (int) $row['inbound'];

		if ( $inbound < $min_sample ) {
			return null;
		}

		$answered = (int) $row['answered'];

		return (int) round( ( $answered / $inbound ) * 100 );
	}
}

if ( ! function_exists( 'zymarg_sp_store_response_rate' ) ) {
	function zymarg_sp_store_response_rate( $store_id ) {
		$store_id = (int) $store_id;
		$rate     = null;

		if ( $store_id > 0 ) {
			$cache_key = 'zymarg_sp_rr_' . $store_id;
			$cached    = get_transient( $cache_key );

			if ( false !== $cached ) {
				// An empty string is a cached "deliberately no figure", which
				// is different from "nothing cached". Without that distinction
				// every vendor below the sample floor would re-run the query
				// on every single page view.
				$rate = ( '' === $cached ) ? null : (int) $cached;
			} else {
				$rate = zymarg_sp_calculate_response_rate( $store_id );

				set_transient(
					$cache_key,
					( null === $rate ) ? '' : (int) $rate,
					12 * HOUR_IN_SECONDS
				);
			}
		}

		/**
		 * Filter the response rate for a vendor.
		 *
		 * @param int|null $rate     Whole percentage, or null to hide.
		 * @param int      $store_id Vendor user ID.
		 */
		$rate = apply_filters( 'zymarg_sp_store_response_rate', $rate, (int) $store_id );

		if ( null === $rate ) {
			return null;
		}

		$rate = (int) round( (float) $rate );

		return max( 0, min( 100, $rate ) );
	}
}
