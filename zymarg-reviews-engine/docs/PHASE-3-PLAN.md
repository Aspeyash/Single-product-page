# Phase 3 — Vendor scoping + Dokan store-page consumer

**Target release:** ZYMARG Reviews Engine **v1.1.0**
**Status:** PARKED — build starts only on the owner's call.
**Written:** 3 Aug 2026, alongside the v1.0.0 engine release and Single Product 2.0.0.
**Consumers affected:** none. Single Product stays at 2.0.0, no changes required.

---

## 0. How to recall this plan

Open this file, work top to bottom. Sections 1–7 are the build. Section 8 is the
test pass. Section 10 lists the three decisions that must be answered *before*
any code is written — do not start without them.

---

## 1. Goal

Make the engine answer "reviews for **a vendor's whole store**" as well as it
currently answers "reviews for **one product**", then ship a Dokan store-page
consumer that renders it.

**Hard constraint:** product mode must behave byte-identically after the change.
Every vendor-mode branch is additive.

The groundwork already exists in v1.0.0:

- `zymarg_reviews_get_data()` already accepts a `vendor_id` argument (ignored).
- `Shortcode::render()` already parses `vendor_id` and early-returns `''`.
- The shortcode attribute is already documented.

Phase 3 fills those stubs in.

---

## 2. Scope resolution layer (engine core)

`Data_Builder::build( $product_id, $settings )` assumes a single post ID
everywhere — query, summary maths, schema, media gallery. Rather than threading
`vendor_id` through ~20 call sites, introduce one resolver.

**New file:** `includes/class-scope.php`, class `ZymargReviewsEngine\Scope`.

```php
Scope::from_args( [ 'product_id' => 0, 'vendor_id' => 0 ] );
// => [ 'type' => 'product'|'vendor', 'id' => int, 'post_ids' => int[] ]
```

- **product** → `post_ids = [ $product_id ]` (identical to today's path)
- **vendor**  → `post_ids` = published, catalogue-visible products where
  `post_author = $vendor_id`

**New file:** `includes/class-vendor.php`, class `ZymargReviewsEngine\Vendor`.

- `Vendor::product_ids( int $vendor_id ): int[]`
- Dokan stores the vendor on `post_author`, so the default implementation is a
  plain `get_posts()`. Dokan is **not** a hard requirement.
- Filter `zymarg_reviews_vendor_product_ids` ( `$ids`, `$vendor_id` ) so any
  other multivendor plugin can override the mapping.
- `Vendor::exists()` / `Vendor::name()` behind `dokan_get_store_info()` with a
  `get_userdata()` fallback.

**Caching:** transient `zymarg_re_vendor_products_{vendor_id}`, TTL from the
existing `reviews_cache_ttl` setting. Busted on:

- `zymarg_review_submitted`
- `transition_comment_status`
- `save_post_product`
- `deleted_post`

**Signature change:** `Data_Builder::build( array $scope, array $settings )`,
with a thin back-compat wrapper accepting an `int` first argument so nothing in
Single Product 2.0.0 breaks.

---

## 3. Store-wide rating maths

Per-product averages come from Woo's `_wc_average_rating` meta, which is useless
for a store. Vendor mode computes from the comments directly.

- One `$wpdb` query: `COUNT` / `AVG` / `GROUP BY meta_value` over approved
  reviews across `post_ids` → average, total, and the 5→1 breakdown in a single
  round trip.
- **Large-catalogue guard:** above the `reviews_vendor_max_products` threshold
  (see §7), stop passing a `post__in` list and join `wp_posts.post_author`
  instead, so the query never blows up.
- Summary cached in transient `zymarg_re_vendor_summary_{vendor_id}`, same TTL,
  busted by the same hooks as §2.

---

## 4. Card-level differences in vendor mode

A store feed needs context a product feed does not.

- Each card gains a **product** block — thumbnail + title, linked to the product.
  Rendered only when `scope.type === 'vendor'`.
- The variation line stays exactly as-is.
- Verified-buyer badge logic is unchanged (per-review order-item lookup).
- New markup: `.zymarg-review-product` + `__thumb` / `__title`, styled with the
  existing `--zymarg-*` / `--z-*` token set so consumers can restyle it.

---

## 5. Filters, sorting and AJAX

`zymarg_load_reviews` currently posts `product_id`. It will also accept
`vendor_id`, rebuild the scope **server-side**, and re-validate. Never trust a
client-supplied product list.

**Nonce actions do not change:** `zymarg_submit_review`, `zymarg_load_reviews`,
`zymarg_reply_review`, `zymarg_review_vote`, `zymarg_report_review`.

Filter set in vendor mode:

| Filter | Product mode | Vendor mode |
|---|---|---|
| By star rating | yes | yes |
| With photos / videos | yes | yes |
| By variation | yes | **replaced** |
| By product | n/a | **new** — variation names are meaningless across a catalogue |

Sorts unchanged: `recent`, `highest`, `lowest`, `helpful`.

**Media gallery:** in vendor mode group by product first, then by reviewer, so a
store-wide gallery stays navigable. Product mode grouping is untouched.

---

## 6. Schema (SEO)

- Product mode: keeps emitting `AggregateRating` on the `Product` node.
- Vendor mode: emits `AggregateRating` on an `Organization` / `Store` node.
- Gated behind the existing `reviews_enable_schema` **plus** a new
  `reviews_vendor_schema` sub-toggle, because Dokan store pages may already emit
  their own store markup and two `AggregateRating` nodes will conflict.
- **Check the live Dokan store page markup before committing to this.**

---

## 7. Consumers

### 7.1 Shortcode

Lift the `vendor_id` early-return in `Shortcode::render()`:

```
[zymarg_reviews vendor_id="123" limit="10" show_form="no"]
[zymarg_reviews vendor_id="current"]   // resolves on a Dokan store page
```

`show_form` defaults to **off** in vendor mode — a shopper reviews a product,
not a store. (A true store-level review type is a separate, much larger feature;
scope it on its own if ever wanted.)

### 7.2 Dokan store page

**New file:** `includes/class-dokan.php`.

- Resolve the vendor from `dokan_get_store_info()` / the `store` query var.
- Placement, admin-selectable:
  1. **Inline** — hook `dokan_store_profile_frame_after`, render under the store
     header.
  2. **Tab** — register a dedicated **Reviews** store tab with the review count
     in the label.
- Everything behind `function_exists( 'dokan_get_store_info' )`, so the file is
  inert without Dokan.

### 7.3 PHP API

`zymarg_reviews_render( [ 'vendor_id' => 123 ] )` becomes valid. All existing
API functions keep their signatures.

---

## 8. Engine control page — new "Vendor / Store" tab

Ninth tab, following the existing AJAX pattern exactly (no reload on save, no
reload on tab switch, `data-re-tab` / `data-re-panel` / `data-re-key` hooks).

| Setting key | Type | Default |
|---|---|---|
| `reviews_vendor_enabled` | bool | `false` |
| `reviews_vendor_dokan_placement` | choice: `off` / `inline` / `tab` | `off` |
| `reviews_vendor_heading` | text | `Store Reviews` |
| `reviews_vendor_empty_text` | text | `This store has no reviews yet.` |
| `reviews_vendor_per_page` | int 1–100 | `10` |
| `reviews_vendor_show_product` | bool | `true` |
| `reviews_vendor_filter_by_product` | bool | `true` |
| `reviews_vendor_schema` | bool | `false` |
| `reviews_vendor_max_products` | int 50–5000 | `500` |

Keep `Settings::defaults()` ↔ admin-field parity at 100 % — `verify-engine.py`
asserts it and will fail the build otherwise.

---

## 9. Test checklist

- [ ] Product page output is byte-identical to v1.0.0 / SP 2.0.0
- [ ] Vendor with 0 reviews → empty state, no fatal
- [ ] Vendor with exactly 1 review → correct singular strings
- [ ] Vendor with 500+ products → falls back to the `post_author` join
- [ ] Review whose product was trashed or set to draft → excluded from the feed
         and from the summary maths
- [ ] Pagination × filter × sort combinations in vendor mode
- [ ] Gallery opens, navigates, swipes and closes from a store page
- [ ] Engine deactivated → store page degrades quietly, same as the product page
- [ ] Guest / logged-in / verified-buyer states
- [ ] Report + reply + vote all still work on a store-page card
- [ ] `verify-engine.py` clean, `node --check` clean on both JS files

---

## 10. Blocking decisions — answer before writing code

1. **Placement** — inline under the Dokan store header, or a dedicated Reviews
   store tab?
2. **Store schema** — emit `AggregateRating` for the store, or leave all SEO
   markup to Dokan?
3. **"Write a review" in store mode** — hide it (recommended), or show a product
   picker so a shopper can choose which purchased item they are reviewing?

---

## 11. Deliverable

`zymarg-reviews-engine-v1.1.0.zip`

New files: `includes/class-scope.php`, `includes/class-vendor.php`,
`includes/class-dokan.php`.
Modified: `class-data-builder.php`, `class-ajax.php`, `class-shortcode.php`,
`class-settings.php`, `class-admin.php`, `templates/reviews.php`,
`templates/partials/review-cards-loop.php`, `assets/js/zymarg-reviews.js`,
`assets/css/zymarg-reviews.css`, `readme.txt`.

Single Product requires **no** change and stays at 2.0.0.

## 12. Beyond Phase 3 (not planned, just parked)

- Standalone reports dashboard (currently reports live in the Comments screen)
- `commentmeta` cleanup for per-user dedupe rows
- Wiring the settings that exist but are not yet consumed:
  `reviews_require_purchase`, `reviews_one_per_product`,
  `reviews_form_require_title`, `reviews_form_min_length` / `_max_length`,
  `reviews_allowed_image_types` / `_video_types`, `reviews_report_reasons`,
  the two email templates, `reviews_cache_ttl`, `reviews_layout` /
  `reviews_columns`
