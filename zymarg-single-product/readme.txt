=== ZYMARG Single Product ===
Contributors: aspeyash
Tags: woocommerce, single product, template, swatches, buy now, reviews
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 2.4.6
WC requires at least: 8.0
WC tested up to: 9.9
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Standalone single product page template for the ZYMARG Marketplace.

== Description ==

ZYMARG Single Product overrides the WooCommerce default single product template with a
fully custom layout featuring a 3-column desktop design with gallery, product info,
and a buy box.

Key features:
* Template override that beats Elementor, Divi, and all page builders (dual-hook strategy)
* Product gallery with vertical/horizontal/grid layout, lightbox, hover zoom, thumbnails
* Variation swatches rendered on top of WooCommerce native selects (shape, size, OOS behaviour)
* Smart price display with variable product options, savings badge, free shipping hint
* Quantity stepper synced between desktop buy box and mobile sticky bar
* Add to Cart via WooCommerce AJAX (no page reload)
* Buy Now with own session management — saves current cart, restores on abandonment or TTL expiry
* Dokan Pro seller card with fallback to WP user
* Collapsible accordions: Description (with spec table) + Reviews
* Fully embedded ZYMARG Reviews subsystem (no dependency on the standalone Reviews plugin)
* Product grid section hooks for ZYMARG WC Product Grid plugin
* Sticky mobile action bar with qty, ATC, Buy Now
* 6-tab admin panel (Gallery / Swatches / Price / Add to Cart / Trust & Shipping / General)
* AJAX save — settings panel never reloads the page
* HPOS (High-Performance Order Storage) compatible
* Zero dependency on WSE (Woo Swatches Elementor)

== Installation ==

1. Upload the `zymarg-single-product` folder to `/wp-content/plugins/`
2. Activate the plugin in WP Admin → Plugins
3. Go to Single Product in the WordPress admin menu to configure settings

== Changelog ==

= 2.4.6 =
* Fixed: this plugin's global PHP constants (ZYMARG_SP_VERSION, ZYMARG_SP_FILE, ZYMARG_SP_PATH, ZYMARG_SP_URL, ZYMARG_SP_BASENAME, ZYMARG_SP_ASSETS, ZYMARG_SP_TPL_PATH) were identically named to constants defined by the separate "ZYMARG Store Page" plugin, because both plugins independently used "SP" as shorthand ("Single Product" here, "Store Page" there). With both plugins active on the same site, whichever plugin's main file loaded first silently won each constant, and the other plugin's file path / asset URL / version string silently pointed at the wrong plugin - CSS and JS 404s and version-string mismatches, with no visible error. All 7 constants are renamed to a unique ZYMARG_SNGL_* prefix, each now also guarded with if ( ! defined() ) as defense-in-depth against any future third plugin picking the same name.
* Fixed: this plugin's admin CSS/JS enqueue handle, filenames, and window-global JS object were identically named `zymarg-sp-admin` / `zymargSPAdmin` to ZYMARG Store Page's own (unrelated) admin assets. Under WordPress's handle-based enqueue deduplication, whichever plugin registered the handle first "won" it, and the other plugin's admin settings screen silently loaded with no styling and no interactive JS. Renamed to `zymarg-single-product-admin` / `zymargSingleProductAdmin` throughout (enqueue handle, asset filenames, admin CSS classes and IDs, JS object, and the settings-save nonce action). The unrelated `zymarg-sp-*` class families used by the section-repeater UI (e.g. `zymarg-sp-toggle`, `zymarg-sp-section-row`, `zymarg-sp-input`) were checked and confirmed NOT to collide with Store Page, so they are unchanged.
* Fixed: `assets/css/zymarg-tokens.css` had drifted to an older copy (v2.0.0) of the shared ZYMARG design-token stylesheet than the one shipped by ZYMARG Store Page (v2.1.0). Because both plugins register the same `zymarg-tokens` handle and WordPress loads only the first one registered, whichever plugin loaded second was silently missing several CSS variables (`--zym-color-divider`, `--zym-color-error-bg`, dark-mode token overrides, etc.) with no error - just transparent/default colors wherever those tokens were referenced. Replaced with the newer v2.1.0 file (a strict superset - no values changed or removed, only additive: status/neutral tokens promoted to the shared `:root` scope, plus a `[data-theme="dark"]` block).
* Added: a permanent header comment in the main plugin file documenting that "ZYMARG Store Page" is a separate plugin routinely activated alongside this one, the naming-collision history above, and a rule for all future work on this plugin to avoid repeating it (never use a bare `ZYMARG_SP_*` / `SP_*` / `zymarg_sp_*` prefix for anything new here; grep the Store Page plugin source for a name before introducing it).

= 2.4.5 =
* Fixed: the gallery wishlist toggle now broadcasts the same zymarg_wcpg:wishlist:changed event, on the same target and with the same payload shape, as the ZYMARG WC Product Grid plugin's own card hearts - so the theme's existing wishlist toast/card (checkmark, "View wishlist" link) now renders identically on the single product page as it does on the product grid.
* Changed: removed the plugin's own plain wishlist toast on success (the theme's listener now owns success feedback, matching the grid page). The plain toast is still used for error/rollback cases.

= 2.4.4 =
* Fixed: Add to Cart no longer shows WooCommerce's native "View cart" link or the plugin's own "Item added to cart" toast. Error feedback (e.g. "Something went wrong") is unaffected.
* Removed: the now-unused "Show added-to-cart toast notification", "Toast message" and "Show 'View Cart' link in toast" settings from the admin panel.
* Added: the gallery wishlist button now persists for real, through the ZYMARG WC Product Grid plugin's shared wishlist storage (same list as Product Grid's card hearts). Requires ZYMARG WC Product Grid to be active; shows a dismissible admin notice when it is not.
* Changed: wishlist button is now flat and transparent (no circle/background), sitting directly on the product photo, with a dedicated active (filled) heart icon in ZYMARG purple - matching the Product Grid card hearts' idle/active icon pattern.

= 2.4.3 =
* Fixed: on mobile only (max-width: 640px), removed WooCommerce's default bottom margin on the variations/swatches form (form.cart), which left an unwanted gap below the swatches.
* Fixed: old (strikethrough) price is now vertically centered against the current price instead of baseline-aligned, on all devices.
* Changed: current price is now true bold (font-weight 700, was 600), on all devices.

= 2.4.1 =
* Fixed: clicking a variation swatch pushed everything below the swatches down for about a fifth of a second before snapping back. WooCommerce animates its own variation summary block open on every selection, and it decides whether to animate by checking that block's text content rather than whether it is visible - so hiding the price and availability lines visually was not enough to stop it. The block is now collapsed outright, which turns the animation into a layout no-op. Price, gallery, swatch state and Add to Cart are unaffected.

= 2.4.0 =
* Changed: price sizing is now tuned per breakpoint - 24px on desktop, 22px on tablet, 20px on mobile, with the old price, the "From" prefix and the Save badge scaled to match.
* Changed: the gaps between the current price, the old price and the Save badge are now minimal (10px and 8px down to 4px and 3px).
* Changed: the Save badge is vertically centred against the price instead of sitting on the price baseline.
* Changed: spacing inside the Save badge, between the label, the amount and the percentage, reduced from 4px to 2px, and the badge no longer wraps.
* Removed: a mobile price size rule that lost on source order and could never take effect.

= 2.3.0 =
* New: mobile block order is now Gallery > Swatches > Price > Title > Rating by default.
* New: "When hidden on mobile, apply to" - the mobile thumbnail toggle can now be limited to variable or simple products only.
* Removed: "Swatch position on mobile" setting. The new default order makes it redundant.
* Fix: the mobile swatch reorder no longer runs in JavaScript, removing the reorder flash on page load.

= 2.2.2 =
* Fixed: product card prices inside a grid section rendered at the single product page's own price size, roughly twice as large as everywhere else on the site, in brand purple and extra bold. The engine builds card prices with wc_price(), which emits a .woocommerce-Price-amount span, and this plugin styled that WooCommerce class across the whole page wrapper. The rule is now scoped to the price row, so engine cards keep the size their card template sets.
* Fixed: the mobile price size rule listed its selector in the wrong order and never matched. Corrected.

= 2.2.1 =
* Fixed: product cards inside a grid section rendered in this plugin's font instead of the theme font. The engine and the Template Pack declare no font-family, so card text inherited 'Segoe UI' and line-height 1.6 from the page wrapper. Card font sizes are fixed pixel values and were never altered; two different typefaces at the same pixel size simply read as different sizes. Typography now applies to this plugin's own children and skips engine sections, so a card looks the same on a product page as it does anywhere else on the site.
* Removed: dead styles for the placeholder product cards and sliders that this plugin printed before the engine took the sections over (.p-card, .p-img, .p-body, .p-name, .p-price, .p-tag, .p-rating, .slider, .stack-grid, .arrow, .slider-arrows, .section-head, .section-title). No markup has carried these class names since 2.1.0, and their generic names risked colliding with a theme or another plugin.

= 2.2.0 =
* New: each grid section now owns its own heading, printed by this plugin instead of the engine. The heading is rendered only after the grid is known to have products, so an empty section takes its heading away with it.
* New: {vendor_name} token in a section heading, resolved to the seller shop name on vendor sections. Removed on other sources, where there is no vendor context.
* New: automatic section link. Vendor sections resolve the seller store and read Explore Store; other sections read Explore More and link only when a URL is supplied.
* New: section rows open locked. Press Edit before a row can be changed, so opening the tab cannot alter what the front end renders.
* New: removing a section asks for confirmation, and the section list from before the last save can be restored from the same screen.
* New: shortcodes are validated in the browser before saving, and a plain-language summary of the pending section changes is shown on save.
* Change: Ctrl/Cmd + S no longer saves while a section field has focus.
* Change: the engine heading block is forced off for every section, so a leftover show_heading="yes" cannot produce a duplicate heading.

= 2.1.0 =
* NEW: Grid Sections tab - product grid sections are now an ordered, drag-reorderable list managed from the admin panel.
* NEW: Each section runs one ZYMARG Product Grid shortcode, so sources, layouts and cards can be changed without editing code.
* NEW: Sections can be added and removed; the three original sections are migrated automatically on upgrade.
* NEW: Sections whose source returns no products now hide completely, and the next section closes the gap.
* FIX: The settings save handler now supports array values; previously any repeater data would have been wiped on save.
* FIX: Engine assets are pre-resolved for option-stored shortcodes, preventing an unstyled flash on first paint.


= 2.0.0 =
* Reviews are now powered by the separate ZYMARG Reviews Engine plugin.
* Removed the bundled reviews module, its styles, scripts, AJAX handlers and settings UI from this plugin.
* The Reviews accordion renders through zymarg_reviews_render() and is skipped when the engine is not active.
* Soft dependency: every other section keeps working without the engine; administrators see a dismissible notice.
* Admin Reviews tab now only controls the accordion (show, open by default, label) and links to the engine settings.
* Existing review settings stay in storage untouched so the engine can migrate them.

= 1.1.21 =
* Reverted the attribute heading row to its original layout (attribute label and selected value only).
* Removed the variation stock readout from the attribute row. Out-of-stock state is still communicated by disabled swatches and the disabled Add to Cart / Buy Now buttons.

= 1.1.20 =
* Attribute heading is now a strict two-column row: label column flexes, stock column is auto width and never wraps to a second line.
* Stock readout shows only when a variation is out of stock; in-stock variations leave the column empty so the label uses the full row.
* Removed the duplicate stock status block from the buy box. The "Show stock status" setting now controls the attribute-row readout.

= 1.1.19 =
* Variable products: the variation stock status now sits in the attribute heading row, right aligned, at the attribute label size, instead of below the swatches.
* Multi-attribute products show the stock readout on the last attribute row; products with the attribute label disabled get a stock-only row.
* Mobile: smaller product title and tighter spacing between the title, rating, category and price rows.
* Removed the tick glyph after the vendor name in the buy box.

= 1.1.18 =
* Mobile: the review filter bar (All Reviews / With Photos / sort) now stays on a single line instead of wrapping onto two rows.
* New: product category row displayed directly under the rating and sold-count row, with links to each category archive.

= 1.1.17 =
* Swatches: arrow keys now switch the variation as focus moves (selection follows focus); Enter/Space still toggles, Home/End jump to the first/last swatch.
* New: review media gallery. Clicking a review photo or video opens a full-screen viewer spanning all customer media for the product, with the reviewer, rating, variation and review text alongside.
* New: "Customer photos & videos" strip above the review feed.
* New: review video uploads (mp4/webm/mov) with their own size limit, plus two new settings under Reviews - Submission Behavior.
* Gallery filters: by variation, by star rating, and sort by newest or most helpful.

= 1.1.16 =
* Seller card chat icon now matches the design mockup (emoji glyph instead of the tinted inline SVG).
* Removed theme hover/focus underlines on the review-count link and the seller card buttons.
* Description and Reviews accordions now slide open and closed instead of snapping.
* Accordion animation respects prefers-reduced-motion and falls back to native toggling.

= 1.1.15 =
* Fix: variation attribute label was hardcoded dark grey and unreadable in dark mode.
* Fix: seller card star rating never rendered (selector targeted a child svg instead of the svg itself).
* Seller card: removed the "Sold by" label.
* Seller card: rating and product count now share a single line.
* Seller card: averaged 5-star rating on desktop and tablet, single filled star on mobile.
* Seller card: compact mobile layout - smaller avatar, 2-line store name, tighter button padding, wider middle column.

= 1.1.14 =
* New: Reports column in WP Admin > Comments, sortable by report count.
* New: "Reported" filter view beside Pending / Approved / Spam.
* New: optional auto-unapprove once a review reaches the report threshold (off by default, threshold 3).
* New: admin email on the first report of a review, and when a review is auto-unapproved.
* New: "Clear reports" row action to dismiss false reports.
* New: Report Moderation settings section in the Reviews tab.

= 1.1.13 =
* Reviews section now inherits the ZYMARG brand palette instead of the migrated plugin colours.
* Reviews section now follows dark mode automatically.
* Report Abuse modal restyled with the brand palette and a dark-mode variant.

= 1.1.12 =
* Mobile only: reviews summary and form gaps raised from 4px to 8px.

= 1.1.11 =
* Mobile only: slightly increased padding around the reviews accordion body.
* Mobile only: summary and form gaps raised from 0 to 4px.

= 1.1.10 =
* Restored the brand purple price colour; price text is no longer invisible in dark mode.
* Save badge now legible on both light and dark surfaces.
* Swatch hover, selected swatch and tooltip colours now follow the theme tokens.
* Mobile only: removed the empty gap between the site header and the product content.
* Mobile only: minimal padding on accordion bodies, no container padding and no gaps in the reviews block.

= 1.1.9 =
* Price heading now stays on its own line above the price.
* Current price, old price and save badge align on one baseline row.
* Old price strikethrough now runs through the middle of the digits.
* Reduced the gap between the old price and its underline.

= 1.1.8 =
* Reduced visual weight of current, old and savings price text.
* Save badge is now smaller and displays inline with the price.
* Old price strikethrough/underline now uses a precisely positioned line.
* Quantity stepper buttons now match the quantity input background; hover and active colours retained.
* Mobile only: removed the product section grid gap.

= 1.1.7 =
* Updated PHP requirement metadata to 8.0.
* Fixed textarea setting sanitization for shipping and returns text.

= 1.0.0 =
* Initial release
