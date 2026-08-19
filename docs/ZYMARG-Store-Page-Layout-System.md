# ZYMARG Page Layout System — Reference

**Purpose of this document:** This is the reusable layout, spacing, and design-token system used to build marketplace pages consistently. It's documented here using one real, fully-built page as the worked example — a vendor's storefront page, structured from its top banner down to its last section. Any new page should follow these same rules so the whole site feels like one consistent product instead of a collection of separately-designed pages.

**How to use this doc:** Sections 1–2 walk through the example page's actual structure, section by section, so you can see the system applied end-to-end. Sections 3–5 are the reusable rules themselves — the part that should be copied into every new page.

**A living document:** This describes what the code currently does — it isn't a spec the code must obey. If the layout or spacing system changes, update this file to match rather than treating it as fixed.

---

## 1. Page Structure — Top to Bottom

The example page renders in this exact order. Every future page should think in terms of these same "slots" — a fixed hero area, then a stack of interchangeable content sections, then a footer.

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
│  Footer                                      │
└─────────────────────────────────────────────┘
```

Every section between Hero and Footer is **conditional** — if a vendor has no story text, no products in a subset, or isn't approved for Premium, that whole section (heading and all) disappears rather than rendering an empty shell. **This is a hard rule for every future section too: never render a heading over blank content.**

---

## 2. Section-by-Section Breakdown

### 0. Sticky Header
- Fixed position, starts translated off-screen, slides in on scroll (JS-driven).
- Contains: store mini-logo + name + rating/follower summary, a live-search bar, Chat button, Follow button.
- Exists so key actions (search, chat, follow) stay reachable once the hero has scrolled out of view.

### 1. Hero + Store Card
- A fixed-height banner (224px / 288px / 320px across mobile / tablet / desktop) with either the vendor's uploaded cover photo, or a brand-gradient fallback (never a stock photo).
- A **glass card** is absolutely positioned inside the bottom of the banner, containing: logo, store name + badges, online/offline status, stat row (Followers / Rating / Reviews / Products), and action buttons (Follow / Chat / Share).
- **Rule for future pages:** a hero should always have a real-content fallback (gradient/pattern), never a placeholder photo that implies false content.

### 2. Premium Sections — Flash Sale & Featured Items
- Rendered by a single function call from the template — the function itself decides whether anything prints.
- **Gate:** only renders for vendors the marketplace admin has approved (read through a shared approval list — this page never duplicates that approval logic locally).
- Order is fixed: **Flash Sale first, Featured Items second.**
- Layout (grid or carousel/slider) and column counts (desktop/tablet/mobile) are admin-configurable per marketplace, not hardcoded.
- Uses the shared spacing classes described in §3, deferring entirely to that shared system rather than keeping independent padding.

### 3. Admin-Managed Generic Sections (Trending, Best Selling, …)
- Driven by an **ordered, admin-editable list** of product-query rows stored in one setting.
- **List order = render order.** An admin can reorder, rename, disable, or add a brand-new row entirely from the settings screen — no code change required.
- Every row is restricted to the vendor's own products, and to one safe query mechanism only.
- The special "All Products" row is excluded from this loop — it renders separately inside the sidebar layout (§4).
- If a row's query returns nothing, **the whole section (heading + link) is suppressed**, not just the grid.

### 4. Products & Categories Layout
- Two-column layout: category sidebar on the left (sticky on desktop), product grid on the right.
- Sidebar only renders if the vendor has categorized products, **or** the viewer is the store owner/manager (who sees a "no categories yet" nudge instead — never shown to buyers).
- Right-side grid is the "All Products" row from §3, with native infinite scroll. A separate hidden sibling container swaps in when a category/search filter is active, so the grid's own state is never destroyed by filtering.
- Includes a Sort control that round-trips through the URL when no filter is active, and falls back to client-side re-sort when a filter *is* active.

### 5. Reviews + Our Story
- **Four possible states**, resolved once per page load:

  | Story content? | Rated reviews? | Result |
  |---|---|---|
  | ✅ | ✅ | **Combined split panel** — see below |
  | ✅ | ❌ | Story renders alone, full-width, no collapse |
  | ❌ | ✅ | Reviews render alone, full-width, no collapse |
  | ❌ | ❌ | Nothing renders |

- **Combined split panel** (only when both exist): two independently-collapsible cards — Our Story (~38% width) and Customer Reviews (~62% width) side-by-side on desktop (≥1024px); stacked full-width on tablet/mobile with the *same* collapse/expand behavior active at every breakpoint. **Both panels load collapsed by default**, everywhere.
- Reviews content is always delegated to a shared reviews renderer rather than hand-rolled locally — this guarantees every consumer of that renderer gets its full feature set (media strip, lightbox, filters, load more) with zero duplicated code.

---

## 3. The Shared Spacing System

This is the single most important pattern for keeping future pages consistent. **Every content section on the example page (Premium, Trending/Best Selling/etc., Reviews+Story) uses the same four reusable classes** instead of each section picking its own one-off padding/margin values. Any future page section should do the same.

### The 4 classes

| Class | What it controls | Applies to |
|---|---|---|
| Section wrapper class | Section-to-section **top** gap + **left/right side** gap | The outer `<section>` wrapper |
| Section heading class | Heading **font-size** | The `<h2>` (or a visible eyebrow `<p>`, see below) |
| Section heading-row class | Gap **after** the heading, before content — used when there's a heading+link row | The wrapper `<div>` around eyebrow/heading + optional "Explore More" link |
| Section content class | Gap **before** content, when there's no heading-row wrapper | The content block itself (e.g. a grid, or a paragraph) |

**Rule:** the heading-row class and the content class are both single-purpose (margin-bottom-only and margin-top-only respectively) specifically so a section can use both without ever double-spacing the same gap.

### Exact responsive values

| Property | Mobile (<640px) | Tablet (640–1023px) | Desktop (≥1024px) |
|---|---|---|---|
| Section top padding | 16px | 20px | 32px |
| Section left/right padding | **4px** | 8px | 32px |
| Section heading font-size | 18px | 20px | 24px |
| Heading-row margin-bottom | 8px | 12px | 24px |
| Content margin-top | 8px | 12px | 24px |

> **Why mobile and tablet side-padding aren't equal:** a common side-padding utility (an 8px "small padding" step) has no separate mobile-only tier by default — mobile and tablet used to share the same 8px value by accident. This was deliberately corrected: mobile is overridden down to 4px, and tablet is **explicitly re-locked** at 8px via its own dedicated breakpoint rule, specifically so the two tiers can never silently drift back together. **Any new section must keep this same three-tier intent** — don't just rely on one utility class and assume it covers all breakpoints correctly.

### Container width
Every section is centered and capped at **1280px max width**. New pages should use the same cap for content sections so nothing on the site is ever wider than 1280px.

### The heading pattern (used everywhere)
Every section header follows this same two-part pattern:
```html
<p class="eyebrow">Eyebrow Label</p>
<h2 id="section-anchor" class="section-heading">Visible or visually-hidden heading</h2>
```
- The **eyebrow** (a small `<p>`) is always: small size, semibold, uppercase, wide letter-spacing, secondary brand color.
- The **`<h2>`** either renders visibly (bold/dark styling) or is made visually-hidden-but-still-in-the-DOM when the eyebrow alone is enough visually — this keeps the section's accessible name correct for screen readers either way.
- **Every section's outer `<section>` tag must reference that heading's `id`** so assistive tech can name the landmark correctly. New sections must do the same — this is an accessibility requirement, not a nice-to-have.

### The collapse/expand mechanic
Used by the Reviews+Story split panel and the sidebar categories drawer. Reuse this exact technique for any future collapsible section:
```css
.collapse-wrapper {
  display: grid;
  grid-template-rows: 1fr;
  transition: grid-template-rows 0.35s ease;
}
.collapse-wrapper[data-state="closed"] {
  grid-template-rows: 0fr;
}
```
This animates smoothly **without JavaScript height-measuring** — no `scrollHeight` reads, no layout thrash. JS only ever toggles a `data-state` attribute between `"open"` and `"closed"`.

---

## 4. Design Tokens — Colors, Type, Radius, Shadows

⚠️ **Important gotcha to flag to your developer:** there are currently **two parallel token systems** in play, both using the same brand colors but under different variable names. Don't mix them up.

### System A — a shared, site-wide token set
Loaded once and shared across every plugin/page on the marketplace, regardless of how many are active. **Never redefine one of these values inside an individual page or plugin** — treat it as a single source of truth.

| Token role | Value |
|---|---|
| Brand primary | `#9500A5` |
| Brand secondary | `#BD00D1` |
| Brand accent/light | `#FEA9FF` |
| Strong heading text | `#36003D` |
| Body text | `#534152` |
| Default border | `#D8BFD3` |
| Card background | `#FFFFFF` |
| Page background | `#FAF5FB` |
| Rating stars (front-end only) | `#F6AD55` |
| Brand gradient | `linear-gradient(135deg, #9500A5 0%, #BD00D1 60%, #FEA9FF 130%)` |

Also defines a full spacing scale (2px–64px in fixed steps), radius tokens (16px cards, fully-rounded pills), and motion timing (300ms, ease-in-out).

**Dark mode** is handled entirely by this one shared token layer via a `data-theme="dark"` attribute — the **site theme** sets that attribute on the page; individual pages/plugins never toggle it themselves. Anything that consumes these shared tokens goes dark automatically the instant the theme flips that attribute.

### System B — a page-local Tailwind theme extension
Some pages define their own Tailwind v4 `@theme` block, mirroring the shared tokens above under Tailwind-friendly names so utility classes (like `bg-brand-primary`, `text-brand-dark`) can be generated from them.

**Why two systems exist:** the shared token layer is the cross-page source of truth. A page-local Tailwind theme extension is only needed because Tailwind utility classes must be defined through Tailwind's own `@theme` block to work as utilities — a plain CSS variable alone isn't enough for Tailwind to generate a class from it. **Any future page built with Tailwind utilities will need this same kind of local `@theme` mirror**; any future page using plain CSS (not Tailwind) should reach for the shared tokens directly instead of inventing a third naming scheme.

### Typography
- Font: a variable sans-serif family, loaded from a CDN, falling back to the system UI font stack.
- Heading sizes are controlled by the shared section-heading class (18/20/24px responsive, §3) — don't hand-pick a font-size per section.

### Border radius
- Cards: 16px
- Small elements/chips: 12px
- Pills/badges: fully rounded

### Shadows
- Card shadow: a soft shadow using the brand's dark purple as the shadow color (roughly `rgba(53,0,61,0.05–0.10)`), never plain black. This is a deliberate brand choice — match it in any new UI.

---

## 5. Rules for Building a New Page (Checklist)

When building a new page and wanting it to feel consistent with this one, follow this checklist:

- [ ] **Container width:** cap content at 1280px, centered.
- [ ] **Section wrapper:** every content section is a `<section>` using the shared top/side padding class — never hand-pick your own padding values per section.
- [ ] **Section side padding:** use the shared responsive side-padding pattern — remember mobile needs its own explicit override; don't assume one utility class alone covers all breakpoints correctly.
- [ ] **Heading pattern:** eyebrow (uppercase, wide letter-spacing, secondary brand color) + heading using the shared heading class for font-size, either visible or visually-hidden. Always wire the section's accessible name to that heading's `id`.
- [ ] **Heading-to-content gap:** use the heading-row class (if there's a heading+link row) or the content class (if content follows the heading directly) — never a hardcoded one-off margin value.
- [ ] **Empty state:** if a section's data source returns nothing, suppress the *entire* section (heading included) — never show a heading over blank space.
- [ ] **Colors:** pull from the shared token set (or the page's own Tailwind theme mirror of them, if using Tailwind) — never hardcode a hex value that already exists as a token.
- [ ] **Collapsible UI:** reuse the collapse grid-rows trick (§3) rather than `max-height` hacks or JS-measured heights.
- [ ] **Radius/shadow:** 16px for cards, brand-purple-tinted shadows, never plain black shadows.
- [ ] **Dark mode:** never write your own dark-mode toggle logic — just consume the shared tokens and dark mode works automatically once the theme sets `data-theme="dark"`.
- [ ] **Admin-configurability:** if a section's content might reasonably change per vendor/admin (like Trending/Best Selling), consider whether it should be a query-driven, admin-editable row rather than hardcoded — this is what lets an admin reorder/rename/disable sections with zero code changes.

---

*Keep this file updated whenever the spacing system or token values change, so it stays a reliable single source of truth for page consistency.*
