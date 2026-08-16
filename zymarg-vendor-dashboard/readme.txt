=== ZYMARG Vendor Dashboard ===
Contributors: zymarg
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.46.14
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A custom, on-brand vendor "business operating system" for WooCommerce + Dokan marketplaces.

== Description ==

ZYMARG Vendor Dashboard replaces the stock vendor experience with a modern, app-like dashboard that takes over the vendor dashboard page (slug: dashboard) — or renders anywhere via the [zymarg_vendor_dashboard] shortcode.

Sections:

* Dashboard — warm greeting, Quick Actions, stat cards, 7-day revenue chart, latest orders, low stock, recent reviews.
* Products — card grid (not tables) with Edit, Feature, Hide, Duplicate and Delete.
* Orders — tabbed by lifecycle (Pending, Processing, Shipped, Delivered, Cancelled, Refunds).
* Earnings — Today / Week / Month, Available Balance, Withdrawn, Pending, 30-day trend.
* Analytics — Revenue, Orders, Visitors, Conversion + Top Products.
* Promotions — a native vendor coupon creator that works on Dokan Lite.
* Reviews — reply, hide, report, and filter reviews on your products.
* Messages — a Messenger-style buyer/vendor inbox.
* Customers — Recent / Repeat / Top buyers, with one-tap messaging.
* Payouts — a native withdrawal system that runs on Dokan Lite (no Dokan Pro). Vendors save a payout method (bKash, Nagad, Rocket or bank transfer), request a withdrawal of their available balance, and track its status. Admins approve / mark-paid / reject requests from Settings -> ZYMARG Payouts. Toggle it from Settings -> ZYMARG Vendor (the "Payouts" feature).

Requires WooCommerce. Dokan (Lite or Pro) is recommended for full multi-vendor data (per-vendor order amounts, store info, balances). Designed to pair with the ZYMARG OS theme for full branding, but works standalone on any theme via built-in fallback styling.

== Changelog ==

= 1.46.14 =
* Added: three new fields on the Premium "Limits and display" screen -- Columns: desktop / tablet / mobile (1 to 6 each, defaults 4/3/2). Only meaningful when Layout above is Grid, and shared between Flash Sale and Featured Items since both render on the same store page grid. Requires ZYMARG Store Page 1.24.2 or newer to actually apply on the front end -- older Store Page versions ignore these and keep rendering a flat 4-column grid, same as before.
* Added: a live pending-request count bubble on the "Vendor Hub" and "Premium" admin sidebar items, matching the same visual style WordPress core uses for its Plugins-update count and WooCommerce uses for its order count (an `.update-plugins`/`.plugin-count` bubble). Updates automatically while sitting on any admin page, with no page reload, by riding WordPress core's own Heartbeat API -- the same polling loop already running on every wp-admin screen for post-lock and autosave -- rather than adding a second independent polling loop. Ticks every 15-60 seconds depending on the site's own Heartbeat interval, matching the responsiveness of WordPress's and WooCommerce's own equivalents.

= 1.46.13 =
* Fix: the Support section's inline inbox sat inside a rounded, shadowed card with the section's 28px frame around it and a 1180px width cap, while the Messages section's inbox ran edge to edge and full width. The two never matched, and neither did the seller Support surface versus the buyer's My Account Support panel.
* Cause: `zymarg_vd_support_inbox_html()` called `[zymarg_support_chat]` and then regex-injected `.is-host-chrome` into the returned markup. Every edge-to-edge rule the Communication plugin ships is keyed `:not(.is-host-chrome)`, so injecting it made all of them stand down — permanently, on the seller side only.
* Cause, one layer deeper: that injection was a workaround for the Communication plugin's own `.zymarg-vendor-main:has(.zymarg-inbox:not(.is-host-chrome)){padding:0}`, which matched on DOM PRESENCE. This section's inbox ships `hidden`, so that rule stripped the whole Support section's frame on first paint with nothing clicked. The workaround was aimed at the right symptom but the wrong layer.
* That rule is now scoped at source, in ZYMARG Communication 1.32.11: it targets the Messages variant (`.zymarg-inbox--vendor`) only, and the support surface is governed by rules gated on `.is-support-live`, a class its support.js sets solely while the inbox is genuinely on screen. So the frame is no longer stripped on first paint and there is nothing left to opt out of.
* The injection also overrode the site's own "Match host page heading style" setting (`surface.host_native_chrome`), which SupportChat has read for itself since Communication 1.32.9 — pinning the seller inbox into card mode regardless of the toggle while the buyer inbox followed it. With the injection gone that setting is authoritative on both sides: off = edge to edge on both, on = a card on both.
* REQUIRES ZYMARG Communication 1.32.11 or newer for the fix to take effect. On older Communication builds the injection is kept exactly as it was, because there the first-paint bug it was written for is still real. No behaviour change on those installs.
* Note: this section's inbox continues to hide the conversation list, filters and search and render as a single-column thread. That is deliberate — the seller has exactly one admin thread — and is unrelated to the framing fix above.

= 1.46.12 =
* Change: the "Section Name" `<h1>` at the top of every native section (Support, Orders, Products, Earnings, Analytics, Promotions, Reviews, Messages, Customers, Followers, Notifications, plus the add-on sections Payouts, Store Settings, Shipping & SEO, Refunds, Staff, Settings) is no longer visible. The sidebar's active state already indicates the section, and each section's own subtitle already names it in context, so the redundant h1 read as visual noise on every panel.
* Note: hidden visually with the standard `.screen-reader-text` clip pattern rather than removed from the HTML. The heading is still in the DOM, still announced by screen readers, and still contributes to the page outline and search-engine outline — so a11y and SEO are unaffected. Reverting to the visible heading is a single-line CSS change if you ever want it back.
* Note: the Dashboard section is deliberately excluded. Its greeting reads "Good morning, {first}" with the timezone-aware greeting span and the waving-hand emoji — a personal welcome, not a section name. The Dashboard header carries a new `.zymarg-vendor-greeting--personal` modifier class, and the CSS rule that hides every other section's h1 explicitly excludes any greeting carrying it. That greeting stays fully visible.
* Note: nothing else changes. Every section's subtitle stays visible as the first thing the sighted vendor reads, quick actions, badges, tabs and the "row"-variant greeting metadata (Products, Earnings) are all untouched. Paired with theme 5.16.10, which lands the same treatment on the buyer's My Account panels.

= 1.46.11 =
* Fix: on 375px viewports the Support section's "Get help with your orders, account, or any issues." subtitle wrapped to two lines while the same copy on the theme's My Account Support panel stayed on one. The shared `.zymarg-vendor-greeting__sub` runs at `.98rem` (15.68px), and at that size the sentence overshoots the mobile column by two words; the theme's `.panel-desc` uses 14px and fits. The subtitle is now 14px on the Support section only, scoped through a new `.zvd-support-greeting` marker on this section's header. Every other section (Dashboard, Orders, Earnings, Analytics, Promotions, Reviews, Messages, Customers, Followers, Notifications, Payouts, Store Settings, Shipping & SEO, Settings, Staff) keeps its subtitle as it was — those aren't affected and don't need to be.
* Change: the tile title moves from 13px to 14px so "Contact Support" doesn't sit dwarfed by the 38x38 icon square next to it. Paired with theme 5.16.9 which lands the same bump on `.action-card .ac-label`, so the two surfaces stay identical.
* Change: the tile SVG now renders at `stroke-width: 2` instead of 1.9. `zymarg_os_vendor_icon()` sets 1.9 on every icon (correct for the plugin's own sidebar and stat-card icons), while the theme's `zymarg_os_account_icon()` sets 2 for its Support tile. Overridden via CSS on `.zvd-support-tile__icon svg` only, so every other icon in the plugin still renders at the plugin's default weight.

= 1.46.10 =
* Change: The Support section adopts the compact tile design from the theme's My Account -> Support panel — a buyer viewing their support surface and a vendor viewing theirs now read identically. The tiles float directly under the greeting; the "How can we help?" wrapping card is gone, matching how every other action-card surface on the marketplace is laid out.
* Change: Each tile now uses the theme's action-card scale (38x38 icon square, 13px title, 12px radius, 1.25rem padding), a three-column grid on wide screens (two columns from 480px, one column below), and the same hover-lift on primary as the buyer surface. Namespace stays `.zvd-support-*` — the plugin owns its copy of the design, so the layout holds on any theme, and on ZYMARG OS the two panels render byte-identically because the tokens match.
* Change: The "Chat or email our team" subtitle under Contact Support is removed on this side too, matching the theme's 5.16.8 change. Help Center keeps its "FAQs and guides" subtitle because it disambiguates a card that isn't otherwise self-explanatory.
* Note: The compact tiles inherit `text-align: start`, which is the same fix the theme applied to `.action-card` in 5.16.8 so a `<button>` and an `<a>` sharing this shape render their text identically. The Contact tile is a button and the Help tile is an anchor — without this, the button label would sit centred while the anchor sat left, exactly the bug the theme's 5.16.8 fixed on My Account.
* Note: The "off" state ("Support is currently off"), the inline inbox card, and the "install the Communication plugin" notice all keep their `.zymarg-vendor-card` shell — those are section-scale panels, not action tiles, so they stay on the dashboard's card scale.

= 1.46.9 =
* Fix: The Support section had no gap at the top or the sides. Its heading sat flush against the sidebar and its card overflowed the right edge, while every other section kept the usual 28px frame. Support now frames identically to Orders, Earnings and the rest.
* Note: The cause was outside this plugin. The ZYMARG Communication plugin zeroes `.zymarg-vendor-main`'s padding so the *Messages* inbox can run edge to edge, but that selector matches a `.zymarg-inbox` found ANYWHERE in the column rather than only one that is a direct child. Our Support inbox is deliberately nested inside a card, so it tripped a rule written for a different surface.
* Note: It applied before anything was clicked. The Support inbox ships `hidden`, but `:has()` matches on a node's presence in the DOM, not on whether it renders — so the section was unframed from first paint.
* Note: Fixed from this side only, by tagging the inbox with `.is-host-chrome` — the opt-out the Communication plugin documents for a host that draws its own heading and card, which is exactly what the Support section does. The Communication plugin is not modified, and the Messages section is untouched.
* Note: This is why 1.46.6 through 1.46.8 did not fix it. Those releases adjusted card margins and the section's wrapper markup; the section's own markup was already correct and the padding was being removed a layer above it.
* Fix: "Contact Support" did nothing when Support was opened from the sidebar. Its click handler was inlined into the section's HTML, and the in-place section swap assigns `innerHTML`, which does not execute `<script>` tags — so the handler only ever ran on a full page reload. It now lives in `vendor-dashboard.js` and re-binds on every swap.
* Note: The inline inbox drops its own border, radius and shadow now that it keeps them, so the card around it reads as one surface instead of a double-walled box, and its height closes on the same gutter the rest of the dashboard ends on.

= 1.46.1 =
* Fix: The dashboard's Quick Action for products was labelled "Product", but it never opened the product list — it opened the *new product* form. It now reads "Add Product", the same wording the Products screen header already used for the identical link.
* Change: The "Coupon" Quick Action was removed. It pointed at exactly the same promotions section URL as the "Promotion" action next to it, so the row carried two buttons to one destination. Coupon creation is unaffected — the "Create coupon" form still lives in Promotions, which "Promotion" opens.
* Change: The native product editor no longer shows "Sale start date" and "Sale end date". Vendors set a sale price; scheduling a campaign window was out of place on this form.
* Note: Removing those two fields does not clear existing schedules. The editor deliberately no longer calls WooCommerce's sale-date setters at all, so a window set in wp-admin survives a vendor save untouched — had the setters been left in place with the fields gone, every save would have silently wiped it.
* Note: No behaviour outside these three surfaces changed. The `plus-ticket` icon, the Promotions coupon form, and `zymarg_os_vendor_new_product_url()` (including its filter, which the native editor uses to retarget the link) are all as they were.

= 1.46.0 =
* New: The vendor sidebar menu order is now yours to set. Settings -> ZYMARG Vendor gained a "Sidebar menu order" panel where you drag items into any order — or move them with arrow buttons, which also work by keyboard and on touch. "Reset to default order" appears once a custom order is saved.
* New: `zymarg_vd_nav_order()` returns the saved order as a `key => weight` map, and `zymarg_vd_apply_nav_order()` applies it on the `zymarg_os_vendor_nav_items` filter at priority 30 — after every hook that inserts into the nav, so it sorts the final item set.
* Note: The order is stored as a sparse weight map, never as a positional list. The sort only ever reorders — it cannot add or drop an item, so no future menu entry (from this plugin or any other) can be silently hidden from vendors by a saved order.
* Note: Menu position carries no functional meaning. Routing is by `?vsection=`, feature toggles and staff permissions are keyed by name, and the dashboard's in-page navigation binds by `data-section`. Reordering cannot affect access control, routing or gating.
* Note: Only features that are currently switched on are listed. Switching one back on later restores it at its default position rather than at a remembered one.
* Note: With no custom order saved this layer is inert and the sidebar is identical to 1.45.7.

= 1.45.7 =
* Fix: The Reviews screen loaded a maximum of 40 reviews and stopped there, with no way to reach anything older. Any seller with a real history simply could not see — or reply to — their earlier buyers. The list is now paginated with prev/next links and reports "Page X of Y · N reviews".
* Fix: The activity feed announced an unrated review as "New 5-star review". A review left without a star rating is now announced as "New review", so a seller is never told they received top marks nobody gave them.
* New: `zymarg_os_vendor_reviews_count()` and `zymarg_os_vendor_reviews_query_args()`. The list and its count are built from one shared query, so they cannot disagree about which reviews exist.
* Change: `zymarg_os_vendor_reviews_data()` now accepts `$page` and `$per_page`. Both are optional, so existing calls keep working.
* Change: The `zymarg_os_vendor_reviews_limit` filter now sets the page size rather than a hard ceiling on the whole list.
* Note: The star filter buttons act on the reviews currently on screen, i.e. the current page.

= 1.45.6 =
* Fix: Seller replies were inserted without `comment_type => 'review'`, so WooCommerce and the ZYMARG Reviews Engine both filtered them out of the buyer-facing review list. Vendors were replying into a void. Replies now carry the correct comment type and appear under the review they answer.
* Fix: Replies now store a `_zymarg_store_reply` meta flag, which is what the Reviews Engine reads to badge a reply as coming from the store owner.
* Fix: The "Reported" filter on the Reviews screen read a `_zymarg_reported` meta key that nothing in the codebase ever writes, so the filter always returned an empty list. It now reads `_zymarg_report_count`, the key the Reports module actually maintains.

= 1.45.5 =
* Fix: Publishing an announcement no longer reloads the admin page. The AJAX request already succeeded, but the success handler then called `window.location.reload()`, throwing away the single-page admin the rest of the hub works hard to maintain. The new announcement row is now inserted straight into the list and the form resets in place.
* Fix: The announcements screen never re-initialised after an admin SPA content swap. `admin-announcements.js` listened for `zymarg-vd:section-loaded`, which is the *frontend* vendor dashboard event and is never fired anywhere in wp-admin. The admin router calls `window.ZymargAnnouncementsInit` and broadcasts `zymarg:contentSwapped`; the screen now binds to both.
* Change: Announcement row markup moved into a shared `zymarg_vd_render_announcement_row()` helper, used by both the initial page render and the create-announcement AJAX response, so the two can no longer drift apart.
* Change: Vendor dashboard navigation — Notifications moved from second-from-last to second position, directly under Dashboard.
* Housekeeping: `Stable tag` in readme.txt was stuck at 1.34.0 while the plugin header had reached 1.45.4; both now track the same number.
* Fix: Plugin folder renamed from `ZYMARG_Vendor_Dashboard_1_39_0` to `zymarg-vendor-dashboard` (no version suffix). The old name was both stale and version-stamped, which meant WordPress treated each upload as a brand-new plugin instead of an in-place update — leaving two copies active and risking fatal class/function redeclaration. All paths derive from `__FILE__`, so nothing internal depended on the folder name.

= 1.34.0 =
* Phase 4 — Buyer reply email notifications: when a vendor sends a message to a buyer, the buyer receives a branded ZYMARG email with a message preview and a direct link to their inbox. Fully filterable — use `zymarg_vd_buyer_reply_email_body` to supply a custom HTML template, and `zymarg_vd_buyer_reply_email_subject` to customise the subject. Master toggle on the Push Notifications admin page (ON by default). Buyers can opt out individually via a one-click unsubscribe link in every email. New file: `includes/buyer-email-notify.php`.
* Phase 5 — Push notification to buyer on vendor reply: mirrors the existing vendor push logic but in reverse — when the vendor is the message author, the buyer's registered devices receive a push via Firebase FCM HTTP v1. Respects the admin `new_message` event toggle. Added `zymarg_vd_push_on_vendor_reply()` in `push-notifications.php`.

= 1.33.8 =
* FIX (hardening): wrapped the Tier 3a Insight Engine call in try/catch as defense-in-depth. Even though the engine is now guaranteed exception-safe on its side (Automation v3.3.1), a fatal in this exact code path previously took the site down, so the consumer now also catches any Throwable and falls through silently to Tier 3b/1/2. Belt and suspenders.

= 1.33.6 =
* FEAT: Wire Vendor Dashboard to ZYMARG Insight Engine (Phase 2). Tier 3a now calls zymarg_auto_generate_insight() via function_exists() guard -- zero dependency if Automation is inactive. Fallback chain: Tier 3a (local engine) -> Tier 3b (LLM API) -> Tier 1 (priority ladder) -> Tier 2 (trend patterns) -> time-of-day pool. Nothing breaks with or without Automation active.

= 1.33.5 =
* FIX: Fatal parse error on line 4832 — curly quote characters (201c201d) inside double-quoted PHP strings terminated the string early causing "unexpected identifier" syntax error. Replaced all curly quotes in subtitle strings with straight ASCII quotes. Full php -l syntax check passes on all plugin PHP files.

= 1.33.4 =
* FIX: Bump version to 1.33.4 — corrects two hotfixes that were silently pushed under the same v1.33.2 filename (making it impossible to identify the latest zip by name alone). Going forward every change gets a new version number without exception.

= 1.33.3 =
* FIX: Rebuilt zip with correct WordPress-expected structure — plugin folder at zip root (zymarg-vendor-dashboard/) with no nested extracted_v* prefix wrapper. Previous zip caused "No valid plugins were found" on WordPress plugin installer.

= 1.33.2 =
* NEW: 3-tier AI-powered dashboard greeting subtitle (replaces the old random motivational strings).
  - Tier 1 — Priority ladder: deterministic, no randomness. Highest-urgency signal always wins
    (pending orders count, rating drop below 4.0, negative sales delta, low-stock product name,
    positive sales delta with real %, no orders by afternoon). Vendors always see the single most
    relevant thing — never a lucky coin flip.
  - Tier 2 — 7-day trend pattern detector: analyses the revenue_series data already loaded on the
    dashboard (no extra queries). Detects multi-day growth streaks, "sales are back" moments after
    dry spells, best-day-of-the-week records, sharp dips after strong stretches, and first-sale-of-day
    celebrations. All messages reference actual ৳ figures and real patterns.
  - Tier 3 — LLM-generated insight (OpenAI GPT-4o-mini or Anthropic Claude Haiku): passes a live
    vendor snapshot (today_sales, today_orders, sales_delta, pending_orders, low_stock, rating,
    7-day revenue trend, hour of day, currency ৳) to the AI with a strict 12-word limit and a prompt
    that forbids generic phrases. Result is cached per vendor for 1 hour via transient. Falls back to
    Tier 1/2 silently on any API failure — zero vendor-facing errors ever.
* NEW: AI Greeting Intelligence settings panel in ZYMARG Vendor Dashboard Settings page. Controls:
    enable/disable toggle, provider dropdown (OpenAI / Anthropic), API key field (password input with
    show/hide toggle), model override field (blank = recommended default). Status indicator shows
    green (active), amber (enabled but no key), or grey (off). Saving the AI settings automatically
    busts all per-vendor AI subtitle caches.
* The subtitle function signature gains an optional $vendor_id parameter for cache keying —
  fully backwards compatible (defaults to current user).

= 1.33.1 =
* FIX: sidebar store card rendered "View store" above the vendor's store name, contrary to the intended reading order. Root cause was two leftover `transform: translateY()` rules on `.zymarg-vendor-store__name` (+20px) and `.zymarg-vendor-store__link` (-12px) that visually swapped the two lines without changing the underlying markup order. Removed both transforms; the store name now displays first, with "View store" and the verification status line below it, matching source (DOM) order.

= 1.33.0 =
* PRIVACY FIX: removed the "Public phone" and "Show my email address on the store page" fields from Section 5 "Store Profile." Both wrote directly into Dokan's own store-header template, which prints a vendor's real phone number and email address in front of any customer. This marketplace's policy is that customers never see a vendor's raw contact details — buyers reach vendors exclusively through the built-in Contact Seller messaging feature, which never exposes either. A one-time cleanup routine force-clears any phone/show_email value a vendor had already saved (e.g. during this plugin's pre-launch trial) so nothing already saved keeps leaking after this version installs — every other Store Profile field (store name, address, banner, vacation mode) is left completely untouched by the cleanup.
* FIX: the "Store banner" uploader was, in the previous version, wired to WordPress's admin Media Library picker (wp.media()) — the wrong tool entirely for a vendor-facing control, and one that doesn't function as a usable picker on mobile. It's replaced with the SAME mobile-friendly crop + adaptive-compress + camera/gallery uploader that already powers the sidebar avatar photo, generalized to a second "banner" target. Uploads now happen instantly (tap the banner → pick/crop → done — no separate Save click needed).
* Redesigned the banner control's visuals from a raw floating `<img>` with buttons above it into a proper card component: a 4:1 aspect-ratio box with a dashed empty state, a "Change banner" hover overlay once an image is set, and a Remove pill button — consistent with the rest of the dashboard's card language.
* New AJAX target-branching: `zymarg_vd_upload_store_image_ajax()` / `zymarg_vd_remove_store_image_ajax()` now take a `target` of `avatar` or `banner`, each with its own independent user-meta cache and old-attachment cleanup, so the two images can never interfere with each other.

= 1.32.0 =
* REMOVED: the standalone "Store Settings" sidebar page. Its "Social links" block duplicated Settings Section 7 (harmlessly for Facebook/Instagram/Twitter/YouTube, but its separate WhatsApp field disagreed silently with Section 7's — two meta keys, no sync). The rest of the page — store name, public phone, email visibility, banner, address, and Vacation mode — had no duplicate anywhere, so it was folded into a brand-new Settings Section 5 "Store Profile" rather than deleted outright. Zero data migration was needed: the new section reads/writes the exact same `dokan_profile_settings` keys the old page did.
* FIX: Danger Zone's own "Deactivate Store" vacation toggle wrote a typo'd meta key (`setting_go_vocation`) that was silently disconnected from the real storefront vacation effects (the away-notice and the optional Add-to-cart pause), which read the correctly-spelled `setting_go_vacation`. That toggle looked functional but did nothing to the actual storefront. Removed it; Vacation mode now lives exclusively in the new Section 5 "Store Profile," using the correct key, so the control vendors see is the one that actually works.
* Section 5 "Store Profile" also upgrades the banner picker from a raw file upload to a WordPress media-library picker (same UX as Section 7's social-share image), while storing the exact same attachment-ID data.
* Settings page is now 11 sections (was 10): 1 Account, 2 Change Password, 3 Notification Preferences, 4 Store Preferences, 5 Store Profile (NEW), 6 Tax & Business Info, 7 SEO & Store Meta, 8 Social Links, 9 Data Export, 10 Danger Zone, 11 Login & Security. New AJAX action: `zymarg_vd_settings_save_store_profile`.

= 1.31.0 =
* REMOVED: Settings Section 11 "Push Notification Opt-in." It duplicated the Push column already in Section 3's Notification Preferences grid, and the two disagreed silently because they wrote to two different user_meta keys (`_zymarg_vd_push_prefs` vs `_zymarg_vd_notification_prefs`) that never synced. Section 3's Push toggle is now the single source of truth for a vendor's push preference. `zymarg_vd_push_event_on()` (the function every real push notification actually checks) was rewired to read from Section 3's data instead. Any vendor who had already saved a Section 11 preference has it migrated automatically into Section 3 on first load (their choice is preserved, not reset). The Settings page now has 10 sections instead of 11; numbering of Sections 4-10 is unchanged.

= 1.30.2 =
* FIX: Section 10 (Login & Security) — removed all broken My Account links (vendors are marketplace sellers, not WC account holders). Replaced intro text with accurate description. Fixed passkeys empty state to be informational only. Added Revoke button to Active Sessions table (most-recently-used session is marked "Current"; all others show Revoke). Added Remove button to Passkeys table. Both buttons remove their row on success with no page reload. New AJAX endpoints: zymarg_vd_settings_revoke_session and zymarg_vd_settings_remove_passkey, both gated with zymarg_vendor_action nonce and constrained to the current user's own data.

= 1.30.1 =
* FIX: Critical — renamed admin-hub AJAX action `wp_ajax_zymarg_vd_load_section` → `wp_ajax_zymarg_vd_hub_load_section` to stop it from colliding with the frontend SPA endpoint. The admin-hub handler (admin-only, requires `manage_options`) was registered on the same hook as the vendor SPA handler and loaded first, causing WordPress to call it first and die() with a 403 before the vendor handler could run. The SPA navigation was silently broken for all vendors. Updated admin-hub.js to POST the new action name. No other code changes — audit confirmed all CSS "duplicates" were valid intentional cascade patterns, not real bugs.

= 1.30.0 =
* NEW: Vendor Settings Phase B — final four accordion sections now live. Section 8 Data Export streams vendor-scoped Orders / Customers / Products CSVs via admin-post.php (60-second per-type rate limit; filename includes store slug + date). Section 9 Danger Zone bundles three escalating destructive actions — Deactivate Store (soft, Dokan vacation mode toggle), Close Store Permanently (typed-store-name confirm + admin email), Delete Account (7-day WP-Cron scheduled deletion with "DELETE MY ACCOUNT" typed confirm and Cancel button until it runs). Section 10 Login & Security is a read-only bridge into the ZYMARG Login & Security plugin — active sessions, recent sign-in events, and passkeys tables shown; graceful empty state when ZLS is inactive. Section 11 Push Notification Opt-in writes to _zymarg_vd_push_prefs user_meta consumed by zymarg_vd_push_event_on(); admin-globally-off events show a disabled tooltip; test-push button when Firebase is configured and a device is registered. All 11 Settings sections now shipped.

= 1.29.0 =
* NEW: Vendor Settings Sections 4-7 now live — Store Preferences (auto-accept orders, minimum order value with BDT prefix, default order notes template), Tax & Business Info (BD BIN/TIN, business name, trade license, address; kept private), SEO & Store Meta (title/description with live char counters, OG image via media library, OG title/description overrides; SEO tags emitted on the vendor store page including <title> override), Social Links (Facebook/Instagram/Twitter/YouTube saved into Dokan's social array + WhatsApp/TikTok as native fields; WhatsApp gets a wa.me test link). Sections 8-11 still pending — coming in the next release.

= 1.28.1 =
* Notification Preferences: add SMS channel toggle (opt-in, defaults OFF). Preferences save now; live SMS delivery pending future SMS gateway integration. Mobile view stacks each event as a mini-card with labeled Email/Push/SMS rows.

= 1.28.0 =
* NEW: Full native Settings page (Phase 1 of 3) -- collapsible accordion layout replaces the old 4-card hub. Ships Account, Change Password, and Notification Preferences sections. Sections 4-11 placeholder cards ready for Phase 2 & 3.

= 1.25.3 =
* Mobile fix (Orders): tabs now display as a 2-column grid on phones (3 rows); order row cards wrap so the status badge, View, Approve and Cancel buttons all sit on one line. Moved the status badge into the buttons container for cleaner mobile layout.

= 1.25.2 =
* Mobile fix: shrink icon size and spacing on the Earnings section's 6-card stat grid (2-column mobile layout) so labels like "Available Balance" and "Pending Withdrawal" no longer look cramped against the icon on phone screens.

= 1.25.1 =
* Docs: Clarified the Developer API reference on the D-Instruction page. Added a note right after "Authentication -- WordPress Application Passwords" explaining that a single Application Password authenticates all three APIs referenced on the page (zymarg/v1, wc/v3, dokan/v1) -- there is no separate ZYMARG API key and no need for WooCommerce Consumer Key/Secret. The Firebase service-account JSON (section 8) is a separate, second credential used only for push notifications, never for REST API auth.

= 1.23.0 =
* Fix: On a full page load of the vendor dashboard, Dokan Lite's default dashboard-home scripts (the customizable/draggable widget grid — customizable-dashboard.js, plus dashboard.js, dashboard-charts.js, store-performance.js and the reports bundle) were repositioning OUR custom shell's cards into a broken masonry/gridstack layout (Store Settings and all other sections). On SPA navigation these scripts didn't re-run, so the layout stayed correct. Since our plugin replaces Dokan's dashboard entirely, those scripts serve no purpose on our takeover page.
* We now dequeue Dokan's dashboard-home scripts (and the reports style) at wp_enqueue_scripts priority 100, but ONLY on our vendor-dashboard takeover page (guarded by zymarg_os_is_vendor_dashboard()). Dokan's own pages (product edit, withdraw, etc.) are untouched. Assets are matched by src path (not handle) so it survives Dokan handle renames. The path-fragment list is filterable via `zymarg_vd_dokan_dequeue_paths`.

= 1.22.1 =
* Added feature toggles for Verification badges and Announcements (Settings -> ZYMARG Vendor). Admin can now disable these independently.
* Updated the D-Instruction (Help) page with documentation cards for ALL new features: SPA navigation, variable products, per-vendor commission, verification badges, announcements, auto-disbursement, and staff accounts.

= 1.22.0 =
* Fix (security/correctness): staff permission gate is now enforced for ALL sections, not just add-on ones. Previously a staff member could reach a native section (Earnings, Analytics, Products, etc.) they weren't granted by typing the URL or via SPA navigation — the permission check only covered add-on sections and ran against the swapped vendor user. The gate now runs at the single render entry point (zymarg_vd_render_section_content, used by both full-page loads and SPA AJAX) against the real logged-in staff user, before the vendor-swap. New helper zymarg_vd_staff_section_allowed() is the single source of truth; product-edit follows the "products" permission.

= 1.21.0 =
* New: Vendor Staff Accounts. Vendors can add staff members who get their own login and access specific sections of the dashboard based on assigned permissions.
* New custom role 'zymarg_vendor_staff' with only 'read' capability. All access is gated by a plugin-level permission system (7 keys: products, orders, earnings, messages, reviews, promotions, analytics).
* New vendor-facing "Staff" section in the sidebar: add staff (first/last name, email, password, permission checkboxes), edit permissions per member, remove staff (safely changes role to subscriber).
* Staff login experience: staff log in with their own email/password, are redirected to /dashboard/, see the vendor dashboard shell with only sections they have permission for. The sidebar shows the vendor's store name with a "Staff: {first_name}" label.
* Staff see the vendor's data (products, orders, earnings, etc.) -- all data queries use the staff's linked vendor_id, not the staff's own user_id.
* Staff cannot access: payouts, store-settings, shipping, notifications, settings. Staff cannot manage other staff or escalate permissions.
* Staff are blocked from wp-admin (redirected to dashboard) and the admin bar is hidden.
* Three AJAX endpoints: zymarg_vd_add_staff, zymarg_vd_update_staff_permissions, zymarg_vd_remove_staff. All require the actual vendor (not staff) as requester and verify ownership.
* Feature toggle: 'staff' added to the feature registry so admins can disable staff accounts marketplace-wide.
* Nav item: 'Staff' appears between 'Customers' and 'Shipping', only visible to actual vendors (not staff).
* Security: only the vendor who owns the staff can manage them; staff cannot access the Staff section or any always-blocked sections regardless of permission array.

= 1.20.0 =
* New: Auto-Disbursement (Scheduled Payouts). Admins can enable automatic payout creation for eligible vendors on a weekly, biweekly, or monthly schedule.
* New admin setting card on the Payouts page: enable/disable toggle, frequency selector, minimum balance override, "Run now" button for manual trigger.
* Cron job processes all vendors with role 'seller' or 'vendor': checks balance >= minimum, verifies saved payout method, skips vendors with existing pending/approved requests, then creates auto-approved payout posts.
* Auto-generated payouts skip the manual request + admin approval steps -- admin still transfers money manually (bKash/bank) and marks as Paid.
* AJAX endpoints: zymarg_vd_save_auto_disbursement (save settings + reschedule) and zymarg_vd_run_auto_disbursement (manual trigger). Both require manage_options + nonce.
* Last run summary stored in option (timestamp, count, total amount, skipped with reasons). Displayed on admin Payouts page.
* Vendor-facing: auto-generated payouts show an "Auto" badge in the withdrawal history to distinguish from manual requests.
* Settings stored in option 'zymarg_vd_auto_disbursement' (array). Cron hook: zymarg_vd_auto_disbursement_run.

= 1.19.0 =
* New: Vendor Announcement System. Admins can broadcast messages to all vendors or specific vendors from a new "Announcements" page in the Vendor Hub.
* New admin page: Announcements submenu under Vendor Hub with AJAX-powered create/deactivate/delete operations.
* Announcements stored as a custom post type (zymarg_vd_announce) with target (all/specific vendor IDs) and status (active/expired) meta.
* Vendor-facing: Active announcements appear as premium styled cards at the top of the Notifications section, with gradient accent bar and "NEW" badge for unread items.
* "Mark as read" per vendor (stored in user_meta _zymarg_vd_read_announcements). Once marked, the card loses its "NEW" badge.
* Notification dot: The Notifications nav item in the vendor sidebar shows a subtle purple pulsing dot when there are unread announcements.
* Security: All admin endpoints require manage_options; vendor mark-as-read requires logged-in vendor role.
* CPT registered on init (not admin-only) so vendors can query announcements on the frontend. Set public=false, show_ui=false (no WP admin UI).

= 1.18.0 =
* New: Vendor Verification Badges. Admins can mark vendors as "ID Verified" or "Fully Verified" from the Commission page.
* Verification level stored as user_meta (_zymarg_vd_verification_level: '', 'id', 'full') with an optional admin note (_zymarg_vd_verification_note).
* Badge display: colored circle with SVG checkmark -- blue (#2196F3) for ID Verified, purple (#9500A5) for Fully Verified. Tooltip shows the level on hover.
* Badge appears on: vendor dashboard sidebar (next to store name), Dokan store page header (dokan_store_header_info_fields hook), and WooCommerce product cards (after shop loop item title).
* Vendor-facing: vendors see their own verification status in the sidebar -- "Fully Verified" / "ID Verified" badge or "Not yet verified" hint.
* New PHP API: zymarg_vd_is_vendor_verified($user_id) returns 'full'|'id'|''; zymarg_vd_verification_badge($user_id, $size) returns badge HTML.
* Security: only admins (manage_options) can set verification level; saves use the existing nonce-protected commission AJAX endpoint.

= 1.17.0 =
* New: Variable product support (Stage 2 - Variations). After saving a variable product with attributes, a Variations card appears in the product editor.
* "Generate variations" button creates WC_Product_Variation posts for every combination of attributes marked "Used for variations". Only missing combinations are created (no duplicates).
* Each variation row shows: attribute combination label, regular price, sale price, SKU, manage-stock checkbox with quantity field, enabled toggle, and a remove button.
* "Save variations" button saves all variation data in one AJAX call using WooCommerce's WC_Meta_Box_Product_Data::save_variations() for maximum compatibility (with manual fallback).
* Individual variations can be removed with the x button (confirm dialog, animated removal).
* Stock quantity field visibility toggles per variation based on the manage-stock checkbox.
* After saving variations, WC_Product_Variable::sync() updates the parent product's price range.
* All three AJAX endpoints (generate, save, delete) enforce nonce verification, login check, and product ownership.
* New JS file: product-variations.js (vanilla JS, event-delegated, SPA-compatible).
* Responsive: variation fields stack on mobile.

= 1.16.0 =
* New: Variable product support (Stage 1 - Attributes). The product editor now has a "Product type" dropdown (Simple / Variable). Selecting "Variable" hides the Pricing card and shows an Attributes card.
* Attributes card: add custom text attributes (name + pipe-separated values), or pick from existing WooCommerce global attributes (pa_color, pa_size, etc.) with term selection checkboxes.
* Each attribute has a "Used for variations" checkbox for future Stage 2 variation generation.
* Save handler: creates WC_Product_Variable for variable type, saves _product_attributes meta in WooCommerce's expected format, sets taxonomy terms via wp_set_object_terms.
* Type switching: switching between Simple and Variable is fully supported. Existing variations are preserved when switching back to Simple.
* Backwards-compatible: existing simple products without the product_type field continue working exactly as before.

= 1.15.1 =
* Rename: admin submenu "Vendors" renamed to "Commission" — clearer purpose, and will house all commission-related features (per-vendor now, per-product later).

= 1.15.0 =
* New: Admin Vendors page under Vendor Hub. Lists all vendor accounts (seller/vendor role) in branded cards with per-vendor commission override.
* Commission types: Use Global Default (deletes meta so Dokan falls back), Percentage, Flat, or Combine (% + flat).
* AJAX save per vendor card with inline success/error feedback, nonce and capability protection.
* Writes to Dokan Lite's native user_meta keys (dokan_admin_percentage, dokan_admin_percentage_type, dokan_admin_additional_fee) so commission changes take effect immediately with no additional hooks.
* Integrated into the Vendor Hub AJAX navigation (SPA section swap).

= 1.14.1 =
* Branding: SPA section-loading state now shows the ZYMARG Discovery Spark (xl, 48px) instead of a generic spinner. Falls back to the spinner if the theme doesn't provide the spark function.

= 1.14.0 =
* Performance (big one): the vendor dashboard now uses in-place SPA navigation. Clicking a sidebar section (Dashboard, Products, Orders, Earnings, Analytics, Promotions, Reviews, Messages, Customers, Notifications, Payments, Store Settings, Shipping, Settings) swaps ONLY the content area over AJAX instead of doing a full page reload. This skips the WordPress + WooCommerce + Dokan + Elementor re-boot on every click — section switches drop from ~10s to ~1-2s. Matches how the theme's My Account page already works.
* The first visit to /dashboard/ still does a normal full load (unavoidable stack boot); every section click after that is fast.
* Progressive enhancement: if JavaScript, the network, or the endpoint ever fails, the sidebar links fall back to normal full-page navigation — nothing breaks.
* Browser Back/Forward buttons work (History API), and direct links to a section (e.g. ?vsection=orders) still load normally.
* Internals: new single-source-of-truth renderer zymarg_vd_render_section_content() shared by the full-page and AJAX paths; new nonce-gated + capability-checked endpoint wp_ajax_zymarg_vd_load_section; sections fire a `zymarg-vd:section-loaded` DOM event so section scripts (payouts, store settings, shipping, refunds, coupons, messages) re-bind after a swap. Delegated handlers are bound once; direct-bound handlers are re-initialised per swap.
* Note: the Add/Edit Product screen still opens as a normal page load for now (a future release may bring it into the SPA flow too).

= 1.13.93 =
* Performance: Vendor dashboard is now 3-10× faster on repeat clicks. The four heavy data functions (Dashboard home, Orders, Earnings, Analytics) are wrapped in a short-lived per-vendor transient cache — Dashboard/Orders 60s TTL, Earnings/Analytics 120s TTL.
* Precise invalidation: cache flushes automatically for the affected vendor(s) on order status change, new order, order refund, product save/delete, and comment/review changes. No stale data after real actions.
* Filters: zymarg_vd_no_cache (bypass toggle, defaults to WP_DEBUG), zymarg_vd_cache_ttl_dashboard / _orders / _earnings / _analytics (per-slice TTL overrides).
* Safety: cache falls back gracefully if transients aren't writable; keys are strictly per-vendor so no cross-vendor leaks; WP_DEBUG bypasses everything so developers see live data.

= 1.13.92 =
* Change: Timezone selector on the Settings page is now a bare inline field (label + dropdown + Save/Reset) — no section header, no card wrapper. Same functionality as 1.13.91, cleaner look per user preference.

= 1.13.91 =
* New: Per-vendor timezone selector in Settings -> Preferences. Vendor picks their own timezone; the dashboard greeting and time-of-day subtitle use it. Falls back to the site timezone when unset. Uses native wp_timezone_choice() for the dropdown; validates with timezone_open() before saving.
* Fix: dashboard greeting "Good afternoon" -> "Good evening" flash on load. Client and server now compute the hour in the SAME timezone (the vendor's chosen one, or the site default) so the JS no longer overwrites the server-rendered greeting. JS uses Intl.DateTimeFormat and silently no-ops on old browsers that don't support it (the server greeting stays intact).

= 1.13.52 =
* Fix: quick-action cards now auto-size to fit their full label text (removed fixed width that caused truncation)

= 1.13.51 =
* Fix: quick-action card text no longer overflows the box (overflow hidden + ellipsis on label)

= 1.13.50 =
* Fix: last quick-action card no longer touches the right edge (added end padding to swipe row)

= 1.13.49 =
* Quick actions row becomes a smooth horizontal swipe slider on mobile (no unnecessary gaps, fully functional links)

= 1.13.19 =
* Fix: order tab count badge alignment -- force inline with text. Badge was floating to top-right like a notification dot instead of sitting on the same line as the tab label.

= 1.13.16 =
* Fix: order row layout -- proper inline alignment on desktop with explicit grid-template-areas, reduced padding and gap for tighter rows. Added tablet breakpoint (720-1080px). Mobile now shows View + action buttons in one row instead of stacked.

= 1.13.15 =
* New: native order actions -- vendors can Approve, Ship, Deliver and Cancel orders directly from the ZYMARG dashboard via AJAX (no page reload, no Dokan redirect). Actions validate ownership, verify nonce, animate the row out and update tab counts in real time.

= 1.13.13 =
* Fix: AJAX navigation no longer constructs broken URLs for relative hrefs.
  Previously, relative links like "admin.php?page=..." were concatenated with
  window.location.origin, skipping the /wp-admin/ path and causing
  ERR_TUNNEL_CONNECTION_FAILED. Now uses the current page directory as base.
* Fix: Safety timeout no longer auto-navigates to the broken URL. It resets
  loading state and shows a helpful message instead.
* Fix: On AJAX failure or bad response, the page reloads cleanly rather than
  navigating to a potentially malformed URL.

= 1.13.12 =
* Fix: AJAX navigation no longer blocks clicks after a failed or slow load.
  Added a 10-second safety timeout that resets the loading state and falls back
  to normal navigation. Rapid double-clicks are debounced. Content swap is
  wrapped in error recovery. A custom event (zymarg:contentSwapped) fires after
  each successful swap so third-party scripts can re-initialize.

= 1.13.9 =
* New: AJAX-powered admin navigation for Vendor Hub pages -- clicking submenu
  items or hub cards loads content instantly without full page reload.
* Change: top-level admin menu renamed from "Vendor" to "Vendor Hub".

= 1.13.8 =
* New: consolidated admin menus into a single top-level "Vendor" hub. One
  sidebar entry now holds ZYMARG Vendor (settings), ZYMARG Payouts, and
  D-Instruction as sub-pages with a branded card landing page.

= 1.13.7 =
* New: native Settings hub page -- vendors click Settings in the sidebar and
  see an in-shell account overview + hub cards linking to Store Settings,
  Payments, Shipping & SEO, and Password/Security. Fixes the
  /dashboard/settings/ redirect loop.

= 1.12.15 =
* New: Dark-mode toggle switch in the vendor sidebar (right above the
  Logout button). Mirrors the buyer-side My Account placement — buyer +
  seller now have the same dark-mode flip in the same spot.
* Architecture: the switch is rendered via the theme's
  [zymarg_theme_switch] shortcode (introduced in ZYMARG OS v5.8.11), so
  the dark-mode logic stays a single source of truth in the theme. The
  vendor sidebar guards with shortcode_exists() so it gracefully hides
  the switch on older themes / non-ZYMARG themes.

= 1.12.14 =
* UX: D-Instruction moved from the WordPress top admin toolbar to the
  WordPress LEFT SIDEBAR (top-level menu, position 26, right after
  Comments). Same accent-pink (#FEA9FF) brand colour on the menu link +
  dashicons-book-alt icon; brightens to white on hover and when the page
  is the current screen.
* Removed: the toolbar entry (admin_bar_menu / wp_before_admin_bar_render
  hooks) is gone — the page URL admin.php?page=zymarg-vd-instructions
  still works unchanged.

= 1.12.13 =
* New: Avatar gains a "Remove photo" option. When a photo is set, tapping
  the sidebar avatar now opens a small picker with **Change photo** and
  **Remove photo** — instead of going straight to the gallery. When no
  photo is set yet, the avatar still opens the gallery in one tap
  (unchanged simple flow).
* UX: Camera icon now fades on hover/focus (desktop) when a photo is set,
  matching the ZYMARG OS theme's My Account avatar pattern (v5.8.6+). On
  touch devices, the tap-on-avatar opens the picker — same clean look
  on mobile.
* Backend: new AJAX endpoint `zymarg_vd_remove_store_image` — mirrors the
  upload handler (nonce + permission check), deletes the attachment +
  clears `_zymarg_store_avatar_id`/`_zymarg_store_avatar_url` user meta.
  Also clears Dokan's `gravatar` field when it points to the attachment,
  so the public store page reverts in lockstep.

= 1.12.12 =
* New: Non-destructive `uninstall.php` safety net. Vendor data (payout
  methods, withdrawal/refund requests, shipping fees, store SEO, feature
  toggles, dismissed compat warnings, uploaded store avatars) is now
  PRESERVED when the plugin is Deleted. A future reinstall picks up
  exactly where you left off.
* New: Opt-in destructive wipe for dev resets. Add
  `define('ZYMARG_VD_DELETE_ALL_DATA', true);` to wp-config.php (or
  `add_filter('zymarg_vd_delete_data_on_uninstall', '__return_true');`)
  BEFORE clicking Delete to fully wipe plugin-owned data. Dokan's own
  data is never touched either way.
* Docs: Added an "Updating safely" section to README explaining the three
  valid update paths and what's preserved.

= 1.12.11 =
* UX: Rewrote the Dokan compatibility heads-up notice with on-brand voice
  (warmer, clearer "Dokan version check" framing + a tight 3-bullet sanity
  pass instead of a wall of text).
* Tweak: The Dokan Pro sanity-check bullet now only renders when Dokan Pro
  is actually active — cleaner notice on Dokan Lite-only sites.
* No change to validated caps (Dokan Lite 3.14.0 / Dokan Pro 5.0.2) — these
  intentionally reflect personally-tested versions, not latest-installed.

= 1.12.10 =
* Tweak: View store link nudged 2px further up (now translateY -12px).

= 1.12.9 =
* Tweak: Sidebar store header — store name nudged 20px down, "View store"
  link nudged 10px up (visual-only via transform, no layout impact).

= 1.12.8 =
* UX: Hide the camera/upload icon overlay once a real store photo is set.
  Clicking the avatar still opens the gallery to change it — the icon is
  just no longer needed visually once an image is in place.

= 1.12.7 =
* Cleanup: Removed dead localization strings for the removed size readout
  and camera feature (sizeOk, sizeWarn, compressing, capture, takePhoto,
  cameraDenied). No functional change vs v1.12.6.

= 1.12.6 =
* UX: Removed the file-size readout (KB / quality info) from the crop modal.
  Customers no longer see compression details after cropping their image.

= 1.12.5 =
* Fix: Avatar icon moved 2px left (now right:13px; bottom:11px).

= 1.12.4 =
* Fix: Avatar icon moved 5px left + 6px up (now right:11px; bottom:11px).

= 1.12.3 =
* Fix: Repositioned the camera/upload icon on the sidebar avatar (6px left,
  4px up) for better visual alignment within the avatar bounds.

= 1.12.2 =
* UX: Clicking the sidebar avatar now opens the device gallery directly (no
  intermediate "Choose from Gallery / Take Photo" popup). Simpler flow:
  tap avatar → pick image → cropper appears → save.

= 1.12.1 =
* Change: D-Instruction is now ONLY in the WordPress top admin toolbar (the
  left-sidebar admin menu entry has been removed, since the toolbar is where
  you actually wanted it). The page URL still works as before.
* Change: toolbar entry restyled to use the ZYMARG brand colour on the font
  only — accent pink (#FEA9FF) text + icon on the WP toolbar's own background,
  brightening to white on hover/focus. No gradient pill / no background.

= 1.12.0 =
* New: D-Instruction now appears in the WordPress top admin toolbar, alongside
  "+ New", "Dokan", "UpdraftPlus" etc. — so it is always one click away from
  wherever you are in wp-admin (and from the frontend, for logged-in admins).
* Styled with the official ZYMARG brand palette: purple gradient pill
  (#9500A5 -> #BD00D1 -> #FEA9FF), white text, subtle purple-tinted shadow,
  matching the rest of the dashboard. The left-sidebar menu is unchanged.

= 1.11.0 =
* New: Dokan compatibility monitor. The plugin now tracks the highest Dokan Lite
  and Dokan Pro versions it has been validated against (1.11.0 covers Dokan
  3.14.0 and Dokan Pro 5.0.2). When a NEWER minor or major Dokan/Pro is
  installed, a dismissible admin notice asks you to do a 5-minute staging check
  so any future API change is caught early. Patch updates do not trigger it.
* New: a "Dokan compatibility" panel at the bottom of Settings -> ZYMARG Vendor
  shows installed vs validated versions at a glance, so you always know where
  you stand.
* Read-only: the monitor never disables features and never blocks anything.
* Missing icon (truck) added to the docs icon set so the Shipping & SEO card
  renders correctly.

= 1.10.0 =
* New: Dokan Pro-aware hybrid mode (no Pro required). On free Dokan Lite the
  dashboard is fully Pro-equivalent — every native module (Payouts, Product
  editor, Store Settings, Refunds, Shipping, SEO) runs. If Dokan Pro IS active,
  the overlapping native modules automatically stand down so Pro owns those
  features — no duplicates, no conflicts:
    - Refunds defer to Pro's RMA module (when active).
    - Shipping fees defer to Pro's shipping.
    - Vacation defers to Pro's seller-vacation module (same data keys, so it
      carries over seamlessly).
    - The native product editor defers to Pro's full product management.
    - Store SEO stands down when Yoast / Rank Math is present (no duplicate tags).
    - Payouts (bKash / Nagad / Rocket) stay active either way — Pro has no
      Bangladeshi payout methods.
* New: when Pro is active, the ZYMARG shell steps aside on Dokan's own dashboard
  sub-pages so Pro renders its real UI there, while ZYMARG keeps the branded
  base dashboard and its native-only sections.
* This layer only reads whether Pro / its modules are active — it never
  activates anything and adds no licence or paid dependency.

= 1.9.0 =
* New: native Shipping fees — each vendor can set a flat shipping charge for
  their items plus an optional "free shipping over X" threshold. At checkout one
  shipping fee per vendor is added to the cart (skipped when that vendor's
  subtotal passes the free threshold). Works on Dokan Lite, no Pro required.
* New: Store SEO — set a meta title and description for your store page; output
  in the document <title> and a <meta name="description"> with no SEO plugin.
* Both live in the in-shell "Shipping" screen and are independently toggleable
  ("Shipping fees" and "Store SEO" in Settings -> ZYMARG Vendor).

= 1.8.0 =
* New: native Refund Requests — a complete refund workflow on Dokan Lite (no
  Dokan Pro). Buyers request a refund from their order page (reason + optional
  amount, within a configurable window); vendors review them in a new "Refunds"
  screen in the dashboard.
* Vendors can Approve (which records a WooCommerce refund for the amount, capped
  at the order's remaining refundable total) or Reject with a note. Customers
  cannot file duplicate open requests, and a note is added to the order.
* Fully toggleable via Settings -> ZYMARG Vendor ("Refund requests").

= 1.7.0 =
* New: native Store Settings — edit your public store profile from inside the
  ZYMARG dashboard. Reads and writes Dokan's own dokan_profile_settings, so the
  storefront stays in sync. No Dokan Pro required.
* Fields: store name, phone, public-email visibility, full address, social links
  (Facebook, Instagram, Twitter/X, YouTube, WhatsApp), and store banner upload
  with live preview.
* New: working Vacation mode — show an away-notice on your products, and
  optionally pause sales (hide Add to cart on your products) while you are away.
* The sidebar store link, the "Store Banner" quick action and the Store Settings
  nav now open the native screen.
* Fully toggleable: switch "Store Settings" off in Settings -> ZYMARG Vendor to
  hand back to Dokan's settings page.

= 1.6.0 =
* New: native Product Editor — add and edit products from inside the ZYMARG
  dashboard instead of handing off to Dokan's form. Works on Dokan Lite, no
  Dokan Pro required.
* Full simple-product editor: name, short + full description, regular & sale
  price (with sale-lower-than-regular validation), SKU (duplicate-guarded),
  inventory (track quantity or stock status), categories, tags, featured image
  upload with live preview, virtual / downloadable flags, featured toggle and
  status (Published / Draft / Pending).
* "Add Product", the Products "Edit" buttons and product names now open the
  in-shell editor; new products are saved under the vendor as author and
  ownership is enforced on edit and save.
* Variable/other product types are left to the full product form (a clear
  message is shown) so nothing is broken.
* Fully toggleable: switch "Native product editor" off in Settings -> ZYMARG
  Vendor to fall back to Dokan's product form.
* On-brand UI using the ZYMARG design tokens; responsive two-column layout.

= 1.5.0 =
* New: native Payouts module — a complete withdrawal/payout system that works on
  Dokan Lite, no Dokan Pro required. Built for Bangladesh: vendors save a payout
  method (bKash, Nagad, Rocket mobile numbers, or full bank-transfer details),
  then request a withdrawal of their available balance.
* New: available balance maths — gross balance (Dokan balance when present, else
  lifetime earnings from completed/processing orders) minus in-flight requests
  and amounts already paid out, with a configurable minimum withdrawal.
* New: admin review screen at Settings -> ZYMARG Payouts — filter by status and
  Approve / Mark paid / Reject each request with an optional note; a pending
  count bubble appears on the menu. Vendors can cancel their own pending
  requests and see admin notes in their history.
* The "Withdraw" quick action and the Earnings "Withdraw" button now open the
  native Payouts screen instead of handing off to Dokan (when enabled).
* Fully toggleable: switch the whole module off via the "Payouts" feature in
  Settings -> ZYMARG Vendor to fall back to the Dokan withdraw page.
* On-brand UI using the ZYMARG design tokens (purple gradient, card styling),
  responsive down to mobile.

= 1.4.0 =
* New: in-dashboard Store Image uploader. Click the sidebar avatar to pick from
  the device gallery or take a photo, then crop FREE-FORM (drag the corners) or
  snap to 1:1 / 4:3 / 16:9 with one tap.
* New: smart adaptive compressor — targets ≤ 50 KB by preferring WebP (with a
  JPEG fallback), capping the longest side at 800 px, and stepping quality /
  scale down only as much as needed. Shows the final size + format in the modal
  so vendors can re-crop if they ever want more pixels.
* New: uploads land as a real WordPress attachment AND are wired into Dokan's
  store logo (`dokan_profile_settings['gravatar']`), so the public store page
  also picks up the new image automatically.
* All endpoints nonce-protected and limited to dashboard-eligible users.
* New: a D-Instruction card explaining the uploader.

= 1.3.6 =
* New: a gallery/upload icon badge on the store avatar — click it to upload your
  store image (opens Dokan Store Settings, which uses the media gallery).
* Fix: the store name / "View store" gap is now eliminated for good — the text
  uses plain block stacking (no flex), forced so no inherited rule can push the
  two lines apart.

= 1.3.5 =
* Change: the sidebar store avatar now shows the Dokan store logo, then a
  custom-uploaded photo, and otherwise a clean on-brand gradient "initials"
  chip — replacing the grey default icon.
* New: the store avatar is now clickable and opens Dokan Store Settings so you
  can upload/change the store logo.
* Fix: store name / "View store" spacing locked tight (margin + line-height
  reset). If you now see the gradient initials avatar, the new CSS has loaded.

= 1.3.4 =
* Fix: removed the remaining gap between the store name and "View store" link in
  the sidebar store card by resetting inherited margins/line-height and
  vertically centering the text against the avatar.

= 1.3.3 =
* Improve: the plugin version is now read from the plugin header as the single
  source of truth. The D-Instruction page heading, asset cache-busting and the
  version constant all derive from it automatically — bumping the header is all
  that's needed on a release.

= 1.3.2 =
* Fix: tightened the sidebar store card — the store name and "View store" link
  no longer have an oversized gap (they were inheriting the theme's large body
  line-height).

= 1.3.1 =
* New: on activation the plugin now auto-creates a "Dashboard" page (slug:
  dashboard) holding the [zymarg_vendor_dashboard] shortcode IF one does not
  already exist — so the vendor dashboard works out of the box, with or without
  Dokan. When Dokan's own dashboard page is present, it is left untouched and
  taken over automatically at runtime.

= 1.3.0 =
* New: "D-Instruction" — a top-level admin menu with on-brand cards documenting
  every feature individually (what it does + how to use it).
* New: "Contact Seller" button on product pages — buyers can start a
  conversation with a product's vendor directly. Logged-out shoppers get a
  sign-in link; the message lands in the vendor inbox and the buyer's
  [zymarg_my_messages]. Also available via [zymarg_contact_seller] and toggleable
  in Settings -> ZYMARG Vendor.

= 1.2.0 =
* New: Feature toggles. A settings screen at Settings -> ZYMARG Vendor lets you
  turn individual dashboard features on/off (Products, Orders, Earnings,
  Analytics, Promotions, Reviews, Messages, Customers, Notifications, the Quick
  Actions row, and the Dokan hand-off items). Disabled features are removed from
  the sidebar, blocked on direct URL access (fall back to Dashboard), dropped
  from Quick Actions, and disable the buyer Messages shortcode. The Dashboard
  home is always available.
* Added a Settings link on the Plugins screen.

= 1.1.0 =
* New: Notifications — a single chronological feed of new orders, low-stock
  alerts, new reviews and new buyer messages, with type filters. Fills the only
  sidebar item that previously had no destination.
* New: two-way Messages — buyers can now reply from the storefront via the
  [zymarg_my_messages] shortcode (their conversations with vendors, with a
  thread view + composer). Add the shortcode to any page (e.g. /my-messages/).
* The inbox JavaScript is now shared between the vendor and buyer views.
* Note: config screens (Shipping, Payments, Store Settings, Settings) remain
  Dokan hand-offs by design — they are update-safe and not reimplemented.

= 1.0.0 =
* Initial release. Extracted the ZYMARG Vendor Dashboard (Phases 0-5) from the
  ZYMARG OS theme into a standalone plugin so the functionality and data survive
  theme changes. Self-contained styling with token fallbacks; uses the theme's
  design tokens and Discovery Spark mark automatically when ZYMARG OS is active.
