# ZYMARG Store Page — Layout & Design System Reference

**Purpose of this document:** This is the canonical reference for how the ZYMARG Store Page is structured, spaced, and styled — from the top banner to the last section. Any new page (future store redesigns, other vendor-facing pages, or new marketplace pages) should follow these same rules so the whole site feels like one consistent product instead of a collection of separately-designed pages.

**Source of truth:** Everything below is read directly from the shipped code as of **ZYMARG Store Page v1.28.4**:
- `templates/store.php`
- `includes/premium-sections.php`
- `includes/class-store-sections.php`
- `assets/css/store-page.css`
- `assets/css/zymarg-tokens.css`
- `assets/css/store-tailwind.src.css`

If the CSS ever changes, this document should be updated to match — it is documentation of what the code *does*, not a spec the code must obey. When in doubt, the CSS wins; update this file to catch up.

---

## 1. Page Structure — Top to Bottom

The store page renders in this exact order. Every future page should think in terms of these same "slots" — a fixed hero area, then a stack of interchangeable content sections, then a footer.

```
┌─────────────────────────────────────────────┐
│  0. Sticky Header (hidden until scroll)      │  ← appears only after scrolling past the hero
├─────────────────────────────────────────────┤
│  1. Hero + Store Card                        │  ← banner image/gradient + glass card overlay
├─────────────────────────────────────────────┤
│  2. Premium Sections (conditional)           │  ← Flash Sale, then Featured Items ("Handpicked")
├─────────────────────────────────────────────┤
│  3. Admin-Managed Generic Sections           │  ← Trending, Best Selling, and any admin adds later
├─────────────────────────────────────────────┤
│  4. Products & Categories Layout             │  ← category sidebar (left) + All Products grid (right)
├─────────────────────────────────────────────┤
│  5. Reviews + Our Story                      │  ← combined split-panel, or one/neither standalone
├─────────────────────────────────────────────┤
│  Footer (theme-provided, via get_footer())   │
└─────────────────────────────────────────────┘
```

Every section between Hero and Footer is **conditional** — if a vendor has no story text, no products in a subset, or isn't approved for Premium, that whole section (heading and all) disappears rather than rendering an empty shell. **This is a hard rule for every future section too: never render a heading over blank content.**

---

## 2. Section-by-Section Breakdown

### 0. Sticky Header
- `<header id="sticky-header">`, `position: fixed`, starts translated off-screen (`-translate-y-full`), slides in on scroll (JS-driven).
- Contains: store mini-logo + name + rating/follower summary, the AURA search bar, Chat button, Follow button.
- Exists so key actions (search, chat, follow) stay reachable once the hero has scrolled out of view.

### 1. Hero + Store Card
- A fixed-height banner (`h-56 sm:h-72 lg:h-80` — 224px / 288px / 320px) with either the vendor's uploaded cover photo, or a brand-gradient fallback (never a stock photo).
- A **glass card** is absolutely positioned inside the bottom of the banner (`.zsp-hc-card`), containing: logo, store name + badges, online/offline status, stat row (Followers / Rating / Reviews / Products), and action buttons (Follow / Chat / Share).
- **Rule for future pages:** a hero should always have a real-content fallback (gradient/pattern), never a placeholder photo that implies false content.

### 2. Premium Sections — Flash Sale & Featured Items
- Rendered by `zymarg_sp_premium_render_all()`, called unconditionally from the template — the function itself decides whether anything prints.
- **Gate:** only renders for vendors the marketplace admin has approved (read through `zymarg_vd_premium_get_vendor_flash_ids()` / `...featured_ids()` from the Vendor Dashboard plugin — Store Page never duplicates that approval logic).
- Order is fixed: **Flash Sale first, Featured Items second.**
- Layout (grid or carousel/slider) and column counts (desktop/tablet/mobile) are admin-configurable per marketplace, not hardcoded.
- Uses the shared `.zy-section` / `.zy-section-heading-row` / `.zy-section-content` classes (see §3) via its own `.zsp-premium` wrapper, which **defers entirely** to that shared system rather than keeping independent padding.

### 3. Admin-Managed Generic Sections (Trending, Best Selling, …)
- Driven by `ZYMARG_SP_Store_Sections::get_generic_rows()` — an **ordered, admin-editable list** of `[zymarg_products]` shortcode rows stored in one option (`zymarg_sp_store_sections`).
- **Array order = render order.** An admin can reorder, rename, disable, or add a brand-new row entirely from the settings screen — no code change required.
- Every row is restricted to `source="current_vendor"` (a store page only ever shows *this* vendor's products) and to the `[zymarg_products]` shortcode tag only — enforced in `sanitize_rows()`.
- The special "All Products" row (identified by `current_vendor_subset="all"`, not by a hardcoded ID) is excluded from this loop — it renders separately inside the sidebar layout (§4).
- If a row's query returns nothing, **the whole section (heading + link) is suppressed**, not just the grid — detected via the engine's `zymarg-wcpg__empty` marker class.

### 4. Products & Categories Layout
- Two-column flex layout (`flex-col lg:flex-row gap-8`): category sidebar on the left (sticky on desktop, `lg:sticky lg:top-20`), product grid on the right (`flex-1 min-w-0`).
- Sidebar only renders if the vendor has categorized products, **or** the viewer is the store owner/shop manager (who sees a "no categories yet" nudge instead — never shown to buyers).
- Right-side grid is the "All Products" row from §3, rendered via the Product Grid engine with native infinite scroll. A separate hidden sibling container (`#product-grid-filtered`) swaps in when a category/search filter is active, so the engine's own grid state is never destroyed by filtering.
- Includes a Sort control (`?zy_sort=`) that round-trips through the URL when no filter is active, and falls back to client-side re-sort when a filter *is* active.

### 5. Reviews + Our Story
- **Four possible states**, resolved once per page load:

  | Story content? | Rated reviews? | Result |
  |---|---|---|
  | ✅ | ✅ | **Combined split panel** — see below |
  | ✅ | ❌ | Story renders alone, full-width, no collapse |
  | ❌ | ✅ | Reviews render alone, full-width, no collapse |
  | ❌ | ❌ | Nothing renders |

- **Combined split panel** (only when both exist): two independently-collapsible cards — Our Story (~38% width) and Customer Reviews (~62% width) side-by-side on desktop (≥1024px); stacked full-width on tablet/mobile with the *same* collapse/expand behavior active at every breakpoint. **Both panels load collapsed by default**, everywhere.
- Reviews content is always delegated to the ZYMARG Reviews Engine's own renderer (`zymarg_reviews_render()`) — Store Page never hand-rolls review cards. This guarantees Store Page automatically gets every Reviews Engine feature (media strip, lightbox, filters, AJAX Load More) with zero duplicated code.

---

## 3. The Shared Spacing System — `.zy-section` and friends

This is the single most important pattern for keeping future pages consistent. **Every content section on the page (Premium, Trending/Best Selling/etc., Reviews+Story) uses the same four CSS classes** instead of each section picking its own one-off padding/margin values. Any future page section should do the same.

### The 4 classes

| Class | What it controls | Applies to |
|---|---|---|
| `.zy-section` | Section-to-section **top** gap + **left/right side** gap | The outer `<section>` wrapper |
| `.zy-section-heading` | Heading **font-size** | The `<h2>` (or a visible `<p>` eyebrow, see §4) |
| `.zy-section-heading-row` | Gap **after** the heading, before content — used when there's a heading+link row | The wrapper `<div>` around eyebrow/heading + optional "Explore More" link |
| `.zy-section-content` | Gap **before** content, when there's no heading-row wrapper | The content block itself (e.g. a grid, or a paragraph) |

**Rule:** `.zy-section-heading-row` and `.zy-section-content` are both single-purpose (margin-bottom-only and margin-top-only respectively) specifically so a section can use both without ever double-spacing the same gap.

### Exact responsive values (as of v1.28.4)

| Property | Mobile (<640px) | Tablet (640–1023px) | Desktop (≥1024px) |
|---|---|---|---|
| `.zy-section` top padding | 16px | 20px | 32px |
| `.zy-section` left/right padding | **4px** | 8px | 32px |
| `.zy-section-heading` font-size | 18px | 20px | 24px |
| `.zy-section-heading-row` margin-bottom | 8px | 12px | 24px |
| `.zy-section-content` margin-top | 8px | 12px | 24px |

> **Why mobile and tablet side-padding aren't equal:** Tailwind's `px-2` utility (used inline in the markup, `class="... px-2 lg:px-8"`) has no separate mobile-only tier — mobile and tablet used to share the same 8px value by accident. As of v1.28.4 this was deliberately corrected: mobile is `!important`-flagged down to 4px, and tablet is **explicitly re-locked** at 8px via its own `@media (min-width: 640px) and (max-width: 1023.98px)` rule specifically so the two tiers can never silently drift back together. **Any new section must keep this same three-tier intent** — don't just rely on a single Tailwind utility class and assume it covers all breakpoints correctly.

### Container width
Every section is centered and capped with `mx-auto max-w-7xl` (**1280px max width**), matching Tailwind's `max-w-7xl`. New pages should use the same cap for content sections so nothing on the site is ever wider than 1280px.

### The heading pattern (used everywhere)
Every section header follows this same two-part pattern:
```html
<p class="text-xs font-semibold uppercase tracking-[0.2em] text-zy-secondary">Eyebrow Label</p>
<h2 id="section-anchor" class="zy-section-heading ...">Visible or sr-only heading</h2>
```
- The **eyebrow** (`<p>`) is always: `text-xs`, `font-semibold`, `uppercase`, `tracking-[0.2em]`, colored `text-zy-secondary`.
- The **`<h2>`** either renders visibly (`.zy-section-heading` + bold/dark styling) or is made `sr-only` (visually hidden but still in the DOM) when the eyebrow alone is enough visually — this keeps the section's `aria-labelledby` landmark correctly named for screen readers either way.
- **Every section's outer `<section>` tag carries `aria-labelledby` pointing at that heading's `id`.** New sections must do the same — this is an accessibility requirement, not a nice-to-have.

### The collapse/expand mechanic (`.zy-collapse`)
Used by the Reviews+Story split panel and the sidebar categories drawer. Reuse this exact technique for any future collapsible section:
```css
.zy-collapse {
  display: grid;
  grid-template-rows: 1fr;
  transition: grid-template-rows 0.35s ease;
}
.zy-collapse[data-state="closed"] {
  grid-template-rows: 0fr;
}
```
This animates smoothly **without JavaScript height-measuring** — no `scrollHeight` reads, no layout thrash. JS only ever toggles the `data-state` attribute between `"open"` and `"closed"`.

---

## 4. Design Tokens — Colors, Type, Radius, Shadows

⚠️ **Important gotcha to flag to your developer:** there are **two parallel token systems** in play, both using the same brand colors but under different variable names. Don't mix them up.

### System A — `--zym-*` tokens (`zymarg-tokens.css`)
Shared **site-wide** across every ZYMARG plugin (Store Page, Single Product, Vendor Dashboard, Reviews Engine, etc.) — loaded once regardless of how many ZYMARG plugins are active. **Never redefine a `--zym-*` value inside an individual plugin.**

| Token | Value | Use |
|---|---|---|
| `--zym-color-primary` | `#9500A5` | Brand primary |
| `--zym-color-secondary` | `#BD00D1` | Brand secondary |
| `--zym-color-accent` | `#FEA9FF` | Brand accent/light |
| `--zym-color-dark` | `#36003D` | Strong heading text |
| `--zym-color-text` | `#534152` | Body text |
| `--zym-color-border` | `#D8BFD3` | Default border |
| `--zym-color-surface` | `#FFFFFF` | Card backgrounds |
| `--zym-color-bg` | `#FAF5FB` | Page background |
| `--zym-color-star` | `#F6AD55` | Rating stars (front-end only) |
| `--zym-gradient` | `linear-gradient(135deg, #9500A5 0%, #BD00D1 60%, #FEA9FF 130%)` | Brand gradient (buttons, avatar fallback, badges) |

Also defines a full spacing scale (`--zym-space-1` through `--zym-space-15`, 2px–64px), radius tokens (`--zym-radius-card: 16px`, `--zym-radius-pill: 9999px`), and motion timing (`--zym-motion-standard: 300ms`, `--zym-ease: ease-in-out`).

**Dark mode** is handled entirely inside this one file via `[data-theme="dark"]` — the **theme** sets that attribute on the page; plugins never toggle it themselves. Every plugin that consumes `--zym-*` tokens goes dark automatically the instant the theme flips that attribute — front-end and admin both.

### System B — `--color-zy-*` tokens (Store Page's own Tailwind v4 theme)
Defined inline in `store.php` (`<style type="text/tailwindcss"> @theme {...} </style>`) and mirrored in `store-tailwind.src.css` for the build pipeline. Used for Tailwind utility classes like `bg-zy-primary`, `text-zy-dark`, `border-zy-border`.

| Token | Value | Matches |
|---|---|---|
| `--color-zy-primary` | `#9500A5` | = `--zym-color-primary` |
| `--color-zy-secondary` | `#BD00D1` | = `--zym-color-secondary` |
| `--color-zy-accent` | `#FEA9FF` | = `--zym-color-accent` |
| `--color-zy-dark` | `#36003D` | = `--zym-color-dark` |
| `--color-zy-body` | `#534152` | = `--zym-color-text` |
| `--color-zy-border` | `#D8BFD3` | = `--zym-color-border` |
| `--color-zy-surface` | `#FFFFFF` | = `--zym-color-surface` |
| `--color-zy-container` | `#EAEDFF` | (no direct `--zym-*` equivalent) |

**Why two systems exist:** `--zym-*` is the cross-plugin token file everything else in the ZYMARG ecosystem uses. `--color-zy-*` is Store Page's *own* Tailwind v4 theme extension, needed because Tailwind utility classes (`bg-zy-primary`, etc.) must be defined through Tailwind's own `@theme` block to work as utilities — a plain CSS variable isn't enough for Tailwind to generate a class from it. **Any future page built with Tailwind utilities will need this same `@theme` block**; any future page using plain CSS (not Tailwind) should reach for `--zym-*` directly instead of inventing a third naming scheme.

### Typography
- Font: `"Inter Variable"` (loaded from Fontsource CDN), falling back to `ui-sans-serif, system-ui, sans-serif`.
- Heading sizes are controlled by `.zy-section-heading` (18/20/24px responsive, §3) — don't hand-pick a font-size per section.

### Border radius
- Cards: `16px` (`rounded-2xl` / `--zym-radius-card`)
- Small elements/chips: `12px` (`rounded-xl` / `--zym-radius-image-small`)
- Pills/badges: fully rounded (`9999px` / `--zym-radius-pill`)

### Shadows
- Card shadow (light mode): `0 4px 20px rgba(53, 0, 61, 0.05)` (used on the split panel) or the slightly stronger `--zym-shadow-card: 0 4px 24px rgba(53,0,61,0.10), 0 1px 4px rgba(53,0,61,0.06)` (used elsewhere, e.g. sidebar category cards, hover states).
- Shadows use the brand's dark purple (`rgba(53,0,61,...)`) as the shadow color, not plain black — this is a deliberate brand choice. Match it in any new UI.

---

## 5. Rules for Building a New Page (Checklist)

When your developer builds a new page and wants it to feel consistent with the Store Page, follow this checklist:

- [ ] **Container width:** cap content at `max-w-7xl` (1280px), centered with `mx-auto`.
- [ ] **Section wrapper:** every content section is a `<section>` with the shared `.zy-section` class for top/side padding — never hand-pick your own padding values per section.
- [ ] **Section side padding:** use the `px-2 lg:px-8` Tailwind pattern (or the plain-CSS equivalent) — remember mobile needs its own explicit 4px override; don't assume `px-2` alone covers mobile correctly.
- [ ] **Heading pattern:** eyebrow `<p>` (uppercase, `tracking-[0.2em]`, `text-zy-secondary`) + `<h2>` using `.zy-section-heading` for font-size, either visible or `sr-only`. Always wire `aria-labelledby` on the section to the heading's `id`.
- [ ] **Heading-to-content gap:** use `.zy-section-heading-row` (if there's a heading+link row) or `.zy-section-content` (if content follows the heading directly) — never a hardcoded `mt-*`/`mb-*` utility.
- [ ] **Empty state:** if a section's data source returns nothing, suppress the *entire* section (heading included) — never show a heading over blank space.
- [ ] **Colors:** pull from `--zym-*` tokens (or the page's own `@theme` mirror of them, if using Tailwind) — never hardcode a hex value that already exists as a token.
- [ ] **Collapsible UI:** reuse the `.zy-collapse` grid-rows trick (§3) rather than `max-height` hacks or JS-measured heights.
- [ ] **Radius/shadow:** 16px for cards, brand-purple-tinted shadows (`rgba(53,0,61,...)`), never plain black shadows.
- [ ] **Dark mode:** never write your own dark-mode toggle logic — just consume `--zym-*` tokens and dark mode works automatically once the theme sets `data-theme="dark"`.
- [ ] **Admin-configurability:** if a section's content might reasonably change per vendor/admin (like Trending/Best Selling), consider whether it should be a `[zymarg_products]`-shortcode-driven row rather than hardcoded — this is what lets an admin reorder/rename/disable sections with zero code changes.

---

## 6. Known Version History (Spacing System)

For context on *why* certain values look the way they do, in case a similar spacing decision comes up on a future page:

- **v1.24.0** — Introduced the shared `.zy-section` / `.zy-section-heading` / `.zy-section-heading-row` / `.zy-section-content` system, replacing each section's own hand-picked utilities (which had drifted section-to-section and never adapted for tablet).
- **v1.28.0** — Introduced the Reviews+Story combined split panel and the reusable `.zy-collapse` mechanic.
- **v1.28.1** — Fixed sticky positioning being clipped by a collapse wrapper's `overflow: hidden`, and fixed double-padding inside the split panel's Reviews column.
- **v1.28.2** — Store-wide spacing pass: desktop section gap 48px→32px; split panel inner padding tightened to 8px (mobile/tablet) / 16px (desktop).
- **v1.28.3** — Fixed `.zsp-premium` (Handpicked/Flash Sale/Trending) having its own stale hardcoded padding that had drifted from the shared system — now fully deferred to `.zy-section`.
- **v1.28.4** — Mobile-only side gap reduced to 4px (was 8px, shared with tablet by accident); tablet explicitly re-locked at 8px so the two tiers can't drift together again.

---

*Generated for the ZYMARG marketplace team — keep this file updated whenever the spacing system or token values change, so it stays a reliable single source of truth for page consistency.*
