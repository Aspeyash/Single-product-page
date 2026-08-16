/**
 * ZYMARG Vendor Dashboard - AJAX admin navigation.
 *
 * Makes the whole Vendor Hub admin area behave as a single page: sidebar
 * submenu clicks, hub card clicks, in-page hub links and plain admin POST
 * forms are all handled over AJAX with no full page reload.
 *
 * v1.39.0 - Full-AJAX pass:
 *   - Slug allow-list now comes from PHP (ZymargAdminHub.slugs), so a screen
 *     can never be registered server-side but missing client-side. That gap is
 *     exactly why Vendors and Push Notifications used to hard-navigate while
 *     the other screens swapped.
 *   - Removed every window.location.reload() fallback. A failed request now
 *     renders an inline retry inside the content area; the page is never
 *     thrown away, and no work in the sidebar is lost.
 *   - The loading indicator is the Discovery Spark, never a spinner.
 *   - Plain <form method="post"> submits inside the hub are intercepted and
 *     posted over AJAX, then the section is re-rendered in place.
 *   - Re-initialisation is broadcast on zymarg:contentSwapped AND the older
 *     per-screen init functions are re-run when present.
 *
 * @package ZYMARG_Vendor_Dashboard
 */
(function ($) {
    'use strict';

    if (typeof ZymargAdminHub === 'undefined') {
        return;
    }

    var cfg     = ZymargAdminHub;
    var ajaxUrl = cfg.ajaxUrl;
    var nonce   = cfg.nonce;
    var i18n    = cfg.i18n || {};

    var $content      = null;
    var isLoading     = false;
    var currentXhr    = null;
    var lastClickTime = 0;

    /** Minimum ms between accepted clicks (debounce rapid clicks). */
    var CLICK_DEBOUNCE = 300;

    /**
     * The hub page slugs we handle, supplied by PHP so client and server can
     * never drift apart.
     */
    var validSlugs = cfg.slugs || [];

    /**
     * Extract the page slug from a URL's query string.
     *
     * @param {string} url Full URL or relative path.
     * @return {string|null} The slug, or null if not a Vendor Hub page.
     */
    function getSlugFromUrl(url) {
        if (!url) {
            return null;
        }
        var page = null;
        try {
            page = new URL(url, window.location.href).searchParams.get('page');
        } catch (e) {
            var match = url.match(/[?&]page=([^&#]+)/);
            page = match ? decodeURIComponent(match[1]) : null;
        }
        return (page && validSlugs.indexOf(page) !== -1) ? page : null;
    }

    /**
     * Resolve a possibly relative admin href against the current location.
     *
     * @param {string} href Raw href.
     * @return {string} Absolute URL.
     */
    function absoluteUrl(href) {
        try {
            return new URL(href, window.location.href).href;
        } catch (e) {
            return href;
        }
    }

    /**
     * Render the Discovery Spark loading region.
     *
     * ZYMARG has no spinners: the Spark's sequential lens pulse IS the loading
     * animation, and the visible text is required so reduced-motion users and
     * screen readers still get feedback.
     */
    function showLoading() {
        if (!$content || !$content.length) {
            return;
        }
        $content.html(
            '<div class="zvd-loading-region">' +
                '<div class="zymarg-loading" role="status" aria-live="polite">' +
                    (cfg.spark || '') +
                    '<span class="zymarg-loading__text">' +
                        (i18n.loading || 'Loading') +
                    '</span>' +
                '</div>' +
            '</div>'
        );
    }

    /**
     * Render an inline failure with a retry control.
     *
     * We deliberately never reload the page here. A reload is the exact
     * behaviour we are trying to remove.
     *
     * @param {string} slug The slug that failed.
     */
    function showFailure(slug) {
        if (!$content || !$content.length) {
            return;
        }
        $content.html(
            '<div class="zvd-card">' +
                '<div class="zvd-notice zvd-notice--error" role="alert">' +
                    '<span class="zvd-notice__label">' + (i18n.error || 'Error:') + '</span> ' +
                    '<span>' + (i18n.failed || 'That did not load. Try again.') + '</span>' +
                '</div>' +
                '<button type="button" class="zvd-btn zvd-btn--primary zvd-retry" ' +
                    'data-slug="' + slug + '">' + (i18n.retry || 'Retry') + '</button>' +
            '</div>'
        );
    }

    /**
     * Update the WordPress admin sidebar menu highlights.
     *
     * @param {string} slug The active page slug.
     */
    function updateMenuHighlights(slug) {
        var $topMenu = $('#toplevel_page_zymarg-vendor-hub');
        if (!$topMenu.length) {
            return;
        }

        $topMenu
            .addClass('wp-has-current-submenu wp-menu-open current')
            .removeClass('wp-not-current-submenu');
        $topMenu.find('> a')
            .addClass('wp-has-current-submenu wp-menu-open')
            .removeClass('wp-not-current-submenu');

        $topMenu.find('.wp-submenu a').each(function () {
            var $link    = $(this);
            var $li      = $link.parent('li');
            var linkSlug = getSlugFromUrl($link.attr('href') || '');

            if (linkSlug === slug) {
                $li.addClass('current');
                $link.addClass('current').attr('aria-current', 'page');
            } else {
                $li.removeClass('current');
                $link.removeClass('current').removeAttr('aria-current');
            }
        });
    }

    /**
     * Re-run per-screen initialisers after a content swap.
     *
     * Screens that expose an init function get called directly; everything
     * else can listen for the zymarg:contentSwapped event.
     *
     * @param {string} slug Slug just rendered.
     * @param {string} url  URL just rendered.
     */
    function reinitialise(slug, url) {
        var initialisers = [
            'ZymargAnnouncementsInit',
            'ZymargVendorsInit',
            'ZymargPayoutsAdminInit',
            'ZymargPushInit'
        ];

        initialisers.forEach(function (name) {
            if (typeof window[name] === 'function') {
                try {
                    window[name]();
                } catch (err) {
                    // A broken screen initialiser must not take the router down.
                }
            }
        });

        $(document).trigger('zymarg:contentSwapped', { slug: slug, url: url });
    }

    /**
     * Load a section via AJAX.
     *
     * @param {string}  slug        The page slug to load.
     * @param {string}  url         The full URL (for pushState).
     * @param {boolean} pushHistory Whether to push a history entry.
     */
    function loadSection(slug, url, pushHistory) {
        if (isLoading) {
            return;
        }
        isLoading = true;

        if (currentXhr && currentXhr.abort) {
            try { currentXhr.abort(); } catch (ignore) { /* noop */ }
        }

        showLoading();

        currentXhr = $.ajax({
            url: ajaxUrl,
            type: 'POST',
            timeout: 20000,
            data: {
                action: 'zymarg_vd_hub_load_section',
                section: slug,
                nonce: nonce
            }
        })
        .done(function (response) {
            try {
                if (response && response.success && response.data && response.data.html) {
                    $content.html(response.data.html);

                    if (pushHistory) {
                        window.history.pushState({ zymargSlug: slug }, '', url);
                    }
                    if (response.data.title) {
                        document.title = response.data.title + ' \u2039 ' + document.title.split('\u2039').pop().trim();
                    }

                    updateMenuHighlights(slug);
                    reinitialise(slug, url);
                } else {
                    showFailure(slug);
                }
            } catch (err) {
                showFailure(slug);
            }
        })
        .fail(function (xhr, textStatus) {
            if ('abort' === textStatus) {
                return;
            }
            showFailure(slug);
        })
        .always(function () {
            isLoading  = false;
            currentXhr = null;
        });
    }

    /**
     * Handle a click on a Vendor Hub navigation link.
     *
     * @param {Event} e Click event.
     */
    function handleNavClick(e) {
        // Let the browser handle modified clicks (new tab, download, etc).
        if (e.which > 1 || e.shiftKey || e.ctrlKey || e.metaKey || e.altKey) {
            return;
        }

        var href = $(this).attr('href');
        var slug = getSlugFromUrl(href);
        if (!slug) {
            return;
        }

        var now = Date.now();
        if (now - lastClickTime < CLICK_DEBOUNCE) {
            e.preventDefault();
            return;
        }
        lastClickTime = now;

        e.preventDefault();
        loadSection(slug, absoluteUrl(href), true);
    }

    /**
     * Intercept plain admin POST forms inside the hub and submit them over
     * AJAX, then re-render the current section in place.
     *
     * Screens that already have their own dedicated AJAX handler opt out with
     * data-zvd-ajax="off", and file uploads / admin-post.php streams (CSV
     * exports) are left alone because they need a real browser navigation.
     *
     * @param {Event} e Submit event.
     */
    function handleFormSubmit(e) {
        var form   = this;
        var $form  = $(form);
        var action = ($form.attr('action') || '').toLowerCase();

        if ($form.data('zvdAjax') === 'off' || $form.attr('id') || $form.attr('enctype')) {
            return; // Screen-specific handler, or a real upload.
        }
        if (action.indexOf('admin-post.php') !== -1 || action.indexOf('options.php') !== -1) {
            return; // Real navigation required (file streams / core settings API).
        }

        var slug = getSlugFromUrl(window.location.href);
        if (!slug) {
            return;
        }

        e.preventDefault();

        var $submit = $form.find('[type="submit"]').first();
        $submit.addClass('is-busy').prop('disabled', true);

        $.ajax({
            url: window.location.href,
            type: 'POST',
            timeout: 20000,
            data: $form.serialize()
        })
        .done(function (html) {
            // Pull just our content container out of the returned document.
            var $fresh = $('<div>').append($.parseHTML(html)).find('#zymarg-admin-ajax-content');

            if ($fresh.length) {
                $content.html($fresh.html());
                reinitialise(slug, window.location.href);
            } else {
                // Fall back to a clean AJAX re-render of the section.
                loadSection(slug, window.location.href, false);
            }
        })
        .fail(function () {
            $submit.removeClass('is-busy').prop('disabled', false);
            $form.prepend(
                '<div class="zvd-notice zvd-notice--error" role="alert">' +
                    '<span class="zvd-notice__label">' + (i18n.error || 'Error:') + '</span> ' +
                    '<span>' + (i18n.failed || 'That did not save. Try again.') + '</span>' +
                '</div>'
            );
        });
    }

    /**
     * Initialize once DOM is ready.
     */
    function init() {
        $content = $('#zymarg-admin-ajax-content');
        if (!$content.length) {
            return;
        }

        var initialSlug = getSlugFromUrl(window.location.href);
        if (initialSlug) {
            window.history.replaceState({ zymargSlug: initialSlug }, '', window.location.href);
        }

        // Sidebar submenu links.
        $(document).on(
            'click',
            '#adminmenu #toplevel_page_zymarg-vendor-hub .wp-submenu a',
            handleNavClick
        );

        // Hub cards, back links, and any in-page link opted in.
        $(document).on(
            'click',
            '.zvd-nav-link, .zvd-hub-card, .zymarg-admin-hub-card, .zymarg-back-to-hub',
            handleNavClick
        );

        // Any other in-content link that points at a hub screen.
        $(document).on('click', '#zymarg-admin-ajax-content a[href*="page=zymarg-"]', handleNavClick);

        // Inline retry after a failed load.
        $(document).on('click', '.zvd-retry', function () {
            var slug = $(this).data('slug');
            if (slug) {
                loadSection(slug, window.location.href, false);
            }
        });

        // Plain POST forms inside the hub.
        $(document).on('submit', '#zymarg-admin-ajax-content form', handleFormSubmit);

        // Browser back/forward.
        $(window).on('popstate', function (e) {
            var state = e.originalEvent.state;
            var slug  = (state && state.zymargSlug) || getSlugFromUrl(window.location.href);
            if (slug) {
                loadSection(slug, window.location.href, false);
            }
        });
    }

    $(document).ready(init);

})(jQuery);
