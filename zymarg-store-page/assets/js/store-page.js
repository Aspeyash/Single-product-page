/* =============================================================================
   ZYMARG Store Page — store-page.js  v1.6.0
   Handles: product grid, load-more, sort, sticky header, follow toggle,
            story toggle, and the AURA Studio live-search bar.

   v1.2.5 changes
   ──────────────
   • Removed hardcoded catLabels map from CONFIG. The map contained Auralux
     Athletics-specific slug→label pairs ("running-shoes", "activewear", etc.)
     that were meaningless for any other vendor's store. catLabel() now relies
     entirely on the name property returned by the Dokan REST API
     (p.categories[0].name), which is the real WooCommerce category name for
     every vendor. Falls back to "Products" only when the API returns no
     category data at all (e.g. uncategorised product on mock fallback).

   v1.2.4 changes
   ──────────────
   • Search results now STAY ON PAGE — no navigation on result click, Enter, or
     "View all". Instead they filter the live product grid directly.
   • Quick-view drawer: clicking a search result slides in a product panel without
     leaving the store page (falls back to grid-filter if permalink is unavailable).
   • Dynamic quick-pick pills: fetches vendor's real categories from Dokan REST API
     instead of showing hardcoded "Jackets / Bags / Footwear" pills.
   • Active search state: search bar shows a "Showing results for X" banner above
     the grid with a clear button to reset back to the full catalog.
   ============================================================================= */
(function () {
  "use strict";

  /* ══════════════════════════════════════════════════════════════════════════
     CONFIG — merged from wp_localize_script + defaults
     ══════════════════════════════════════════════════════════════════════════ */
  const WP = window.ZYMARG_CONFIG || {};

  const CONFIG = {
    storeId:   WP.storeId  || 1,
    apiBase:   WP.apiBase  || "/wp-json/",
    shopUrl:   WP.shopUrl  || "/",
    perPage:   WP.perPage  || 8,
    showAura:  WP.showAura !== undefined ? !!WP.showAura : true,
    storeName: WP.storeName || "",

    // Cards are rendered server-side by the ZYMARG Template Pack via the
    // Product Grid engine. This page still decides which products to show;
    // it no longer decides what they look like.
    ajaxUrl:     WP.ajaxUrl     || "/wp-admin/admin-ajax.php",
    cardsAction: WP.cardsAction || "",
    cardsNonce:  WP.cardsNonce  || "",
  };

  /* ══════════════════════════════════════════════════════════════════════════
     CATALOGUE FALLBACK - removed, see below
     ══════════════════════════════════════════════════════════════════════════ */
  /*
   * The mock product catalogue that used to live here has been removed.
   * It rendered twelve invented products - AeroFlex Pro Running Shoe and
   * friends, with invented prices, ratings and sold counts - whenever the
   * Dokan REST call failed, on real vendors' stores. A shopper could be
   * shown stock that does not exist, and a seller credited with sales they
   * never made. An API failure now reports an API failure.
   */

  /*
   * A neutral, local, inline placeholder for a product with no image. It is
   * obviously not a photograph of anything, which is the point: the random
   * stock photo it replaces implied the product looked like that.
   */
  const NO_IMAGE = "data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 400 400'%3E%3Crect width='400' height='400' fill='%23f1eef2'/%3E%3Cpath d='M150 235l40-45 30 34 26-29 44 50z' fill='%23cfc6d2'/%3E%3Ccircle cx='168' cy='170' r='16' fill='%23cfc6d2'/%3E%3C/svg%3E";

  /* ══════════════════════════════════════════════════════════════════════════
     PRODUCT GRID STATE
     ══════════════════════════════════════════════════════════════════════════ */
  const PAGE_SIZE    = CONFIG.perPage;
  let visibleCount   = PAGE_SIZE;
  let currentPage    = 1;
  let totalPages     = 999;     // unknown until first API response; set properly after
  let productsList   = [];
  let isApiAvailable = true;
  let scrollObserver = null;    // IntersectionObserver instance

  // Search filter state
  let activeSearchQuery  = "";   // non-empty when the grid is showing search results
  let currentResults     = [];   // current category/search result set (shared across handlers)
  let searchResultsList  = [];   // accumulated products for the current search
  const SEARCH_PAGE_SIZE = 20;   // results per page during search
  let searchPage         = 1;    // current search page loaded
  let searchTotalPages   = 1;    // total pages available for current query
  let searchLoadingMore  = false; // guard against double-fetch

  // Category filter state (client-side pagination over a locally-filtered set)
  let activeCatProducts  = [];   // full filtered product array for the active category
  let activeCatName      = "";   // display name of the active category
  let catPage            = 0;    // how many PAGE_SIZE slices have been rendered
  const CAT_PAGE_SIZE    = 20;   // products to show per load-more in category mode

  /*
   * v1.23.0: #product-grid is now server-rendered by the Product Grid engine
   * (see templates/store.php) with its own native infinite scroll, so this
   * script no longer builds its initial page or paginates it. It is kept as
   * a SIBLING, not replaced: `staticGrid` is only ever hidden/shown, never
   * written to.
   *
   * Every existing category/search code path in this file still targets the
   * variable named `grid` by identity (dozens of call sites), so rather than
   * touch each one, `grid` itself now points at the new sibling container,
   * #product-grid-filtered. That container starts empty and hidden; the two
   * small helpers below are the only new logic that decides which of the two
   * containers is visible at any moment. Category and search fetch/render
   * logic is otherwise completely unchanged.
   */
  const staticGrid  = document.getElementById("product-grid");
  const grid        = document.getElementById("product-grid-filtered");

  /* Show the filtered grid, hide the server-rendered static one. Called the
     moment a category filter or a search becomes active. */
  function showFilteredGrid() {
    if (staticGrid) staticGrid.style.display = "none";
    if (grid) grid.style.display = "";
  }

  /* Hide the filtered grid, reveal the static one exactly as the server
     rendered it — it was never touched, so nothing needs to be re-fetched
     or re-rendered here. Called whenever a filter/search is cleared. */
  function showStaticGrid() {
    if (grid) {
      grid.style.display = "none";
      grid.innerHTML = ""; // don't keep a stale filtered result set in the DOM
    }
    if (staticGrid) staticGrid.style.display = "";
  }

  // Infinite scroll elements (replace old load-more button). These now
  // belong to the category/search path only — #product-grid has its own,
  // engine-native infinite scroll and never uses these.
  const infiniteLoader   = document.getElementById("zy-infinite-loader");
  const scrollSentinel   = document.getElementById("zy-scroll-sentinel");
  const a11yStatus       = document.getElementById("zy-scroll-a11y");

  /* ── Loader state helpers ──────────────────────────────────────────────── */
  function loaderShow() {
    if (!infiniteLoader) return;
    infiniteLoader.className = "zy-infinite-loader is-loading";
    infiniteLoader.innerHTML = `
      <span class="zy-loader-dots" aria-hidden="true"><span></span><span></span><span></span></span>
      <span class="zy-loader-label">Loading more products <span class="zy-text-dots"><span>.</span><span>.</span><span>.</span></span></span>`;
    if (a11yStatus) a11yStatus.textContent = "Loading more products.";
  }
  function loaderHide() {
    if (!infiniteLoader) return;
    infiniteLoader.className = "zy-infinite-loader";
    infiniteLoader.innerHTML = "";
  }
  function loaderFinished() {
    if (!infiniteLoader) return;
    infiniteLoader.className = "zy-infinite-loader is-finished";
    infiniteLoader.innerHTML = `
      <span class="zy-finished-icon" aria-hidden="true">✓</span>
      <span>All products loaded</span>`;
    if (a11yStatus) a11yStatus.textContent = "All products have been loaded.";
    // Stop observing — nothing more to load
    if (scrollObserver) scrollObserver.disconnect();
  }

  // Keep a compat shim so existing code that checks loadMoreBtn doesn't throw
  const loadMoreBtn = null;
  const sortSelect  = document.querySelector("[data-sort-select]");

  /* ── Star SVG ─────────────────────────────────────────────────────────── */
  function starSVG(filled) {
    return `<svg class="h-3 w-3 ${filled ? "text-amber-400" : "text-zy-border"}" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.868 2.884c-.321-.772-1.415-.772-1.736 0l-1.83 4.401-4.753.381c-.833.067-1.171 1.107-.536 1.651l3.62 3.102-1.106 4.637c-.194.813.691 1.456 1.405 1.02L10 15.591l4.069 2.485c.713.436 1.598-.207 1.404-1.02l-1.106-4.637 3.62-3.102c.635-.544.297-1.584-.536-1.65l-4.752-.382-1.831-4.401Z"/></svg>`;
  }

  /* ── HTML escaper ─────────────────────────────────────────────────────── */
  function escHtml(s) {
    return String(s || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  /* ── Cards come from the server ──────────────────────────────────────────
   * The ZYMARG card is a PHP template owned by the ZYMARG Template Pack, so
   * it cannot be built here. The in-file card markup that used to live at
   * this spot has been removed.
   *
   * This page still decides WHICH products to show, exactly as before: sort,
   * the category filter and AURA search each resolve their own product list
   * from Dokan's REST API. It then asks the server to draw that list. One
   * card design, in one place, and a Template Pack update restyles this grid
   * with no change here.
   * ─────────────────────────────────────────────────────────────────────── */

  function productIds(list) {
    return (list || [])
      .map(function (p) { return p && p.id ? parseInt(p.id, 10) : 0; })
      .filter(function (id) { return id > 0; });
  }

  async function fetchCardsHTML(list) {
    const ids = productIds(list);
    if (!ids.length || !CONFIG.cardsAction || !CONFIG.cardsNonce) return "";

    const fd = new FormData();
    fd.append("action", CONFIG.cardsAction);
    fd.append("nonce",  CONFIG.cardsNonce);
    fd.append("ids",    ids.join(","));

    try {
      const res  = await fetch(CONFIG.ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" });
      const json = await res.json();
      return (json && json.success && json.data && json.data.html) ? json.data.html : "";
    } catch (e) {
      return "";
    }
  }

  /* Where the engine keeps its cards inside a rendered widget. */
  const CARD_HOST_SEL = ".zymarg-wcpg__grid, .zymarg-wcpg__items, .zymarg-wcpg__slider-wrapper, .zymarg-wcpg";

  /* Replace the grid with a freshly rendered widget. */
  async function renderProducts(list) {
    if (!grid) return;
    const html = await fetchCardsHTML(list);
    if (html) {
      grid.innerHTML = html;
      return;
    }
    // An empty list is a legitimate answer; a failed render is not, and the
    // two must not look alike.
    if (productIds(list).length) renderProductsError();
  }

  /*
   * Append to the grid.
   *
   * The server returns a complete engine widget, but appending a second
   * widget would leave two independent grids stacked on the page. So the new
   * cards are lifted out and moved into the widget already rendered here.
   *
   * They stay functional because the engine binds add-to-cart, wishlist and
   * quick view by delegation from an ancestor, and that ancestor is the
   * widget already in the grid. Appending bare cards into it is fine;
   * appending cards with no widget around them is what would break them.
   */
  async function appendProducts(list) {
    if (!grid) return 0;
    const html = await fetchCardsHTML(list);
    if (!html) return 0;

    const tmp = document.createElement("div");
    tmp.innerHTML = html;

    const incoming = tmp.querySelector(CARD_HOST_SEL);
    const cards    = incoming
      ? Array.prototype.slice.call(incoming.children)
      : Array.prototype.slice.call(tmp.children);

    const host = grid.querySelector(CARD_HOST_SEL);
    if (!host || !cards.length) {
      // Nothing to append into yet — paint the batch instead of dropping it.
      grid.innerHTML = html;
      return cards.length;
    }

    cards.forEach(function (node) { host.appendChild(node); });
    return cards.length;
  }


  /* -- Honest failure state -------------------------------------------- */
  /*
   * Shown when the catalogue cannot be loaded. It must never be confused
   * with an empty store: "0 products" is a statement about the seller,
   * whereas a load failure is a statement about us.
   */
  function renderProductsError() {
    if (!grid) return;
    setProductsHeading("Products unavailable", true);
    grid.innerHTML =
      '<div class="zy-grid-msg py-12 text-center text-zy-body/60">' +
        '<p class="text-sm font-semibold">We could not load this store&rsquo;s products.</p>' +
        '<p class="mt-1 text-xs">This is a problem on our side, not an empty store.</p>' +
        '<p class="mt-3"><button type="button" class="text-zy-primary underline" ' +
        'onclick="window.location.reload()">Try again</button></p>' +
      '</div>';
  }

  /* ── Local sort ───────────────────────────────────────────────────────── */

  /* ── Dokan REST product fetch ─────────────────────────────────────────── */
  async function loadProducts(reset = false) {
    // If there's an active search, re-apply it instead of loading the full catalog
    if (activeSearchQuery) {
      applySearchToGrid(activeSearchQuery);
      return;
    }

    if (reset) { currentPage = 1; productsList = []; }

    if (!isApiAvailable) {
      renderProductsError();
      return;
    }

    try {
      const val     = sortSelect ? sortSelect.value : "popular";
      const orderby = { newest:"date", "price-asc":"price", "price-desc":"price", rating:"rating" }[val] || "popularity";
      const order   = val === "price-asc" ? "asc" : "desc";

      const url = new URL(`dokan/v1/stores/${CONFIG.storeId}/products`, CONFIG.apiBase);
      url.searchParams.set("page",     currentPage);
      url.searchParams.set("per_page", CONFIG.perPage);
      url.searchParams.set("orderby",  orderby);
      url.searchParams.set("order",    order);
      url.searchParams.set("status",   "publish");

      const res  = await fetch(url.toString());
      if (!res.ok) throw new Error("API error");
      const data = await res.json();

      // Track total pages so the IntersectionObserver knows when to stop
      totalPages = parseInt(res.headers.get("X-WP-TotalPages") || "1", 10);

      productsList = productsList.concat(data);
      await renderProducts(productsList);
      // Loader state is managed by the IntersectionObserver handler, not here

      // Update heading: use X-WP-Total for exact count, fall back to visible count
      const total     = res.headers.get("X-WP-Total");
      if (reset) {
        const count = total ? parseInt(total).toLocaleString() : productsList.length.toLocaleString();
        setProductsHeading(`${count} products in store`, false);
      }

    } catch (err) {
      console.warn("[ZYMARG] Product fetch failed.", err);
      isApiAvailable = false;
      renderProductsError();
    }
  }

  /* ── Products heading ───────────────────────────────────────── */
  /*
   * Idle, this heading repeats the "All Products" label directly above it, so
   * it is hidden from sight but kept in the DOM: the section's aria-labelledby
   * points at it, and removing it would leave the landmark unnamed.
   *
   * While searching or filtering it stops being a duplicate and becomes real
   * feedback ("42 results for shoes"), so it is shown. It reuses the exact
   * classes the heading shipped with, so the size, weight and colour stay on
   * the same type scale as every other section heading.
   */
  const HEADING_VISIBLE_CLASSES = ["mt-2", "text-xl", "font-bold", "tracking-tight", "text-zy-dark", "sm:text-2xl"];

  function setProductsHeading(text, visible) {
    const headingEl = document.getElementById("products-heading");
    if (!headingEl) return;

    headingEl.textContent = text;

    if (visible) {
      headingEl.classList.remove("sr-only");
      HEADING_VISIBLE_CLASSES.forEach(c => headingEl.classList.add(c));
    } else {
      HEADING_VISIBLE_CLASSES.forEach(c => headingEl.classList.remove(c));
      headingEl.classList.add("sr-only");
    }
  }

  /* ══════════════════════════════════════════════════════════════════════════
     SEARCH → GRID FILTER (core new behavior)
     ══════════════════════════════════════════════════════════════════════════ */

  /* ── In-page search banner (shows above the grid while search is active) ─ */
  let searchBanner = null;

  function showSearchBanner(query, count) {
    if (!searchBanner) {
      searchBanner = document.createElement("div");
      searchBanner.id = "zy-search-banner";
      searchBanner.className = "aura-search-banner";
      const productsSection = document.getElementById("products");
      if (productsSection) productsSection.insertBefore(searchBanner, productsSection.firstChild);
    }

    searchBanner.innerHTML = `
      <span class="aura-search-banner__text">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path stroke-linecap="round" d="m21 21-4.35-4.35"/></svg>
        Showing <strong>${count}</strong> result${count !== 1 ? "s" : ""} for "<strong>${escHtml(query)}</strong>"
      </span>
      <button type="button" class="aura-search-banner__clear" id="zy-clear-search" aria-label="Clear search and show all products">
        Clear search
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>`;

    searchBanner.style.display = "flex";

    document.getElementById("zy-clear-search").addEventListener("click", clearSearchFilter);
  }

  function hideSearchBanner() {
    if (searchBanner) searchBanner.style.display = "none";
  }

  /* ── Apply search results to the product grid ─────────────────────────── */
  /* ── Apply first page of search results to the grid ─────────────────── */
  async function applySearchToGrid(query, firstPageProducts, totalPages) {
    activeSearchQuery = query;
    searchResultsList = firstPageProducts || [];
    searchPage        = 1;
    searchTotalPages  = totalPages || 1;

    // Reveal the filtered grid, hide the server-rendered static one. Safe to
    // call on every invocation of this function, including the re-sort
    // path — it is a pure visibility toggle, so calling it while already
    // showing the filtered grid is a no-op.
    showFilteredGrid();

    if (searchResultsList.length === 0) {
      setProductsHeading(`No results for "${query}"`, true);
      if (grid) {
        grid.innerHTML = `
          <div class="zy-grid-msg py-12 text-center text-zy-body/60">
            <svg class="mx-auto mb-3 h-10 w-10 text-zy-border" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" aria-hidden="true">
              <circle cx="11" cy="11" r="8"/><path stroke-linecap="round" d="m21 21-4.35-4.35"/>
            </svg>
            <p class="text-sm font-semibold">No products found for "${escHtml(query)}"</p>
            <p class="mt-1 text-xs">Try a different keyword or <button type="button" class="text-zy-primary underline" onclick="document.getElementById('zy-clear-search')&&document.getElementById('zy-clear-search').click()">browse all products</button></p>
          </div>`;
      }
      loaderHide();
    } else {
      setProductsHeading(`${searchResultsList.length} result${searchResultsList.length !== 1 ? "s" : ""} for "${query}"`, true);
      await renderProducts(searchResultsList);
      // Show load-more only if more search pages remain
      if (loadMoreBtn) {
        if (searchPage >= searchTotalPages) loaderHide(); else loaderShow();
      }
    }

    showSearchBanner(query, searchResultsList.length);

    const productsSection = document.getElementById("products");
    if (productsSection) {
      productsSection.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  }

  /* ── Append next page of search results to the grid ──────────────────── */
  /* Fetches a single page of search results — used by both the AURA dropdown
     ("See all results") and loadMoreSearchResults. Lives in the outer scope so
     both can call it. Uses its own AbortController so it doesn't interfere with
     the dropdown's per-keystroke aborts. */
  let searchFetchCtrl = null;
  async function fetchSearchPage(query, page = 1) {
    if (searchFetchCtrl) searchFetchCtrl.abort();
    searchFetchCtrl = new AbortController();

    const url = new URL(`dokan/v1/stores/${CONFIG.storeId}/products`, CONFIG.apiBase);
    url.searchParams.set("search",   query);
    url.searchParams.set("per_page", String(SEARCH_PAGE_SIZE));
    url.searchParams.set("page",     String(page));
    url.searchParams.set("status",   "publish");
    url.searchParams.set("_fields",
      "id,name,price,regular_price,sale_price,on_sale,stock_status,stock_quantity,categories,images,date_created,permalink,short_description");

    const res = await fetch(url.toString(), {
      signal:  searchFetchCtrl.signal,
      headers: { Accept: "application/json" },
    });
    if (!res.ok) throw new Error(`API ${res.status}`);

    const products   = await res.json();
    const totalPages = parseInt(res.headers.get("X-WP-TotalPages") || "1");
    return { products, totalPages };
  }

  /* ── Fetch products by category (sidebar category links) ───────────────
     Strategy 1: wc/v3/products?seller_id=X&category=<term_id>
       - Proper WooCommerce REST API; Dokan registers seller_id filter here.
       - Supports true category filtering by term ID.
     Strategy 2: dokan/v1/stores/X/products (fetch all, filter client-side)
       - Dokan endpoint does NOT support ?category= param.
       - We load up to 100 products and match by categories[].id or .slug.
     ─────────────────────────────────────────────────────────────────────── */
  let catFetchCtrl = null;
  async function fetchByCategory(catSlug, catId, page = 1) {
    if (catFetchCtrl) catFetchCtrl.abort();
    catFetchCtrl = new AbortController();
    const signal = catFetchCtrl.signal;

    // Strategy 1: wc/v3/products with seller_id + category term ID
    if (catId) {
      try {
        const url = new URL(`wc/v3/products`, CONFIG.apiBase);
        url.searchParams.set("seller_id", String(CONFIG.storeId));
        url.searchParams.set("category",  String(catId));
        url.searchParams.set("per_page",  String(SEARCH_PAGE_SIZE));
        url.searchParams.set("page",      String(page));
        url.searchParams.set("status",    "publish");
        url.searchParams.set("_fields",
          "id,name,price,regular_price,sale_price,on_sale,stock_status,stock_quantity,categories,images,date_created,permalink,short_description");
        const res = await fetch(url.toString(), { signal, headers: { Accept: "application/json" } });
        if (res.ok) {
          const products   = await res.json();
          const totalPages = parseInt(res.headers.get("X-WP-TotalPages") || "1");
          if (Array.isArray(products)) return { products, totalPages };
        } else {
          const errText = await res.text();
        }
      } catch (err) {
        if (err.name === "AbortError") throw err;
      }
    }

    // Strategy 2: Dokan endpoint — fetch ALL pages of vendor products, filter client-side.
    // Dokan does not support ?category= so we must pull everything and match locally.
    // We fetch page 1 first to get X-WP-TotalPages, then fetch remaining pages in parallel.
    try {
      const buildUrl = (pg) => {
        const u = new URL(`dokan/v1/stores/${CONFIG.storeId}/products`, CONFIG.apiBase);
        u.searchParams.set("per_page", "100");
        u.searchParams.set("page",     String(pg));
        u.searchParams.set("status",   "publish");
        u.searchParams.set("_fields",
          "id,name,price,regular_price,sale_price,on_sale,stock_status,stock_quantity,categories,images,date_created,permalink,short_description");
        return u.toString();
      };

      // Page 1 — also tells us the total page count
      const res1 = await fetch(buildUrl(1), { signal, headers: { Accept: "application/json" } });
      if (!res1.ok) throw new Error(`API ${res1.status}`);
      const page1 = await res1.json();
      if (!Array.isArray(page1)) throw new Error("Non-array response");

      const totalPages = parseInt(res1.headers.get("X-WP-TotalPages") || "1", 10);

      // Fetch all remaining pages in parallel
      let allProducts = [...page1];
      if (totalPages > 1) {
        const pageNums = [];
        for (let pg = 2; pg <= totalPages; pg++) pageNums.push(pg);
        const batches = await Promise.all(
          pageNums.map(pg =>
            fetch(buildUrl(pg), { signal, headers: { Accept: "application/json" } })
              .then(r => r.ok ? r.json() : [])
              .catch(() => [])
          )
        );
        batches.forEach(b => { if (Array.isArray(b)) allProducts = allProducts.concat(b); });
      }

      // Filter to only this category
      const catIdNum = catId ? parseInt(catId, 10) : 0;
      const filtered = allProducts.filter(p => {
        if (!p.categories || !p.categories.length) return false;
        return p.categories.some(c =>
          (catIdNum && c.id === catIdNum) || (catSlug && c.slug === catSlug)
        );
      });

      return { products: filtered, totalPages: 1 };
    } catch (err) {
      if (err.name === "AbortError") throw err;
      throw err;
    }
  }

  async function loadMoreSearchResults() {
    if (searchLoadingMore || searchPage >= searchTotalPages) return;
    searchLoadingMore = true;

    if (loadMoreBtn) loaderShow();

    try {
      const { products: batch, totalPages } = await fetchSearchPage(activeSearchQuery, searchPage + 1);

      searchPage++;
      searchTotalPages  = totalPages;
      searchResultsList = searchResultsList.concat(batch);

      // Append new cards without clearing existing ones
      await appendProducts(batch);

      // Update banner count
      showSearchBanner(activeSearchQuery, searchResultsList.length);

      // Show/hide load-more
      if (loadMoreBtn) {
        
        if (searchPage >= searchTotalPages) loaderHide(); else loaderShow();
      }
    } catch (err) {
      console.warn("[ZYMARG] Search load-more failed:", err);
      if (loadMoreBtn) {
        
        if (searchPage >= searchTotalPages) loaderHide(); else loaderShow();
      }
    }

    searchLoadingMore = false;
  }

  /* -- Search fallback: none. Never invent matches. ------------------- */
  function localSearch() {
    // There is no local catalogue to fall back to any more, and inventing
    // matches would be worse than returning none. Callers treat an empty
    // array as "no results" and say the search is unavailable.
    return [];
  }

  /* ── Clear search and restore full product catalog ───────────────────── */
  function clearSearchFilter() {
    activeSearchQuery = "";
    searchResultsList = [];
    searchPage        = 1;
    searchTotalPages  = 1;

    // Reset category filter state
    activeCatProducts = [];
    activeCatName     = "";
    catPage           = 0;

    // Remove active highlight from sidebar category links
    document.querySelectorAll(".zy-sidebar-cat").forEach(l => l.classList.remove("is-active"));

    // Restore heading
    setProductsHeading("Products in store", false);

    // Re-enable infinite scroll for normal catalog mode
    totalPages = 999;
    loaderHide();
    if (scrollObserver && scrollSentinel) {
      scrollObserver.disconnect();
    }

    /*
     * v1.23.0: restore the server-rendered "All Products" grid instead of
     * re-fetching the catalog. It was only ever hidden by showFilteredGrid()
     * — its markup, scroll position and the engine's own infinite scroll
     * are exactly as they were, so there is nothing to reload here.
     */
    showStaticGrid();
    visibleCount = PAGE_SIZE;
    hideSearchBanner();

    // Clear the AURA input
    const auraInput = document.getElementById("aura-input");
    const auraClear = document.getElementById("aura-clear");
    if (auraInput) auraInput.value = "";
    if (auraClear) auraClear.style.display = "none";
  }

  /* ══════════════════════════════════════════════════════════════════════════
     QUICK-VIEW DRAWER
     Shows product details in a slide-in panel — user stays on store page
     ══════════════════════════════════════════════════════════════════════════ */
  let drawerEl    = null;
  let overlayEl   = null;
  let drawerCache = {};  // id → product data

  function buildDrawer() {
    // Overlay
    overlayEl = document.createElement("div");
    overlayEl.className = "zy-drawer-overlay";
    overlayEl.addEventListener("click", closeDrawer);

    // Drawer panel
    drawerEl = document.createElement("div");
    drawerEl.className = "zy-drawer";
    drawerEl.setAttribute("role", "dialog");
    drawerEl.setAttribute("aria-modal", "true");
    drawerEl.setAttribute("aria-label", "Product quick view");

    document.body.appendChild(overlayEl);
    document.body.appendChild(drawerEl);

    // Keyboard close
    document.addEventListener("keydown", e => {
      if (e.key === "Escape") closeDrawer();
    });
  }

  function openDrawer(productData) {
    if (!drawerEl) buildDrawer();

    const p        = productData;
    const name     = escHtml(p.name || "");
    const price    = parseFloat(p.price || p.price) || 0;
    const oldPrice = parseFloat(p.regular_price || "") || (p.oldPrice || null);
    const rating    = parseFloat(p.average_rating !== undefined ? p.average_rating : p.rating);
    const hasRating = !isNaN(rating) && rating > 0;
    const images = (p.images && p.images.length > 0)
                     ? p.images.map(i => i.src)
                     : [NO_IMAGE];
    const cat      = (p.categories && p.categories[0]) ? p.categories[0].name : (p.cat || "");
    const discount = (oldPrice && oldPrice > price) ? Math.round((1 - price / oldPrice) * 100) : 0;
    const stars    = hasRating ? [1,2,3,4,5].map(i => starSVG(i <= Math.round(rating))).join("") : "";
    const rawDesc  = p.short_description || p.description || "No description available.";
    const desc     = escHtml(rawDesc.replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim());
    const inStock  = p.stock_status !== "outofstock";
    const permalink = p.permalink || "#";

    const slidesHTML = images.map((src, i) => `
      <div class="zy-slider__slide ${i === 0 ? "is-active" : ""}" data-index="${i}">
        <img src="${escHtml(src)}" alt="${name}" class="zy-drawer__img" loading="${i === 0 ? "eager" : "lazy"}" />
      </div>`).join("");

    const dotsHTML = images.length > 1 ? `
      <div class="zy-slider__dots">
        ${images.map((_, i) => `<button class="zy-slider__dot ${i === 0 ? "is-active" : ""}" data-index="${i}" aria-label="Image ${i+1}"></button>`).join("")}
      </div>` : "";

    const arrowsHTML = images.length > 1 ? `
      <button class="zy-slider__arrow zy-slider__arrow--prev" aria-label="Previous image">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
      </button>
      <button class="zy-slider__arrow zy-slider__arrow--next" aria-label="Next image">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
      </button>` : "";

    drawerEl.innerHTML = `
      <div class="zy-drawer__inner">
        <button type="button" class="zy-drawer__close" onclick="window.__zyCloseDrawer&&window.__zyCloseDrawer()" aria-label="Close quick view">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>

        <div class="zy-drawer__img-wrap">
          <div class="zy-slider">
            ${slidesHTML}
          </div>
          ${arrowsHTML}
          ${dotsHTML}
          ${discount ? `<span class="zy-drawer__discount-badge">-${discount}%</span>` : ""}
        </div>

        <div class="zy-drawer__info">
          <p class="zy-drawer__cat">${escHtml(cat)}</p>
          <h2 class="zy-drawer__name">${name}</h2>

          <div class="zy-drawer__stars" aria-label="${rating} out of 5 stars">
            ${stars}
            <span class="zy-drawer__rating-val">${rating}</span>
          </div>

          <div class="zy-drawer__pricing">
            <span class="zy-drawer__price">৳${price.toLocaleString()}</span>
            ${(oldPrice && oldPrice > price) ? `<span class="zy-drawer__old-price">৳${oldPrice.toLocaleString()}</span>` : ""}
          </div>

          <p class="zy-drawer__stock ${inStock ? "zy-drawer__stock--in" : "zy-drawer__stock--out"}">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              ${inStock
                ? `<path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>`
                : `<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>`}
            </svg>
            ${inStock ? "In stock" : "Out of stock"}
          </p>

          <p class="zy-drawer__desc">${desc}</p>

          <div class="zy-drawer__actions">
            <a href="${escHtml(permalink)}" class="zy-drawer__btn zy-drawer__btn--primary" target="_blank" rel="noopener">
              View full product page
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
            </a>
            <button type="button" class="zy-drawer__btn zy-drawer__btn--secondary" onclick="window.__zyCloseDrawer&&window.__zyCloseDrawer()">
              Keep browsing
            </button>
          </div>
        </div>
      </div>`;

    // Init slider
    if (images.length > 1) {
      let current = 0;
      const slides = drawerEl.querySelectorAll(".zy-slider__slide");
      const dots   = drawerEl.querySelectorAll(".zy-slider__dot");

      function goTo(index) {
        slides[current].classList.remove("is-active");
        dots[current] && dots[current].classList.remove("is-active");
        current = (index + images.length) % images.length;
        slides[current].classList.add("is-active");
        dots[current] && dots[current].classList.add("is-active");
      }

      drawerEl.querySelector(".zy-slider__arrow--prev")
        .addEventListener("click", () => goTo(current - 1));
      drawerEl.querySelector(".zy-slider__arrow--next")
        .addEventListener("click", () => goTo(current + 1));
      dots.forEach(dot => dot.addEventListener("click", () => goTo(+dot.dataset.index)));

      // Touch swipe support
      let touchStartX = 0;
      const slider = drawerEl.querySelector(".zy-slider");
      slider.addEventListener("touchstart", e => { touchStartX = e.touches[0].clientX; }, { passive: true });
      slider.addEventListener("touchend", e => {
        const diff = touchStartX - e.changedTouches[0].clientX;
        if (Math.abs(diff) > 40) goTo(diff > 0 ? current + 1 : current - 1);
      });
    }

    // Animate in
    requestAnimationFrame(() => {
      overlayEl.classList.add("is-open");
      drawerEl.classList.add("is-open");
    });

    document.body.style.overflow = "hidden";
  }

  function closeDrawer() {
    if (!drawerEl) return;
    overlayEl.classList.remove("is-open");
    drawerEl.classList.remove("is-open");
    document.body.style.overflow = "";
  }

  /* ── Fetch product by id then open drawer ────────────────────────────── */
  async function openQuickView(productId) {
    if (!drawerEl) buildDrawer();


    // Check cache
    if (drawerCache[productId]) {
      openDrawer(drawerCache[productId]);
      return;
    }

    // Check search results list (already fetched)
    const fromSearch = searchResultsList.find(p => p.id === productId);
    if (fromSearch) {
      drawerCache[productId] = fromSearch;
      openDrawer(fromSearch);
      return;
    }

    // Check the main products list
    const fromGrid = productsList.find(p => p.id === productId);
    if (fromGrid) {
      drawerCache[productId] = fromGrid;
      openDrawer(fromGrid);
      return;
    }

    // Fetch from API
    if (isApiAvailable) {
      // Show loading state while fetching
      if (!drawerEl) buildDrawer();
      drawerEl.innerHTML = `<div class="zy-drawer__loading"><span class="zymarg-spark zymarg-spark--xl" role="img" aria-label="ZYMARG Discovery Spark"><svg class="zymarg-spark__svg" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><g class="zymarg-spark-group--accent"><path class="zymarg-spark-item--purple" d="M10.4 5.4c0 1.32-0.24 2.4-1.44 2.4 1.2 0 1.44 1.08 1.44 2.4 0-1.32 0.24-2.4 1.44-2.4-1.2 0-1.44-1.08-1.44-2.4z"></path><path class="zymarg-spark-item--gold" d="M10.4 6.0c0 0.96-0.18 1.8-1.08 1.8 0.9 0 1.08 0.84 1.08 1.8 0-0.9 0.18-1.8 1.08-1.8-0.9 0-1.08-0.84-1.08-1.8z"></path></g><g class="zymarg-spark-group--companion"><path class="zymarg-spark-item--purple" d="M9.5 10.92c0 2.25-0.45 4.12-2.4 4.12 1.95 0 2.4 1.87 2.4 4.12 0-2.25 0.45-4.12 2.4-4.12-1.95 0-2.4-1.87-2.4-4.12z"></path><path class="zymarg-spark-item--gold" d="M9.5 11.5c0 1.9-0.38 3.54-2.0 3.54 1.62 0 2.0 1.64 2.0 3.54 0-1.9 0.38-3.54 2.0-3.54-1.62 0-2.0-1.64-2.0-3.54z"></path></g><g class="zymarg-spark-group--hero"><path class="zymarg-spark-item--purple" d="M15.2 5.6c0 3.45-0.69 6.3-4.08 6.3 3.39 0 4.08 2.85 4.08 6.3 0-3.45 0.69-6.3 4.08-6.3-3.39 0-4.08-2.85-4.08-6.3z"></path><path class="zymarg-spark-item--gold" d="M15.2 6.5c0 2.9-0.58 5.4-3.39 5.4 2.81 0 3.39 2.5 3.39 5.4 0-2.9 0.58-5.4 3.39-5.4-2.81 0-3.39-2.5-3.39-5.4z"></path></g></svg></span> Loading…</div>`;
      overlayEl.classList.add("is-open");
      drawerEl.classList.add("is-open");
      document.body.style.overflow = "hidden";

      try {
        const url = new URL(`wc/v3/products/${productId}`, CONFIG.apiBase);
        const res = await fetch(url.toString());
        if (!res.ok) throw new Error(`API ${res.status}`);
        const data = await res.json();
        drawerCache[productId] = data;
        openDrawer(data);
      } catch (err) {
        console.warn("[ZYMARG] Quick-view fetch failed:", err);
        // No invented product to fall back to: say the load failed.
        drawerEl.innerHTML = '<div class="zy-drawer__loading">Could not load product details.</div>';
      }
    }
  }

  // Expose to global scope for onclick handlers in card HTML
  window.__zyOpenQuickView = openQuickView;
  window.__zyCloseDrawer   = closeDrawer;

  /* ══════════════════════════════════════════════════════════════════════════
     STORE DETAILS & REVIEWS
     ══════════════════════════════════════════════════════════════════════════ */
  async function fetchStoreDetails() {
    try {
      const res   = await fetch(`${CONFIG.apiBase}dokan/v1/stores/${CONFIG.storeId}`);
      if (!res.ok) throw new Error("Store API error");
      const store = await res.json();

      /*
       * Banner and logo are NOT touched here any more (v1.22.6).
       *
       * PHP already resolves both correctly from the same source of truth
       * Dokan's own REST endpoint reads from (`dokan_profile_settings`),
       * with this plugin's own upload fallback on top -- see
       * `templates/store.php`. Overwriting them here with Dokan's REST
       * response caused the correct server-rendered banner/logo to flash
       * briefly on load and then get replaced by a different, sometimes
       * wrong image a moment later, because the REST endpoint does not
       * always resolve the saved attachment the same way the template does.
       *
       * This is the same class of bug already fixed for name/avatar in
       * 1.1.3 and intentionally left alone for location/rating (see the
       * comments below): PHP owns the banner and logo, JS no longer
       * touches them.
       */

      const locEl = document.querySelector("[data-store-location]");
      if (locEl && store.address) {
        const city    = store.address.city    || "";
        const country = store.address.country || "";
        const locText = [city, country].filter(Boolean).join(", ");

        /*
         * No invented city here either. This used to fall back to
         * "Dhaka, Bangladesh", which would have re-inserted the fake
         * location at runtime even after the template stopped printing it.
         *
         * The template already renders this line from the same saved
         * address, and expands the stored two-letter country code into a
         * real country name. The API hands back the raw code, so the
         * server value is left alone whenever it is present -- overwriting
         * it would turn "Dhaka, Bangladesh" back into "Dhaka, BD".
         */
        const serverText = (locEl.textContent || "").trim();
        if (!serverText) {
          const row = locEl.closest("div");
          if (locText) {
            locEl.textContent = locText;
          } else if (row) {
            row.hidden = true;
          }
        }
      }

      /*
       * The store rating is NOT touched here any more.
       *
       * It is rendered server-side from the ZYMARG Reviews Engine, which
       * aggregates the real product reviews this vendor has received. Dokan's
       * `store.rating` is a separate, seller-level rating system fed by
       * different data; writing it over the engine's number produced a score
       * that disagreed with the reviews printed directly underneath it, and
       * fell back to a fabricated 4.8 / 12,480 whenever the API was quiet.
       *
       * If the store has no ratings, the server omits the markup entirely, so
       * there is nothing here to fill in.
       */

      /*
       * Hero Store Card v2: [data-store-followers] is now the number-only
       * node (a <b>), with its "Followers" label rendered separately by a
       * sibling <span> in the template — NOT a single "N followers" string
       * like before. Writing the old "<strong>N</strong> followers" HTML
       * into it would duplicate the word "Followers" next to the label span.
       * textContent (number only) keeps the two in sync correctly.
       */
      const followersEl = document.querySelector("[data-store-followers]");
      if (followersEl && store.followers_count !== undefined) {
        followersEl.textContent = new Intl.NumberFormat().format(store.followers_count);
      }

      // [data-store-since] no longer exists on the Hero Store Card (v2
      // redesign dropped the "Since <year>" row) — this now safely no-ops
      // via the null guard below if the element isn't present.
      if (store.registered) {
        const sinceEl = document.querySelector("[data-store-since]");
        if (sinceEl) sinceEl.textContent = `Since ${new Date(store.registered).getFullYear()}`;
      }

      if (store.store_description) {
        const descEl = document.querySelector("[data-store-desc]");
        if (descEl) descEl.textContent = store.store_description;
      }

    } catch (err) {
      console.warn("[ZYMARG] Store details fetch failed — placeholders kept.", err);
    }
  }

  /*
   * fetchStoreReviews() was removed.
   *
   * It called Dokan's /stores/<id>/reviews endpoint, which is a separate
   * seller-review system, and overwrote the review list with three of its
   * rows -- stamping every one of them "Verified Purchase" without checking,
   * and silently keeping the hardcoded mock reviews whenever it failed.
   *
   * The review feed is now rendered server-side by the ZYMARG Reviews Engine
   * from real WooCommerce product reviews, and paginated with plain links, so
   * it works with JavaScript disabled and cannot fall back to fiction.
   */

  /* ══════════════════════════════════════════════════════════════════════════
     STICKY HEADER (unchanged)
     ══════════════════════════════════════════════════════════════════════════ */
  function initStickyHeader() {
    const header = document.getElementById("sticky-header");
    if (!header) return;

    // Show sticky header the moment the page scrolls at all
    const onScroll = () => {
      const scrolled = window.scrollY > 0;
      header.classList.toggle("-translate-y-full", !scrolled);
      header.classList.toggle("translate-y-0",      scrolled);
    };
    window.addEventListener("scroll", onScroll, { passive: true });
    onScroll(); // set correct state on load
  }

  /* ══════════════════════════════════════════════════════════════════════════
     STORY TOGGLE (unchanged)
     ══════════════════════════════════════════════════════════════════════════ */
  /* -------------------------------------------------------------
   * Share button
   *
   * Vanilla on purpose: nothing on this front end loads jQuery.
   *
   * navigator.share opens the real OS share sheet (WhatsApp, Messenger,
   * Telegram...) and is the path almost every buyer here will take, since
   * it is a mobile-only API in practice. Desktop browsers mostly lack it,
   * so the fallback copies the link. navigator.clipboard needs a secure
   * context, hence the last-resort execCommand path for plain http.
   * ------------------------------------------------------------- */
  function initShareButton() {
    const btn = document.querySelector("[data-share-btn]");
    if (!btn) return;

    const note   = document.querySelector("[data-share-note]");
    const status = document.querySelector("[data-share-status]");
    let noteTimer = null;

    function flash(message) {
      if (status) status.textContent = message;
      if (!note) return;
      note.textContent = message;
      note.hidden = false;
      if (noteTimer) clearTimeout(noteTimer);
      noteTimer = setTimeout(() => { note.hidden = true; }, 2000);
    }

    function legacyCopy(text) {
      const ta = document.createElement("textarea");
      ta.value = text;
      ta.setAttribute("readonly", "");
      ta.style.position = "absolute";
      ta.style.left = "-9999px";
      document.body.appendChild(ta);
      ta.select();
      let ok = false;
      try { ok = document.execCommand("copy"); } catch (e) { ok = false; }
      document.body.removeChild(ta);
      return ok;
    }

    btn.addEventListener("click", async () => {
      const url   = btn.getAttribute("data-share-url") || window.location.href;
      const title = btn.getAttribute("data-share-title") || document.title;

      if (navigator.share) {
        try {
          await navigator.share({ title: title, url: url });
          return;
        } catch (err) {
          // Dismissing the sheet is not a failure, so stay silent.
          if (err && err.name === "AbortError") return;
        }
      }

      if (navigator.clipboard && navigator.clipboard.writeText) {
        try {
          await navigator.clipboard.writeText(url);
          flash("Link copied");
          return;
        } catch (e) { /* fall through */ }
      }

      flash(legacyCopy(url) ? "Link copied" : "Copy failed");
    });
  }

  function initStoryToggle() {
    const btn     = document.querySelector("[data-story-toggle]");
    const more    = document.querySelector("[data-story-more]");
    const label   = document.querySelector("[data-story-label]");
    const chevron = document.querySelector("[data-story-chevron]");
    if (!btn) return;

    btn.addEventListener("click", () => {
      const expanded = btn.getAttribute("aria-expanded") === "true";
      btn.setAttribute("aria-expanded", String(!expanded));
      if (more) more.classList.toggle("hidden", expanded);
      if (label) label.textContent = expanded ? "Read More" : "Show Less";
      if (chevron) chevron.classList.toggle("rotate-180", !expanded);
    });
  }

  /* ══════════════════════════════════════════════════════════════════════════
     FOLLOW BUTTON
     - Seeds state from ZYMARG_CONFIG.isFollowing (server-rendered)
     - Requires login; redirects guests to wp-login.php
     - POSTs to /wp-json/zymarg/v1/follow or /unfollow with WP REST nonce
     - Updates follower count in all [data-store-followers] elements
     - Disables button while request is in flight (prevents double-clicks)
     ══════════════════════════════════════════════════════════════════════════ */
  function initFollowButton() {
    const cfg       = window.ZYMARG_CONFIG || {};
    const storeId   = cfg.storeId   || 0;
    const apiBase   = (cfg.apiBase  || "").replace(/\/$/, "");
    const followNs   = cfg.followNs  || "zymarg/v1";
    const nonce     = cfg.nonce     || "";
    const loginUrl  = cfg.loginUrl  || "/wp-login.php";
    const isLoggedIn = !!cfg.isLoggedIn;

    // Seed from server — avoids a flash of wrong state on load.
    let following = !!cfg.isFollowing;

    function applyUI() {
      document.querySelectorAll("[data-follow-label]").forEach(l => {
        l.textContent = following ? "Following" : "Follow";
      });
      document.querySelectorAll("[data-follow-btn]").forEach(b => {
        if (following) {
          b.classList.remove("bg-zy-gradient", "text-white", "shadow-lg");
          b.classList.add("border", "border-zy-primary", "text-zy-primary", "bg-zy-surface");
        } else {
          b.classList.add("bg-zy-gradient", "text-white", "shadow-lg");
          b.classList.remove("border", "border-zy-primary", "text-zy-primary", "bg-zy-surface");
        }
      });
    }

    function updateCount(count) {
      if (count === undefined || count === null) return;
      const formatted = count >= 1000
        ? (count / 1000).toFixed(1).replace(/\.0$/, "") + "K"
        : String(count);
      /*
       * FIX: Hero Store Card v2's [data-store-followers] is the number-only
       * <b> node inside the stats cluster, with "Followers" rendered by a
       * separate sibling <span> label — NOT a combined "N followers"
       * string. This function still wrote the OLD pre-redesign HTML
       * ("<strong>N</strong> followers") into that same node on every page
       * load (via the unconditional cfg.followersCount seed call below),
       * which visually duplicated the word "Followers" right next to the
       * new label span. Now writes the formatted number only, matching the
       * fix already applied to fetchStoreDetails()'s equivalent write.
       */
      document.querySelectorAll("[data-store-followers]").forEach(el => {
        el.textContent = formatted;
      });
      // Also update sticky header meta line if present.
      const stickyMeta = document.querySelector("[data-sticky-meta]");
      if (stickyMeta) {
        stickyMeta.textContent = stickyMeta.textContent.replace(/[\d.,]+K?\s*followers/, `${formatted} followers`);
      }
    }

    function setLoading(on) {
      document.querySelectorAll("[data-follow-btn]").forEach(b => {
        b.disabled = on;
        b.style.opacity = on ? "0.6" : "";
        b.style.cursor  = on ? "wait" : "";
      });
    }

    async function toggleFollow() {
      if (!isLoggedIn) {
        window.location.href = loginUrl;
        return;
      }
      if (!storeId) return;

      const endpoint = following ? "unfollow" : "follow";
      setLoading(true);

      // Optimistic UI.
      following = !following;
      applyUI();

      try {
        const res = await fetch(`${apiBase}/${followNs}/${endpoint}`, {
          method : "POST",
          headers: {
            "Content-Type" : "application/json",
            "X-WP-Nonce"   : nonce,
          },
          body: JSON.stringify({ store_id: storeId }),
        });

        if (!res.ok) {
          // Roll back optimistic change.
          following = !following;
          applyUI();
          if (res.status === 401) {
            window.location.href = loginUrl;
          } else {
            console.warn("[ZYMARG] Follow request failed:", res.status);
          }
          return;
        }

        const data = await res.json();
        // Sync state with authoritative server response.
        following = !!data.following;
        applyUI();
        updateCount(data.followers_count);

      } catch (err) {
        // Network error — roll back.
        following = !following;
        applyUI();
        console.error("[ZYMARG] Follow request error:", err);
      } finally {
        setLoading(false);
      }
    }

    // Apply initial server-seeded state immediately (no flash).
    applyUI();

    // Seed the live follower count from server config so the displayed
    // number always reflects the DB value, not the PHP template fallback.
    if (cfg.followersCount !== undefined) {
      updateCount(cfg.followersCount);
    }

    // Attach click handler to all follow buttons (hero + sticky header).
    document.querySelectorAll("[data-follow-btn]").forEach(btn => {
      btn.addEventListener("click", toggleFollow);
    });
  }

  /* ══════════════════════════════════════════════════════════════════════════
     AURA STUDIO SEARCH BAR — v1.3.2
     Results now filter the in-page product grid instead of navigating away
     ══════════════════════════════════════════════════════════════════════════ */
  function initAuraSearch() {
    const searchRoot = document.getElementById("aura-search-root");
    if (!CONFIG.showAura) {
      if (searchRoot) searchRoot.style.display = "none";
      return;
    }

    const input      = document.getElementById("aura-input");
    const dropdown   = document.getElementById("aura-dropdown");
    const clearBtn   = document.getElementById("aura-clear");
    const statusEl   = document.getElementById("aura-status");
    const errorBar   = document.getElementById("aura-error");
    const pillsEl    = document.getElementById("aura-pills");
    if (!input || !dropdown) return;

    let debounceTimer  = null;
    let activeIndex    = -1;
    let currentResults = [];
    let currentQuery   = "";
    let abortCtrl      = null;
    let pendingSearch  = null;   // resolves when the in-flight debounced fetch completes

    /* ── Fetch real vendor categories for dynamic pills ─────────────────── */
    async function loadDynamicPills() {
      if (!pillsEl) return;
      try {
        const url = new URL(`dokan/v1/stores/${CONFIG.storeId}/categories`, CONFIG.apiBase);
        url.searchParams.set("per_page", "100"); // fetch all vendor categories
        const res = await fetch(url.toString());
        if (!res.ok) throw new Error(`API ${res.status}`);
        const cats = await res.json();

        if (!cats.length) { pillsEl.style.display = "none"; return; }

        const PILLS_VISIBLE = 6;
        let pillsExpanded = false;

        function renderPills() {
          const visible = pillsExpanded ? cats : cats.slice(0, PILLS_VISIBLE);
          const hasMore = cats.length > PILLS_VISIBLE;

          pillsEl.innerHTML = visible.map(c =>
            `<button class="aura-pill" type="button">${escHtml(c.name)}</button>`
          ).join("") + (hasMore
            ? pillsExpanded
              ? `<button class="aura-pill aura-pill--more" type="button" data-pills-toggle>Show less ↑</button>`
              : `<button class="aura-pill aura-pill--more" type="button" data-pills-toggle>+${cats.length - PILLS_VISIBLE} more</button>`
            : "");

          // Category click — filter the in-page grid directly (no search dropdown)
          pillsEl.querySelectorAll(".aura-pill:not([data-pills-toggle])").forEach(pill => {
            pill.addEventListener("click", async () => {
              const query = pill.textContent.trim();
              // Mark the active pill visually
              pillsEl.querySelectorAll(".aura-pill").forEach(p => p.classList.remove("is-active"));
              pill.classList.add("is-active");
              // Put the category name in the search box and show clear button
              input.value = query;
              if (clearBtn) clearBtn.style.display = "flex";
              closeDropdown();
              try {
                const { products: firstPage, totalPages } = await fetchSearchPage(query);
                currentResults = firstPage;
                applySearchToGrid(query, firstPage, totalPages);
              } catch (err) {
                if (err.name !== "AbortError") {
                  // Search failed. Report it rather than inventing results.
                  const mockResults = localSearch(query);
                  currentResults = mockResults;
                  applySearchToGrid(query, mockResults, 1);
                }
              }
            });
          });

          // Expand/collapse toggle
          const toggleBtn = pillsEl.querySelector("[data-pills-toggle]");
          if (toggleBtn) {
            toggleBtn.addEventListener("click", () => {
              pillsExpanded = !pillsExpanded;
              renderPills();
            });
          }
        }

        renderPills();
      } catch (_) {
        // API unavailable — hide the pills container (no hardcoded fallback)
        if (pillsEl) pillsEl.style.display = "none";
      }
    }

    function escapeRe(str) { return str.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"); }

    function highlight(text, q) {
      if (!q) return text;
      return text.replace(new RegExp(`(${escapeRe(q)})`, "gi"), "<mark>$1</mark>");
    }

    function badgeHtml(p) {
      if (p.on_sale) return '<span class="aura-badge aura-badge--sale">Sale</span>';
      if (p.stock_status === "outofstock") return '<span class="aura-badge aura-badge--out">Out of stock</span>';
      if (p.stock_quantity !== null && p.stock_quantity <= 5) return '<span class="aura-badge aura-badge--low">Low stock</span>';
      if (p.date_created && (Date.now() - new Date(p.date_created).getTime()) < 30*24*60*60*1000)
        return '<span class="aura-badge aura-badge--new">New drop</span>';
      return "";
    }

    function catLabel(p) {
      if (!p.categories || !p.categories.length) return "Products";
      // Use the real WooCommerce category name returned by the API.
      // This works correctly for every vendor — no slug→label mapping needed.
      return p.categories[0].name || "Products";
    }

    function priceHtml(p) {
      const price   = parseFloat(p.price)         || 0;
      const regular = parseFloat(p.regular_price) || 0;
      const fmt     = n => `৳${n.toLocaleString()}`;
      if (p.on_sale && regular > price) {
        return `<span class="aura-price">${fmt(price)}</span><span class="aura-old-price">${fmt(regular)}</span>`;
      }
      return `<span class="aura-price">${fmt(price)}</span>`;
    }

    function thumbPlaceholder() {
      return `<span class="aura-result-thumb aura-result-thumb--placeholder">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round"
            d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0
               013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5
               1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0
               11-.75 0 .375.375 0 01.75 0zm-.375 0h.008v.015h-.008V9.75zm4.875-.75a.375.375 0 11-.75 0
               .375.375 0 01.75 0zm-.375 0h.008v.015h-.008V9z"/>
        </svg>
      </span>`;
    }

    async function fetchSearchProducts(query) {
      if (abortCtrl) abortCtrl.abort();
      abortCtrl = new AbortController();

      const url = new URL(`dokan/v1/stores/${CONFIG.storeId}/products`, CONFIG.apiBase);
      url.searchParams.set("search",   query);
      url.searchParams.set("per_page", "8");
      url.searchParams.set("status",   "publish");
      url.searchParams.set("_fields",
        "id,name,price,regular_price,sale_price,on_sale,stock_status,stock_quantity,categories,images,date_created,permalink,short_description");

      const res = await fetch(url.toString(), {
        signal:  abortCtrl.signal,
        headers: { Accept: "application/json" },
      });
      if (!res.ok) throw new Error(`API ${res.status}`);
      return res.json();
    }

    function openDropdown()  {
      if (pillsEl) pillsEl.style.display = "none";
      dropdown.style.display = "block";
      input.setAttribute("aria-expanded", "true");
    }
    function closeDropdown() {
      dropdown.style.display = "none";
      dropdown.classList.remove("is-loading");
      input.setAttribute("aria-expanded", "false");
      activeIndex = -1;
    }

    function showLoading() {
      // If the dropdown already has results, show the spinner as a top-bar overlay
      // so previous results stay visible while the new fetch is in flight.
      // If the dropdown is empty (first search), fall back to the full spinner state.
      const hasExistingContent = dropdown.querySelector(".aura-result-row, .aura-state");
      if (hasExistingContent) {
        // Remove any existing loading bar before adding a new one
        const existing = dropdown.querySelector(".aura-loading-bar");
        if (existing) existing.remove();
        dropdown.classList.remove("is-loading");
        const bar = document.createElement("div");
        bar.className = "aura-loading-bar";
        bar.innerHTML = SPARK_HTML;
        dropdown.insertBefore(bar, dropdown.firstChild);
      } else {
        dropdown.classList.add("is-loading");
        dropdown.innerHTML = `<div class="aura-state"><span class="zymarg-spark zymarg-spark--xl" role="img" aria-label="ZYMARG Discovery Spark"><svg class="zymarg-spark__svg" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><g class="zymarg-spark-group--accent"><path class="zymarg-spark-item--purple" d="M10.4 5.4c0 1.32-0.24 2.4-1.44 2.4 1.2 0 1.44 1.08 1.44 2.4 0-1.32 0.24-2.4 1.44-2.4-1.2 0-1.44-1.08-1.44-2.4z"></path><path class="zymarg-spark-item--gold" d="M10.4 6.0c0 0.96-0.18 1.8-1.08 1.8 0.9 0 1.08 0.84 1.08 1.8 0-0.9 0.18-1.8 1.08-1.8-0.9 0-1.08-0.84-1.08-1.8z"></path></g><g class="zymarg-spark-group--companion"><path class="zymarg-spark-item--purple" d="M9.5 10.92c0 2.25-0.45 4.12-2.4 4.12 1.95 0 2.4 1.87 2.4 4.12 0-2.25 0.45-4.12 2.4-4.12-1.95 0-2.4-1.87-2.4-4.12z"></path><path class="zymarg-spark-item--gold" d="M9.5 11.5c0 1.9-0.38 3.54-2.0 3.54 1.62 0 2.0 1.64 2.0 3.54 0-1.9 0.38-3.54 2.0-3.54-1.62 0-2.0-1.64-2.0-3.54z"></path></g><g class="zymarg-spark-group--hero"><path class="zymarg-spark-item--purple" d="M15.2 5.6c0 3.45-0.69 6.3-4.08 6.3 3.39 0 4.08 2.85 4.08 6.3 0-3.45 0.69-6.3 4.08-6.3-3.39 0-4.08-2.85-4.08-6.3z"></path><path class="zymarg-spark-item--gold" d="M15.2 6.5c0 2.9-0.58 5.4-3.39 5.4 2.81 0 3.39 2.5 3.39 5.4 0-2.9 0.58-5.4 3.39-5.4-2.81 0-3.39-2.5-3.39-5.4z"></path></g></svg></span></div>`;
      }
      openDropdown();
      if (statusEl) statusEl.textContent = "Searching…";
    }

    /* ── Select result: open quick-view drawer, NOT navigate away ───────── */
    function selectResult(idx) {
      const p = currentResults[idx];
      if (!p) return;
      input.value = p.name;
      if (clearBtn) clearBtn.style.display = "flex";
      closeDropdown();
      if (statusEl) statusEl.textContent = `Selected: ${p.name}`;

      // Store in drawer cache so we don't refetch
      drawerCache[p.id] = p;
      openQuickView(p.id);
    }

    function moveActive(dir) {
      const rows = dropdown.querySelectorAll(".aura-result-row");
      if (!rows.length) return;
      activeIndex = Math.max(0, Math.min(activeIndex + dir, rows.length - 1));
      rows.forEach((row, i) => {
        row.classList.toggle("is-active", i === activeIndex);
        if (i === activeIndex) row.scrollIntoView({ block: "nearest" });
      });
    }

    function renderSearchDropdown(products, query) {
      dropdown.innerHTML = "";
      dropdown.classList.remove("is-loading");
      activeIndex = -1;

      if (!products.length) {
        dropdown.innerHTML = `
          <div class="aura-state">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round"
                d="M15.182 16.318A4.486 4.486 0 0012.016 15a4.486 4.486 0 00-3.198 1.318M21 12a9 9 0
                   11-18 0 9 9 0 0118 0zM9.75 9.75c0 .414-.168.75-.375.75S9 10.164 9 9.75 9.168 9
                   9.375 9s.375.336.375.75zm-.375 0h.008v.015h-.008V9.75zm4.875 0c0 .414-.168.75-.375.75s-.375-.336-.375-.75
                   .168-.75.375-.75.375.336.375.75zm-.375 0h.008v.015h-.008V9.75z"/>
            </svg>
            <p class="aura-state__cta-text">Couldn\u2019t find what you\u2019re looking for?</p>
            <a class="aura-state__cta-btn" href="${(ZYMARG_CONFIG.communityUrl || '/community')}">Request Here</a>
          </div>`;
        if (statusEl) statusEl.textContent = "No results found.";
        openDropdown();
        return;
      }

      // Group by category
      const grouped = {};
      products.forEach(p => {
        const cat = catLabel(p);
        (grouped[cat] = grouped[cat] || []).push(p);
      });

      Object.entries(grouped).forEach(([cat, items]) => {
        const lbl = document.createElement("div");
        lbl.className   = "aura-cat-label";
        lbl.textContent = cat;
        dropdown.appendChild(lbl);

        items.forEach(p => {
          const idx   = products.indexOf(p);
          const thumb = p.images?.[0]?.src
            ? `<img class="aura-result-thumb" src="${p.images[0].src}" alt="${p.name}" loading="lazy">`
            : thumbPlaceholder();

          const row = document.createElement("button");
          row.className = "aura-result-row";
          row.setAttribute("type", "button");
          row.setAttribute("role", "option");
          row.setAttribute("data-idx", idx);
          row.innerHTML = `
            ${thumb}
            <div class="aura-result-info">
              <div class="aura-result-name">${highlight(p.name, query)}</div>
              <div class="aura-result-meta">${cat}${p.sku ? " · SKU " + p.sku : ""} · tap to quick-view</div>
            </div>
            <div class="aura-result-right">
              ${priceHtml(p)}
              ${badgeHtml(p)}
            </div>`;

          row.addEventListener("mousedown", e => { e.preventDefault(); selectResult(idx); });
          dropdown.appendChild(row);
        });
      });

      /* ── "See all results" → fetch ALL pages then filter the grid ──────── */
      const viewAll = document.createElement("button");
      viewAll.type      = "button";
      viewAll.className = "aura-view-all";
      viewAll.textContent = `See all results in store ↓`;
      viewAll.addEventListener("mousedown", async e => {
        e.preventDefault();
        closeDropdown();
        viewAll.disabled = true;
        try {
          const { products: firstPage, totalPages } = await fetchSearchPage(query);
          applySearchToGrid(query, firstPage, totalPages);
        } catch (err) {
          if (err.name !== "AbortError") {
            // Fallback: use the 8 already fetched from the dropdown
            applySearchToGrid(query, products, 1);
          }
        }
      });
      dropdown.appendChild(viewAll);

      if (statusEl) statusEl.textContent = `${products.length} result${products.length !== 1 ? "s" : ""} for "${query}".`;
      openDropdown();
    }

    function triggerSearch(query, immediate = false) {
      currentQuery = query.trim();
      if (!currentQuery) {
        closeDropdown();
        pendingSearch = null;
        if (statusEl) statusEl.textContent = "";
        if (errorBar) errorBar.style.display = "none";
        return;
      }

      // showLoading() is called here, outside the setTimeout, so the spinner
      // appears instantly on every keystroke — only the actual API fetch is debounced.
      showLoading();
      clearTimeout(debounceTimer);

      // Expose the in-flight fetch as a promise so the Enter handler can await it
      pendingSearch = new Promise(resolve => {
        const delay = immediate ? 0 : 180;
        debounceTimer = setTimeout(async () => {
          try {
            const products  = await fetchSearchProducts(currentQuery);
            currentResults  = products;
            if (errorBar) errorBar.style.display = "none";
            renderSearchDropdown(products, currentQuery);
          } catch (err) {
            if (err.name === "AbortError") { resolve(); return; }
            console.warn("[ZYMARG AURA] Search failed — no local fallback:", err);

            // No invented results: report the failure honestly.
            const mockResults = localSearch(currentQuery);
            currentResults    = mockResults;
            if (errorBar) errorBar.style.display = "none";
            renderSearchDropdown(mockResults, currentQuery);

            if (statusEl && !mockResults.length) {
              statusEl.textContent = "Live search unavailable — please try again.";
            }
          }
          resolve();
        }, delay);
      });
    }

    input.addEventListener("input", () => {
      const v = input.value;
      if (clearBtn) clearBtn.style.display = v ? "flex" : "none";
      triggerSearch(v);
    });

    input.addEventListener("focus", () => {
      if (input.value.trim()) {
        triggerSearch(input.value);
      } else if (pillsEl) {
        pillsEl.style.display = "flex";
      }
    });

    input.addEventListener("keydown", async e => {
      if      (e.key === "ArrowDown") { e.preventDefault(); moveActive(+1); }
      else if (e.key === "ArrowUp")   { e.preventDefault(); moveActive(-1); }
      else if (e.key === "Enter") {
        e.preventDefault();
        e.stopPropagation();
        // Clear any pending debounce so the delayed triggerSearch callback
        // can't re-open the dropdown after we intentionally close it.
        clearTimeout(debounceTimer);
        if (activeIndex >= 0) {
          selectResult(activeIndex);
        } else if (input.value.trim()) {
          const query = input.value.trim();
          closeDropdown();
          try {
            const { products: firstPage, totalPages } = await fetchSearchPage(query);
            currentResults = firstPage;
            applySearchToGrid(query, firstPage, totalPages);
          } catch (err) {
            if (err.name !== "AbortError") {
              // Fallback: flush debounce and use whatever the dropdown fetched
              triggerSearch(query, true);
              await pendingSearch;
              applySearchToGrid(query, currentResults, 1);
            }
          }
        }
      }
      else if (e.key === "Escape") {
        closeDropdown();
        if (statusEl) statusEl.textContent = "";
      }
    });

    if (clearBtn) {
      clearBtn.addEventListener("click", () => {
        input.value = "";
        clearBtn.style.display = "none";
        closeDropdown();
        if (pillsEl) {
          pillsEl.style.display = "none";
          pillsEl.querySelectorAll(".aura-pill.is-active").forEach(p => p.classList.remove("is-active"));
        }
        document.querySelectorAll(".zy-sidebar-cat.is-active").forEach(l => l.classList.remove("is-active"));
        if (statusEl) statusEl.textContent = "";
        if (errorBar) errorBar.style.display = "none";
        if (abortCtrl) abortCtrl.abort();
        // If a search filter is active on the grid, clear it too
        if (activeSearchQuery) clearSearchFilter();
        input.focus();
      });
    }

    document.addEventListener("mousedown", e => {
      if (searchRoot && !searchRoot.contains(e.target)) {
        closeDropdown();
        if (pillsEl) pillsEl.style.display = "none";
      }
    });

    // Load real categories for pills
    loadDynamicPills();
  }

  /* ═══════════════════════════════════════════════════════════════════════════
     MOBILE SEARCH SHEET  v1.0
     ──────────────────────────────────────────────────────────────────────────
     Provides a full-width bottom sheet containing the AURA search bar for
     mobile viewports where the sticky header search bar is hidden (sm:block
     means it only shows at ≥640 px).

     Communication contract with zymarg-footer (v3.0.3):
       • This function sets [data-zsp-search-ready] on <body> once the sheet
         DOM is built — the footer reads this attribute at click-time to decide
         whether to dispatch the custom event or open its own overlay.
       • The footer dispatches CustomEvent("zymarg:open-store-search") on
         document (no payload needed).
       • This function listens for that event and calls openSheet().

     The sheet is only active on mobile (< 640 px). On desktop the attribute
     is still set so the footer check still passes, but openSheet() returns
     early and the desktop sticky-header AURA bar is used instead (reachable
     by scrolling to the top, which the user can do naturally).
     ══════════════════════════════════════════════════════════════════════════ */
  function initMobileSearchSheet() {
    // ── Build sheet DOM ──────────────────────────────────────────────────────
    const sheet   = document.createElement('div');
    const backdrop = document.createElement('div');

    const _storeName = (document.querySelector('[data-store-name]') || {}).textContent || '';
    const _placeholder = _storeName.trim()
      ? 'Search inside ' + _storeName.trim() + '\u2019s store\u2026'
      : 'Search products\u2026';

    sheet.id        = 'zsp-mobile-search-sheet';
    sheet.setAttribute('aria-modal', 'true');
    sheet.setAttribute('aria-label', 'Search this store');
    sheet.setAttribute('aria-hidden', 'true');

    backdrop.id = 'zsp-mobile-search-backdrop';

    sheet.innerHTML = `
      <div class="zsp-sheet__search-wrap">
        <div class="aura-search__field zsp-sheet__field">
          <span class="aura-search__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
              <circle cx="11" cy="11" r="8"/>
              <path stroke-linecap="round" d="m21 21-4.35-4.35"/>
            </svg>
          </span>
          <input
            type="search"
            id="zsp-sheet-input"
            class="zsp-sheet__input"
            placeholder="${_placeholder}"
            autocomplete="off"
            autocorrect="off"
            spellcheck="false"
            aria-label="Search products in this store"
            aria-expanded="false"
            aria-owns="zsp-sheet-dropdown"
            aria-autocomplete="list"
          />
          <button type="button" id="zsp-sheet-clear" aria-label="Clear search" style="display:none"
            class="zsp-sheet__clear">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>
        <div id="zsp-sheet-pills" class="aura-pills zsp-sheet__pills" role="list" aria-label="Browse by category"></div>
        <div id="zsp-sheet-dropdown" class="zsp-sheet__dropdown" role="listbox" aria-label="Search results" style="display:none"></div>
        <span id="zsp-sheet-status" class="zsp-sr-only" aria-live="polite" aria-atomic="true"></span>
      </div>`;

    document.body.appendChild(backdrop);
    document.body.appendChild(sheet);

    const sheetInput    = document.getElementById('zsp-sheet-input');
    const sheetClear    = document.getElementById('zsp-sheet-clear');
    const sheetDropdown = document.getElementById('zsp-sheet-dropdown');
    const sheetPillsEl  = document.getElementById('zsp-sheet-pills');
    const sheetStatusEl = document.getElementById('zsp-sheet-status');

    // ── Recent searches (localStorage) ───────────────────────────────────────
    var RECENT_KEY   = 'zymarg_recent_searches';
    var RECENT_LIMIT = 5;

    function getRecent() {
      try { var r = localStorage.getItem(RECENT_KEY); return r ? JSON.parse(r) : []; } catch(e) { return []; }
    }
    function saveRecent(list) {
      try { localStorage.setItem(RECENT_KEY, JSON.stringify(list)); } catch(e) {}
    }
    function addRecent(q) {
      q = (q || '').trim();
      if (!q) return;
      var list = getRecent().filter(function(s) { return s.toLowerCase() !== q.toLowerCase(); });
      list.unshift(q);
      if (list.length > RECENT_LIMIT) list = list.slice(0, RECENT_LIMIT);
      saveRecent(list);
    }
    function clearRecent() {
      try { localStorage.removeItem(RECENT_KEY); } catch(e) {}
    }

    function renderRecent() {
      var list = getRecent();
      if (!list.length) { sheetDropdown.style.display = 'none'; sheetDropdown.innerHTML = ''; return; }
      var html = '<div class="zsp-recent">' +
        '<div class="zsp-recent__row">' +
          '<span class="zsp-recent__icon" aria-hidden="true"></span>' +
          '<span class="zsp-recent__label">RECENT SEARCHES</span>' +
          '<a href="#" class="zsp-recent__clear">Clear</a>' +
        '</div>' +
        '<div class="zsp-recent__pills">';
      list.forEach(function(q) {
        html += '<button type="button" class="zsp-recent__pill" data-q="' + q.replace(/"/g, '&quot;') + '">' + q + '</button>';
      });
      html += '</div></div>';
      sheetDropdown.innerHTML = html;
      sheetDropdown.style.display = 'block';
    }

    // ── Dropdown delegate for recent pill clicks and clear ────────────────────
    sheetDropdown.addEventListener('mousedown', function(e) {
      var pill = e.target.closest && e.target.closest('.zsp-recent__pill');
      if (pill) {
        e.preventDefault();
        var q = pill.getAttribute('data-q') || '';
        sheetInput.value = q;
        sheetClear.style.display = 'flex';
        sheetTriggerSearch(q);
        return;
      }
      var clr = e.target.closest && e.target.closest('.zsp-recent__clear');
      if (clr) {
        e.preventDefault();
        clearRecent();
        sheetDropdown.style.display = 'none';
        sheetDropdown.innerHTML = '';
      }
    });

    // ── Open / close ─────────────────────────────────────────────────────────
    function openSheet() {
      // On desktop (≥ 640 px) the sticky AURA bar is visible — skip the sheet.
      if (window.innerWidth >= 640) return;

      var stickyHeader = document.getElementById('sticky-header');
      if (stickyHeader) stickyHeader.style.visibility = 'hidden';

      sheet.removeAttribute('aria-hidden');
      sheet.removeAttribute('inert');
      sheet.classList.add('is-open');
      backdrop.classList.add('is-open');
      document.body.style.overflow = 'hidden';
      // Show pills when opening with no query, hide when there's already a query
      if (!sheetInput.value.trim() && sheetPillsEl) sheetPillsEl.style.display = 'flex';
      // Show recent searches if no query
      if (!sheetInput.value.trim()) renderRecent();
      // Show clear button immediately on open (structural parity with ZSS)
      sheetClear.style.display = 'flex';
      // Focus the input after the slide-up animation starts
      setTimeout(function () {
        try { sheetInput.focus({ preventScroll: true }); } catch (e) {}
      }, 80);
    }

    function closeSheet() {
      // Blur before hiding so focus is never trapped inside an aria-hidden
      // subtree — fixes the WCAG "aria-hidden on focused element" violation.
      if (document.activeElement && sheet.contains(document.activeElement)) {
        document.activeElement.blur();
      }

      var stickyHeader = document.getElementById('sticky-header');
      if (stickyHeader) stickyHeader.style.visibility = '';

      sheet.setAttribute('aria-hidden', 'true');
      // inert removes the element from tab order and AT tree in one shot;
      // gracefully ignored by browsers that don't support it yet (pre-Chrome 102).
      sheet.setAttribute('inert', '');
      sheet.classList.remove('is-open');
      backdrop.classList.remove('is-open');
      document.body.style.overflow = '';
      // Reset the sheet input + dropdown
      sheetInput.value = '';
      sheetClear.style.display = 'none';
      sheetDropdown.style.display = 'none';
      sheetDropdown.innerHTML = '';
    }

    // ── Event: footer fires this to open the sheet ───────────────────────────
    document.addEventListener('zymarg:open-store-search', openSheet);

    // ── Close triggers ───────────────────────────────────────────────────────
    backdrop.addEventListener('click', closeSheet);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && sheet.classList.contains('is-open')) closeSheet();
    });

    // ── Touch-drag to dismiss (swipe down) ───────────────────────────────────
    var touchStartY = 0;
    sheet.addEventListener('touchstart', function (e) {
      touchStartY = e.touches[0].clientY;
    }, { passive: true });
    sheet.addEventListener('touchend', function (e) {
      var diff = e.changedTouches[0].clientY - touchStartY;
      if (diff > 80) closeSheet(); // swipe down > 80 px → dismiss
    }, { passive: true });

    // ── Search logic (full parity with desktop AURA bar) ─────────────────────
    var sheetAbortCtrl  = null;
    var sheetDebounce   = null;
    var sheetResults    = [];
    var sheetActiveIdx  = -1;

    // ── Helpers (mirror desktop equivalents exactly) ──────────────────────────
    function sheetEscHtml(s) {
      return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function sheetHighlight(text, q) {
      if (!q) return text;
      return text.replace(new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi'), '<mark>$1</mark>');
    }
    function sheetCatLabel(p) {
      return (p.categories && p.categories[0] && p.categories[0].name) ? p.categories[0].name : 'Products';
    }
    function sheetPriceHtml(p) {
      var price   = parseFloat(p.price)         || 0;
      var regular = parseFloat(p.regular_price) || 0;
      var fmt     = function(n) { return '\u09F3' + n.toLocaleString(); };
      if (p.on_sale && regular > price) {
        return '<span class="zsp-result-price">' + fmt(price) + '</span><span class="zsp-result-old-price">' + fmt(regular) + '</span>';
      }
      return '<span class="zsp-result-price">' + fmt(price) + '</span>';
    }
    function sheetBadgeHtml(p) {
      if (p.on_sale)                                          return '<span class="aura-badge aura-badge--sale">Sale</span>';
      if (p.stock_status === 'outofstock')                    return '<span class="aura-badge aura-badge--out">Out of stock</span>';
      if (p.stock_quantity !== null && p.stock_quantity <= 5) return '<span class="aura-badge aura-badge--low">Low stock</span>';
      if (p.date_created && (Date.now() - new Date(p.date_created).getTime()) < 30*24*60*60*1000)
                                                              return '<span class="aura-badge aura-badge--new">New drop</span>';
      return '';
    }

    // ── Fetch — identical fields to desktop fetchSearchProducts ──────────────
    async function sheetFetch(query) {
      if (sheetAbortCtrl) sheetAbortCtrl.abort();
      sheetAbortCtrl = new AbortController();

      const url = new URL('dokan/v1/stores/' + CONFIG.storeId + '/products', CONFIG.apiBase);
      url.searchParams.set('search',   query);
      url.searchParams.set('per_page', '8');
      url.searchParams.set('status',   'publish');
      url.searchParams.set('_fields',
        'id,name,price,regular_price,sale_price,on_sale,stock_status,stock_quantity,categories,images,date_created,permalink,short_description');

      const res = await fetch(url.toString(), {
        signal:  sheetAbortCtrl.signal,
        headers: { Accept: 'application/json' },
      });
      if (!res.ok) throw new Error('API ' + res.status);
      return res.json();
    }

    // ── Loading state — overlay bar when results already shown, full spinner on first search ─
    var SPARK_HTML = '<span class="zymarg-spark zymarg-spark--xl" role="img" aria-label="ZYMARG Discovery Spark"><svg class="zymarg-spark__svg" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><g class="zymarg-spark-group--accent"><path class="zymarg-spark-item--purple" d="M10.4 5.4c0 1.32-0.24 2.4-1.44 2.4 1.2 0 1.44 1.08 1.44 2.4 0-1.32 0.24-2.4 1.44-2.4-1.2 0-1.44-1.08-1.44-2.4z"></path><path class="zymarg-spark-item--gold" d="M10.4 6.0c0 0.96-0.18 1.8-1.08 1.8 0.9 0 1.08 0.84 1.08 1.8 0-0.9 0.18-1.8 1.08-1.8-0.9 0-1.08-0.84-1.08-1.8z"></path></g><g class="zymarg-spark-group--companion"><path class="zymarg-spark-item--purple" d="M9.5 10.92c0 2.25-0.45 4.12-2.4 4.12 1.95 0 2.4 1.87 2.4 4.12 0-2.25 0.45-4.12 2.4-4.12-1.95 0-2.4-1.87-2.4-4.12z"></path><path class="zymarg-spark-item--gold" d="M9.5 11.5c0 1.9-0.38 3.54-2.0 3.54 1.62 0 2.0 1.64 2.0 3.54 0-1.9 0.38-3.54 2.0-3.54-1.62 0-2.0-1.64-2.0-3.54z"></path></g><g class="zymarg-spark-group--hero"><path class="zymarg-spark-item--purple" d="M15.2 5.6c0 3.45-0.69 6.3-4.08 6.3 3.39 0 4.08 2.85 4.08 6.3 0-3.45 0.69-6.3 4.08-6.3-3.39 0-4.08-2.85-4.08-6.3z"></path><path class="zymarg-spark-item--gold" d="M15.2 6.5c0 2.9-0.58 5.4-3.39 5.4 2.81 0 3.39 2.5 3.39 5.4 0-2.9 0.58-5.4 3.39-5.4-2.81 0-3.39-2.5-3.39-5.4z"></path></g></svg></span>';

    function sheetShowLoading() {
      const hasContent = sheetDropdown.querySelector('.zsp-result-row');
      if (hasContent) {
        const existing = sheetDropdown.querySelector('.aura-loading-bar');
        if (existing) existing.remove();
        const bar = document.createElement('div');
        bar.className = 'aura-loading-bar';
        bar.innerHTML = SPARK_HTML;
        sheetDropdown.insertBefore(bar, sheetDropdown.firstChild);
      } else {
        sheetDropdown.innerHTML = '<div class="aura-state">' + SPARK_HTML + '</div>';
        sheetDropdown.style.display = 'block';
      }
      sheetInput.setAttribute('aria-expanded', 'true');
      if (sheetStatusEl) sheetStatusEl.textContent = 'Searching\u2026';
    }

    // ── Render results — grouped by category, with badges and SKU ────────────
    function sheetRenderResults(products, query) {
      sheetDropdown.innerHTML = '';
      sheetActiveIdx = -1;

      if (!products.length) {
        sheetDropdown.innerHTML =
          '<div class="aura-state">' +
            '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24">' +
              '<path stroke-linecap="round" stroke-linejoin="round"' +
                ' d="M15.182 16.318A4.486 4.486 0 0012.016 15a4.486 4.486 0 00-3.198 1.318M21 12a9 9 0' +
                '   11-18 0 9 9 0 0118 0zM9.75 9.75c0 .414-.168.75-.375.75S9 10.164 9 9.75 9.168 9' +
                '   9.375 9s.375.336.375.75zm-.375 0h.008v.015h-.008V9.75zm4.875 0c0 .414-.168.75-.375.75s-.375-.336-.375-.75' +
                '   .168-.75.375-.75.375.336.375.75zm-.375 0h.008v.015h-.008V9.75z"/>' +
            '</svg>' +
            '<p class="aura-state__cta-text">Couldn\u2019t find what you\u2019re looking for?</p>' +
            '<a class="aura-state__cta-btn" href="' + (ZYMARG_CONFIG.communityUrl || '/community') + '">Request Here</a>' +
          '</div>';
        sheetDropdown.style.display = 'block';
        sheetInput.setAttribute('aria-expanded', 'true');
        if (sheetStatusEl) sheetStatusEl.textContent = 'No results found.';
        return;
      }

      // Group by category — mirrors desktop renderSearchDropdown
      var grouped = {};
      products.forEach(function(p) {
        var cat = sheetCatLabel(p);
        if (!grouped[cat]) grouped[cat] = [];
        grouped[cat].push(p);
      });

      Object.keys(grouped).forEach(function(cat) {
        var lbl = document.createElement('div');
        lbl.className   = 'aura-cat-label';
        lbl.textContent = cat;
        sheetDropdown.appendChild(lbl);

        grouped[cat].forEach(function(p) {
          var idx   = products.indexOf(p);
          var thumb = (p.images && p.images[0] && p.images[0].src)
            ? '<img class="zsp-result-thumb" src="' + sheetEscHtml(p.images[0].src) + '" alt="' + sheetEscHtml(p.name) + '" loading="lazy">'
            : '<span class="zsp-result-thumb zsp-result-thumb--placeholder"></span>';

          var meta = sheetEscHtml(cat) + (p.sku ? ' \u00B7 SKU ' + sheetEscHtml(p.sku) : '') + ' \u00B7 tap to quick-view';

          var row = document.createElement('button');
          row.type = 'button';
          row.className = 'zsp-result-row';
          row.setAttribute('role', 'option');
          row.setAttribute('data-idx', idx);
          row.innerHTML =
            thumb +
            '<div class="zsp-result-info">' +
              '<div class="zsp-result-name">' + sheetHighlight(sheetEscHtml(p.name), query) + '</div>' +
              '<div class="zsp-result-cat">' + meta + '</div>' +
            '</div>' +
            '<div class="zsp-result-right">' +
              sheetPriceHtml(p) +
              sheetBadgeHtml(p) +
            '</div>';

          row.addEventListener('mousedown', function(e) { e.preventDefault(); sheetSelectResult(idx); });
          row.addEventListener('touchend',  function(e) { e.preventDefault(); sheetSelectResult(idx); });
          sheetDropdown.appendChild(row);
        });
      });

      // "See all results" button → filters the grid and closes sheet
      var viewAll = document.createElement('button');
      viewAll.type = 'button';
      viewAll.className = 'zsp-sheet-view-all';
      viewAll.textContent = 'See all results in store \u2193';
      viewAll.addEventListener('mousedown', async function(e) {
        e.preventDefault();
        closeSheet();
        try {
          var result = await fetchSearchPage(query);
          applySearchToGrid(query, result.products, result.totalPages);
        } catch (err) {
          if (err.name !== 'AbortError') applySearchToGrid(query, sheetResults, 1);
        }
      });
      sheetDropdown.appendChild(viewAll);
      sheetDropdown.style.display = 'block';
      sheetInput.setAttribute('aria-expanded', 'true');
      if (sheetStatusEl) sheetStatusEl.textContent = products.length + ' result' + (products.length !== 1 ? 's' : '') + ' for "' + query + '".';
    }

    function sheetSelectResult(idx) {
      var p = sheetResults[idx];
      if (!p) return;
      addRecent(sheetInput.value.trim());
      sheetInput.setAttribute('aria-expanded', 'false');
      closeSheet();
      if (typeof openQuickView === 'function') {
        drawerCache[p.id] = p;
        openQuickView(p.id);
      } else if (p.permalink) {
        window.location.href = p.permalink;
      }
    }

    function sheetTriggerSearch(query) {
      var q = (query || '').trim();
      if (!q) {
        sheetDropdown.style.display = 'none';
        sheetDropdown.innerHTML = '';
        sheetInput.setAttribute('aria-expanded', 'false');
        if (sheetStatusEl) sheetStatusEl.textContent = '';
        if (sheetPillsEl) sheetPillsEl.style.display = 'flex';
        return;
      }
      // Hide pills while searching
      if (sheetPillsEl) sheetPillsEl.style.display = 'none';
      sheetShowLoading();
      clearTimeout(sheetDebounce);
      sheetDebounce = setTimeout(async function() {
        try {
          var products = await sheetFetch(q);
          sheetResults = products;
          sheetRenderResults(products, q);
        } catch (err) {
          if (err.name === 'AbortError') return;
          // Search failed. Report it rather than inventing results.
          var mockResults = localSearch(q);
          sheetResults    = mockResults;
          sheetRenderResults(mockResults, q);
          if (sheetStatusEl && !mockResults.length) sheetStatusEl.textContent = 'Live search unavailable — please try again.';
        }
      }, 180);
    }

    // ── Category pills — load from API, mirror desktop loadDynamicPills ───────
    function loadSheetPills() {
      if (!sheetPillsEl) return;
      var url = new URL('dokan/v1/stores/' + CONFIG.storeId + '/categories', CONFIG.apiBase);
      url.searchParams.set('per_page', '100');
      fetch(url.toString()).then(function(res) {
        if (!res.ok) throw new Error('API ' + res.status);
        return res.json();
      }).then(function(cats) {
        if (!cats.length) { sheetPillsEl.style.display = 'none'; return; }

        var PILLS_VISIBLE = 6;
        var pillsExpanded = false;

        function renderSheetPills() {
          var visible = pillsExpanded ? cats : cats.slice(0, PILLS_VISIBLE);
          var hasMore = cats.length > PILLS_VISIBLE;

          sheetPillsEl.innerHTML = visible.map(function(c) {
            return '<button class="aura-pill" type="button" role="listitem">' + sheetEscHtml(c.name) + '</button>';
          }).join('') + (hasMore
            ? pillsExpanded
              ? '<button class="aura-pill aura-pill--more" type="button" data-pills-toggle>Show less \u2191</button>'
              : '<button class="aura-pill aura-pill--more" type="button" data-pills-toggle>+' + (cats.length - PILLS_VISIBLE) + ' more</button>'
            : '');

          sheetPillsEl.querySelectorAll('.aura-pill:not([data-pills-toggle])').forEach(function(pill) {
            pill.addEventListener('click', async function() {
              var query = pill.textContent.trim();
              sheetPillsEl.querySelectorAll('.aura-pill').forEach(function(p) { p.classList.remove('is-active'); });
              pill.classList.add('is-active');
              sheetInput.value = query;
              sheetClear.style.display = 'flex';
              sheetPillsEl.style.display = 'none';
              sheetShowLoading();
              try {
                var result = await fetchSearchPage(query);
                closeSheet();
                applySearchToGrid(query, result.products, result.totalPages);
              } catch (err) {
                if (err.name !== 'AbortError') {
                  var mock = localSearch(query);
                  closeSheet();
                  applySearchToGrid(query, mock, 1);
                }
              }
            });
          });

          var toggleBtn = sheetPillsEl.querySelector('[data-pills-toggle]');
          if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
              pillsExpanded = !pillsExpanded;
              renderSheetPills();
            });
          }
        }
        renderSheetPills();
      }).catch(function() {
        if (sheetPillsEl) sheetPillsEl.style.display = 'none';
      });
    }

    loadSheetPills();

    // ── Input events ─────────────────────────────────────────────────────────
    sheetInput.addEventListener('input', function () {
      var v = sheetInput.value;
      sheetClear.style.display = v ? 'flex' : 'none';
      if (!v.trim()) { renderRecent(); return; }
      sheetTriggerSearch(v);
    });

    sheetInput.addEventListener('keydown', function (e) {
      var rows = sheetDropdown.querySelectorAll('.zsp-result-row');
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (!rows.length) return;
        sheetActiveIdx = Math.min(sheetActiveIdx + 1, rows.length - 1);
        rows.forEach(function (r, i) { r.classList.toggle('is-active', i === sheetActiveIdx); });
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (!rows.length || sheetActiveIdx < 0) return;
        sheetActiveIdx = Math.max(sheetActiveIdx - 1, 0);
        rows.forEach(function (r, i) { r.classList.toggle('is-active', i === sheetActiveIdx); });
      } else if (e.key === 'Enter') {
        e.preventDefault();
        // Clear pending debounce so it can't re-open the dropdown after close.
        clearTimeout(sheetDebounce);
        if (sheetActiveIdx >= 0) {
          sheetSelectResult(sheetActiveIdx);
        } else if (sheetInput.value.trim()) {
          var q = sheetInput.value.trim();
          addRecent(q);
          closeSheet();
          fetchSearchPage(q).then(function (result) {
            applySearchToGrid(q, result.products, result.totalPages);
          }).catch(function () {
            applySearchToGrid(q, sheetResults, 1);
          });
        }
      } else if (e.key === 'Escape') {
        closeSheet();
      }
    });

    sheetClear.addEventListener('click', function () {
      sheetInput.value = '';
      sheetClear.style.display = 'none';
      sheetDropdown.style.display = 'none';
      sheetDropdown.innerHTML = '';
      sheetResults = [];
      // Mirror desktop clear: reset the product grid if a search filter is active.
      if (activeSearchQuery) clearSearchFilter();
      try { sheetInput.focus(); } catch (e) {}
    });

    // ── Signal to the footer that this sheet is ready ─────────────────────────
    document.body.setAttribute('data-zsp-search-ready', '1');
  }

  /* ═══════════════════════════════════════════════════════════════════════════
     BOOT
     ══════════════════════════════════════════════════════════════════════════ */
  function boot() {
    if (sortSelect)  sortSelect.addEventListener("change", () => {
      visibleCount = PAGE_SIZE;
      if (activeSearchQuery) {
        // Re-sort within current search results locally
        const sorted = [...searchResultsList].sort((a, b) => {
          const val = sortSelect.value;
          if (val === "price-asc")  return (parseFloat(a.price)||0) - (parseFloat(b.price)||0);
          if (val === "price-desc") return (parseFloat(b.price)||0) - (parseFloat(a.price)||0);
          if (val === "rating")     return (parseFloat(b.average_rating||b.rating)||0) - (parseFloat(a.average_rating||a.rating)||0);
          return 0;
        });
        applySearchToGrid(activeSearchQuery, sorted, searchTotalPages);
      } else if (activeCatProducts.length > 0) {
        /*
         * A category filter is active. This reproduces the exact behaviour
         * this branch already had before v1.23.0 — it did not special-case
         * an active category filter, so choosing a sort here fell through
         * to the plain "no active search" branch below and reloaded the
         * full, newly-sorted catalog, silently dropping the category
         * filter. That is unchanged. The only difference is that the
         * catalog now paints into the (now-visible) filtered container
         * rather than the page's only grid, since there was only one before.
         */
        showFilteredGrid();
        totalPages = 999;
        loaderHide();
        if (scrollObserver && scrollSentinel) {
          scrollObserver.disconnect();
        }
        activeCatProducts = [];
        activeCatName     = "";
        catPage           = 0;
        loadProducts(true).then(() => {
          if (scrollObserver && scrollSentinel) scrollObserver.observe(scrollSentinel);
        });
      } else {
        /*
         * v1.23.0: #product-grid is server-rendered with no live re-sort of
         * its own, so an unfiltered sort change reloads the page with the
         * chosen value carried as ?zy_sort= — templates/store.php reads
         * that query var and folds it into the "All Products" shortcode's
         * orderby/order attributes before rendering. Any other existing
         * query params on the URL are preserved.
         */
        const zyUrl = new URL(window.location.href);
        zyUrl.searchParams.set("zy_sort", sortSelect.value);
        window.location.href = zyUrl.toString();
      }
    });
    /* ── Infinite scroll via IntersectionObserver ─────────────────────────
       Replaces the old "Load More" button. Triggers automatically when the
       sentinel div near the bottom enters the viewport.
       Works across all three modes: category filter, text search, normal.
    ────────────────────────────────────────────────────────────────────── */
    let scrollObserverLocked = false; // prevent double-fire
    const LOADER_MIN_MS = 1500; // minimum time the loader stays visible

    async function handleScrollTrigger() {
      if (scrollObserverLocked) return;
      scrollObserverLocked = true;

      // Show loader immediately so it's always visible when user scrolls near bottom
      loaderShow();
      const loaderShownAt = Date.now();

      // Helper: wait out the remaining minimum display time before hiding
      async function hideAfterMinTime(finishFn) {
        const elapsed = Date.now() - loaderShownAt;
        const remaining = LOADER_MIN_MS - elapsed;
        if (remaining > 0) await new Promise(r => setTimeout(r, remaining));
        finishFn();
      }

      try {
        // ── Mode 1: Category filter (client-side pagination) ────────────
        if (activeCatProducts.length > 0) {
          const start = catPage * CAT_PAGE_SIZE;
          const slice = activeCatProducts.slice(start, start + CAT_PAGE_SIZE);
          if (slice.length === 0) {
            await hideAfterMinTime(loaderFinished);
            return;
          }
          await appendProducts(slice);
          catPage++;
          const allShown = (catPage * CAT_PAGE_SIZE) >= activeCatProducts.length;
          await hideAfterMinTime(allShown ? loaderFinished : loaderHide);
          return;
        }

        // ── Mode 2: Text search (paginated API) ─────────────────────────
        if (activeSearchQuery) {
          if (searchPage >= searchTotalPages) {
            await hideAfterMinTime(loaderFinished);
            return;
          }
          await loadMoreSearchResults();
          await hideAfterMinTime(searchPage >= searchTotalPages ? loaderFinished : loaderHide);
          return;
        }

        // ── Mode 3: Normal catalog ───────────────────────────────────────
        if (!isApiAvailable) {
          // Nothing to page through: the catalogue never loaded.
          await hideAfterMinTime(loaderFinished);
          return;
        }

        // Live API — only fetch if more pages exist
        if (currentPage >= totalPages && totalPages > 1) {
          await hideAfterMinTime(loaderFinished);
          return;
        }

        currentPage++;
        await loadProducts();
        await hideAfterMinTime(currentPage >= totalPages ? loaderFinished : loaderHide);

      } finally {
        scrollObserverLocked = false;
      }
    }

    scrollObserver = new IntersectionObserver(
      (entries) => {
        if (entries[0].isIntersecting && !scrollObserverLocked) {
          handleScrollTrigger();
        }
      },
      { root: null, rootMargin: "0px 0px", threshold: 0 }
    );

    /*
     * v1.23.0: no initial fetch here any more, and the observer is NOT
     * attached to the sentinel on boot.
     *
     * #product-grid (the "All Products" row) is now server-rendered by the
     * Product Grid engine with its own native infinite scroll — this script
     * never builds its first page or paginates it. #zy-scroll-sentinel
     * belongs to the category/search path only, so `scrollObserver` starts
     * idle and is attached reactively, exactly where it already was: inside
     * the category click handler and the search render paths, once one of
     * those becomes active and shows #product-grid-filtered.
     */

    fetchStoreDetails();
    initStickyHeader();
    initStoryToggle();
    initShareButton();
    initFollowButton();
    initAuraSearch();
    initMobileSearchSheet();
    initSidebarCatLinks();
    initSidebarCatsToggle();
    // initChatPopup() removed (Phase 7) — inbox.js (Comm plugin) calls zymargChatPopup.init() on DOMContentLoaded.
  }

  // NOTE (Phase 7): initChatPopup() removed from store-page.js.
  // popup.js (Comm plugin frontend/js/popup.js) now owns this logic.
  // inbox.js calls zymargChatPopup.init(window.ZYMARG_CONFIG) on DOMContentLoaded.


  /* ── Sidebar category links — filter grid in-page, no navigation ──────── */
  function initSidebarCatLinks() {
    document.querySelectorAll(".zy-sidebar-cat[data-cat-name]").forEach(link => {
      link.addEventListener("click", async function(e) {
        e.preventDefault();

        const catName = this.dataset.catName;
        const catSlug = this.dataset.catSlug || "";
        const catId   = this.dataset.catId   || "";

        // Highlight the active sidebar link
        document.querySelectorAll(".zy-sidebar-cat").forEach(l => l.classList.remove("is-active"));
        this.classList.add("is-active");

        // Reveal the filtered grid, hide the server-rendered static one.
        // The static grid's own content is left completely alone underneath
        // — it is only hidden, never cleared or re-fetched — so clearing
        // this filter later restores it instantly with no extra work.
        showFilteredGrid();

        // Show a loading state on the heading and grid
        setProductsHeading(`Loading "${catName}"…`, true);
        if (grid) {
          grid.innerHTML = `
            <div class="zy-grid-msg py-16 text-center text-zy-body/60">
              <span class="zymarg-spark zymarg-spark--xl" role="img" aria-label="ZYMARG Discovery Spark"><svg class="zymarg-spark__svg" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><g class="zymarg-spark-group--accent"><path class="zymarg-spark-item--purple" d="M10.4 5.4c0 1.32-0.24 2.4-1.44 2.4 1.2 0 1.44 1.08 1.44 2.4 0-1.32 0.24-2.4 1.44-2.4-1.2 0-1.44-1.08-1.44-2.4z"></path><path class="zymarg-spark-item--gold" d="M10.4 6.0c0 0.96-0.18 1.8-1.08 1.8 0.9 0 1.08 0.84 1.08 1.8 0-0.9 0.18-1.8 1.08-1.8-0.9 0-1.08-0.84-1.08-1.8z"></path></g><g class="zymarg-spark-group--companion"><path class="zymarg-spark-item--purple" d="M9.5 10.92c0 2.25-0.45 4.12-2.4 4.12 1.95 0 2.4 1.87 2.4 4.12 0-2.25 0.45-4.12 2.4-4.12-1.95 0-2.4-1.87-2.4-4.12z"></path><path class="zymarg-spark-item--gold" d="M9.5 11.5c0 1.9-0.38 3.54-2.0 3.54 1.62 0 2.0 1.64 2.0 3.54 0-1.9 0.38-3.54 2.0-3.54-1.62 0-2.0-1.64-2.0-3.54z"></path></g><g class="zymarg-spark-group--hero"><path class="zymarg-spark-item--purple" d="M15.2 5.6c0 3.45-0.69 6.3-4.08 6.3 3.39 0 4.08 2.85 4.08 6.3 0-3.45 0.69-6.3 4.08-6.3-3.39 0-4.08-2.85-4.08-6.3z"></path><path class="zymarg-spark-item--gold" d="M15.2 6.5c0 2.9-0.58 5.4-3.39 5.4 2.81 0 3.39 2.5 3.39 5.4 0-2.9 0.58-5.4 3.39-5.4-2.81 0-3.39-2.5-3.39-5.4z"></path></g></svg></span>
              <p class="text-sm">Loading products…</p>
            </div>`;
        }
        loaderHide();
        if (scrollObserver && scrollSentinel) scrollObserver.disconnect();
        const catLoaderShownAt = Date.now();
        async function hideCatLoader(finishFn) {
          const elapsed = Date.now() - catLoaderShownAt;
          const remaining = LOADER_MIN_MS - elapsed;
          if (remaining > 0) await new Promise(r => setTimeout(r, remaining));
          finishFn();
        }

        // Scroll to products section immediately
        const productsSection = document.getElementById("products");
        if (productsSection) {
          productsSection.scrollIntoView({ behavior: "smooth", block: "start" });
        }

        try {
          const { products: firstPage, totalPages } = await fetchByCategory(catSlug, catId);

          // Store the full filtered set; render only first CAT_PAGE_SIZE items
          activeCatProducts = firstPage;   // full array from fetchByCategory
          activeCatName     = catName;
          catPage           = 1;
          activeSearchQuery = "";          // not a text search — clear search state
          searchResultsList = [];

          setProductsHeading(
            activeCatProducts.length > 0
              ? `${activeCatProducts.length} product${activeCatProducts.length !== 1 ? "s" : ""} in "${catName}"`
              : `No products in "${catName}"`,
            true
          );

          if (activeCatProducts.length === 0) {
            if (grid) {
              grid.innerHTML = `
                <div class="zy-grid-msg py-12 text-center text-zy-body/60">
                  <svg class="mx-auto mb-3 h-10 w-10 text-zy-border" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/><path stroke-linecap="round" d="m21 21-4.35-4.35"/>
                  </svg>
                  <p class="text-sm font-semibold">No products found in "${escHtml(catName)}"</p>
                  <p class="mt-1 text-xs">Try another category or <button type="button" class="text-zy-primary underline" onclick="document.getElementById('zy-clear-search')&&document.getElementById('zy-clear-search').click()">browse all products</button></p>
                </div>`;
            }
            await hideCatLoader(loaderHide);
          } else {
            // Render first page
            const firstSlice = activeCatProducts.slice(0, CAT_PAGE_SIZE);
            await renderProducts(firstSlice);
            if (scrollObserver && scrollSentinel) scrollObserver.observe(scrollSentinel);
            if (loadMoreBtn) {
              await hideCatLoader(activeCatProducts.length <= CAT_PAGE_SIZE ? loaderHide : loaderHide);
            }
          }

          showSearchBanner(catName, activeCatProducts.length);

        } catch (err) {
          if (err.name !== "AbortError") {
            console.warn("[ZYMARG] Category fetch failed:", err);
            // Show a friendly error — don't use localSearch here because it
            // matches mock product names, not real category names from the API.
            setProductsHeading(`${catName}`, true);
            if (grid) {
              grid.innerHTML = `
                <div class="zy-grid-msg py-12 text-center text-zy-body/60">
                  <p class="text-sm font-semibold">Could not load products right now.</p>
                  <p class="mt-1 text-xs"><button type="button" class="text-zy-primary underline" onclick="document.getElementById('zy-clear-search')&&document.getElementById('zy-clear-search').click()">Browse all products</button></p>
                </div>`;
            }
            loaderHide();
          }
        }
      });
    });
  }

  /* ── Sidebar "Show all categories" toggle ─────────────────────────────── */
  function initSidebarCatsToggle() {
    const toggleBtn = document.getElementById("sidebar-cats-toggle");
    if (!toggleBtn) return; // fewer than 8 categories — button not rendered

    const drawer    = document.getElementById("sidebar-cats-drawer");
    const label     = document.getElementById("sidebar-cats-toggle-label");
    const icon      = document.getElementById("sidebar-cats-toggle-icon");
    const total     = parseInt(toggleBtn.dataset.total, 10);
    let expanded    = false;

    toggleBtn.addEventListener("click", () => {
      expanded = !expanded;

      if (expanded) {
        // Show drawer
        drawer.hidden = false;
        // Trigger CSS transition: set max-height after a paint frame
        requestAnimationFrame(() => {
          drawer.classList.add("is-open");
        });
      } else {
        // Collapse drawer
        drawer.classList.remove("is-open");
        // Wait for transition to finish before hiding
        drawer.addEventListener("transitionend", () => {
          if (!expanded) drawer.hidden = true;
        }, { once: true });
      }

      label.textContent = expanded
        ? "Show less ↑"
        : `Show all ${total} categories`;
      icon.style.transform = expanded ? "rotate(180deg)" : "";
    });
  }

  // Save scroll position when navigating to a product page
  document.addEventListener("click", function (e) {
    const card = e.target.closest("a[data-product-id]");
    if (card) {
      sessionStorage.setItem("zy_scroll_pos", window.scrollY);
    }
  });

  // Restore scroll position when coming back
  if (sessionStorage.getItem("zy_scroll_pos")) {
    const pos = parseInt(sessionStorage.getItem("zy_scroll_pos"), 10);
    sessionStorage.removeItem("zy_scroll_pos");
    window.scrollTo({ top: pos, behavior: "instant" });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }

})();
