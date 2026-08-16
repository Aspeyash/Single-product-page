=== ZYMARG Store Page ===
Contributors:      zymarg
Tags:              dokan, vendor, store, marketplace, activewear
Requires at least: 6.0
Tested up to:      6.7
Requires PHP:      7.4
Stable tag:        1.27.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Replaces Dokan's default vendor store page with the premium ZYMARG Store Page design — full hero, trust strip, featured collections, category sidebar, AURA live-search, dynamic product grid, reviews section, and collapsible store story. No theme edits required — activate and done.

== Description ==

**ZYMARG Store Page** is a drop-in replacement for the Dokan vendor store template. Once activated it automatically overrides the default Dokan store page across every vendor store on your marketplace with a fully designed, production-ready layout.

**What's included:**

* **Full-page hero** with dynamic banner image, logo, store name, verified-seller badge, and meta strip (location, followers, rating, member-since)
* **Trust highlight strip** — Verified Seller · Fast Shipping · Official Store · Secure Payment · Cash on Delivery · Easy Returns
* **Featured Collections carousel** — horizontally scrollable collection cards with badge labels
* **Shop-by-Category sidebar** — sticky on desktop, grid on mobile
* **Dynamic product grid** — powered by the Dokan REST API with sort (popular / newest / price / rating) and infinite load-more pagination; automatic fallback to local mock data when the API is unavailable
* **AURA Studio live-search bar** — debounced, keyboard-navigable dropdown with product thumbnails, prices, sale/new/low-stock badges, and category grouping; fires against `dokan/v1/stores/{id}/products?search=`
* **Customer reviews section** — star-score summary with percentage bars + individual review cards with photos, seller replies, and Verified Purchase labels; populated from `dokan/v1/stores/{id}/reviews`
* **Collapsible store story panel** — animated read-more / show-less toggle
* **Sticky header** — slides in on scroll-past-hero with avatar, store name, rating, and follow/chat buttons
* **Follow button toggle** — stateful across all instances on the page
* **Fully internationalised** — all strings wrapped in `__()` / `esc_html_e()`
* **WCAG-friendly markup** — semantic HTML5, ARIA labels, keyboard navigation throughout

**Design system:**

Uses Tailwind CSS v4 (browser build, zero build step) with a custom `@theme` token layer:
`--color-zy-primary #9500A5` · `--color-zy-secondary #BD00D1` · `--color-zy-accent #FEA9FF` · `--color-zy-dark #36003D`

**Requirements:**

* WordPress 6.0+
* Dokan (free or Pro) — any recent version with REST API enabled
* WooCommerce (installed as Dokan dependency)
* PHP 7.4+

== Installation ==

1. Upload the `zymarg-store-page` folder to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins → Installed Plugins**.
3. Visit any Dokan vendor store URL — the ZYMARG design loads automatically.
4. Fine-tune via **Dokan → ZYMARG Store Page** in the WordPress admin.

== Frequently Asked Questions ==

= Does this replace ALL store pages? =
Yes. The template override fires globally for every vendor store on your Dokan marketplace. Individual vendor customisation is on the roadmap.

= Do I need to edit my theme? =
No. The plugin hooks into `dokan_locate_template` (priority 99) and `template_include` as a fallback — no theme files are touched.

= What happens if the Dokan REST API is unreachable? =
The JavaScript automatically falls back to a curated set of mock product cards so the page always renders correctly. An error bar appears in the AURA search component to indicate the live-search is unavailable.

= Is Dokan Pro required? =
No. The plugin works with Dokan Lite. Dokan Pro features (followers count, advanced store settings) enhance the output when available.

= Can I customise the colour scheme? =
Yes. Edit the `@theme` block inside `templates/store.php` to change the design tokens, or override the CSS custom properties in `assets/css/store-page.css`.

== Screenshots ==

1. Full store page — hero, trust strip, featured collections
2. Product grid with AURA live-search dropdown open
3. Customer reviews section and store story panel
4. Admin settings page under Dokan → ZYMARG Store Page

== Changelog ==

= 1.27.0 — Reviews section now uses the Reviews Engine's full renderer (photos, videos, lightbox) =
* Changed: the Customer Reviews section on the store page now renders through `zymarg_reviews_render(['vendor_id'=>...])` (ZYMARG Reviews Engine 1.3.2+) instead of hand-rolled Tailwind markup built from `zymarg_reviews_get_data()`. Which reviews display is unchanged -- every approved review left on any product this vendor owns, aggregated into one feed, each card still tagged with which product it is about -- but the section now gets the same customer photo/video media strip and full-screen/mini-player lightbox viewer that ZYMARG Single Product's review section already has, instead of bare `<img>`/`<video controls>` thumbnails with no click-to-enlarge.
* Changed: paging switches from a plain `?zy_reviews_page=N` link to the engine's own AJAX "Load More", now that Reviews Engine 1.3.2 supports a vendor-scoped Load More request.
* Removed: the section's own hand-drawn rating-summary card (average, star breakdown bars) -- the engine's own summary card replaces it and is functionally identical (same average, same breakdown percentages, same source data), just rendered by the engine instead of by this plugin.
* Requires ZYMARG Reviews Engine 1.3.2 or later. On an older engine version (or with the engine deactivated), the reviews section does not render at all -- same fail-safe behaviour as before, just gated on `function_exists( 'zymarg_reviews_render' )` instead of an empty review list.

= 1.26.0 — Fix: "All Products" Sort control wraps to two lines on mobile =

* Fixed: on narrow mobile widths (~320–375px), the "Sort" label and its "Most Popular" dropdown in the All Products section broke onto two lines instead of staying on one row. The control's `<label>` in `templates/store.php` now carries a dedicated `zy-products-sort` class, and a new scoped rule in `store-page.css` keeps it on one line (`flex-wrap:nowrap`, a shrinking/truncating `<select>`, tighter gap/padding under 640px). The "All Products" heading label and every other section on the page are unaffected.

= 1.25.0 — Fix: old-price position; hide vendor row on Handpicked cards + section defaults; unify Trending/Best Selling heading style =

* Fixed: the old/strikethrough price on every product card rendered visibly lower and squashed compared to the same card on a page that does not load Tailwind (e.g. a plain WooCommerce single-product page). Root cause: the card markup wraps the old price in a native `<sub class="zymarg-wcpg__price-old">` element, and this plugin's compiled Tailwind preflight (`store-tailwind.css`) resets every `<sub>` tag on the page with `position:relative;bottom:-.25em;line-height:0` — a bare tag selector that wins by default because neither the Product Grid engine nor the Template Pack ever declares `position`/`bottom` for that class. Fixed with a scoped `.zymarg-wcpg__price-old { position:static; bottom:auto; line-height:1; }` rule in `store-page.css`, which as a class selector beats Tailwind's tag selector regardless of load order. Scoped to this plugin only; the shared Product Grid engine and Template Pack are unchanged.
* Changed: the Handpicked/Featured Items section's product cards no longer show the "sold by {vendor}" row. Every card on a store page already belongs to the vendor whose store is being viewed, so the row was redundant. Flash Sale cards are unaffected — only Featured Items was scoped in.
* Changed: the Trending, Best Selling, and All Products sections' default `[zymarg_products]` shortcodes now include `show_vendor="no"`. This only applies to fresh installs (or a section manually re-saved from the admin panel) — existing saved section rows on a live site are not retroactively changed.
* Fixed: the Trending and Best Selling section headings (and any section added later through the admin panel's "Add Section") now use the same eyebrow-label + visually-hidden-heading pattern as Limited Time, Handpicked, and All Products, instead of the bold/dark/larger heading style they previously rendered with. Because Trending, Best Selling, and every future admin-managed section share one render loop (`ZYMARG_SP_Store_Sections::get_generic_rows()` in `templates/store.php`), this is a single change that applies to all of them, now and going forward — nothing section-specific to maintain. The Reviews ("What buyers are saying") and Our Story headings are untouched, as they render through a separate code path.

= 1.24.3 — Fix: Columns settings had no effect when Premium Layout is Carousel =

* Fixed: the Columns (desktop/tablet/mobile) settings added in 1.24.2 only applied when the marketplace admin's Premium Layout was set to "Grid" — with Layout set to "Carousel" (the ZYMARG default), the settings saved correctly but had zero effect on the front end, which kept falling back to the engine's own hardcoded 4/3/2. Root cause: `zymarg_sp_premium_layout_config()` only attached the columns settings to the Grid branch of its layout config; the Carousel branch never received them at all.
* The engine's own slider template already reads exactly the same `columns` / `columns_tablet` / `columns_mobile` config keys to decide how many product cards are visible at once per breakpoint, so no new engine capability was needed — Carousel mode now receives the same three admin-configured numbers Grid mode does, and both Layout options respect them identically.

= 1.24.2 — Fix: Flash Sale and Featured Items grids always showed 4 columns on every device =

* Fixed: the Flash Sale and Featured Items sections (both rendered by `zymarg_sp_premium_layout_config()`) always showed a flat 4-column grid on desktop, tablet, and mobile alike — the same defect already fixed in 1.24.1 for the "All Products" section's search/category-switch AJAX repaint, just in this separate call site, which builds its config from the Vendor Dashboard's Premium Display settings rather than a shortcode. `columns_desktop` / `columns_tablet` / `columns_mobile` are now read from those settings (new fields added in ZYMARG Vendor Dashboard 1.46.14) and correctly propagated as `layout.columns` plus `responsive.tablet.layout.columns` / `responsive.mobile.layout.columns` in the engine config, so both sections now respect the admin's configured column counts at every breakpoint.
* Requires ZYMARG Vendor Dashboard 1.46.14 or newer for the new fields to exist; on an older Vendor Dashboard version, `zymarg_sp_premium_display()` fills in the same 4/3/2 defaults Vendor Dashboard itself ships as of 1.46.14, so nothing breaks and behaviour is unchanged from 1.24.1 until both plugins are updated together.
* This does not affect Carousel layout mode, which has never used a column count and is unchanged.

= 1.24.1 — Fix: All Products grid always showed 4 columns after a search or category switch =

* Fixed: after searching or switching category on the store page, the "All Products" grid always repainted as a flat 4-column layout on every device — desktop, tablet, and mobile — ignoring whatever `columns` / `columns_tablet` / `columns_mobile` / `gap` the admin had configured on the "All Products" row's shortcode. Root cause: search and category filtering repaint the grid through a separate AJAX path (`ZYMARG_SP_Grid_Bridge::ajax_render_cards()`), whose config hardcoded `columns => 4` with no responsive breakpoint keys at all — a completely different code path from the initial page-load render, which already read those same shortcode attributes correctly via `do_shortcode()`. The AJAX repaint now reads the same four attributes from the same "All Products" row shortcode (the initial render's own single source of truth) via a new `ZYMARG_SP_Store_Sections::attr_of()` helper, so a search or category switch always matches the admin's configured layout, with nothing hardcoded and no separate setting to keep in sync. The Premium sections' own layout config (Flash Sale / Featured Items) is a fully separate code path and is unaffected by this change.

= 1.24.0 — Fix: All Products grid columns/gap overridden by theme; new shared section heading + spacing component =

* Fixed: the "All Products" grid's admin-configured `columns` / `columns_tablet` / `columns_mobile` / `gap` shortcode attributes were being silently overridden at every breakpoint (desktop, tablet, and mobile), and the card gap never matched the ZYMARG card template's intended 8px spacing. Root cause: the ZYMARG OS theme ships its own base rule targeting `.zymarg-wcpg .zymarg-wcpg__grid`, and the Product Grid engine's own responsive rules target `.zymarg-wcpg__grid[data-columns-tablet="N"]` / `.zymarg-wcpg__grid--cols-N` — both selectors resolve to identical CSS specificity, so whichever stylesheet loaded last on the page won the tie, which was the theme rather than the engine. Fixed with a new rule scoped through `#product-grid` / `#product-grid-filtered` (IDs unique to this plugin's own template), reading the engine's own `--columns-desktop` / `--columns-tablet` / `--columns-mobile` / `--grid-gap` CSS custom properties so it always reflects whatever the admin configures — nothing hardcoded, and the ZYMARG OS theme file is untouched.
* Added: a shared `.zy-section` / `.zy-section-heading` / `.zy-section-heading-row` / `.zy-section-content` CSS component, now applied to every content section on the store page (the admin-managed Product Grid Sections panel — Trending, Best Selling, All Products, and any section added in future — plus Reviews and Our Story). Replaces each section's previously hand-picked, inconsistent Tailwind utilities (`pt-10` / `pt-12` / `pt-14`, `mb-6` / `mt-6` / `mt-3`, `text-xl sm:text-2xl`) with one true 3-tier responsive scale (mobile / tablet / desktop) for heading size and section spacing. Desktop sizing is unchanged from before; mobile and the new tablet tier are tightened for a more compact layout. Because the admin-managed sections loop applies these classes generically, any section added later through the settings screen inherits this automatically with no further plugin changes.

= 1.23.1 — Hero Store Card: reverted to solid card, now theme-aware in light and dark mode =

* Change: the Hero Store Card's glass/frosted background (translucent + blur, introduced in 1.22.0) has been reverted to a solid card background. Reasoning: on a multi-vendor marketplace, banner photos vary too widely in brightness and contrast from seller to seller for translucent text to stay reliably legible for everyone — a solid card guarantees consistent readability regardless of what photo a seller has uploaded, and matches every other card on the page (product cards, category cards, review cards), which have always used a solid surface.
* Fix: the card's background, name, status text, and Chat/Share buttons now use the same theme color tokens already used everywhere else on this page (the ones store-page-dark.css remaps under dark mode), instead of colors hardcoded for the glass treatment. The card now automatically matches the site's light/dark mode switch correctly - previously it was fixed to white text regardless of theme, which would have been unreadable if this card had ever been checked against dark mode.
* No layout changes - logo size/position, name/status placement, the four stats, and the Follow/Chat/Share buttons are all unchanged from 1.22.x; only the card's own background and text colors changed.

= 1.23.0 — Admin-managed Product Grid Sections (Trending / Best Selling / All Products) =
* Added: a new "Product Grid Sections" panel on the plugin's settings screen. An ordered, drag-reorderable list of sections that render on the vendor store page — each row runs one `[zymarg_products]` shortcode against the ZYMARG WC Product Grid engine's `current_vendor` source (requires engine v2.17.3+), so what a section shows, and how it's laid out, is entirely admin-editable with no plugin update needed. Ships with three default rows: Trending and Best Selling (sliders, 8 products, 5/4/2 visible on desktop/tablet/mobile), and All Products (the existing category-filtered grid, now sourced from the engine instead of a direct Dokan REST call).
* Added: rows open locked — press Edit before anything in a row can be changed, so visiting the settings screen can never alter what shoppers see by accident. Removing a row asks for confirmation, and the list from before your last save can be restored with one click.
* Added: every row must use `source="current_vendor"` — the only source a store page section can run, since a store page always shows exactly one vendor's own products. A row using any other source, or omitting the source attribute, is rejected on save.
* Changed: the "All Products" grid is now server-rendered by the Product Grid engine, with the engine's own native infinite scroll, instead of being built client-side from a direct call to Dokan's REST API. Whichever admin-configured row resolves to `current_vendor_subset="all"` (the default when that attribute is left off) is the one that renders here.
* Changed: the category-filter sidebar and AURA/mobile search are functionally unchanged — same fetch strategy, same card rendering — but now render into a new sibling container that sits alongside the server-rendered All Products grid rather than replacing it. Clearing a category or search filter now simply reveals the original grid again instead of re-fetching it, since it was never destroyed.
* Changed: the Sort control now reloads the page with a `?zy_sort=` parameter when no category filter or search is active, instead of re-fetching the grid over AJAX — the server-rendered grid has no live re-sort of its own. Sorting while a category filter or search is active is unaffected and still happens instantly, client-side, exactly as before.
* Requires: ZYMARG WC Product Grid engine v2.17.3 or later for the `current_vendor_subset` shortcode attribute used by the default sections. Older engine versions will render every section as if `current_vendor_subset="all"`, since that attribute is silently ignored below v2.17.3 — update the engine before this version if you use the Trending/Best Selling defaults.

= 1.22.6 — Fix: correct banner and logo briefly replaced by wrong images after page load =

* Fix: on page load, the correct store banner and logo (already rendered correctly by PHP) could be visibly swapped out a moment later for a different, incorrect image. Root cause: `fetchStoreDetails()` made a follow-up call to Dokan's own store REST endpoint purely to refresh live stats (followers, description, etc.), but it also unconditionally overwrote the banner and logo with whatever that endpoint returned — even though the endpoint does not always resolve the saved attachment the same way this plugin's own template does. This is the same class of bug already fixed for the store name and avatar in 1.1.3. The banner and logo are no longer touched by this script at all — PHP is now the sole source for both, exactly like name and avatar already were.

= 1.22.5 — Fix: some vendors' banner photos did not appear at all =

* Fix: on multi-vendor sites, a store banner uploaded through the vendor dashboard's own uploader could fail to appear entirely, silently falling back to the plain gradient background as though no banner had ever been set - while a banner set via the WordPress media library (as an admin typically would) displayed correctly. Root cause: Dokan can store the banner as either a URL string or a bare numeric attachment ID depending on which upload path was used, and this plugin only ever handled the URL-string case. The store logo already handled both cases correctly; the banner now uses that same resolution logic, so both upload paths display correctly.

= 1.22.4 — Hero banner: fix cut-off/gap under the banner image on mobile =

* Fix: on some sites the hero banner photo did not fully cover its container on mobile, leaving a visible gap of blank space above the store card. Root cause: many host themes (including Astra) ship a global CSS rule for content images (roughly `img { max-width: 100%; height: auto; }`) that can end up more specific than this plugin's own banner sizing rules, particularly at certain breakpoints - when the theme's rule won, the banner's height collapsed to its own aspect ratio instead of filling the fixed-height banner area. The banner image now carries an explicit, narrowly-scoped override so it always fills its container edge-to-edge regardless of what a host theme's own image styles do. Desktop and tablet, which were already unaffected, are unchanged.

= 1.22.3 — Hero Store Card: larger, more legible stats text on mobile =

* The Followers/Rating/Reviews/Products numbers and labels were shrunk quite small in 1.22.2 to guarantee they wouldn't overlap the Follow/Chat/Share buttons. Measured the actual available space at the narrowest common phone width (320px) and increased the text as far as it will go while still leaving a small safe gap before the buttons — number size 9px to 11px, label size 5.5px to 6.5px. Confirmed no overlap and no overflow past the card edge at 320px through 428px.

= 1.22.2 — Hero Store Card: stats and buttons stay on one line on mobile =

* Fix: 1.22.1 stopped the Follow/Chat/Share buttons from overlapping the Followers/Rating/Reviews/Products stats on narrow phones by letting the buttons wrap onto a second line when needed — but the layout is meant to be a single line at every phone size, not two. Measured the actual space available inside the card at real phone widths (320px up to 428px) versus how much room the stats and buttons needed, then reduced gaps, stat text size, and button padding on mobile only until both blocks fit on one line with room to spare, at every width tested, with no overlap. Tablet and desktop sizing is unchanged.

= 1.22.1 — Hero Store Card fixes: name color, duplicate follower count, mobile overlap =

* Fix: the store name on the Hero Store Card rendered in the host theme's default black text instead of white. It was only ever inheriting its color from the card, and a theme's own direct `h1` color rule overrides an inherited value regardless of specificity — the name now sets its own white color explicitly.
* Fix: the follower count briefly showed a small duplicate "followers" label underneath the new "FOLLOWERS" stat label. A second script (the Follow button's live counter, separate from the one already fixed in 1.22.0) was still writing the old pre-redesign "<strong>N</strong> followers" text into the same element on every page load — it now writes the number only, like the rest of the card.
* Fix: on narrow mobile screens the Follow / Chat / Share buttons could render on top of the Followers/Rating/Reviews/Products stats instead of moving out of the way. The stats and buttons now wrap onto their own line automatically whenever a phone is too narrow to fit both side by side — wider phones are unaffected and keep the original single-line layout.

= 1.22.0 — Hero Store Card redesign: glass card moved inside the banner, new stats, seller status =

The store card used to sit as a separate white block directly below the banner, pulled up onto it with a negative margin. It now lives inside the banner itself, anchored to the bottom and constrained so it can never grow taller than the banner — no more risk of the card spilling past the photo on unusual content.

* New: glass/frosted card style (blurred background, soft border, inner highlight) replaces the previous solid white card, so it reads correctly against any banner photo instead of only working well against a purple gradient.
* New: Online / Offline status indicator on the card, driven directly by Dokan's own seller "Enable Selling" / "Disable Selling" setting. If Dokan is not active the indicator is hidden entirely rather than guessing — this plugin never claims data it cannot verify.
* New: Reviews count is now its own stat, shown separately from the average Rating (previously combined into a single "4.4 (5 ratings)" string).
* New: Products count stat — how many published products this vendor currently has — did not exist on the card before.
* Fix: when a vendor has uploaded a real banner photo, the purple gradient tint and dark bottom fade that used to sit on top of every banner (including real photos) are now removed entirely. Those two overlays are kept only as part of the gradient background shown when no banner has been uploaded — a real photo is now shown exactly as uploaded, with no tint.
* Layout: responsive per device — a single row on desktop (name/status, then stats, then Follow/Chat/Share, right-aligned); a three-column row on tablet (logo+name/status taking the remaining width, then stats, then actions); two stacked rows on mobile (identity row, then a tightened stats+actions row).
* The "Since <year>" and Location rows, and the store tagline, are no longer shown directly on the hero card in this version — the card now focuses on identity, live status, and the four core stats. (Location and tagline can still be added back to a future revision if wanted; the underlying data is unchanged.)
* Follow / Chat / Share buttons keep their existing colors and click behavior exactly as before — only their position and sizing on the card changed.
* JS: fixed the live store-details refresh (`fetchStoreDetails()`) writing "<strong>N</strong> followers" directly into the followers stat, which would have duplicated the word "Followers" next to the new stat label — it now writes the number only.

= 1.20.0 — Flash Sale hero is now fully designable from the admin =

The banner at the top of the Flash Sale page was three hard-coded sentences and
a hard-coded gradient. You could not change the eyebrow text, let alone the
colours, without editing PHP. It now has a full control surface under
**Store Page → Settings → Flash Sale Hero**.

**Content**

* Eyebrow, title, subtitle, button label and button URL
* Background image, drawn as a real `<img>` with `fetchpriority="high"` so it does not cost you Largest Contentful Paint
* Left or centred text
* Optional live deal count — "12 deals live now"
* Optional countdown to the soonest-ending sale

**Design**

* Minimum height, padding scale, content width, bottom corner radius
* Full gradient control: start, middle and end colour plus the angle
* Text colour, and an image-darkening control so light text stays readable over a photo
* Title and subtitle sizes, or leave them at 0 to keep the built-in fluid sizing

**Hero slides**

* Add two or more slides and the hero becomes a swipeable banner. It is a CSS
  scroll-snap track, not a scripted carousel: it swipes on touch, works from the
  keyboard, needs no JavaScript, and adds nothing to the page weight.

**Your own design**

* **Custom Heading HTML** replaces just the eyebrow/title/subtitle and leaves the rest of the hero alone
* **My own HTML design** replaces the whole hero. Paste a complete HTML file exactly as your developer wrote it — the document wrapper is removed and the stylesheet is rewritten so it can only affect this section. It cannot reach your header, footer or the product grid
* Live data placeholders: `{{eyebrow}}` `{{title}}` `{{subtitle}}` `{{cta_label}}` `{{cta_url}}` `{{bg_image}}` `{{deal_count}}` `{{ends_at}}`, plus `{{#slides}}…{{/slides}}` to repeat per slide
* **Extra CSS** now works on the built-in design too, not only alongside a pasted document

**Nothing changes until you change something.** Each control emits CSS only when
it is moved off its default, so the page renders exactly as it did in 1.19.3
until you deliberately edit it. Upgrading is visually a no-op.

**Under the hood**

* Settings live in their own `zymarg_sp_flash_hero` option, so they are not at risk from the existing settings sanitiser
* The countdown is rendered server-side as an absolute deadline with a formatted date fallback, so it survives page caching and still reads correctly with JavaScript off
* Fixed: the settings screen posted a hand-maintained list of four field names over Ajax, so any new control would have silently failed to save while still reporting success. It now posts the whole form
* The custom-design engine is entirely self-contained — this plugin gains no dependency on any other ZYMARG plugin, and the hero behaves identically whichever others are active

= 1.19.3 — Fix: flash sale card layout shift on reload, made cache-proof =
* Fix: The Flash Sale card still jumped on reload — rendering huge, then settling — even after the earlier attempts. The card's image geometry lives in its own stylesheet, and although the plugin enqueues that stylesheet early, an enqueued file is a separate request whose arrival the plugin does not fully control: a page-cache plugin can serve HTML pointing at a stale sheet, a CDN or proxy can delay or reorder it. The Flash Sale section is the only card present in the store page's initial HTML, so when its sheet is late it paints before its geometry arrives and one product fills the screen.
* Fix: The load-bearing geometry — the grid track and the 1:1 image box, for both the general and flash cards — is now printed inline in the `<head>`. Inline CSS is part of the HTML document, so it cannot arrive late relative to the card it sizes, regardless of caching, CDN or enqueue order. It is the minimum needed to reserve space and nothing cosmetic; the card's own stylesheet still owns the rest.
* Fix: A genuine gap in the earlier safety net, not just a delivery problem. It sized the image box and the image but not the image *link* — which is an `<a>`, `display:inline` by default. With the link left inline the image had no definite parent height to resolve against and escaped to its natural size, which is the single-giant-card symptom. The link is now forced to a filling block in both the inline critical CSS and the enqueued sheet, matching what the card's own CSS does.
* Note: Every critical rule is wrapped in `:where()` for zero specificity, so the engine's `frontend.css` and the card's `style.css` override all of it the instant they load. It cannot pin a value that later changes upstream; the worst it can do is briefly hold the correct shape.
* Note: The early enqueue from 1.18.2 and the compiled Tailwind from 1.19.1 both remain. This is the third layer, and the only one immune to caching, because it is the one that was still failing in the field.

= 1.19.2 — Fix: the /flash-sale/ page rendered nothing in 1.19.0 =
* Fix: The dedicated /flash-sale/ page showed no products at all after 1.19.0. The `premium_flash` source class was declared *inside* the engine's source-registry filter, which only runs when the engine resolves a source — that is, during a render. The page checked `class_exists()` first to decide whether to render at all, saw nothing, skipped the source and fell through to an empty page. The guard ran before the thing it was guarding.
* Fix: The class is now declared by `zymarg_sp_declare_premium_flash_source()`, hooked to `zymarg_wcpg_init` — the engine's own boot on `plugins_loaded:20`, after `Source_Base` can be autoloaded and well before any render. `class_exists()` is answerable by the time a template asks. The registry filter calls the same function rather than assuming it already ran, so an AJAX request that reaches the filter by a different path still registers the source.
* Note: The store page's own Flash Sale and Featured sections were never affected by this — they use a pre-fetched list and never touch the source. Only the dedicated page broke.
* Note: Regression tested by stubbing the engine's `Source_Base` and confirming the class is absent before `declare()`, present and correctly subclassed after, and that a second call is a no-op.

= 1.19.1 — Fix: the store page layout shift, at its actual cause =
* Fix: Entering a store page showed one enormous card filling the screen, with the whole page snapping into position a moment later, on every load. `store.php` is written almost entirely in Tailwind utilities — 117 of its 140 `class` attributes — and the only thing generating those rules was the Tailwind *browser* build: a JIT compiler that must download from a CDN, parse, scan the DOM and inject a stylesheet before any of them exist. Until it finished the page had no layout at all: no container widths, no grids, no flex, just full-width block flow.
* Fix: Those utilities are now compiled ahead of time with the Tailwind v4 CLI into `assets/css/store-tailwind.css`, built against the same `@theme` block `store.php` declares so the output is what the browser build was producing anyway. It is an ordinary stylesheet, so it reaches the `<head>` and is in force before first paint.
* Note: What identified the cause was that the store *listing* and the /flash-sale/ page never shifted. Both take their layout from ordinary stylesheets — `zsl-` classes in `store-listing.css`, plain CSS on the flash page — and only the store page depended on the compiler. Three surfaces, one difference.
* Note: The browser build is deliberately kept. A utility assembled at runtime in JavaScript cannot be seen by a static scan, so the compiler stays as the safety net for anything the build missed. It is no longer required for the page to lay out, which was the actual bug. Once you are satisfied nothing depends on it, removing it is a straight performance win: it is a compiler shipped to every visitor.
* Note: `assets/css/store-tailwind.src.css` is the build source, with the rebuild command in its header. Re-run it after adding Tailwind utilities to any template, or they will only appear once the browser build catches up.
* Note: The two earlier attempts at this were both wrong. 1.18.3 constrained the Premium section wrapper, which could not help because the card was what was oversized; 1.18.4 added a zero-specificity safety net for the grid and image box, which was worth keeping but treated a symptom two levels below the cause.

= 1.19.0 — Flash sales page now pages properly, at any catalogue size =
* New: Premium flash sales are registered as a real Product Grid source (`premium_flash`), so the /flash-sale/ page has load-more and infinite scroll like every other grid.
* Fix: That page previously stopped after its first batch. It handed the engine a pre-fetched list, which skips the Query Engine — and load-more works by re-running the query with an offset, so there was no query to re-run. A marketplace with a thousand live flash sales could only ever show the first two dozen of them, with no way to reach the rest.
* Fix: The old approach also scanned up to 800 products on every uncached request and then displayed 24 of them — slow on a large catalogue and still incomplete. The source now walks a cursor in batches of 100 and stops the moment it has enough for the page being viewed, so cost is proportional to the page, not to the size of the catalogue. Hard scan ceiling of 2000 per request.
* Note: The Premium approval workflow keeps sole authority. Every candidate is put through `zymarg_vd_premium_flash_is_live()`, which applies the admin master switch, the vendor's approval, a positive price and both ends of the date window. None of that is reimplemented in the source.
* Note: The date window is deliberately not checked in SQL. It would be faster, but it would mean writing Premium's liveness rule a second time against unindexed date strings, with "empty means open-ended" on both ends and a site-local comparison Premium performs in PHP. Two copies of that rule would eventually disagree, and the disagreement would surface as products shown on a page Premium considers expired.
* Change: Ordering on that page is now newest flash sale first, not soonest-ending. Soonest-ending suits a countdown page but cannot be done correctly in SQL here — the end date is an unindexed string and an empty value means "no finish", which sorts before every real date and would float the least urgent sales to the top. Doing it in PHP would require reading every flash product on every page load, which is the cost this release exists to remove. Override with `zymarg_sp_premium_flash_query_args`.
* Fix: The card's countdown and scarcity bar now survive load-more. The two window filters were scoped around each render, and load-more re-renders in a separate AJAX request where that scope no longer exists — so appended cards lost their countdown while the first page kept one. They are now hooked globally, which is safe because both callbacks return the incoming value untouched unless the product has a live Premium flash sale.
* Note: The store page's own Premium sections still use a pre-fetched list. They are capped by the Vendor Dashboard at ten products and need no paging, and the pre-fetched path guarantees exactly the ID list Premium returned.
* Note: The source accepts `premium_flash_vendor` to scope to a single store, so the same source can serve a store page as well as the marketplace-wide page.

= 1.18.4 — Fix: a single product could fill the whole screen before the CSS arrived =
* Fix: On entering a store page one card rendered enormous, occupying the entire viewport, and only settled once the page had finished loading — every time, not just on a first visit. Two rules were missing at first paint and each does real damage on its own: with no grid rules a card becomes a full-width block, and with no image rules its `<img>` is laid out at natural resolution, which on a large product photo is exactly the reported symptom.
* Fix: `store-page.css` now carries a first-paint safety net for the product grid and the card image box. It is ordinary CSS enqueued on `wp_enqueue_scripts`, so it is in the `<head>` before anything paints.
* Note: Every rule in that safety net is wrapped in `:where()`, which has zero specificity. The instant the engine's `frontend.css` and the card template's own `style.css` load they override all of it, with no `!important` and no heavier selectors. It cannot fight them, and it cannot go stale when either ships a new layout — the worst it can do is briefly approximate them.
* Note: 1.18.2 already enqueues those stylesheets early to avoid this, and that remains the primary mechanism. This release accepts that a correct stylesheet arriving late is worse than an approximate one arriving on time, and covers the two rules whose absence is destructive rather than cosmetic.
* Note: The width fix in 1.18.3 was real but addressed only the Premium section wrapper. It could not help the card itself, which is what was actually oversized.

= 1.18.3 — Fix: Premium layout shift, and the /flash-sale/ page was always empty =
* Fix: The Flash Sale and Featured sections rendered full-bleed on first load, stretching a card across the whole page, then snapped into place — and looked correct after a reload. Their width came from Tailwind utilities (`max-w-7xl`), and this page loads Tailwind's *browser* build: a JIT compiler that downloads, scans the DOM and only then generates CSS. Until it finished those classes did not exist. On a second visit the compiler is cached and applies almost immediately, which is why the fault looked intermittent and self-healing.
* Fix: Those sections now take their layout from `store-page.css` — ordinary CSS enqueued on `wp_enqueue_scripts`, so it is in the `<head>` and in force before the first pixel. The Tailwind classes remain on the markup and are harmless; they resolve to the same values once the compiler catches up.
* Fix: The dedicated /flash-sale/ page showed no products, on any marketplace running its flash sales through Vendor Dashboard Premium. The page asked the engine's `flash_deals` source, whose definition is "on sale in WooCommerce with a future sale end date" — which Premium never satisfies and never will, because it applies its price at runtime and deliberately leaves `_sale_price` empty so its products stay out of WooCommerce's global on-sale lists. The page was therefore structurally incapable of finding them.
* Fix: The page now gathers live Premium flash sales marketplace-wide, ordered soonest-ending first, rendered with the flash card and fed from Premium's own dates. Premium exposes only a per-vendor lookup, so candidates are found by its meta flag and each one is then put through `zymarg_vd_premium_flash_is_live()` — which applies the admin master switch, the vendor's approval and the date window. The approval workflow keeps full authority and none of it is reimplemented here.
* Note: The engine's `flash_deals` source is retained as a fallback, so a site that runs its flash sales through WooCommerce sale windows instead still gets a populated page.
* Fix: A crash that had not shipped yet. `premium_flash_ids()` referenced `CACHE_KEY` and `CACHE_TTL`, two class constants dropped when this file was rewritten in 1.17.0. An undefined class constant is a fatal error on PHP 8, and `php -l` does not catch it. Both are restored, and the cache is now flushed on product save, trash, untrash and delete rather than waiting out its five-minute TTL — otherwise a vendor switching a flash sale on would not appear here for up to five minutes.
* Note: Products on this page are handed to the engine pre-fetched, which means no load-more. The list is capped by `zymarg_sp_flash_per_page` (24 by default, hard limit 100). Paging it needs a custom source rather than a pre-fetched list.

= 1.18.2 — Fix: cards stacked vertically and rendered unstyled =
* Fix: Product cards rendered in a single vertical column with no styling. The Product Grid engine registers its stylesheets on `wp_enqueue_scripts` but only enqueues them from inside its render routine — and every render this plugin performs happens inside a template (`store.php`, `flash-sale.php`), which runs long after `wp_head()` has been sent. WordPress therefore printed those `<link>` tags in the footer. Since `frontend.css` is where the grid itself is defined, the cards were plain blocks until the very end of the document.
* Fix: All engine and card stylesheets are now enqueued during `wp_enqueue_scripts`, before first paint — `zymarg-wcpg-frontend`, quick view, slider, and the `style.css` shipped beside each card template. Enqueueing under the engine's own handles keeps this idempotent: when the engine reaches its render-time enqueue it finds the handle already enqueued and does nothing, and the URL and version stay exactly what the engine would have produced.
* Fix: The engine's frontend and quick-view scripts are enqueued on these pages too, so add to cart, wishlist and quick view are wired on a render path the engine was not expecting.
* Note: Both card templates are preloaded — the general card and the flash card. Which one a page needs depends on the vendor's Premium approval state, which is not resolvable this early in the request, so preloading both is correct rather than guessing wrong.
* Note: This also fixes the same latent problem on the /flash-sale/ page added in 1.17.0, which renders through the engine from a template for exactly the same reason.
* Note: `zymarg_sp_preload_grid_assets` filter lets another surface opt in to the same preloading.
* Note: The engine handles are matched by name rather than by reading its `Assets` class constants. That class is documented as internal, so depending on its shape would be a worse dependency than depending on two stable strings — and a handle that is not registered is simply skipped.

= 1.18.1 — All Products grid renders the ZYMARG general card =
* New: The main "All Products" grid on a vendor's store page now renders the ZYMARG Template Pack's general card. Every product card on the store page is now a Template Pack card — the Flash Sale strip, the Featured Items strip and the product listing itself.
* Removed: `renderCard()` from `store-page.js` — 60 lines of hand-written card markup built in JavaScript. It was the last hand-rolled product card in this plugin. Nothing here draws a product card any more.
* Note: The search bar, sort control, category filter and infinite scroll all behave exactly as before. They were never the problem: each already resolved its own product list from Dokan's REST API, and what they could not do is draw a PHP card template. They now send the product IDs they have resolved to the server and inject the card markup that comes back, so the interaction logic is untouched.
* New: `ZYMARG_SP_Grid_Bridge::ajax_render_cards()` — a read-only endpoint that renders products by ID and writes nothing. Nonce-checked, `absint`-filtered, capped at 60 IDs per request, and registered for logged-out visitors too, because shoppers browse stores logged out.
* Note: Appended batches are lifted out of the returned widget and moved into the widget already on the page, rather than appending a second widget that would leave two independent grids stacked. This matters for more than layout: the engine binds add-to-cart, wishlist and quick view by delegation from an ancestor, so cards appended into the existing widget stay functional while cards dropped in with no widget around them would have looked correct and done nothing.
* Fix: The grid container is now a `<div>` rather than a `<ul>`, because the returned markup carries the engine's own grid wrapper and a `<div>` inside a `<ul>` is invalid. The five status messages written into that container (load failure, no search results, category loading, no category results, category load failure) were converted to `<div class="zy-grid-msg">` with their closing tags corrected to match.
* Fix: `applySearchToGrid()` is now `async`. It paints the grid, which is now an awaited server call.
* Note: Product cards on this page gain working add-to-cart, wishlist and quick view. They had none before — the old card was a single link to the product page, and its wishlist button was decoration with no handler behind it anywhere in the file.
* Note: Known cost, and the next thing to improve — the first paint now makes two requests: the existing Dokan REST call for which products, then one call for their markup. Server-rendering the first page would remove that round trip and make the catalogue crawlable, which it currently is not, but it needs the initial JS fetch to stand down and that is a separate change.
* Note: Requires ZYMARG WC Product Grid 2.10.0+ and ZYMARG Template Pack 1.7.0+.

= 1.18.0 — Premium sections render through ZYMARG Template Pack =
* New: The Flash Sale and Featured Items sections on a vendor's store page now render through the ZYMARG WC Product Grid engine using the ZYMARG Template Pack's card templates — the "flash" card for Flash Sale, the "zymarg" card for Featured Items. Updating Template Pack restyles both sections with no change to this plugin, because the card's stylesheet and script are loaded from beside the card template rather than copied in here.
* Removed: `zymarg_sp_premium_card()`. This plugin drew its own product card in hand-written markup; that design could only ever drift from the rest of the site. No hand-rolled product card remains in the Premium sections.
* New: `ZYMARG_SP_Grid_Bridge` — the single place this plugin talks to the engine. It calls only `Public_API::render()`, the one entry point the engine documents as stable; everything else in the engine is declared internal and free to change between releases. Modelled deliberately on the ZYMARG Homepage plugin's bridge, which is already proven against this same engine.
* Note: The Vendor Dashboard's Premium workflow keeps complete authority over what appears. Both sections still read `zymarg_vd_premium_get_vendor_flash_ids()` and `zymarg_vd_premium_get_vendor_featured_ids()`, which apply the admin master switch, the seller's request, the pending state, the approval and the per-vendor caps internally. No gate is duplicated here and none was added.
* Note: Products are handed to the engine pre-fetched, so its Query Engine is skipped entirely. That is what keeps the decision with Premium — the engine is asked to draw a list, never to choose one. It also means the Vendor Dashboard's own defensive grid exclusion, which only runs inside engine Source classes, cannot strip these products back out.
* Fix: The flash card's countdown and scarcity bar now work for Premium products. Both are resolved by the card from WooCommerce's on-sale fields, which Premium never writes — it applies the flash price at runtime and deliberately leaves `_sale_price` empty so Premium products stay out of WooCommerce's global on-sale lists, the homepage Flash Deals and the /flash-sale/ page. Premium's own dates are now fed in through the two filters added in Template Pack 1.7.0, hooked around the Flash Sale render only. Nothing here reads or writes `_sale_price`, `get_sale_price` or `is_on_sale`, so that isolation is intact.
* Note: The countdown expires at the same instant Premium stops applying the flash price, not at UTC midnight. Premium compares site-local time against a UTC-parsed date, so the real end is `strtotime( end ) - gmt_offset`; the conversion mirrors Premium's own arithmetic rather than approximating it. On a Bangladesh store the naive reading would have expired the timer six hours early.
* Note: A Premium flash sale with no end date shows no countdown, and one with no start date shows an empty scarcity bar. Both are honest answers. Left to the engine, a missing start date falls back to the product's creation date and counts the product's entire sales history as "sold in this sale" — an older product would open its flash sale looking nearly sold out.
* Change: When the admin picks the carousel layout, the engine's slider renders it and `premium-carousel.css` / `premium-carousel.js` have been removed. Premium's "continuous" rotation maps to the slider's free momentum scrolling, the nearest honest equivalent; the engine's slider has no marquee mode, so the marquee speed setting no longer applies. Glide speed still does.
* Note: Requires ZYMARG WC Product Grid 2.10.0+ and ZYMARG Template Pack 1.7.0+. Without the engine the Premium sections render nothing rather than falling back to a second card design. Without Template Pack the engine substitutes its own "classic" card, which is a different design.
* Note: Not in this release — the main product grid, which is still rendered client-side by `store-page.js`. It is deliberately separate: that grid is bound up with sort, the category filter, the AURA search and infinite scroll, and putting it in the same release as the Premium work would mean a problem in one taking down the other.

= 1.17.0 — Marketplace-wide Flash Sale page =
* New: A standalone Flash Sale page at /flash-sale/ listing every flash sale running anywhere on the marketplace, from every vendor, ending soonest first. This is the plugin's fourth public surface; it previously served only the vendor store page, the store directory and the My Account "Following" section.
* New: The page provisions itself. On activation, and once after updating, the plugin looks for the slug and only creates a page when nothing holds it. A page you already built at that slug is adopted rather than duplicated, so you cannot end up with both "flash-sale" and "flash-sale-2". If a product or another post type owns the slug, the plugin stands down instead of fighting it for the URL.
* New: `[zymarg_flash_sale]` renders the same grid anywhere. The created page contains it as a safety net, so if another plugin ever wins `template_include` the page still shows its products rather than rendering blank.
* New: Filters `zymarg_sp_flash_sale_slug`, `zymarg_sp_flash_sale_title`, `zymarg_sp_flash_per_page` and `zymarg_sp_flash_render_config`, plus `ZYMARG_SP_Flash_Sale::page_url()` so other ZYMARG plugins can link here without hardcoding the slug.
* Note: The card design is NOT in this plugin. The page renders through the Product Grid engine using the ZYMARG Template Pack's "flash" card, and the engine loads that card's CSS and JS from beside its template. Updating Template Pack therefore restyles this page with no change here — the design is never copied into this plugin, so it cannot fall behind.
* Note: Which products count as a flash sale is also not decided here. The engine's `flash_deals` source owns that definition (on sale now, with a sale end date still in the future, admin-flagged, and passing its eligibility check), and this page asks it with `flash_vendor_scope` set to `site`. Left on `auto` the source would scope to a single vendor whenever a vendor context happened to be resolvable, which on a marketplace page would quietly show one seller's deals instead of everyone's.
* Note: The Vendor Dashboard's own flash-sale meta is intentionally not used here. That mechanism powers the per-vendor strip on a store page and has a different definition of "live"; the flash card is built against the engine's definition and needs the sale end date, managed stock and stock quantity that its validator guarantees.
* Note: No Tailwind on this page. The store page and store directory need the Tailwind browser build because they are written in utilities; this template is plain CSS against the shared brand tokens, so it inherits dark mode and skips that payload.
* Note: An empty grid and a broken one do not look alike. With no live sales the page says so; with the Product Grid engine or Template Pack switched off, administrators get a notice naming the missing plugin while shoppers see the ordinary empty state. Nothing is invented to fill the space.
* Note: Deactivating the plugin leaves the page in place. It may carry your own copy, translations, SEO metadata and inbound links.

= 1.16.8 — Infinite scroll on the store directory =
* New: The store directory loads the next page of stores as you reach the bottom, matching the behaviour of the product grid on an individual store page.
* New: Store cards now render from a single shared template, so a card appended by infinite scroll is the same markup as the ones already on the page rather than a second copy that can drift.
* Note: The numbered pager is still rendered and is only hidden once the script has confirmed it can take over. With JavaScript unavailable, or in a browser without IntersectionObserver, the directory still pages normally and stays crawlable.
* Note: If a page fails to load, it says so and the numbered pager comes back. Nothing is silently skipped and no filler cards are shown.
* Note: The address bar is kept in step with the page you have scrolled to, so a reload or a shared link does not throw you back to the top.
* Fix: The plugin header still described a "mock fallback" for the product grid. That fallback was deleted in 1.16.7.

= 1.16.7 — No invented data anywhere on the store page =
* Fix: Removed the mock product catalogue. Twelve invented products (AeroFlex Pro Running Shoe, StrideCore Performance Tee and friends) with invented prices, ratings and sold counts were hardcoded into `store-page.js` and rendered on real vendors' stores whenever the Dokan REST call failed. A shopper could be shown stock that does not exist, and a seller credited with sales they never made.
* Fix: A failed catalogue load now says so. It previously fell through to the fake catalogue and printed "12 products in store". An empty store and a broken request are different statements and no longer look alike.
* Fix: Quick view could open a fabricated product detail page for a product that was never listed.
* Fix: Search no longer falls back to matching invented product names. When live search fails it reports that it is unavailable instead of returning invented matches.
* Fix: Infinite scroll paged through the fake array. With no catalogue loaded it now stops cleanly.
* Fix: Product images no longer fall back to a random photo from picsum.photos. A product with no image gets a neutral inline placeholder that does not claim to depict anything.
* Fix: The store cover no longer falls back to a random picsum landscape seeded with a fictional brand. Every store without a banner was showing the same borrowed photo; the brand gradient is used instead.
* Fix: Product categories without a thumbnail no longer borrow a random stock photo. They show a neutral initial.
* Fix: Repaired corrupted characters in two comment banners in `store-page.js`.

= 1.16.6 — Real store ratings and real store reviews =
* Fix: The store rating was hardcoded. `$store_rating` fell back to `4.8` and `$rating_count` to `12480`, so a brand new store with no customers displayed "4.8 (12,480 ratings)" in the header, the sticky bar and the reviews summary.
* Fix: The summary drew five solid stars unconditionally, regardless of the score next to them. Filled stars now follow the real average.
* Fix: The rating breakdown bars were a fixed 82/11/4/2/1 curve written into the template. They now come from the real distribution of this vendor's reviews, and each bar's tooltip reports how many reviews it represents.
* Fix: Three fictional reviews ("Nusrat R.", "Tanvir A.", "Sadia A.", with stock photos and a fake vendor reply) shipped as static markup and were shown to every visitor of every store. Removed.
* Fix: Product cards and the quick-view drawer invented a `4.7` rating and a `120 sold` figure for anything the API did not describe. Unrated products now show no stars, and products with no sales figure show no "sold" line.
* New: The store page now reads its rating and its review feed from the ZYMARG Reviews Engine, aggregated across every product the vendor owns. Requires ZYMARG Reviews Engine 1.0.2 or later.
* New: When a store has no ratings, every rating surface hides itself completely — header, sticky bar and summary. No placeholder, no "no reviews yet" notice, no zero score.
* Change: The reviews section is read-only by design. Buyers write reviews from My Account against an order they actually placed; the store page displays them and never collects them.
* Change: The review feed is paginated with plain links (`?zy_reviews_page=`) and shows every review the store has, not a fixed three. It works with JavaScript disabled.
* Change: "Verified Purchase" is now printed only on reviews that are actually verified. The old markup and the old fetch applied the badge to every review unconditionally.
* Change: Each review now names and links the product it was written about, which matters on a store page where reviews span the whole catalogue.
* Removed: `fetchStoreReviews()`. It queried Dokan's separate seller-review endpoint and, on any failure, silently left the fabricated reviews on screen.

= 1.16.5 =
* Fix: Store listing controls now fill the full container width. The row was shrink-wrapping to its contents, leaving a large empty gap to the right of the sort dropdown on desktop. The search field is now the elastic item and absorbs the leftover space.
* Fix: Store count rendered as "128stores" with no space. The count used `display: flex`, which turns the number and the word into separate flex items and discards the whitespace between them. Now centred with `line-height` instead.
* Change: Desktop control order is now search, Search button, store count, sort dropdown. The "Sort by" caption is centred over its dropdown.
* Change: Mobile controls are squeezed into a single row — sort, store count, search field, search button — instead of wrapping onto two rows.
* Change: Mobile sort control is now an arrow-only 44px button. The truncated "Most pr..." label wasted horizontal space; the full option names are still shown in the native picker.
* Change: Mobile submit button is icon-only (magnifier). An `.zsl-sr` span keeps it named for screen readers and the 44px tap target is preserved.
* Change: Search placeholder is now "Search by store name".
* Fix: The "No categories yet" notice on the vendor store page is no longer shown to shoppers. Its wording ("Products will appear here once they are assigned to categories") was an instruction to the seller that buyers could not act on. The whole category sidebar is now omitted for buyers when a store has no categories, and the product grid expands to full width.
* New: Store owners and shop managers still see the categories notice, reworded as actionable guidance and marked "Only visible to you".
* Fix: Store listing subtitle is split across two lines and centred.
* Fix: Rounded-corner consistency on the store listing — the sort select, pager links, and card logos used a 10px control radius that did not match the rest of the design. Badge pills are now explicitly pill-radiused so they stay rounded even if the Tailwind CDN fails to load.
* Housekeeping: `Stable tag` in readme.txt was stuck at 1.5.0 while the plugin header had moved on; both now track the same number.

= 1.5.0 =
* Fix: Chat popup now correctly opens conversations when using ZYMARG Communication plugin v1.11.0+. The REST API endpoint `/conversations` requires an `initial_message` field — the pre-filled textarea text is now sent as the opening message payload so the conversation is created successfully. The message is still only transmitted to the seller after the buyer presses Send.

= 1.4.3 =
* New: Unread badge on Chat buttons — when the seller replies while the buyer has the popup closed, a red dot appears on every "Chat" button on the page. The badge clears the moment the buyer reopens the popup. Implemented via a MutationObserver on the live-chat widget's messages container so it works with the SSE stream from the Communication plugin without touching its internals.

= 1.4.2 =
* Fix: Chat popup no longer auto-sends a message on behalf of the buyer when they click "Chat". Previously "Hi, I'm interested in your store." was silently posted to the seller the moment the popup opened. Now only the conversation is created (or resumed) — no message is sent until the buyer hits Send.
* Improvement: The chat textarea is pre-filled with "Hi! I'd love to know more about your products." as a friendly prompt that the buyer can edit, delete, or send as-is. Nothing is transmitted until they choose to send.

= 1.4.1 =
* Fix: Mobile search dropdown gap reduced to 0px — sits flush directly below the search bar.
* Fix: Mobile search dropdown left/right offset set to explicit 8px — aligns perfectly with the search bar on both sides.

= 1.3.8 =
* Fix: Mobile search sheet loading state now uses the ZYMARG Discovery Spark animation instead of a plain CSS spinner — matches desktop AURA bar exactly for both the full-screen first-search state and the overlay loading bar.

= 1.3.7 =
* Feature: Mobile search sheet now has full feature parity with the desktop AURA bar.
* Feature: Category pills added to the mobile sheet — loaded from the same Dokan API as desktop, with expand/collapse and active state; tapping a pill filters the grid and closes the sheet.
* Feature: Search results in the mobile sheet are now grouped by category with labels, matching desktop layout.
* Feature: Sale, Out of stock, Low stock, and New drop badges now appear in mobile search result rows (requires stock_quantity and date_created — added to API _fields).
* Feature: SKU shown in result row meta on mobile, matching desktop.
* Feature: Mobile loading state now shows an overlay bar when previous results exist, keeping them visible while a new query is in flight — matches desktop behaviour.
* Feature: aria-live status announcements added to the mobile sheet for screen reader parity with desktop.
* Fix: Debounce delay on mobile aligned to 180 ms (was 200 ms) to match desktop.

= 1.3.6 =
* Fix: Mobile search sheet — pressing Enter no longer re-opens the dropdown; clearTimeout(sheetDebounce) is now called on Enter, matching the desktop fix from 1.3.4.
* Fix: Mobile search clear button now also resets the product grid when a search filter is active (calls clearSearchFilter), matching desktop behaviour.
* Fix: Mobile ArrowUp from an unselected state (sheetActiveIdx = -1) no longer incorrectly jumps to the first result; ArrowDown/Up both bail early when no rows exist.

= 1.3.5 =
* Fix: Mobile search sheet no longer sets aria-hidden on an ancestor while the search input still holds focus — input is blurred before the sheet is hidden, eliminating the WCAG "aria-hidden on focused element" violation and the associated browser console warning.
* Improvement: closeSheet() now also sets the inert attribute alongside aria-hidden, removing the sheet from the tab order and assistive-technology tree in one step. inert is removed on open. Gracefully ignored by browsers that don't support it (pre-Chrome 102).

= 1.3.4 =
* Fix: AURA search dropdown now correctly stays hidden after pressing Enter — the pending debounce timer is now cancelled on Enter so the delayed triggerSearch callback can no longer re-open the dropdown after it was intentionally closed.

= 1.3.3 =
* (skipped — internal build)

= 1.3.2 =
* Fix: AURA search spinner now appears instantly on first keystroke instead of waiting for the 180 ms debounce + API latency — users get immediate visual feedback.
* Fix: Previous search results stay visible as a loading bar overlay while a new search is in flight — no more blank-then-results flash when refining a query.
* Improvement: Debounce delay reduced from 260 ms to 180 ms for a noticeably snappier search-as-you-type experience while still preventing API spam.

= 1.3.1 =
* Fix: fetchSearchPage moved to outer scope so loadMoreSearchResults can access it — previously it was trapped inside initAuraSearch() causing load-more to silently fail during search.
* Fix: Load-more button now correctly shows "Load more products" and appends next 20 search results on each click, matching the normal grid behaviour.

* Improvement: Search now shows first 20 results instantly on Enter or "See all results in store ↓" — no more waiting for all pages to load.
* Improvement: Load-more button now works inside search mode — each press appends the next 20 matching products to the grid until all results are shown.
* Removed: fetchAllSearchProducts (was fetching every page before showing anything — caused long wait on large stores).

* Fix: "See all results" and Enter now fetch ALL matching products (paginated, up to 100 per page) instead of reusing only the 8 shown in the dropdown preview — customers now see every matching product in the grid.
* Change: "Show all X results for '...' in product grid ↓" button text replaced with the cleaner "See all results in store ↓".
* Improvement: Enter key on the search bar also triggers a full all-pages fetch before applying results to the grid.
* Improvement: Fallback to the 8-result preview set if the full fetch fails (e.g. API unavailable), so the grid always shows something.
* Fix: Plugin folder name corrected to `zymarg-store-page` (no version suffix) so WordPress treats uploads as in-place updates rather than new plugins — eliminates the fatal class-redefinition error that occurred when the old and new plugin loaded simultaneously.
* Fix: Gap between store name and followers line in the sticky nav increased from 0.5 (2 px) to gap-2 (8 px) for better readability.
* Fix: Gap between category name and product count in the sidebar category list increased from gap-0.5 (2 px) to gap-2 (8 px) — prevents text from appearing cramped on all screen sizes.

= 1.2.4 =
* Fix: Pressing Enter in the AURA search bar now correctly fires results on desktop — the search input is wrapped in its own <form onsubmit="return false"> so the WordPress theme's header form can no longer intercept and swallow the Enter key.
* Fix: Enter handler rewritten to call triggerSearch with immediate=true (zero debounce delay) then await the result — eliminates the self-abort race condition where the previous fetch was being cancelled before results could be applied.
* Fix: Typing a new keyword after "See all results" now works correctly.
* Fix: aura-clear button given explicit type="button" to prevent accidental form submission.
* Admin: Top-level sidebar menu renamed from "ZYMARG" to "Store Page".
* Admin: Duplicate "Dashboard" submenu removed — only "Settings" now appears under Store Page.

= 1.2.3 =
* Fix: Pressing Enter in AURA search bar now immediately cancels the debounce and fires the API fetch, then applies results to the product grid (race condition fix).
* Remove: Keyboard hint footer row (↑↓ navigate / ↵ quick-view / Esc close) from the search dropdown.

= 1.2.1 =
* Fix: Products heading no longer shows hardcoded "266 products in store" on page load — now shows "Products in store" instantly, then updates to the real count from X-WP-Total once the API responds (or uses the mock data count as fallback).
* Fix: Hardcoded quick-pick pills (Jackets, Bags, Footwear, Limited Drop, Sale) completely removed from the template — pills container is now empty by default and only appears when the Dokan categories API returns real results.
* Fix: Pills container is now hidden when the categories API is unavailable or returns no results, rather than showing an empty row.
* Fix: Product card restored to original <a> tag structure (identical to v1.1.5) — eliminates the extra spacing that appeared when using <article role="button"> and ensures layout is pixel-perfect with the original.

= 1.2.0 =
* New: Search results now stay fully in-page — clicking a result, pressing Enter, or tapping "View all" no longer navigates away from the store page.
* New: Quick-view drawer — clicking any search result (or product card) slides in a product panel from the right with image, price, stock status, and a "View full product page" link that opens in a new tab. Users can dismiss it and keep browsing the same store.
* New: "View all results" in the AURA dropdown now filters the live product grid directly and smoothly scrolls down to it, instead of redirecting to WooCommerce's global search page.
* New: Active search banner appears above the product grid showing "X results for 'query'" with a one-click Clear button that resets the grid back to the full catalog.
* New: Sort dropdown now works inside search results — re-sorts the filtered list by price or rating without losing the search state.
* New: Dynamic quick-pick pills — on search-bar focus the plugin fetches the vendor's real Dokan categories (dokan/v1/stores/{id}/categories) and replaces the hardcoded "Jackets / Bags / Footwear" pills with the store's actual categories. Falls back gracefully to the hardcoded pills if the API is unavailable.
* New: Product cards now open the quick-view drawer on click instead of navigating to the product permalink directly.
* Improved: Local mock search now used as a fallback when the Dokan REST API is unavailable, so the search bar always returns results.
* Improved: Quick-view drawer caches fetched product data to avoid redundant API calls within a session.

= 1.1.5 =
* Fixed: avatar broken image — get_avatar_url() returns a Gravatar.com CDN URL which fails for vendors without a Gravatar account (shows broken image icon + alt text). Reverted to Dokan custom-upload only for PHP render; JS swaps in the photo after API resolves with onerror guard so the initial-letter fallback is preserved if the URL fails.

= 1.1.4 =
* Fixed: vendor avatar/profile image never rendered — $store_info['gravatar'] is empty unless the vendor uploads a custom photo via Dokan Pro. Now uses get_avatar_url($store_id) as a reliable WordPress core fallback so every vendor always has a profile image.

= 1.1.3 =
* Fixed: vendor name hidden and avatar gone after page load — JS in fetchStoreDetails() was removing all text nodes and overwriting logo elements, destroying what PHP had already rendered correctly. PHP owns name and avatar; JS no longer touches them.

= 1.1.2 =
* Fixed: vendor name disappearing after page load — JS was iterating a live NodeList while removing text nodes, causing every other node to be skipped; converted to Array.from() snapshot before removal.
* Fixed: vendor avatar not showing immediately — sticky header logo was rendering only the initial letter even when PHP had the gravatar URL; now renders the <img> server-side just like the hero logo does.
* Fixed: avatar image breaking layout after JS load — container was missing overflow-hidden so the image wasn't clipped to the rounded shape; also removed rounded-2xl from the inner <img> (border-radius belongs on the container, not the img inside it).
* Fixed: JS logo swap unnecessarily replacing PHP-rendered avatar images; JS now skips elements that already contain an <img>.

= 1.1.1 ="
* Fixed: zip folder was named zymarg-store-page-v1.1.0 instead of zymarg-store-page — caused WordPress to derive the wrong plugin slug, breaking activation.

= 1.1.0 ="
* Fixed critical double HTML skeleton — removed duplicate <!DOCTYPE>/<html>/<head>/<body> that was emitted after get_header(); Tailwind @theme tokens now injected via wp_head hook.
* Fixed: Products per page admin setting was never passed to JavaScript; JS now reads perPage from ZYMARG_CONFIG.
* Fixed: AURA search bar admin toggle (show_aura_search) was never wired to the frontend; JS now hides the search root when the toggle is off.
* Fixed: Quick-pick pills were never shown to the user (display:none permanent); pills now appear on focus when the input is empty and hide when a real search fires.
* Fixed: Pills not hidden when clear button is clicked or user clicks outside search root.
* Fixed: Review content (r.content), author name, and product name/badge from the Dokan REST API were inserted into innerHTML without sanitisation; added escHtml() throughout.
* Fixed: justfy-content typo on review avatar span (no visual impact but invalid CSS class).

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.0 =
Upgrade immediately — fixes a critical double HTML document bug and several JavaScript wiring gaps. Deactivate and re-activate the plugin after uploading.

= 1.0.0 =
First public release.
