/**
 * ZYMARG Cart — Sticky Cart Total module (v1.5.0).
 *
 * Manages the responsive sticky behaviour for Widget 3 (Cart Total) and its
 * floating breakdown popup.
 *
 * Behaviour summary:
 *   1. Reads per-breakpoint sticky toggles from the widget's data-attributes
 *      (data-sticky-desktop / data-sticky-tablet / data-sticky-mobile),
 *      computes the current breakpoint, and adds/removes the
 *      .zymarg-sticky-active class on the widget element + a matching body
 *      class. CSS handles the actual position:fixed at the bottom.
 *
 *   2. Uses a ResizeObserver to publish the widget's rendered height to a
 *      CSS custom property `--zc-sticky-total-height` on <html>. The Cart
 *      Body widget (Widget 2) consumes this to add `padding-bottom` equal
 *      to the sticky widget's height, so the last product is never hidden
 *      under it. This works even when admin hides parts of Widget 3 via
 *      Style controls — the height is always the *actual* rendered height.
 *
 *   3. Intercepts the subtotal-bar click in sticky mode and toggles the
 *      .zymarg-sticky-popup element instead of the inline breakdown panel.
 *      The breakdown module bails out in sticky mode so the two handlers
 *      don't conflict (see zymarg-cart-breakdown.js).
 *
 *   4. Auto-closes the popup on page scroll (per the user-confirmed spec)
 *      and on outside-tap. Escape key also closes.
 *
 *   5. Re-evaluates sticky activation on window resize so a tablet user
 *      rotating their device picks up the correct breakpoint immediately.
 *
 * Breakpoints (match Elementor's defaults; can be overridden by themes):
 *   Mobile    : < 768px
 *   Tablet    : 768px - 1024px
 *   Desktop   : >= 1025px
 *
 * Dependencies: jQuery (global), zymargCartData (wp_localize_script).
 *
 * @package ZymargCart
 * @since   1.5.0
 */

/* global zymargCartData */
( function ( $, cfg ) {
    'use strict';

    if ( ! cfg ) {
        return;
    }

    // =========================================================================
    // Constants
    // =========================================================================

    var SEL_TOTAL          = '.zymarg-cart-total';
    var SEL_BAR            = '.zymarg-subtotal-bar';
    var SEL_ARROW          = '.zymarg-breakdown-arrow';
    var SEL_POPUP          = '.zymarg-sticky-popup';

    var CLASS_STICKY       = 'zymarg-sticky-active';
    var CLASS_POPUP_OPEN   = 'zymarg-sticky-popup--open';
    var CLASS_ARROW_OPEN   = 'breakdown-arrow-open';
    var CLASS_BODY_STICKY  = 'zymarg-cart-sticky-active';

    var BODY_VAR_HEIGHT    = '--zc-sticky-total-height';

    var BP_MOBILE_MAX      = 767;   // < 768px = mobile
    var BP_TABLET_MAX      = 1024;  // 768px - 1024px = tablet

    // Throttle helpers for resize / scroll handlers.
    var RESIZE_DEBOUNCE_MS = 150;

    // =========================================================================
    // Breakpoint detection
    // =========================================================================

    /**
     * Returns the current breakpoint label.
     *
     * @return {'mobile'|'tablet'|'desktop'}
     */
    function currentBreakpoint() {
        var w = window.innerWidth || document.documentElement.clientWidth || 0;
        if ( w <= BP_MOBILE_MAX ) {
            return 'mobile';
        }
        if ( w <= BP_TABLET_MAX ) {
            return 'tablet';
        }
        return 'desktop';
    }

    /**
     * Checks whether sticky mode should be active right now for the given
     * widget, based on its data-attribute toggles and the current breakpoint.
     *
     * @param {jQuery} $widget
     * @return {boolean}
     */
    function shouldBeSticky( $widget ) {
        if ( ! $widget.length ) {
            return false;
        }
        var bp = currentBreakpoint();
        var attr;
        if ( bp === 'mobile' ) {
            attr = 'data-sticky-mobile';
        } else if ( bp === 'tablet' ) {
            attr = 'data-sticky-tablet';
        } else {
            attr = 'data-sticky-desktop';
        }
        return 'yes' === ( $widget.attr( attr ) || 'no' );
    }

    // =========================================================================
    // Height publishing (CSS variable + body class)
    // =========================================================================

    /**
     * Publishes the widget's rendered height to a CSS custom property on
     * <html> so other widgets (notably Widget 2 Cart Body) can reserve
     * matching `padding-bottom` space. Falls back to a sensible default
     * if the widget hasn't rendered yet.
     *
     * @param {HTMLElement} el
     */
    function publishHeight( el ) {
        if ( ! el ) {
            return;
        }
        var h = el.offsetHeight || 0;
        if ( h > 0 ) {
            document.documentElement.style.setProperty( BODY_VAR_HEIGHT, h + 'px' );
        }
    }

    /**
     * Clears the published height when sticky mode deactivates so Widget 2
     * stops reserving extra bottom padding.
     */
    function clearHeight() {
        document.documentElement.style.setProperty( BODY_VAR_HEIGHT, '0px' );
    }

    // =========================================================================
    // Sticky activation / deactivation
    // =========================================================================

    var resizeObserver = null;

    function activateSticky( $widget ) {
        if ( $widget.hasClass( CLASS_STICKY ) ) {
            return;
        }
        $widget.addClass( CLASS_STICKY );
        document.body.classList.add( CLASS_BODY_STICKY );

        // Initial height publish.
        publishHeight( $widget.get( 0 ) );

        // Observe future size changes (e.g. when admin hides/shows parts of
        // the widget via Elementor or when content reflows on rotate).
        if ( 'ResizeObserver' in window && ! resizeObserver ) {
            resizeObserver = new ResizeObserver( function ( entries ) {
                for ( var i = 0; i < entries.length; i++ ) {
                    publishHeight( entries[ i ].target );
                }
            } );
        }
        if ( resizeObserver ) {
            resizeObserver.observe( $widget.get( 0 ) );
        }
    }

    function deactivateSticky( $widget ) {
        if ( ! $widget.hasClass( CLASS_STICKY ) ) {
            return;
        }
        $widget.removeClass( CLASS_STICKY );
        document.body.classList.remove( CLASS_BODY_STICKY );

        // Also close the popup if it's open — sticky off means popup off.
        closePopup( $widget );

        if ( resizeObserver ) {
            try {
                resizeObserver.unobserve( $widget.get( 0 ) );
            } catch ( err ) { /* element may be gone */ }
        }
        clearHeight();
    }

    /**
     * Evaluates every cart-total widget on the page against the current
     * breakpoint + its toggles, and activates/deactivates accordingly.
     */
    function evaluateAll() {
        $( SEL_TOTAL ).each( function () {
            var $widget = $( this );
            if ( shouldBeSticky( $widget ) ) {
                activateSticky( $widget );
            } else {
                deactivateSticky( $widget );
            }
        } );
    }

    // =========================================================================
    // Popup toggle
    // =========================================================================

    function openPopup( $widget ) {
        var $popup = $widget.find( SEL_POPUP );
        if ( ! $popup.length ) {
            return;
        }

        // Remove the HTML `hidden` attribute so the element is rendered. We
        // rely on CSS (visibility + opacity) for transitions — `hidden`
        // applies `display: none !important` which short-circuits CSS
        // transitions entirely (you cannot animate from `display: none`).
        $popup
            .prop( 'hidden', false )
            .attr( 'aria-hidden', 'false' );

        // Defer the open-class addition by one frame so the browser paints
        // the initial state (visibility: hidden, opacity: 0, translateY 20px)
        // before transitioning to the open state. Without this, the browser
        // may collapse both states into one paint and skip the animation.
        var raf = window.requestAnimationFrame || function ( cb ) {
            return window.setTimeout( cb, 16 );
        };
        raf( function () {
            $popup.addClass( CLASS_POPUP_OPEN );
        } );

        // Flip chevron + update aria-expanded on the subtotal bar so the
        // expand/collapse affordance stays accurate in sticky mode too.
        var $bar = $widget.find( SEL_BAR );
        $bar.attr( 'aria-expanded', 'true' );
        $bar.find( SEL_ARROW ).addClass( CLASS_ARROW_OPEN );
    }

    function closePopup( $widget ) {
        var $popup = $widget.find( SEL_POPUP );
        if ( ! $popup.length || ! $popup.hasClass( CLASS_POPUP_OPEN ) ) {
            return;
        }

        $popup
            .removeClass( CLASS_POPUP_OPEN )
            .attr( 'aria-hidden', 'true' );

        var $bar = $widget.find( SEL_BAR );
        $bar.attr( 'aria-expanded', 'false' );
        $bar.find( SEL_ARROW ).removeClass( CLASS_ARROW_OPEN );

        // The CSS handles the rest: visibility transitions to hidden after
        // the opacity/transform finish (250ms delay on the visibility
        // transition in the base state). No JS timer needed.
    }

    function togglePopup( $widget ) {
        var $popup = $widget.find( SEL_POPUP );
        if ( ! $popup.length ) {
            return;
        }
        if ( $popup.hasClass( CLASS_POPUP_OPEN ) ) {
            closePopup( $widget );
        } else {
            openPopup( $widget );
        }
    }

    function closeAllPopups() {
        $( SEL_TOTAL ).each( function () {
            closePopup( $( this ) );
        } );
    }

    // =========================================================================
    // Event listeners
    // =========================================================================

    // Subtotal bar tap in sticky mode → toggle the popup.
    $( document ).on( 'click', SEL_BAR, function () {
        var $widget = $( this ).closest( SEL_TOTAL );
        if ( ! $widget.hasClass( CLASS_STICKY ) ) {
            return; // Inline mode — breakdown module handles it.
        }
        togglePopup( $widget );
    } );

    // Keyboard accessibility: Enter / Space on the bar in sticky mode.
    $( document ).on( 'keydown', SEL_BAR, function ( e ) {
        if ( e.key !== 'Enter' && e.key !== ' ' ) {
            return;
        }
        var $widget = $( this ).closest( SEL_TOTAL );
        if ( ! $widget.hasClass( CLASS_STICKY ) ) {
            return;
        }
        e.preventDefault();
        togglePopup( $widget );
    } );

    // Escape key closes any open popup.
    $( document ).on( 'keydown', function ( e ) {
        if ( e.key === 'Escape' ) {
            closeAllPopups();
        }
    } );

    // Click outside the popup (and outside the subtotal bar that opens it)
    // closes the popup. The bar itself toggles via its own handler.
    $( document ).on( 'click', function ( e ) {
        var $target = $( e.target );
        if (
            $target.closest( SEL_POPUP ).length ||
            $target.closest( SEL_BAR ).length
        ) {
            return;
        }
        closeAllPopups();
    } );

    // Page scroll auto-closes the popup (per spec — Q3 / Option B).
    // Use passive listener so it never blocks scrolling. Only react if
    // a popup is actually open, to avoid pointless work on every scroll.
    var scrollListener = function () {
        if ( document.querySelector( '.' + CLASS_POPUP_OPEN ) ) {
            closeAllPopups();
        }
    };
    window.addEventListener( 'scroll', scrollListener, { passive: true } );

    // =========================================================================
    // Resize / breakpoint changes
    // =========================================================================

    var resizeTimer = null;
    window.addEventListener( 'resize', function () {
        clearTimeout( resizeTimer );
        resizeTimer = setTimeout( function () {
            evaluateAll();
            // Re-publish height since rendered height may have changed too.
            $( SEL_TOTAL + '.' + CLASS_STICKY ).each( function () {
                publishHeight( this );
            } );
        }, RESIZE_DEBOUNCE_MS );
    }, { passive: true } );

    // =========================================================================
    // Init
    // =========================================================================

    $( function () {
        // Skip in the Elementor editor preview — sticky positioning collides
        // with the editor's iframe / canvas chrome and confuses the preview.
        if (
            window.elementor ||
            ( window.elementorFrontend && window.elementorFrontend.isEditMode && window.elementorFrontend.isEditMode() )
        ) {
            return;
        }

        evaluateAll();
    } );

} )( jQuery, zymargCartData );
