=== ZYMARG Cart ===
Contributors: zymarg
Tags: woocommerce, cart, multi-vendor, dokan, marketplace
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 2.4.0
License: Proprietary

A fully custom, standalone WooCommerce cart page for the ZYMARG multi-vendor marketplace.
Zero Elementor dependency. Admin-configurable. Dokan Pro integrated.

== Description ==

ZYMARG Cart v2.0.0 is a complete rewrite of the original Elementor widget plugin into a
self-contained standalone cart page plugin. It overrides the WooCommerce default cart page
with a fully featured custom experience — without depending on Elementor or any page builder.

Features:
- Partial checkout (select which items to purchase)
- Save for Later (hybrid session + usermeta, auto-merge on login)
- Per-product coupons with inline feedback
- Dokan Pro vendor grouping with per-vendor subtotals
- Sticky Cart Total with slide-up popup (mobile default ON)
- 11 AJAX actions (quantity, variation switch, coupons, SFL, partial checkout, restore)
- WP Admin settings panel — 3 tabs: Header / Body / Total
- Uses the shared ZYMARG Design Tokens system for all brand colors, including
  automatic dark mode support (no per-site color overrides — colors are
  centrally managed across the ZYMARG plugin suite)
- Page background inherits from active theme (no hardcoded colours)
- HPOS compatible
- i18n ready (.pot file included)

== Changelog ==

= 2.4.0 =
* Fix: the shared zymarg-tokens.css was missing a canonical name for text and
  icons drawn directly on the fixed brand gradient (--zym-gradient never
  changes between light/dark mode by design). Every plugin previously either
  hardcoded raw #fff (breaking the "no raw colour inside a rule" rule) or
  aliased it to a theme-reactive token that goes dark and produces illegible
  text once dark mode activates. Added three new FIXED tokens to the
  canonical file, propagated byte-identical to all 6 ZYMARG plugins:
  --zym-color-on-gradient (#FFFFFF), --zym-color-primary-fixed (#FFD6FB),
  --zym-color-on-primary-fixed (#36003D). Documented with a full usage guide
  in the ZYMARG Design Tokens reference doc.
* Fix: this plugin's admin settings screen used class="zc-admin-wrap" for its
  wrapper, which does not match the literal ".zymarg-admin" selector the
  shared token file scopes every back-end-only token to. Every back-end-only
  token referenced in this plugin's admin CSS (--zym-shadow-btn,
  --zym-shadow-surface, --zym-color-divider, etc.) was silently resolving to
  nothing. Added class="zymarg-admin" alongside the existing wrapper class
  (additive only, no selectors removed) so those tokens now resolve.
* Fix: replaced every raw hex value this plugin had introduced (color:#fff,
  color:#ffd6fb, stroke:#fff, and one color:#9500a5) with the matching
  --zym-* token, verified byte-for-byte identical to the value it replaced —
  zero visual change, only the underlying mechanism moved from a literal hex
  to a shared token reference. Pre-existing decorative colours with no
  canonical token equivalent (e.g. #e0d0ea, #e8d5f5, #a889b0, #dc3545,
  #f5c8f8) were deliberately left untouched.

= 2.3.0 =
* CRITICAL FIX (Header plugin): the admin Settings::cart_inline_css() generator
  always emitted the plugin's original hardcoded light-mode hex defaults for
  every style field, even on installs that never touched the settings screen.
  This inline <style> block loads after the main stylesheet and therefore
  always won, permanently overriding every dark-mode-aware rule in the mini
  cart popup — this was the actual cause of the popup panel, product rows,
  and cart text staying stuck in light-mode colours regardless of the site's
  dark-mode toggle. Fixed via a new Settings::themed() helper: an un-customised
  setting now defers to the theme-aware --zc-*/--zym-* token instead of the
  hardcoded hex, while an explicitly admin-chosen colour is still honoured.
* Fixed: several duplicate CSS rules later in zymarg-cart.css re-declared
  product-row / saved-item-row / subtotal-bar hover states with hardcoded
  light-only hex, which always won on cascade order over the earlier
  theme-aware token rules — this was the literal cause of rows staying white
  in dark mode. Removed/aligned the duplicates to the theme-aware tokens.
* Fixed: several gradients (vendor row, vendor subtotal footer, saved-section
  header, action bar, subtotal bar) mixed a permanently-light hex with a
  token that DOES flip dark, producing a muddy "light fading into near-black"
  gradient in dark mode. Rebuilt entirely from theme-aware tokens.
* Fixed: multiple buttons/badges (checkout, move-to-cart, coupon apply, saved
  count badge, continue-shopping) used a 2-stop gradient built from
  --zym-color-primary -> --zym-color-secondary, both of which lighten in dark
  mode, paired with an already-light fixed text colour -- producing washed
  out, low-contrast pale-on-pale buttons in dark mode ("gradient not eye
  pleasant, text barely visible"). Restored to the brand's fixed 3-stop
  --zym-gradient (which never changes between modes by design) with fixed
  white/light-pink text, matching the header's Become a Seller / badge fix
  from v2.2.0.
* Fixed: several hover states (edit button, quantity stepper buttons,
  Header's mini-cart quantity/outline buttons) used a fixed light-pink fill
  under theme-reactive text that also lightens in dark mode -- low contrast.
  Replaced with theme-aware surface tints.
* Fixed: vendor static-icon badge text colour was theme-reactive
  (--zym-color-primary lightens in dark mode) sitting on a permanently-light
  pill background -- low contrast in dark mode. Fixed to a permanently dark
  icon colour.

= 2.2.0 =
* CRITICAL FIX: v2.1.0 introduced brand-new token names (e.g. --zym-color-primary-container,
  --zym-color-surface-lowest, --zym-color-outline-variant) into this plugin's copy of the
  shared zymarg-tokens.css that DO NOT EXIST in the real canonical file used by ZYMARG
  Vendor Dashboard, Store Page, Single Product, and Reviews Engine. Because every ZYMARG
  plugin shares one `zymarg-tokens` enqueue handle and WordPress loads only whichever
  plugin registers it first, the real canonical file won on the live site and every
  reference to an invented token resolved to nothing — causing missing backgrounds on
  the "Become a Seller" pill, the wishlist/cart count badges, the header bar, the "My
  Cart" bar, and the checkout button. Fixed by replacing zymarg-tokens.css with the
  exact byte-identical canonical file, and remapping every --zc-* alias and inline
  CSS reference to the REAL shared token names.
* CRITICAL FIX: dark mode was wired to @media (prefers-color-scheme: dark) — the OS-level
  preference — instead of [data-theme="dark"], which is the attribute the site's actual
  theme toggle sets on <html>. Dark-mode color changes never activated on the real
  toggle. Fixed to use [data-theme="dark"], matching the canonical token file.
* Fixed: several places used a theme-reactive token (--zym-color-surface, which flips
  to near-black in dark mode) as the text/icon color sitting on the FIXED brand
  gradient (--zym-gradient, which never changes between light/dark mode by design).
  In dark mode this produced near-invisible dark-on-purple text — affecting the
  "Become a Seller" pill, wishlist/cart badges, and the "My Cart" bar title/icon.
  Fixed to a permanently fixed white value for text/icons on the gradient.
* Restored: the "My Cart" header bar background from a flat solid color back to the
  brand's --zym-gradient (3-stop gradient), and restored --zym-shadow-card /
  --zym-shadow-card-hover for card depth, per design token usage.
* Fixed a PHP syntax error (unescaped apostrophe inside a single-quoted string)
  introduced in the v2.1.0 admin CSS comment.

= 2.1.0 =
* Changed: All brand colors (--zc-* custom properties) are now thin aliases onto
  the shared ZYMARG Design Tokens (--zym-color-* in zymarg-tokens.css) instead of
  duplicated literal hex values. Zero visual change in light mode.
* Changed: The "My Cart" header bar (.zymarg-cart-header) background is now a
  solid brand primary color instead of a two-stop gradient.
* Added: Dark mode now actually works. Previously the @media (prefers-color-scheme:
  dark) override only updated --zc-* variables, but a duplicate :root block later
  in the stylesheet silently redeclared the same variables with light-mode hex
  values, so dark mode had no visible effect. Both blocks now alias onto the
  shared tokens, which correctly flip under dark mode.
* Removed: The admin "🎨 Style" tab (Light Mode Colors, Dark Mode Overrides, and
  Border Radius fields) has been removed. Brand colors are now managed centrally
  via the shared ZYMARG Design Tokens system rather than per-site overrides.
  Settings panel is now 3 tabs: Header / Body / Total.
* No changes to structural layout, markup, or functionality — colors only.

= 2.0.3 =
* Fix: Cart icon now white (#ffffff) on gradient header for proper contrast — was #ffd6fb (near-invisible against dark purple gradient).
* Fix: Vendor row icon, avatar, and name now vertically center-aligned. Static icon, profile photo, vendor name, and arrow icon are all on the same baseline.
* Version bump: pure visual fixes, zero functionality changes.

= 2.0.0 =
* MAJOR: Converted from Elementor widget plugin to standalone cart page plugin.
* REMOVED: All three Elementor widget classes (cart-header, cart-body, cart-total).
* REMOVED: Elementor dependency entirely — plugin works without Elementor installed.
* ADDED: class-zymarg-cart-page.php — WooCommerce cart page override via hooks.
* ADDED: class-zymarg-cart-admin.php — WordPress Admin settings panel with 4 tabs.
* ADDED: class-zymarg-cart-settings.php — Single settings store using get_option/update_option.
* ADDED: assets/css/zymarg-cart-vars.css — Full CSS custom property declaration file.
* ADDED: Admin Style tab — color pickers for primary, surface, text, border, and dark mode tokens.
* CHANGED: All Elementor $settings[] reads now come from Zymarg_Cart_Settings::get_*().
* CHANGED: Template docblocks updated (Zymarg_Cart_Page instead of Zymarg_Widget_*).
* CHANGED: Selected product rows no longer change background colour (removed selected row highlight).
* KEPT: All 7 backend PHP classes unchanged (AJAX, Dokan, session, usermeta, merge, partial, helpers).
* KEPT: All 6 JavaScript modules unchanged.
* KEPT: All 3 CSS stylesheets unchanged (main, mobile, editor removed).
* KEPT: All 9 PHP templates unchanged (settings key names are identical).
* KEPT: All 11 AJAX actions, complete functionality, all icon SVGs.
* KEPT: WooCommerce HPOS compatibility declaration.

= 1.5.6 =
* Fix: Subtotal column price vertically centered in grid cell on desktop/tablet.

= 1.5.5 =
* Fix: Subtotal column alignment inconsistency between simple and variable product rows.

= 1.5.4 =
* Fix: Subtotal column vertical alignment.
* Fix: Coupon column now fully hidden when coupon feature is disabled.

= 1.5.3 =
* UX: Removed extra gap between Cart Body and sticky Cart Total.

= 1.5.2 =
* Fix: Subtotal bar count and amount now update live on every change.

= 1.5.1 =
* Fix: Sticky popup not appearing (CSS containing-block issue).
* Fix: Popup visibility transition on first open.

= 1.5.0 =
* Feature: Sticky Cart Total with slide-up popup.
* Feature: Per-breakpoint sticky toggles (Desktop/Tablet/Mobile).
* Feature: ResizeObserver for Cart Body bottom padding.
* Feature: Footer note in popup.
* Feature: iOS safe-area-inset support.
* Feature: Reduced-motion support.

= 1.4.4 =
* Defaults: Tax line hidden by default; label changed to "VAT".

= 1.4.3 =
* Change: Elementor Pro dependency removed (free Elementor only).

= 1.4.2 =
* Fix: HPOS compatibility declared unconditionally.

= 1.3.0 =
* Architecture: All icons converted from Tabler Icons web font to inline SVG.
* Change: Mobile breakpoint default changed from 768px to 480px.

== Installation ==

1. Deactivate and delete the old ZYMARG Cart widget plugin (v1.x).
2. Upload this plugin folder to /wp-content/plugins/zymarg-cart/
3. Activate via WordPress Plugins screen.
4. Go to WP Admin → ZYMARG Cart → Settings to configure.
5. Your WooCommerce cart page will automatically use the new cart.

== Malaysian Tax Configuration ==

To show the correct tax label for Malaysia (SST), add this to your theme's functions.php:

    add_filter( 'zymarg_cart_tax_label', fn() => 'Tax (6% SST)' );

Or set it via WP Admin → ZYMARG Cart → Settings → Total → Tax Label.

== Notes ==

- Requires WooCommerce 9.0+ and Dokan Pro 3.0+.
- PHP 8.1 or higher required.
- The cart page background inherits from your active Astra theme automatically.
- All style overrides in the Admin Style tab are output as CSS custom property
  overrides in wp_head, sitting on top of the plugin's token defaults.
