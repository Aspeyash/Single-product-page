/**
 * ZYMARG Store Page — Following Page
 *
 * Handles the Unfollow button on the My Account > Following page.
 * Calls the existing REST endpoint, then removes the store card from the DOM.
 * Config is injected by ZYMARG_SP_Following::enqueue_assets() as ZYMARG_FOLLOWING.
 */
(function () {
  "use strict";

  var cfg      = window.ZYMARG_FOLLOWING || {};
  var apiBase  = (cfg.apiBase  || "").replace(/\/$/, "");
  var followNs = cfg.followNs  || "zymarg/v1";
  var nonce    = cfg.nonce     || "";
  var i18n     = cfg.i18n     || {};

  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll("[data-unfollow]").forEach(function (btn) {
      btn.addEventListener("click", handleUnfollow);
    });
  });

  async function handleUnfollow(e) {
    var btn     = e.currentTarget;
    var storeId = parseInt(btn.dataset.unfollow, 10);
    if (!storeId) return;

    // Disable button while the request is in flight.
    btn.disabled = true;
    var originalText = btn.textContent;
    btn.textContent = i18n.unfollowing || "Unfollowing\u2026";

    try {
      var res = await fetch(apiBase + "/" + followNs + "/unfollow", {
        method : "POST",
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce"  : nonce,
        },
        body: JSON.stringify({ store_id: storeId }),
      });

      if (!res.ok) {
        throw new Error("HTTP " + res.status);
      }

      // Success — remove the card from the grid with a fade.
      var card = btn.closest("[data-store-id]");
      if (card) {
        card.style.transition = "opacity .3s, transform .3s";
        card.style.opacity    = "0";
        card.style.transform  = "scale(0.95)";
        setTimeout(function () {
          card.remove();
          maybeShowEmptyState();
        }, 320);
      }

    } catch (err) {
      console.error("[ZYMARG] Unfollow error:", err);
      btn.disabled    = false;
      btn.textContent = originalText;
      // Surface a brief inline error under the button.
      showError(btn, i18n.error || "Something went wrong. Please try again.");
    }
  }

  /**
   * After a card is removed, check if the grid is now empty and swap to the
   * empty state message if so.
   */
  function maybeShowEmptyState() {
    var grid  = document.querySelector(".zymarg-following__grid");
    var count = document.querySelector(".zymarg-following__count");
    if (!grid) return;

    var remaining = grid.querySelectorAll("[data-store-id]").length;

    // Update count line.
    if (count) {
      if (remaining === 0) {
        count.remove();
      } else {
        count.textContent = remaining === 1
          ? "You are following 1 store."
          : "You are following " + remaining + " stores.";
      }
    }

    // Swap to empty state.
    if (remaining === 0) {
      grid.innerHTML =
        '<div class="zymarg-following__empty">' +
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 1 0 7.78L12 21.23l-8.84-8.84a5.5 5.5 0 0 1 7.78-7.78l1.06 1.06 1.06-1.06a5.5 5.5 0 0 1 7.78 0z"/></svg>' +
          '<h3>No stores followed yet</h3>' +
          '<p>When you follow a store, it will appear here so you can easily find it again.</p>' +
        '</div>';
    }
  }

  function showError(btn, message) {
    var existing = btn.parentNode.querySelector(".zymarg-following__error");
    if (existing) existing.remove();
    var el = document.createElement("p");
    el.className   = "zymarg-following__error";
    el.textContent = message;
    el.style.cssText = "color:#dc2626;font-size:0.8125rem;margin:6px 0 0;grid-column:1/-1;";
    btn.parentNode.appendChild(el);
    setTimeout(function () { el.remove(); }, 4000);
  }
})();
