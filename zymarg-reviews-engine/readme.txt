=== ZYMARG Reviews Engine ===
Contributors: zymarg
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 1.3.2
License: GPLv2 or later

The review engine behind the ZYMARG plugins: data, settings, submission, media, moderation and rendering, in one place.

== Description ==

ZYMARG Reviews Engine owns everything about product reviews. Other plugins — ZYMARG Single Product, ZYMARG Single Store, or your own — simply place the section and the engine renders it.

**What the engine owns**

* Review data: ratings, breakdowns, verified-purchase state, helpful votes, store replies
* Submission: the form, validation, eligibility rules, the review window
* Media: photo and video uploads, the customer media strip and the full-screen gallery
* Moderation: reported reviews, report reasons, auto-unapprove threshold, notifications
* Presentation: the markup, the stylesheet and the front-end script
* Settings: one control page with eight tabs, saved over AJAX with no page reload

**How consumers place it**

Shortcode, for pages, page builders and widget areas:

`[zymarg_reviews]`
`[zymarg_reviews product_id="13" layout="compact" limit="5" show_form="no"]`

PHP, for plugins and templates:

`if ( function_exists( 'zymarg_reviews_render' ) ) { zymarg_reviews_render( array( 'product_id' => $product_id ) ); }`

Data only, if you want to build your own presentation:

`$data = zymarg_reviews_get_data( array( 'product_id' => $product_id ) );`

**Restyling without forking**

The stylesheet is built on CSS custom properties. Redefine the tokens in your own theme or consumer plugin and the whole section follows. For full markup control, filter `zymarg_reviews_template_path`.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` and activate it.
2. On activation the engine copies any existing review settings out of ZYMARG Single Product.
3. Open **ZYMARG Reviews** in the admin menu to review the settings.

While ZYMARG Single Product 1.x is active it keeps rendering reviews with its own embedded copy and the engine stays in settings-only mode, so nothing is ever registered twice. Update to Single Product 2.0 to hand rendering over to the engine.

== Frequently Asked Questions ==

= Will I lose existing reviews? =

No. Reviews are WordPress comments with meta; the engine reads exactly the same data. Only settings are migrated.

= Can two plugins show reviews on the same page? =

Yes. Assets and nonces are registered once per request no matter how many placements there are.

= Does uninstalling delete my reviews? =

Never. Uninstall can remove this plugin's own settings only, and only if you opt in under Advanced.

== Roadmap ==

The full build plan for the next release (v1.1.0 - vendor scoping and the Dokan
store-page consumer) ships inside this plugin at:

    docs/PHASE-3-PLAN.md

It is parked until explicitly called for. Three decisions listed in section 10
of that document must be answered before any code is written.

== Changelog ==

= 1.3.2 — Store-wide (vendor) scope now gets the full review experience =
* New: `Data_Builder::get_grouped_review_media_for_vendor()` and `get_vendor_media()` — the store-wide (vendor) equivalents of `get_grouped_review_media()`/`get_product_media()`. `build_vendor()` now populates `media_gallery`/`media_reviews` on a vendor-scoped payload exactly as `build()` already did for a single product, so the customer photo/video strip and the full-screen/mini-player lightbox viewer now work identically on a store-wide feed as on one product's reviews.
* New: `class-ajax.php`'s `load_reviews()` now accepts a `vendor_id` request parameter (precedence rule matches `zymarg_reviews_get_data()`/`zymarg_reviews_render()`: vendor scope wins over `product_id` when both are present), rebuilding the same vendor's product list server-side so AJAX "Load More" works on a store-wide feed instead of requiring a full page reload.
* New: each review card gains a product context block (thumbnail + title, linking to the product) whenever the review came from a store-wide feed — added to `templates/reviews.php`, `templates/partials/review-cards-loop.php`, and the media viewer's sheet (`.zymarg-mv__product` in `zymarg-reviews.js`). Single-product scope is completely unaffected: the block only ever renders when `product_title`/`product_id` are present on a review row, and those keys are only ever set by `Data_Builder::build_vendor()`.
* Changed: the JSON-LD Product schema block in `templates/reviews.php` is now explicitly skipped whenever the render is vendor-scoped (`$data['scope'] === 'vendor'`), rather than relying on `$title_text` happening to be empty outside a product context. This was never a deliberate safeguard before — a future settings or placement change could have silently defeated it and emitted a `Product` schema block with no product behind it, potentially conflicting with the vendor's own store-wide structured data.
* Changed: the widget's, media viewer's and report modal's fallback design tokens (`--z-*`, `--zv-*`, `--zm-*` in `zymarg-reviews.css`) now match the ZYMARG brand tokens used throughout ZYMARG Store Page (colors, 16px/12px card radii, Tailwind's `shadow-lg`/`shadow-xl`, and the "Inter Variable" typeface) — these are fallback values only, reached whenever nothing upstream defines the underlying `--zymarg-*` theme tokens, so a site that already sets its own `--zymarg-*` tokens (Single Product included) sees no change at all.

= 1.3.1 — Full-screen media viewer now actually locks page scroll on mobile =
* Fixed: on touch devices, the page behind the full-screen media viewer kept scrolling while swiping through photos/videos, even though the viewer already set `document.body.style.overflow: hidden` while open. That CSS property alone does not stop a page from scrolling on mobile — the browser's native touch-scroll momentum still moves it unless a `touchmove` listener actively cancels the gesture. The viewer swipe logic only ever listened for `touchstart`/`touchend` (to measure a completed swipe), never `touchmove`, so nothing was telling the browser to withhold scrolling.
* New: a `touchmove` listener now calls `preventDefault()` on the page's own scroll while the viewer is in full-screen mode — but never while in mini player mode, which is deliberately unaffected: mini is picture-in-picture, so the customer is meant to keep browsing the page underneath it while it floats.
* Note: a touch that starts inside the expanded review sheet (scrolling a long review's text) is left alone rather than blocked, so that still scrolls normally.

= 1.3.0 — Media viewer: review sheet now floats over the media on mobile and tablet =
* Fixed: on mobile and tablet, the review sheet (reviewer name, rating, review text, actions) sat in normal document flow beneath the photo/video, so its own height was subtracted from the media's — the media visibly shrank to make room for the sheet, in both the short-preview and expanded ("View Review") states.
* Changed: the sheet is now an absolutely-positioned overlay that floats on top of the bottom portion of the media, with a dark gradient scrim for legibility over unpredictable customer photos and videos. The media stage always fills the entire viewer frame — top to bottom, edge to edge — regardless of whether the sheet is collapsed or expanded.
* Changed: the two-level position indicator ("Review 8 of 24" / "2 / 3") moved from the bottom of the stage to the top, so the floating sheet can never cover it.
* Changed: the permanent side-by-side review panel — previously shown from 768px up — now only appears at 1024px and wider (true desktop widths). Tablets (768–1023px) get the same floating-overlay treatment as phones, since a tablet does not have the side-by-side room a desktop browser window does. Desktop's side panel is unchanged.
* Note: the floating sheet deliberately does not follow the site's light/dark theme — it is always a dark scrim with light text, since it sits on top of unpredictable customer media rather than the plugin's own surface, matching the drop-shadow treatment already used on the nav icons for the same reason. The permanent desktop side panel is a real panel beside the media, not an overlay on top of it, so it correctly continues to follow the site theme as before.
* Scope: CSS only. No PHP, JS, or markup changes — confirmed by MD5 that every PHP and JS file is byte-identical to 1.2.2.

= 1.2.2 — Fixed variation label still not appearing after 1.2.1 =
* Fixed: 1.2.1 read the variation from `WC_Order_Item::get_formatted_meta_data()`, but called it with WooCommerce's default `$include_all = false`. With that default, WooCommerce re-verifies at read time that a meta row's value is still a currently valid attribute value on the variation product, and silently drops the row if that check fails for any reason — even though the meta itself is genuinely present on the order (visible on the WooCommerce order screen). This is what caused the badge to still not appear after 1.2.1 shipped. Now calls `get_formatted_meta_data( '_', true )`, which returns the order item's recorded meta as-is — appropriate for a review, which is meant to be a permanent record of what the customer actually bought, not a label that can silently disappear later if the variation is edited. Confirmed against a real affected order (variation meta present, default call returned 0 rows, `include_all = true` returned the row).
* Fixed: some variation attribute values come back from WooCommerce wrapped in markup (observed live: `<p>black</p>`). These are stripped to plain text as before, so the badge reads `Color: black`, never `Color: <p>black</p>`.
* No change to the label format, the retroactive-resolution behaviour, or the internal-meta exclusion (`_dokan_commission_source` and similar `_`-prefixed order-item meta are still correctly excluded).

= 1.2.1 — Variation label now actually shows on reviews =
* Fixed: the variation badge (e.g. "Color: Black, Size: M") on review cards and in the media viewer was defined in the layout since v1.1.0 but never appeared, because nothing in the plugin ever saved a value for it. `_zymarg_review_variation` was read in four places but written in none.
* New: when a customer submits a review, the plugin now reads the exact variation from the order line item WooCommerce already validated as their completed purchase, formats it the same way WooCommerce's own order screen does (e.g. "Color: Black, Size: M"), and saves it on the review. Simple products correctly show no badge.
* New: reviews submitted before this fix are backfilled automatically at display time, with no migration script. Every review already stores the order and order-item IDs used for the "already reviewed" check; if no variation label was saved, the plugin now resolves it live from that same order item the first time the review is displayed. Old and new reviews both show the correct variation immediately after updating.
* Note: layout is unchanged. The variation line already sat between the reviewer name/date and the star rating on both the review card and the media viewer sheet — it simply had nothing to display until now.

= 1.2.0 — Review media viewer =
* New: the full-screen media viewer now navigates on two axes. Swiping horizontally (or pressing the left/right arrow keys) moves through the media belonging to the review currently being read; swiping vertically (or pressing the up/down arrow keys) moves to the next or previous review that has media. Previously every photo and video on the product sat in one flat sequence, so a customer had no way to tell where one reviewer's media ended and the next began.
* New: reviews with no media are excluded from the viewer entirely, so vertical navigation can never strand the customer on an empty slide. Those reviews still appear normally in the review feed.
* New: two-level position indicator — "Review 8 of 24" above "2 / 3" — so it is always clear both which reviewer is being read and which of their media items is on screen.
* New: desktop mini player (screens 1024px and wider). A button beside the close icon shrinks the viewer into a floating player docked above the theme's scroll-to-top button, which the customer can drag anywhere and resize from its top-left corner. In mini mode the page backdrop is removed and page scrolling is released, so the customer can keep browsing the product page — read other reviews, add to cart — while a video keeps playing in the corner. Full navigation is retained in the small player.
* New: the mini player measures the theme's scroll-to-top button at runtime rather than assuming its size, so it keeps clear of it even after the button is resized, moved to the left, or switched off in the Customizer.
* New: videos autoplay with sound. A mute control appears whenever a video is on screen, and once the customer mutes, that preference carries to every later video in the session. If a browser or power-saving mode refuses unmuted autoplay, playback silently falls back to muted and the control reflects that, rather than presenting a dead player.
* New: "View Review" expands the review sheet over the media when the review is longer than the preview, and collapses back again, without ever changing which media item is on screen. On tablet and desktop the review panel has room to sit beside the media permanently, so no expanding is needed there.
* New: helpful, not-helpful and report actions inside the viewer. Voting in the viewer updates the review card underneath it and voting on the card updates an open viewer, from a single shared painter, so the two surfaces can never disagree.
* New: video thumbnails now carry their runtime (for example "0:23") in the media strip and in review cards.
* New: keyboard and pointer parity for desktop. Arrow keys mirror the swipe gestures, always-visible arrow buttons provide a click equivalent, M toggles mute, Escape closes, and holding Shift with the arrow keys moves the mini player. In mini mode the arrow keys are only captured while focus is inside the player, so page scrolling is never hijacked.
* Performance: images adjacent to the current one are preloaded so swiping is instant, while video files are never preloaded — only their poster and duration, which the payload already carries. A customer browsing twenty reviews no longer risks pulling down hundreds of megabytes of video.
* Fixed: the media viewer had no working colours. Its stylesheet referenced the design tokens declared on the review widget, but the viewer is appended to the end of the document, outside that element, so every one of those variables resolved to nothing — leaving the panel with no background, no corner radius and whatever text colour it happened to inherit. It now declares its own palette with a matching dark-mode block, the same approach the report-abuse dialog already used for the same reason. This is what made text hard to read inside the viewer in both light and dark mode.
* Changed: the reviewer-initial badge has been removed from the customer media strip thumbnails. The strip is a visual index of the product's media; the reviewer's identity belongs to the viewer that opens on tap.
* Changed: the media strip's "See all", "+N" and individual thumbnails now open the viewer at the exact review and media item they represent, instead of an offset into a flat list.
* Changed: the filter and sort dropdowns have been removed from the viewer. They described a flat pool of media, which no longer exists now that browsing follows one reviewer at a time.
* Note: media opened from a review card is resolved by review and attachment, not by position, so media on reviews loaded through "Load more reviews" opens correctly with no extra requests.

= 1.1.0 =
* Removed: The optional "Review Title" field has been removed from the Write a Review submission form, from every review card display (main feed, AJAX Load More partial, and the manual-mode card renderer), and from all review data payloads. Existing review titles already stored in the database are left untouched (harmless, no longer read or rendered) — no data was deleted.
* Removed: The now-unused "Title placeholder" and "Review title required" fields have been removed from the Review Form tab in Settings, since both only existed to configure the title field that no longer exists.

= 1.0.9 =
* Change: Reviewer name is now resolved live from the reviewer's current WordPress profile "Display Name" at render time, for every review and reply left by a registered user. Previously the name shown was a snapshot of comment_author frozen at the moment the review was submitted, so a customer who later set a proper display name (e.g. changing from an auto-generated username like "srijansaha27" to "Srijan Saha") never saw it reflected on reviews they had already left. This fix applies retroactively to existing reviews, not only new ones. Guest reviews (no WordPress account tied to the review) are unaffected and keep showing the stored name exactly as before.
* Change: The review date now sits beside the reviewer name (e.g. "Srijan Saha 11/08/2026") instead of on its own line underneath, and uses a fixed dd/mm/yyyy format everywhere in the plugin — the main review feed, replies, the AJAX Load More partial, and the newly-posted-reply response — independent of the site's WordPress Settings > General date format.
* Fix: The JSON-LD review schema's `datePublished` field now uses an ISO 8601 timestamp instead of the display date string, since the display format changing to dd/mm/yyyy would otherwise have broken schema.org / Google rich-result validation for review markup.
* Change: Review cards are now visibly more compact, with a coordinated size reduction across avatar, name, date, stars, title, body text, photo thumbnails, and spacing — tuned separately for tablet/desktop and mobile (≤767px) breakpoints so density increases without any text dropping below comfortable reading size on a phone.

= 1.0.8 =
* Fixed: after successfully submitting a gated "Write a Review" link, the review form could reappear if the page was reloaded, because the URL still carried its zymarg_review / order_id / item_id / _nonce query params and #zymarg-write-review hash. The form now strips those from the address bar (via history.replaceState, no reload) right after a successful submission, so reloading or revisiting that same link can never re-reveal the form.

= 1.0.6 — The engine can place itself =
* New: Placement tab. The engine can now print the review section itself instead of waiting for a consumer plugin's template to call zymarg_reviews_render(). Pick an anchor hook, a priority, and whether to wrap the section in an accordion.
* Why: a consumer such as ZYMARG Single Product can be frozen permanently. With placement set to Hook, a new review feature ships with an engine update and needs no consumer release.
* New: Anchor hooks are an allowlist, offered as a dropdown, covering the ZYMARG Single Product hooks plus two WooCommerce hooks so placement also works on themes that do not use Single Product. Themes can add their own through the zymarg_reviews_placement_hooks filter, which extends the dropdown and the save allowlist together.
* New: Shortcode placement mode, for sites that want to change the section's arguments without any code. Only the zymarg_reviews tag is accepted and an unusable value falls back to the normal renderer, so a typo in the field cannot take reviews off the site.
* New: The engine now enqueues its own front-end assets on product pages when it is placing the section. Previously the stylesheet reached wp_head only because ZYMARG Single Product enqueued it, so a site without that plugin got a flash of unstyled reviews.
* New: zymarg_reviews_available(), zymarg_reviews_version() and zymarg_reviews_is_placing_itself(). Consumers should prefer the first over function_exists( 'zymarg_reviews_render' ), which stays true even when the engine is running in settings-only mode behind a legacy embedded copy and will deliberately render nothing.
* Fix: The renderer now refuses to print the same scope twice in one request. A site with both a consumer template call and an engine placement active previously produced two review sections, with duplicate DOM ids, two Load More buttons paginating the same feed and doubled schema. Override with the zymarg_reviews_allow_duplicate filter.
* New: Admin warning when the engine is placing the section while ZYMARG Single Product is still set to render its own accordion, because in that case the surviving section is the consumer's.
* Note: Placement defaults to Off. Updating the engine changes nothing about what an existing site prints until an administrator picks a mode.
* Note: The accordion label counts approved top-level reviews only. Since 1.0.4 replies are stored as child comments, so the WordPress comment count would report a product with 12 reviews and 19 replies as "Reviews (31)".

= 1.0.5 — Reply limits are admin settings =
* New: "Customer replies per review" and "Seller replies per review". How many times one user may reply to the same review. 0 means unlimited. Set the seller value to 1 for exactly one official answer per review.
* New: The flood guard is now configurable — replies per window, window length in minutes, and whether it applies to sellers as well as customers. 0 replies turns it off.
* Fix: 1.0.4 hardcoded the guard at five replies per ten minutes, reachable only through a PHP filter. There was no way to change it from the admin screen, and it was a rate limit rather than a per-review cap, so it did not answer "how many times can someone reply".
* Fix: 1.0.4 exempted sellers and shop managers from any limit at all. They now have their own per-review cap, and can optionally be included in the flood guard.
* New: Replies awaiting approval count towards a user's allowance, so a moderated account cannot queue an unlimited number.
* New: The reply form disappears once a user has used up their allowance on a review, instead of offering a form the handler would reject.
* Note: Both limits default to unlimited, matching how replies behaved in 1.0.4, so upgrading imposes no new cap. The caps are enforced in the AJAX handler as well as in the templates.

= 1.0.4 — Interactions, each behind its own toggle =
* New: Interactions tab in the settings screen. Every behaviour below has its own switch, so the whole layer can be turned off again without touching markup or losing data.
* New: Review visibility — everyone (default) or logged-in users only. The Load More endpoint honours it too, so a guest cannot page through reviews the first paint refused to show.
* New: Reactions toggle for the helpful / not helpful buttons.
* Fix: A guest who clicked a reaction button got a bare "Unauthorized." Reactions are stored per user account, so a guest could never record one. Guests are now either asked to log in (default) or the buttons are hidden from them.
* New: Customers can reply to reviews, off by default. Replies are plain text, limited to five per user per ten minutes, and either publish immediately or wait for approval.
* New: Sellers can reply toggle. Seller replies now also work for the vendor who owns the product being reviewed — previously the check required manage_woocommerce or moderate_comments, which a marketplace vendor does not normally hold, so actual sellers were excluded from their own reviews.
* New: Seller replies are pinned above customer replies, on first paint, after Load More, and when one is posted without a reload. Toggleable.
* New: Replies are one level deep by design. A reply can only attach to a review, never to another reply.
* New: The "Write a Review" button in My Account, on completed orders within the review window, per line item, plus a matching action on the orders list. Review_Tracker already built and validated the signed per-item review URL, but nothing handed it to the buyer, so `get_review_url()` had no callers and the button label settings had no readers. Toggleable.
* Change: Reply bodies are sanitised to plain text on save, for sellers as well as customers. Replies stored before 1.0.4 keep rendering exactly as they did, so no existing content changes appearance.
* Note: Every toggle is enforced in the AJAX handlers as well as the templates. Hiding a button does not close an endpoint.
* Note: Defaults reproduce 1.0.3 behaviour. Upgrading changes nothing on the front end except the new My Account button and the guest reaction message.

= 1.0.3 — Store-wide reviews work from the shortcode =
* Fix: [zymarg_reviews vendor_id="42"] rendered nothing at all. The data layer has supported store-wide scope since 1.0.2, but the renderer and the shortcode did not, so a documented attribute failed silently. It now renders that vendor's reviews.
* New: The renderer accepts vendor_id and page, so any theme or plugin can show a store-wide feed without duplicating the query.
* Note: Store-wide scope is deliberately read only. A feed spanning many products has no single product to review, so no form is shown there. Writing a review stays on the product page, where the purchase check is meaningful.
* Fix: The template read product brand, title, price, image, average rating and review count without checking they exist. Under store-wide scope those keys are absent, which would have produced PHP notices on PHP 8.

= 1.0.2 — Store-wide (vendor) scope =
* New: `zymarg_reviews_get_data()` now accepts a `vendor_id`, returning a read-only, store-wide data set aggregated across every published product that vendor owns. It takes precedence over `product_id`, and carries no form state — a store page displays reviews, it does not collect them.
* New: `Data_Builder::vendor_aggregate()` computes the average, the review count and the full star distribution in a single grouped query, cached for six hours per vendor.
* New: `Data_Builder::build_vendor()` returns a paginated review feed. Each entry adds the product it was written about (`product_id`, `product_title`, `product_url`, `product_image`), since a store feed spans the whole catalogue.
* New: `page` argument for paging the vendor feed, plus `total_pages` and `has_more` in the payload.
* New: `zymarg_reviews_vendor_product_ids` filter, for marketplaces where product ownership is not `post_author`.
* New: The cached vendor aggregate is flushed when a review is inserted, edited, approved, unapproved or deleted, so a store's score never lags the reviews printed beneath it.
* Note: `review_count` counts rated reviews only, so it always matches the denominator of the average. `total_reviews` is the size of the feed, which also includes reviews left without a star rating.

= 1.0.1 =
* Fix: A review whose `rating` meta was missing or zero was rendered as a five-star review. The fallback in `Data_Builder` read `$rating > 0 ? $rating : 5`, which silently invented a perfect score and inflated every aggregate built on top of it. A missing rating is now reported as `0` and rendered as unrated.
* Change: `reviews_form_visibility` now offers only "Verified buyers only". The submission handler has always required a valid order, order item and URL nonce, so the "Always visible" and "Any logged-in customer" options rendered a form that could never be submitted. Sites that had either value stored fall back to the gated default on next save.

= 1.0.0 =
* First release. Extracted from the ZYMARG Single Product reviews module into a standalone engine.
* New: own settings store with an eight-tab AJAX control page (General, Display, Review Form, Media, Submission, Moderation, Emails, Advanced).
* New: public API — `zymarg_reviews_render()`, `zymarg_reviews_get_data()`, `zymarg_reviews_get_setting()`, `zymarg_reviews_enqueue()`.
* New: `[zymarg_reviews]` shortcode with per-placement layout, limit and component arguments.
* New: one-time settings migration from `zymarg_sp_settings`.
* New: settings export/import and reset.
* Compatibility: stands down automatically while an older embedded copy of the reviews code is active.
