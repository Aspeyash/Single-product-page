=== ZYMARG Header ===
Contributors: zymarg
Tags: woocommerce, header, multi-vendor, dokan, marketplace
Requires at least: 6.3
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.5.0
License: Proprietary

Standalone site header for the ZYMARG multi-vendor marketplace. No dependency on Theme Builder.

== Description ==

ZYMARG Header is a self-contained site header — two-row layout (top bar + header bar), sticky
on scroll, responsive. Ships its own cart mini-panel (live sync, remove, qty stepper, full admin
controls). Integrates with ZYMARG Search System and WCPG Wishlist when active.

Features:
- Two-row layout: top bar + header bar, sticky on scroll
- Self-contained cart mini-panel with live sync, remove, quantity stepper
- Full admin settings panel for every style field
- Dokan Pro "Become a Seller" pill integration
- Uses the shared ZYMARG Design Tokens system for all brand colors, including
  automatic dark mode support (no per-site color overrides — colors are
  centrally managed across the ZYMARG plugin suite)
- Display Conditions for showing/hiding the header per page/post type/Dokan screen
- i18n ready (.pot file included)

== Changelog ==

= 1.5.0 =
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
* Fix: this plugin's admin settings screen used class="zymarg-admin-wrap" for
  its wrapper, which does not match the literal ".zymarg-admin" selector the
  shared token file scopes every back-end-only token to. Every back-end-only
  token referenced in this plugin's admin CSS (--zym-shadow-btn,
  --zym-shadow-surface, --zym-color-divider, etc.) was silently resolving to
  nothing. Added class="zymarg-admin" alongside the existing wrapper class
  (additive only, no selectors removed) so those tokens now resolve.
* Fix: rewrote the admin_css() branded <style> block (header gradient, brand
  text, badge, tab nav, section headers, submit row, AJAX toast, condition
  rule builder) to reference --zym-* tokens instead of raw hex, plus the
  unit-label spans and description paragraph colour. Every value verified
  byte-for-byte identical to the raw hex it replaced.
* Fix: replaced raw hex in the front-end cart mini-panel and header badge
  CSS (color:#fff, stroke:#fff, color:#ffd6fb) with the matching --zym-*
  token — zero visual change.
* Fix: Settings::themed()'s rendered fallback for cart_checkout_color and
  cart_badge_color used a raw hex literal (#ffd6fb / #fff) instead of the
  theme-aware token every other themed() call in the same method uses.
  Corrected to var(--zym-color-primary-fixed) and
  var(--zym-color-on-gradient) respectively — same exact values, now
  wired through the shared token system like the rest of the block.
* Note: PHP default-value strings in class-settings.php's $defaults array
  and the one-time migration comparisons in class-header.php are left as
  literal hex intentionally — they are comparison/migration anchors, not
  rendered CSS, and PHP has no way to reference a CSS var().
* Note: the topbar gradient and search bar styling in zymarg-header.css are
  explicitly out of scope for this release and were left untouched, per
  standing instruction to change nothing about the current design colors.

= 1.4.0 =
* See in-code history (this is the first readme.txt shipped with this plugin).
