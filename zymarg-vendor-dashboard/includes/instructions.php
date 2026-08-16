<?php
/**
 * ZYMARG Vendor Dashboard — "D-Instruction" admin page.
 *
 * A self-contained, on-brand documentation screen that explains every feature
 * individually in ZYMARG card style. Pure admin UI (no front-end dependency),
 * so it uses inline brand colours rather than the theme's CSS tokens.
 *
 * @package ZYMARG_Vendor_Dashboard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the "D-Instruction" page as a submenu under the Vendor hub.
 *
 * @return void
 */
function zymarg_vd_register_instructions_menu() {
	add_submenu_page(
		'zymarg-vendor-hub',
		__( 'D-Instruction', 'zymarg-vendor-dashboard' ),
		__( 'D-Instruction', 'zymarg-vendor-dashboard' ),
		'manage_options',
		'zymarg-vd-instructions',
		'zymarg_vd_render_instructions_page'
	);
}
add_action( 'admin_menu', 'zymarg_vd_register_instructions_menu' );

/**
 * A small inline-SVG icon set for the instruction cards.
 *
 * @param string $key Icon key.
 * @return string
 */
function zymarg_vd_doc_icon( $key ) {
	$o = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">';
	$c = '</svg>';
	$p = array(
		'rocket'   => '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="M12 15l-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/>',
		'home'     => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
		'bolt'     => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
		'box'      => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
		'cart'     => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
		'wallet'   => '<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/>',
		'chart'    => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
		'megaphone'=> '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
		'star'     => '<polygon points="12 2 15.1 8.3 22 9.2 17 14.1 18.2 21 12 17.8 5.8 21 7 14.1 2 9.2 8.9 8.3 12 2"/>',
		'chat'     => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
		'reply'    => '<polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/>',
		'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
		'bell'     => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
		'link'     => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
		'toggle'   => '<rect x="1" y="5" width="22" height="14" rx="7"/><circle cx="16" cy="12" r="3"/>',
		'dokan'    => '<path d="M3 9l1.5-5h15L21 9"/><path d="M4 9v11a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9"/><path d="M3 9a2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 3 0"/>',
		'store'    => '<path d="M3 9l1.5-5h15L21 9"/><path d="M4 9v11a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9"/><path d="M3 9a2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 3 0"/><path d="M9 21v-6h6v6"/>',
		'refund'   => '<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/><line x1="12" y1="8" x2="12" y2="13"/><line x1="9.5" y1="10.5" x2="14.5" y2="10.5"/>',
		'truck'    => '<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
		'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
		'palette'  => '<circle cx="13.5" cy="6.5" r="1.5"/><circle cx="17.5" cy="10.5" r="1.5"/><circle cx="8.5" cy="7.5" r="1.5"/><circle cx="6.5" cy="12.5" r="1.5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.93 0 1.5-.5 1.5-1.5 0-.46-.18-.9-.5-1.22a1.7 1.7 0 0 1-.5-1.23c0-.96.79-1.55 1.75-1.55H16a5 5 0 0 0 5-5c0-4.96-4.5-8.5-9-8.5z"/>',
	);
	$inner = isset( $p[ $key ] ) ? $p[ $key ] : '';
	return $inner ? $o . $inner . $c : '';
}

/**
 * Render the D-Instruction page — one ZYMARG card per feature.
 *
 * @return void
 */
function zymarg_vd_render_instructions_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$is_ajax = ! empty( $GLOBALS['zymarg_vd_ajax_render'] );
	if ( ! $is_ajax ) {
		echo '<div id="zymarg-admin-ajax-content" class="zymarg-admin">';
	}

	echo '<a href="' . esc_url( admin_url( 'admin.php?page=zymarg-vendor-hub' ) ) . '" class="zvd-back zvd-nav-link">&larr; Back to Vendor Hub</a>';

	$cards = array(
		array(
			'icon'  => 'link',
			'title' => __( 'Setup: connect the dashboard', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'The plugin renders the ZYMARG vendor experience on your vendor dashboard page.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'It automatically takes over the page with the slug "dashboard" (the page where Dokan normally puts [dokan-dashboard]).', 'zymarg-vendor-dashboard' ),
				__( 'Alternatively, add the [zymarg_vendor_dashboard] shortcode to any page.', 'zymarg-vendor-dashboard' ),
				__( 'Sellers see the full dashboard; admins see a labelled preview; logged-out or non-vendor visitors get a sign-in / become-a-vendor prompt.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'home',
			'title' => __( 'Dashboard (home)', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'The signature overview screen — the first thing a vendor sees.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'A warm, time-aware greeting ("Good morning, {name}") based on the visitor\'s local device time.', 'zymarg-vendor-dashboard' ),
				__( 'Four stat cards: Today\'s Sales, Today\'s Orders, Pending Orders and Store Rating.', 'zymarg-vendor-dashboard' ),
				__( 'A 7-day revenue chart, plus Latest Orders, Low Stock and Recent Reviews panels.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'bolt',
			'title' => __( 'Quick Actions', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'One-tap shortcuts at the top of the Dashboard.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( '+ Product, + Coupon, + Withdraw, + Store Banner and + Promotion.', 'zymarg-vendor-dashboard' ),
				__( 'Product and Store Banner hand off to Dokan\'s forms; Withdraw opens the native Payouts screen; Coupon/Promotion open the Promotions screen.', 'zymarg-vendor-dashboard' ),
				__( 'Hide the whole row or individual buttons from Settings -> ZYMARG Vendor.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'box',
			'title' => __( 'Products', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'A card grid of the vendor\'s products — no ugly tables.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'Each card shows image, name, price, stock, units sold and views, with a status badge and featured star.', 'zymarg-vendor-dashboard' ),
				__( 'The ••• menu: Edit, View, Feature/Unfeature, Hide/Unhide, Duplicate and Delete (all instant, ownership-checked).', 'zymarg-vendor-dashboard' ),
				__( 'Use the search box and pagination to find products fast. "Add Product" opens the native editor (or Dokan\'s form if you turn the editor off).', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'box',
			'title' => __( 'Native product editor (add / edit)', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'Create and edit products inside the dashboard — no Dokan Pro needed.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'Edit everything for a simple product: name, descriptions, regular & sale price, SKU, inventory, categories, tags, featured image, virtual/downloadable, featured and status.', 'zymarg-vendor-dashboard' ),
				__( 'New products are saved under you as the author; you can only edit your own products. Sale price must be lower than the regular price; duplicate SKUs are blocked.', 'zymarg-vendor-dashboard' ),
				__( 'Turn it off under Settings -> ZYMARG Vendor ("Native product editor") to fall back to Dokan\'s product form. Variable products still use the full form.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'cart',
			'title' => __( 'Orders', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'Every order, grouped by where it is in its lifecycle.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'Tabs: Pending, Processing, Shipped, Delivered, Cancelled and Refunds, each with a live count.', 'zymarg-vendor-dashboard' ),
				__( 'Rows show order number, date, item count, customer and the vendor\'s revenue share.', 'zymarg-vendor-dashboard' ),
				__( '"View" opens the full order in Dokan.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'refund',
			'title' => __( 'Refund requests', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'A complete refund workflow — no Dokan Pro needed.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'Buyers request a refund from their order page (a reason, and an optional amount), within a configurable time window.', 'zymarg-vendor-dashboard' ),
				__( 'You review requests in the "Refunds" sidebar screen and Approve — which records a WooCommerce refund for the amount (capped at the order\'s remaining refundable total) — or Reject with a note.', 'zymarg-vendor-dashboard' ),
				__( 'Toggle it under Settings -> ZYMARG Vendor ("Refund requests").', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'wallet',
			'title' => __( 'Earnings', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'Income at a glance, plus the balance ready to withdraw.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'Today / This Week / This Month earnings are always calculated from orders.', 'zymarg-vendor-dashboard' ),
				__( 'Available Balance, Withdrawn and Pending Withdrawal read Dokan payout data when active.', 'zymarg-vendor-dashboard' ),
				__( 'A 30-day earnings trend chart and a Withdraw button that opens the native Payouts screen.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'wallet',
			'title' => __( 'Payouts (bKash / Nagad / Rocket / bank)', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'A native withdrawal system that works on Dokan Lite — no Dokan Pro needed.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'Vendors save a payout method — bKash, Nagad or Rocket mobile number, or full bank-transfer details — and request a withdrawal of their available balance.', 'zymarg-vendor-dashboard' ),
				__( 'Available balance = gross balance (Dokan balance when present, else lifetime earnings from completed/processing orders) minus in-flight requests and amounts already paid out. A minimum withdrawal applies (filterable, default 500).', 'zymarg-vendor-dashboard' ),
				__( 'Admins review requests at Settings -> ZYMARG Payouts: Approve / Mark paid / Reject with a note. Vendors can cancel their own pending requests. Toggle the whole module with the "Payouts" feature in Settings -> ZYMARG Vendor.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'chart',
			'title' => __( 'Analytics', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'Performance over the last 30 days.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'Revenue, Orders, Visitors and Conversion stat cards.', 'zymarg-vendor-dashboard' ),
				__( 'A 30-day revenue chart and a Top Products ranking by units sold.', 'zymarg-vendor-dashboard' ),
				__( 'Visitors/Conversion light up when you connect a traffic source via the zymarg_os_vendor_visitors filter.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'megaphone',
			'title' => __( 'Promotions (coupons)', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'A native coupon creator that works on Dokan Lite — no Pro needed.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'Set code, type (%, fixed cart, fixed product), amount, expiry, usage limit and minimum spend.', 'zymarg-vendor-dashboard' ),
				__( 'New coupons are automatically restricted to the vendor\'s own products.', 'zymarg-vendor-dashboard' ),
				__( 'See and delete existing coupons in the list beside the form.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'star',
			'title' => __( 'Reviews', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'Moderate and respond to reviews on your products.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'Filter by rating (All / 5 / 4 / 3 / 2 / 1 stars).', 'zymarg-vendor-dashboard' ),
				__( 'Reply posts a public response; Hide holds the review; Report flags it to the marketplace admin.', 'zymarg-vendor-dashboard' ),
				__( 'A vendor can only act on reviews of their own products.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'chat',
			'title' => __( 'Messages (vendor inbox)', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'A Messenger-style inbox for chatting with buyers.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'Left: conversation list (seeded from your order customers). Right: the chat thread + composer.', 'zymarg-vendor-dashboard' ),
				__( 'Click a customer, type a message and send — it is saved to that conversation.', 'zymarg-vendor-dashboard' ),
				__( 'On mobile, the list drills into a thread with a back button.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'reply',
			'title' => __( 'Buyer replies (shortcode)', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'Let customers reply from the storefront so chat is two-way.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'Create a page (e.g. /my-messages/) and add the [zymarg_my_messages] shortcode.', 'zymarg-vendor-dashboard' ),
				__( 'A logged-in customer sees their conversations with vendors and can reply.', 'zymarg-vendor-dashboard' ),
				__( 'Replies appear instantly in the vendor\'s inbox. Disabling Messages also disables this shortcode.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'chat',
			'title' => __( 'Contact Seller button', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'Lets buyers start a conversation directly from a product page.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'Shows automatically on single product pages (for the product\'s vendor). Hidden on the seller\'s own products.', 'zymarg-vendor-dashboard' ),
				__( 'Logged-out shoppers see a "Sign in to contact" link; logged-in shoppers get an inline message box.', 'zymarg-vendor-dashboard' ),
				__( 'The message lands in the vendor inbox and the buyer\'s [zymarg_my_messages]. Place it anywhere with [zymarg_contact_seller]; toggle it in Settings -> ZYMARG Vendor.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'users',
			'title' => __( 'Customers', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'The people buying from the store, aggregated from orders.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'Tabs: Recent Customers, Repeat Customers and Top Buyers.', 'zymarg-vendor-dashboard' ),
				__( 'Each row shows orders, total spent (vendor share) and last order date.', 'zymarg-vendor-dashboard' ),
				__( 'The "Message" button deep-links straight into that customer\'s chat thread.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'bell',
			'title' => __( 'Notifications', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'Everything that needs attention, in one feed.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'New orders, low-stock alerts, new reviews and new buyer messages, newest first.', 'zymarg-vendor-dashboard' ),
				__( 'Filter chips: All / Orders / Stock / Reviews / Messages.', 'zymarg-vendor-dashboard' ),
				__( 'Each item links straight to the relevant screen.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'store',
			'title' => __( 'Store Profile (Settings -> Section 5)', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'Edit your public store profile — kept in sync with your storefront. Lives inside Settings, not its own sidebar item.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'Set store name, public phone, public-email visibility, full address, and upload a store banner. (Social links have their own Section 8.)', 'zymarg-vendor-dashboard' ),
				__( 'Vacation mode shows an away-notice on your products and can optionally pause sales (hide Add to cart on your products) while you are away — this is the ONLY vacation control in the plugin.', 'zymarg-vendor-dashboard' ),
				__( 'Everything saves into Dokan\'s store profile, so your storefront updates too.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'truck',
			'title' => __( 'Shipping fees & Store SEO (native)', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'Charge shipping and improve search visibility — no Dokan Pro needed.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'Set a flat shipping fee for your products plus an optional "free shipping over X" threshold; one fee per vendor is added at checkout.', 'zymarg-vendor-dashboard' ),
				__( 'Store SEO: set a meta title and description that appear in your store page <title> and meta description, with no SEO plugin.', 'zymarg-vendor-dashboard' ),
				__( 'Toggle each independently in Settings -> ZYMARG Vendor ("Shipping fees" and "Store SEO").', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'dokan',
			'title' => __( 'Settings (Dokan)', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'The remaining vendor Settings link hands off to Dokan — on purpose.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'It opens Dokan\'s real, update-safe vendor settings form.', 'zymarg-vendor-dashboard' ),
				__( 'Payouts, the product editor, Store Profile, Refunds and Shipping/SEO are now all native — see their own cards.', 'zymarg-vendor-dashboard' ),
				__( 'Hide it from Settings -> ZYMARG Vendor if you do not use it.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'link',
			'title' => __( 'Dokan Lite vs Pro (automatic)', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'Fully Pro-equivalent on free Dokan Lite — and conflict-free if you ever add Pro.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'On Dokan Lite (no Pro): every native module runs — Payouts, product editor, Store Profile, refunds, shipping and SEO — so the dashboard behaves like Dokan Pro.', 'zymarg-vendor-dashboard' ),
				__( 'If Dokan Pro is active, the overlapping native modules automatically stand down so Pro owns them (Refunds→RMA, Shipping, Vacation, products, SEO→Yoast/Rank Math). Payouts with bKash/Nagad/Rocket stay on either way.', 'zymarg-vendor-dashboard' ),
				__( 'No licence key or payment is ever required by this plugin; it only detects whether Pro is present.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'shield',
			'title' => __( 'Dokan compatibility monitor', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'Get a heads-up when Dokan or Dokan Pro releases a version we have not validated against.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'On every admin page, the plugin checks the installed Dokan Lite / Dokan Pro versions against the highest versions this build was validated against.', 'zymarg-vendor-dashboard' ),
				__( 'Patch updates (e.g. 5.0.2 -> 5.0.3) are silent — they are very low risk. Minor or major bumps (5.0 -> 5.1, 5.x -> 6.0) show a single dismissible admin notice asking you to do a 5-minute staging check.', 'zymarg-vendor-dashboard' ),
				__( 'A "Dokan compatibility" panel at the bottom of Settings -> ZYMARG Vendor always shows installed vs validated versions, so you know where you stand. The monitor is read-only — it never disables features.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'toggle',
			'title' => __( 'Feature toggles', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'Turn any feature on or off — found under Settings -> ZYMARG Vendor.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'Disabled features are removed from the sidebar AND blocked on direct URL access (they fall back to the Dashboard).', 'zymarg-vendor-dashboard' ),
				__( 'All new features (verification badges, announcements, staff accounts, variable products, SPA navigation) respect these toggles.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'bolt',
			'title' => __( 'SPA navigation (fast section switching)', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'Section clicks load via AJAX instead of full page reloads — instant navigation after the first load.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'The first visit to /dashboard/ is a normal page load (unavoidable stack boot). Every sidebar click after that is ~1-2 seconds.', 'zymarg-vendor-dashboard' ),
				__( 'The sidebar, header and footer stay put — only the content area swaps. The ZYMARG Discovery Spark animates as a loading indicator.', 'zymarg-vendor-dashboard' ),
				__( 'Back/Forward buttons, direct URLs and the mobile sidebar all work correctly. If JS/network fails, links fall back to normal loads.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'box',
			'title' => __( 'Variable products (sizes, colours, variations)', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'Create products with multiple options — each with its own price, stock, SKU and image.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'Select "Variable product" from the Product Type dropdown in the editor. The Pricing card hides (prices are per-variation).', 'zymarg-vendor-dashboard' ),
				__( 'Add Attributes: custom text (e.g. Size: S|M|L|XL) or pick existing WooCommerce global attributes (pa_color, pa_size). Check "Used for variations".', 'zymarg-vendor-dashboard' ),
				__( 'After saving the product, click "Generate variations" to create all combinations. Set regular price, sale price, stock and SKU per variation. Save variations.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'wallet',
			'title' => __( 'Per-vendor commission (admin)', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'Set individual commission rates per vendor — overrides category and global defaults.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'WP Admin -> Vendor Hub -> Commission. Each vendor card shows their current rate and a dropdown to change it.', 'zymarg-vendor-dashboard' ),
				__( 'Types: Use Global Default (inherits category/global), Percentage, Flat (fixed BDT per order), Combine (% + flat).', 'zymarg-vendor-dashboard' ),
				__( 'Writes to Dokan Lite\'s own meta keys — the commission engine picks it up automatically. No Dokan Pro needed.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'shield',
			'title' => __( 'Verification badges', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'Mark vendors as verified — badge shows on store pages, product cards and the vendor dashboard.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'Admin sets verification level per vendor from Vendor Hub -> Commission page: Unverified, ID Verified (blue badge), or Fully Verified (purple badge).', 'zymarg-vendor-dashboard' ),
				__( 'Badges display automatically on the Dokan store header, WooCommerce product cards, and the vendor\'s dashboard sidebar.', 'zymarg-vendor-dashboard' ),
				__( 'Toggle display on/off from Settings -> ZYMARG Vendor ("Verification badges"). Public API: zymarg_vd_is_vendor_verified($user_id) and zymarg_vd_verification_badge($user_id).', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'megaphone',
			'title' => __( 'Announcements (admin to vendors)', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'Send notices to all or specific vendors — they see them in Notifications with a "NEW" badge.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'WP Admin -> Vendor Hub -> Announcements. Create with title, body and target (All Vendors or pick specific ones).', 'zymarg-vendor-dashboard' ),
				__( 'Active announcements appear at the top of the vendor\'s Notifications section as branded cards. The sidebar shows a purple dot for unread announcements.', 'zymarg-vendor-dashboard' ),
				__( 'Vendors click "Mark as read" to dismiss. Admins can Deactivate (hide) or Delete announcements. Toggle from Settings -> ZYMARG Vendor.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'wallet',
			'title' => __( 'Auto-disbursement (scheduled payouts)', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'Automatically approve payouts for eligible vendors on a schedule — no manual requests needed.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'Enable from Vendor Hub -> Payouts -> "Auto-Disbursement" card. Set frequency: Weekly (Mondays), Biweekly (1st & 15th) or Monthly (1st).', 'zymarg-vendor-dashboard' ),
				__( 'On schedule: creates auto-approved payout records for every vendor with balance >= minimum AND a saved payout method AND no existing pending request.', 'zymarg-vendor-dashboard' ),
				__( 'Admin still manually transfers the money (bKash/bank) then marks as Paid. "Run Now" button for immediate testing. Auto-generated payouts show an "Auto" badge.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'users',
			'title' => __( 'Vendor staff accounts', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'Vendors can add team members with role-based access to their dashboard.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'Dashboard -> Staff (vendor-only section). Add staff with name, email, password + select permissions: Products, Orders, Earnings (view-only), Messages, Reviews, Promotions, Analytics.', 'zymarg-vendor-dashboard' ),
				__( 'Staff logs in with their own email/password, sees the vendor\'s data (products, orders, etc.) but ONLY the sections they have permission for. Cannot access Payouts, Settings (incl. Store Profile), or manage other staff.', 'zymarg-vendor-dashboard' ),
				__( 'Permissions are enforced on all access paths (URL, SPA, AJAX). Remove staff reverts them to subscriber role. Toggle from Settings -> ZYMARG Vendor ("Staff accounts").', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'toggle',
			'title' => __( 'Feature toggles', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'Turn any feature on or off — found under Settings -> ZYMARG Vendor.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'Disabled features are removed from the sidebar AND blocked on direct URL access (they fall back to the Dashboard).', 'zymarg-vendor-dashboard' ),
				__( 'They are also dropped from Quick Actions. The Dashboard home is always available.', 'zymarg-vendor-dashboard' ),
				__( 'Everything defaults to ON, so nothing changes until you start switching things off.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'palette',
			'title' => __( 'Store image uploader (crop + auto-compress)', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'Click the sidebar avatar to upload your store image without leaving the dashboard.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'Pick "Choose from Gallery" or "Take Photo", then crop free-form or lock to 1:1 / 4:3 / 16:9.', 'zymarg-vendor-dashboard' ),
				__( 'A smart compressor automatically targets ≤ 50 KB (WebP when supported, JPEG otherwise) with no visible quality loss at avatar size.', 'zymarg-vendor-dashboard' ),
				__( 'On save, the image is wired into Dokan\'s store logo too — so your public store page updates at the same time.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'palette',
			'title' => __( 'Branding & design', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'On-brand with ZYMARG OS, and graceful on any theme.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'With the ZYMARG OS theme, it uses the global gradient, colours and Discovery Spark mark automatically.', 'zymarg-vendor-dashboard' ),
				__( 'On other themes it falls back to built-in styling so it still looks right.', 'zymarg-vendor-dashboard' ),
				__( 'Dark mode flows through wherever the theme provides it.', 'zymarg-vendor-dashboard' ),
			),
		),
		array(
			'icon'  => 'link',
			'title' => __( 'Build an app: JSON REST API', 'zymarg-vendor-dashboard' ),
			'desc'  => __( 'A stable, client-agnostic JSON API for a native app (Flutter, React Native, etc.). Full reference is in the "Developer API reference" panel below this grid.', 'zymarg-vendor-dashboard' ),
			'steps' => array(
				__( 'Base URL: https://your-site.com/wp-json/zymarg/v1/ — endpoints: /me, /dashboard, /orders, /earnings, /analytics, /notifications, /messages.', 'zymarg-vendor-dashboard' ),
				__( 'Auth = WordPress Application Passwords (Users -> Profile -> Application Passwords). No extra plugin. Send it as HTTP Basic auth.', 'zymarg-vendor-dashboard' ),
				__( 'Products, coupons, order status changes and withdrawals reuse the WooCommerce (/wc/v3) and Dokan (/dokan/v1) REST APIs — this layer only adds the vendor dashboard aggregations.', 'zymarg-vendor-dashboard' ),
			),
		),
	);
	?>
	<div class="wrap zymarg-vd-doc">
		<?php
		zymarg_vd_admin_header(
			__( 'D-Instruction', 'zymarg-vendor-dashboard' ),
			__( 'How every feature of the vendor dashboard works.', 'zymarg-vendor-dashboard' )
		);
		?>

		<div class="zvd-grid">
			<?php foreach ( $cards as $card ) : ?>
				<div class="zvd-card">
					<div class="zvd-card__icon"><?php echo zymarg_vd_doc_icon( $card['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<h2 class="zvd-card__title"><?php echo esc_html( $card['title'] ); ?></h2>
					<p class="zvd-card__desc"><?php echo esc_html( $card['desc'] ); ?></p>
					<ul class="zvd-card__list">
						<?php foreach ( $card['steps'] as $step ) : ?>
							<li><?php echo esc_html( $step ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</div>

		<?php echo zymarg_vd_render_api_reference(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>

	<?php
	if ( ! $is_ajax ) {
		echo '</div><!-- #zymarg-admin-ajax-content -->';
	}
}


/**
 * Render the full Developer API reference panel (shown under the feature grid
 * on the D-Instruction page). This is the hand-off document for the app team.
 *
 * @return string HTML.
 */
function zymarg_vd_render_api_reference() {
	$base = esc_url( rest_url( 'zymarg/v1' ) );
	$wc   = esc_url( rest_url( 'wc/v3' ) );
	$dok  = esc_url( rest_url( 'dokan/v1' ) );

	// Endpoint reference: method, path, description.
	$endpoints = array(
		array( 'GET',  '/me',                     __( 'Vendor identity + store profile (id, store name, url, avatar, verification, currency).', 'zymarg-vendor-dashboard' ) ),
		array( 'GET',  '/dashboard',              __( 'Home KPIs: today_sales, sales_delta, today_orders, pending_orders, rating, revenue_series[], latest_orders[], low_stock[], recent_reviews[].', 'zymarg-vendor-dashboard' ) ),
		array( 'GET',  '/orders',                 __( 'Orders grouped into lifecycle buckets (pending/processing/shipped/delivered/cancelled/refunds) + counts. ?status=pending returns one bucket.', 'zymarg-vendor-dashboard' ) ),
		array( 'GET',  '/earnings',               __( 'today/week/month + available/withdrawn/pending balance + 30-day series[].', 'zymarg-vendor-dashboard' ) ),
		array( 'GET',  '/analytics',              __( 'revenue, orders, visitors, conversion, 30-day series[], top products[].', 'zymarg-vendor-dashboard' ) ),
		array( 'GET',  '/notifications',          __( 'Merged activity feed (orders, low stock, reviews, messages), newest first.', 'zymarg-vendor-dashboard' ) ),
		array( 'GET',  '/messages',               __( 'Conversation list (thread previews).', 'zymarg-vendor-dashboard' ) ),
		array( 'GET',  '/messages/{customer_id}', __( 'Full message history of one conversation ({id, from, body, timestamp}).', 'zymarg-vendor-dashboard' ) ),
		array( 'POST', '/messages',               __( 'Send a message. Body: {"customer_id": 123, "body": "text"}.', 'zymarg-vendor-dashboard' ) ),
		array( 'POST', '/devices/register',       __( 'Register this device for push. Body: {"token": "FCM-token", "platform": "android"}. Call after login + on token refresh.', 'zymarg-vendor-dashboard' ) ),
		array( 'POST', '/devices/unregister',     __( 'Remove a device token. Body: {"token": "FCM-token"}. Call on logout.', 'zymarg-vendor-dashboard' ) ),
	);

	$rows = '';
	foreach ( $endpoints as $e ) {
		$rows .= sprintf(
			'<tr><td><span class="zvd-api-method zvd-api-method--%1$s">%2$s</span></td><td><code>%3$s</code></td><td>%4$s</td></tr>',
			esc_attr( strtolower( $e[0] ) ),
			esc_html( $e[0] ),
			esc_html( $e[1] ),
			esc_html( $e[2] )
		);
	}

	$curl = 'curl -u "vendor@example.com:xxxx xxxx xxxx xxxx" \\' . "\n" . '  ' . $base . '/dashboard';

	$dart = <<<DART
// Flutter (Dart) — using package:http
import 'dart:convert';
import 'package:http/http.dart' as http;

class ZymargApi {
  final String base;          // e.g. https://your-site.com/wp-json/zymarg/v1
  final String appPassword;   // "user@email.com:xxxx xxxx xxxx xxxx"
  ZymargApi(this.base, this.appPassword);

  Map<String, String> get _headers => {
        'Authorization': 'Basic ' + base64Encode(utf8.encode(appPassword)),
        'Accept': 'application/json',
      };

  Future<Map<String, dynamic>> dashboard() async {
    final res = await http.get(Uri.parse('\$base/dashboard'), headers: _headers);
    final json = jsonDecode(res.body);
    return json['data'] as Map<String, dynamic>; // envelope: { data, meta }
  }

  Future<void> sendMessage(int customerId, String body) async {
    await http.post(
      Uri.parse('\$base/messages'),
      headers: {..._headers, 'Content-Type': 'application/json'},
      body: jsonEncode({'customer_id': customerId, 'body': body}),
    );
  }
}
DART;

	$envelope = <<<JSON
{
  "data": { /* the payload — shape is documented per endpoint */ },
  "meta": {
    "api_version": "1.0",
    "plugin_version": "1.24.0",
    "source": "wordpress",
    "currency": "BDT",
    "currency_symbol": "৳",
    "generated_at": "2026-07-06T12:00:00+00:00"
  }
}
JSON;

	ob_start();
	?>
	<div class="zvd-api" id="zymarg-vd-api-reference">
		<div class="zvd-api__head">
			<h2><?php esc_html_e( 'Developer API reference — build a native app', 'zymarg-vendor-dashboard' ); ?></h2>
			<p><?php esc_html_e( 'Everything an app developer needs to connect a native app (Flutter, React Native, native iOS/Android) or any external client. This API is a stable contract: code against the response shape, not the WordPress internals.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>

		<div class="zvd-api__block">
			<h3><?php esc_html_e( '1. Base URL', 'zymarg-vendor-dashboard' ); ?></h3>
			<pre class="zvd-api__code"><?php echo esc_html( $base ); ?></pre>
			<p class="zvd-api__note"><?php esc_html_e( 'All endpoints below are relative to this base. It updates automatically to your live site URL.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>

		<div class="zvd-api__block">
			<h3><?php esc_html_e( '2. Authentication — WordPress Application Passwords', 'zymarg-vendor-dashboard' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'The vendor logs into WP Admin -> Users -> Profile -> "Application Passwords".', 'zymarg-vendor-dashboard' ); ?></li>
				<li><?php esc_html_e( 'They enter a name (e.g. "ZYMARG App") and click Add. WordPress shows a password like: xxxx xxxx xxxx xxxx xxxx xxxx (copy it once).', 'zymarg-vendor-dashboard' ); ?></li>
				<li><?php esc_html_e( 'The app sends it as HTTP Basic auth: Authorization: Basic base64("email:app-password").', 'zymarg-vendor-dashboard' ); ?></li>
			</ol>
			<p class="zvd-api__note"><?php esc_html_e( 'No extra plugin needed (built into WordPress 5.6+). Requires HTTPS. Same-origin web clients can instead use cookie auth + the X-WP-Nonce header. Every endpoint requires a logged-in user who can view the vendor dashboard (a vendor, a granted staff member, or an admin).', 'zymarg-vendor-dashboard' ); ?></p>

			<p class="zvd-api__note"><strong><?php esc_html_e( 'One credential covers every API on this page.', 'zymarg-vendor-dashboard' ); ?></strong></p>
			<ul>
				<li><?php esc_html_e( 'The same Application Password created above authenticates all three APIs documented on this page: the custom zymarg/v1 endpoints, the WooCommerce wc/v3 endpoints (section 5), and the Dokan dokan/v1 endpoints (section 5) -- as long as the site is served over HTTPS.', 'zymarg-vendor-dashboard' ); ?></li>
				<li><?php esc_html_e( 'There is no separate ZYMARG API key to generate, and no need to create WooCommerce Consumer Key/Secret keys -- that is a different, older WooCommerce auth method. Application Passwords is a simpler, modern alternative that already works for wc/v3 and dokan/v1 too.', 'zymarg-vendor-dashboard' ); ?></li>
				<li><?php esc_html_e( 'The Firebase service-account JSON in section 8 is a separate, second credential. It is only for Firebase Cloud Messaging push notifications and is never used to authenticate any REST API call.', 'zymarg-vendor-dashboard' ); ?></li>
			</ul>
			<p class="zvd-api__note"><?php esc_html_e( 'In short, there are only two kinds of credentials a developer might ask for: one Application Password for every API call on this page, and the Firebase service-account JSON only if push notifications are wanted.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>

		<div class="zvd-api__block">
			<h3><?php esc_html_e( '3. Response envelope (every response)', 'zymarg-vendor-dashboard' ); ?></h3>
			<pre class="zvd-api__code"><?php echo esc_html( $envelope ); ?></pre>
			<p class="zvd-api__note"><?php esc_html_e( 'Read your data from response["data"]. The "meta" block is the backend-agnostic contract: meta.api_version + meta.source let the same app talk to a different backend later (e.g. a VPS) as long as it returns this same shape.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>

		<div class="zvd-api__block">
			<h3><?php esc_html_e( '4. Endpoints', 'zymarg-vendor-dashboard' ); ?></h3>
			<table class="zvd-api__table">
				<thead><tr><th><?php esc_html_e( 'Method', 'zymarg-vendor-dashboard' ); ?></th><th><?php esc_html_e( 'Path', 'zymarg-vendor-dashboard' ); ?></th><th><?php esc_html_e( 'Returns', 'zymarg-vendor-dashboard' ); ?></th></tr></thead>
				<tbody><?php echo $rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></tbody>
			</table>
		</div>

		<div class="zvd-api__block">
			<h3><?php esc_html_e( '5. Products, coupons, order status & withdrawals — use the existing REST APIs', 'zymarg-vendor-dashboard' ); ?></h3>
			<p class="zvd-api__note"><?php esc_html_e( 'Do not rebuild what WooCommerce and Dokan already expose. Use these battle-tested endpoints (same Application Password auth):', 'zymarg-vendor-dashboard' ); ?></p>
			<table class="zvd-api__table">
				<tbody>
					<tr><td><code><?php echo esc_html( $wc ); ?>/products</code></td><td><?php esc_html_e( 'List / create / update / delete products (filter by ?author={vendor_id}).', 'zymarg-vendor-dashboard' ); ?></td></tr>
					<tr><td><code><?php echo esc_html( $wc ); ?>/orders/{id}</code></td><td><?php esc_html_e( 'Change order status: PUT { "status": "processing" | "completed" | ... }.', 'zymarg-vendor-dashboard' ); ?></td></tr>
					<tr><td><code><?php echo esc_html( $wc ); ?>/coupons</code></td><td><?php esc_html_e( 'Create / list / delete coupons.', 'zymarg-vendor-dashboard' ); ?></td></tr>
					<tr><td><code><?php echo esc_html( $dok ); ?>/withdraw</code></td><td><?php esc_html_e( 'Vendor withdrawals / payout requests (when Dokan is active).', 'zymarg-vendor-dashboard' ); ?></td></tr>
					<tr><td><code><?php echo esc_html( $dok ); ?>/stores/{id}</code></td><td><?php esc_html_e( 'Store profile read / update (when Dokan is active).', 'zymarg-vendor-dashboard' ); ?></td></tr>
				</tbody>
			</table>
		</div>

		<div class="zvd-api__block">
			<h3><?php esc_html_e( '6. Example — cURL', 'zymarg-vendor-dashboard' ); ?></h3>
			<pre class="zvd-api__code"><?php echo esc_html( $curl ); ?></pre>
		</div>

		<div class="zvd-api__block">
			<h3><?php esc_html_e( '7. Example — Flutter (Dart)', 'zymarg-vendor-dashboard' ); ?></h3>
			<pre class="zvd-api__code"><?php echo esc_html( $dart ); ?></pre>
		</div>

		<div class="zvd-api__block">
			<h3><?php esc_html_e( '8. Push notifications (Firebase FCM) — A to Z', 'zymarg-vendor-dashboard' ); ?></h3>
			<p class="zvd-api__note"><?php esc_html_e( 'The full server side is BUILT-IN and ready. It uses Firebase Cloud Messaging HTTP v1 (service-account based — the current standard; the old "server key" API was retired by Google in 2024). Follow these steps once and pushes fire automatically for new orders, order-status changes, new buyer messages, low stock and announcements.', 'zymarg-vendor-dashboard' ); ?></p>

			<p class="zvd-api__note"><strong>A. <?php esc_html_e( 'Create a Firebase project', 'zymarg-vendor-dashboard' ); ?></strong></p>
			<ol>
				<li><?php esc_html_e( 'Go to console.firebase.google.com -> Add project (free Spark plan is fine).', 'zymarg-vendor-dashboard' ); ?></li>
				<li><?php esc_html_e( 'Inside the project, add an Android app (package name e.g. com.zymarg.seller) and, if you build for iPhone, an iOS app (bundle id).', 'zymarg-vendor-dashboard' ); ?></li>
			</ol>

			<p class="zvd-api__note"><strong>B. <?php esc_html_e( 'Get the service-account JSON (for the server)', 'zymarg-vendor-dashboard' ); ?></strong></p>
			<ol>
				<li><?php esc_html_e( 'Firebase Console -> Project Settings (gear) -> Service accounts tab.', 'zymarg-vendor-dashboard' ); ?></li>
				<li><?php esc_html_e( 'Click "Generate new private key" -> a .json file downloads. This is your server credential (keep it secret).', 'zymarg-vendor-dashboard' ); ?></li>
				<li><?php esc_html_e( 'Paste the whole JSON into WP Admin -> Vendor Hub -> Push Notifications, tick "Enable push", Save. (Or, more securely, define ZYMARG_FCM_SERVICE_ACCOUNT in wp-config.php as the JSON or a file path.)', 'zymarg-vendor-dashboard' ); ?></li>
				<li><?php esc_html_e( 'Click "Send test to my devices" to verify (register a device from the app first).', 'zymarg-vendor-dashboard' ); ?></li>
			</ol>

			<p class="zvd-api__note"><strong>C. <?php esc_html_e( 'Wire up the Flutter app', 'zymarg-vendor-dashboard' ); ?></strong></p>
			<ol>
				<li><?php esc_html_e( 'Android: download google-services.json (Project Settings -> your Android app) and place it in android/app/. Add the com.google.gms:google-services plugin to android/build.gradle + apply it in android/app/build.gradle. Set minSdkVersion 21.', 'zymarg-vendor-dashboard' ); ?></li>
				<li><?php esc_html_e( 'iOS: download GoogleService-Info.plist (a separate file) into ios/Runner/ via Xcode, and upload an APNs auth key in Firebase -> Cloud Messaging.', 'zymarg-vendor-dashboard' ); ?></li>
				<li><?php esc_html_e( 'Add packages: firebase_core + firebase_messaging to pubspec.yaml.', 'zymarg-vendor-dashboard' ); ?></li>
			</ol>
			<pre class="zvd-api__code"><?php echo esc_html( "// Flutter push wiring\nawait Firebase.initializeApp();\nfinal messaging = FirebaseMessaging.instance;\nawait messaging.requestPermission();\n\nfinal token = await messaging.getToken();\n// send token to YOUR plugin (Application Password auth as above):\nawait api.registerDevice(token, platform: 'android');\n\n// also re-send on refresh:\nmessaging.onTokenRefresh.listen((t) => api.registerDevice(t, platform: 'android'));\n\n// receive:\nFirebaseMessaging.onMessage.listen((m) => showLocal(m.notification));\nFirebaseMessaging.onMessageOpenedApp.listen((m) {\n  // route using m.data['screen'] and m.data['order_id'] / ['customer_id']\n});" ); ?></pre>

			<p class="zvd-api__note"><strong>D. <?php esc_html_e( 'The push payload the app receives', 'zymarg-vendor-dashboard' ); ?></strong> — <?php esc_html_e( 'notification {title, body} + a data map for routing:', 'zymarg-vendor-dashboard' ); ?></p>
			<pre class="zvd-api__code"><?php echo esc_html( "data: {\n  \"type\": \"new_order\" | \"order_status\" | \"new_message\" | \"low_stock\" | \"announcement\",\n  \"screen\": \"orders\" | \"messages\" | \"products\" | \"notifications\",\n  \"order_id\": \"123\",       // when relevant\n  \"customer_id\": \"45\",      // for messages\n  \"product_id\": \"67\"        // for low stock\n}" ); ?></pre>
		</div>

		<div class="zvd-api__block">
			<h3><?php esc_html_e( '9. Notes', 'zymarg-vendor-dashboard' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'All money values are numbers in the store currency (see meta.currency). Format them client-side.', 'zymarg-vendor-dashboard' ); ?></li>
				<li><?php esc_html_e( 'Dashboard/orders/earnings/analytics data is server-cached (60-120s) for speed; it self-heals and flushes on real changes.', 'zymarg-vendor-dashboard' ); ?></li>
				<li><?php esc_html_e( 'A Flutter mobile app does not need CORS. For a Flutter WEB build served from another domain, add Access-Control-Allow-Origin via the rest_pre_serve_request filter for your app origin.', 'zymarg-vendor-dashboard' ); ?></li>
				<li><?php esc_html_e( 'Push is OFF by default and a silent no-op until you paste the Firebase service-account JSON — safe to ship before you are ready.', 'zymarg-vendor-dashboard' ); ?></li>
			</ul>
		</div>
	</div>

	<?php
	return (string) ob_get_clean();
}
