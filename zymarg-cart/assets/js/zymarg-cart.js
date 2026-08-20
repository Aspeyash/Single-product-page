/**
 * ZYMARG Cart — Main initializer.
 *
 * Bootstraps all JS modules and owns the global cart interaction layer:
 *  - Global selected-keys state (array of WC cart item keys checked for checkout).
 *  - Event delegation for qty steppers, variation selects, coupon toggles,
 *    save-for-later, move-to-cart, remove-saved, remove-coupon, checkout button.
 *  - Variation-ID lookup from the row's data-variations JSON.
 *  - Restore-sentinel check on page load.
 *  - Exposes window.ZymargCart for inter-module communication.
 *
 * Load order (all in footer, handled by wp_enqueue_script dependencies):
 *   1. zymarg-cart.js          ← this file (must load first)
 *   2. zymarg-cart-checkbox.js
 *   3. zymarg-cart-ajax.js
 *   4. zymarg-cart-breakdown.js
 *   5. zymarg-cart-edit-mode.js
 *
 * @package ZymargCart
 * @since   1.0.0
 */

/* global zymargCartData, ZymargAjax */
( function ( $, cfg ) {
    'use strict';

    if ( ! cfg || ! cfg.ajaxUrl ) {
        return;
    }

    // =========================================================================
    // Module-level state
    // =========================================================================

    /** Currently selected WC cart item keys (used by partial checkout). */
    var _selectedKeys = [];

    // =========================================================================
    // Selected-keys helpers
    // =========================================================================

    /**
     * Reads all checked .zymarg-product-cb inputs and rebuilds _selectedKeys.
     * Called on init and after DOM mutations that add/remove product rows.
     *
     * @returns {Array<string>}
     */
    function syncSelectedKeys() {
        _selectedKeys = [];
        $( '.zymarg-product-cb:checked' ).each( function () {
            var key = $( this ).val() || $( this ).data( 'cart-key' );
            if ( key && _selectedKeys.indexOf( key ) === -1 ) {
                _selectedKeys.push( key );
            }
        } );
        return _selectedKeys;
    }

    /** Returns a copy of the current selected-keys array. */
    function getSelectedKeys() {
        return _selectedKeys.slice();
    }

    // =========================================================================
    // Variation-ID lookup
    // =========================================================================

    /**
     * Finds the variation_id from a row's available-variations JSON that best
     * matches the supplied attribute key/value map.
     *
     * A variation matches when every non-empty attribute in the variation equals
     * the corresponding attribute in `attrs` (empty/undefined = "any" → matches
     * any value for that attribute).
     *
     * @param  {Array}  variations  Row's data-variations array (from PHP).
     * @param  {Object} attrs       Current attribute slug → value map.
     * @returns {number} Variation ID, or 0 if not found.
     */
    function findVariationId( variations, attrs ) {
        if ( ! Array.isArray( variations ) ) {
            return 0;
        }
        for ( var i = 0; i < variations.length; i++ ) {
            var v      = variations[ i ];
            var vAttrs = v.attributes || {};
            var match  = true;

            for ( var attrKey in attrs ) {
                if ( ! Object.prototype.hasOwnProperty.call( attrs, attrKey ) ) {
                    continue;
                }
                var vVal = vAttrs[ attrKey ];
                // Blank vVal = "any" → always matches.
                if ( vVal !== undefined && vVal !== '' && vVal !== attrs[ attrKey ] ) {
                    match = false;
                    break;
                }
            }

            if ( match ) {
                return parseInt( v.variation_id, 10 ) || 0;
            }
        }
        return 0;
    }

    // =========================================================================
    // Restore-sentinel check
    // =========================================================================

    /**
     * Reads the #zymarg-restore-sentinel div injected by
     * Zymarg_Cart_Partial::show_restore_spinner(). If a backup or pending
     * restore is flagged, triggers the AJAX restore silently.
     */
    function checkRestoreSentinel() {
        var $s = $( '#zymarg-restore-sentinel' );
        if ( ! $s.length ) {
            return;
        }
        var hasBackup  = '1' === String( $s.data( 'has-backup' ) );
        var hasPending = '1' === String( $s.data( 'has-pending' ) );

        if ( hasBackup || hasPending ) {
            log( 'Restore sentinel detected — triggering restoreCart.' );
            if ( window.ZymargAjax ) {
                ZymargAjax.restoreCart();
            }
        }
    }

    // =========================================================================
    // Event delegation — all cart interactions
    // =========================================================================

    function bindEvents() {

        // ── Quantity stepper ──────────────────────────────────────────────────
        $( document ).on( 'click', '.zymarg-qty-btn', function ( e ) {
            e.preventDefault();
            var $btn  = $( this );
            var $wrap = $btn.closest( '.zymarg-qty-stepper' );
            var $row  = $btn.closest( '.zymarg-product-row' );
            var $val  = $wrap.find( '.zymarg-qty-value' );
            var key   = $row.data( 'cart-key' ) || $wrap.data( 'cart-key' );

            if ( ! key ) {
                return;
            }

            var current = parseInt( $val.text(), 10 ) || 1;
            var min     = parseInt( $wrap.data( 'min' ) || 1, 10 );
            var max     = parseInt( $wrap.data( 'max' ) || 99, 10 );
            var isPlus  = $btn.hasClass( 'zymarg-qty-plus' );
            var isMinus = $btn.hasClass( 'zymarg-qty-minus' );

            // Store for rollback on AJAX error.
            $row.data( 'qty', current );

            var newQty = isPlus  ? current + 1
                       : isMinus ? current - 1
                       : current;

            newQty = Math.max( min, Math.min( max, newQty ) );
            if ( newQty === current ) {
                return;
            }

            // Optimistic UI update.
            $val.text( newQty );
            $wrap.find( '.zymarg-qty-minus' ).prop( 'disabled', newQty <= min );
            $wrap.find( '.zymarg-qty-plus'  ).prop( 'disabled', newQty >= max );

            if ( window.ZymargAjax ) {
                ZymargAjax.updateQuantity( $btn, key, newQty, getSelectedKeys() );
            }
        } );

        // ── Variation select ──────────────────────────────────────────────────
        $( document ).on( 'change', '.zymarg-variation-select', function () {
            var $sel = $( this );
            var $row = $sel.closest( '.zymarg-product-row' );

            // Always read cart-key directly from the DOM attribute, never from
            // jQuery's .data() cache. After a variation change the PHP assigns a
            // new WC cart hash key and zymarg-cart-ajax.js updates the attribute
            // via $row.attr('data-cart-key', newKey). jQuery's cache would still
            // return the original page-load value, causing the second AJAX call
            // to reference a cart item that no longer exists.
            var key = $row[ 0 ].getAttribute( 'data-cart-key' );

            if ( ! key ) {
                return;
            }

            // Build the full attribute map from all selects in this row.
            var attrs = {};
            $row.find( '.zymarg-variation-select' ).each( function () {
                // Read attr-key from DOM attribute directly (same cache-bypass reason).
                var attrKey = this.getAttribute( 'data-attr-key' );
                if ( attrKey ) {
                    attrs[ attrKey ] = $( this ).val();
                }
            } );

            // Read data-variations directly from the DOM attribute to bypass
            // jQuery's internal cache. zymarg-cart-ajax.js calls $row.removeData('variations')
            // after each successful variation change, but reading the attribute
            // directly is safer and avoids the cache entirely.
            var rawVariations = $row[ 0 ].getAttribute( 'data-variations' );
            var variations    = [];
            if ( rawVariations ) {
                try { variations = JSON.parse( rawVariations ); } catch ( err ) { /* ignore */ }
            }

            var variationId = findVariationId( variations, attrs );
            if ( ! variationId ) {
                log( 'No matching variation found for attrs', attrs );
                return;
            }

            if ( window.ZymargAjax ) {
                ZymargAjax.changeVariation( $sel, key, variationId, attrs, getSelectedKeys() );
            }
        } );

        // ── Coupon toggle ("Have a coupon?") ──────────────────────────────────
        // v1.2.1: locate the form via aria-controls instead of walking up the
        // DOM. Pre-1.2.1 used $btn.closest('.zymarg-col-coupon').find(...) —
        // that worked for the desktop toggle (which IS inside .zymarg-col-coupon)
        // but silently failed for the new mobile toggle, which lives inside
        // .zymarg-col-mobile-actions, NOT inside .zymarg-col-coupon. The form
        // never got its open class and the coupon input never appeared on
        // mobile.
        $( document ).on( 'click', '.zymarg-coupon-toggle', function ( e ) {
            e.preventDefault();
            var $btn   = $( this );
            var formId = $btn.attr( 'aria-controls' );
            var $form  = formId
                ? $( document.getElementById( formId ) )
                : $btn.closest( '.zymarg-col-coupon' ).find( '.zymarg-coupon-form' );

            if ( ! $form.length ) {
                return;
            }

            var open = $form.hasClass( 'zymarg-coupon-open' );
            $form.toggleClass( 'zymarg-coupon-open', ! open );

            // Sync aria-expanded on BOTH the desktop and mobile toggles for
            // this row so screen readers report the same state regardless of
            // which one the user activated.
            var $row = $btn.closest( '.zymarg-product-row' );
            if ( formId && $row.length ) {
                $row.find( '.zymarg-coupon-toggle[aria-controls="' + formId + '"]' )
                    .attr( 'aria-expanded', ! open ? 'true' : 'false' );
            } else {
                $btn.attr( 'aria-expanded', ! open ? 'true' : 'false' );
            }

            if ( ! open ) {
                setTimeout( function () {
                    $form.find( '.zymarg-coupon-input' ).trigger( 'focus' );
                }, 50 );
            }
        } );

        // ── Coupon apply button ───────────────────────────────────────────────
        $( document ).on( 'click', '.zymarg-coupon-apply', function ( e ) {
            e.preventDefault();
            var $btn      = $( this );
            var $col      = $btn.closest( '.zymarg-col-coupon, .zymarg-coupon-form-wrap' );
            var $input    = $col.find( '.zymarg-coupon-input' );
            var $row      = $btn.closest( '.zymarg-product-row' );
            var code      = $.trim( $input.val() );
            var productId = parseInt( $btn.data( 'product-id' ), 10 ) || 0;
            var vendorId  = parseInt( $btn.data( 'vendor-id'  ), 10 ) || 0;

            if ( ! code ) {
                $input.trigger( 'focus' );
                return;
            }

            if ( window.ZymargAjax ) {
                ZymargAjax.applyCoupon(
                    $row.length ? $row : $btn.closest( '.zymarg-product-row, [data-coupon-context]' ),
                    code,
                    productId,
                    vendorId,
                    getSelectedKeys()
                );
            }
        } );

        // ── Coupon apply on Enter key ─────────────────────────────────────────
        $( document ).on( 'keydown', '.zymarg-coupon-input', function ( e ) {
            if ( e.key === 'Enter' ) {
                e.preventDefault();
                $( this ).closest( '.zymarg-coupon-form, .zymarg-col-coupon' )
                         .find( '.zymarg-coupon-apply' )
                         .trigger( 'click' );
            }
        } );

        // ── Remove applied coupon (Widget 3 badge) ────────────────────────────
        $( document ).on( 'click', '.zymarg-remove-coupon', function ( e ) {
            e.preventDefault();
            var code = $( this ).data( 'coupon' );
            if ( code && window.ZymargAjax ) {
                ZymargAjax.removeCoupon( code, getSelectedKeys() );
            }
        } );

        // ── Save for Later ────────────────────────────────────────────────────
        $( document ).on( 'click', '.zymarg-save-later-btn', function ( e ) {
            e.preventDefault();
            var $row = $( this ).closest( '.zymarg-product-row' );
            var key  = $row.data( 'cart-key' );
            if ( key && window.ZymargAjax ) {
                ZymargAjax.saveForLater( $row, key, getSelectedKeys() );
            }
        } );

        // ── Move saved item to cart ───────────────────────────────────────────
        $( document ).on( 'click', '.zymarg-move-to-cart-btn', function ( e ) {
            e.preventDefault();
            var $row    = $( this ).closest( '.zymarg-saved-item-row' );
            var itemKey = $row.data( 'item-key' );
            if ( itemKey && window.ZymargAjax ) {
                ZymargAjax.moveToCart( $row, itemKey, getSelectedKeys() );
            }
        } );

        // ── Remove saved item ─────────────────────────────────────────────────
        $( document ).on( 'click', '.zymarg-remove-saved-btn', function ( e ) {
            e.preventDefault();
            var $row    = $( this ).closest( '.zymarg-saved-item-row' );
            var itemKey = $row.data( 'item-key' );
            if ( itemKey && window.ZymargAjax ) {
                ZymargAjax.removeSaved( $row, itemKey );
            }
        } );

        // ── Checkout button ───────────────────────────────────────────────────
        $( document ).on( 'click', '.zymarg-checkout-btn', function ( e ) {
            e.preventDefault();
            if ( window.ZymargAjax ) {
                ZymargAjax.partialCheckout( getSelectedKeys(), $( this ) );
            }
        } );

        // ── Re-sync state when checkbox module signals a change ───────────────
        document.addEventListener( 'zymarg:selectionChanged', function ( e ) {
            if ( e.detail && Array.isArray( e.detail.selectedKeys ) ) {
                _selectedKeys = e.detail.selectedKeys.slice();
            }
        } );

        // ── Re-sync after AJAX item removal ───────────────────────────────────
        document.addEventListener( 'zymarg:itemRemoved', function () {
            syncSelectedKeys();
        } );

        document.addEventListener( 'zymarg:itemSaved', function () {
            syncSelectedKeys();
        } );
    }

    // =========================================================================
    // Utility
    // =========================================================================

    function log( msg, extra ) {
        if ( cfg.debug && window.console ) {
            // eslint-disable-next-line no-console
            console.log( '[ZymargCart] ' + msg, extra !== undefined ? extra : '' );
        }
    }

    // =========================================================================
    // Custom event dispatcher
    // =========================================================================

    function emit( name, detail ) {
        var ev;
        try {
            ev = new CustomEvent( name, { detail: detail, bubbles: true } );
        } catch ( err ) {
            ev = document.createEvent( 'CustomEvent' );
            ev.initCustomEvent( name, true, false, detail );
        }
        document.dispatchEvent( ev );
    }

    // =========================================================================
    // Public API — window.ZymargCart
    // =========================================================================

    window.ZymargCart = {

        /** Dispatches a namespaced CustomEvent on document. */
        emit : emit,

        /** Returns a copy of the currently selected cart item keys. */
        getSelectedKeys : getSelectedKeys,

        /**
         * Replaces the selected-keys array (called by checkbox module after
         * cascade updates).
         *
         * @param {Array<string>} keys
         */
        updateSelectedKeys : function ( keys ) {
            _selectedKeys = Array.isArray( keys ) ? keys.slice() : [];
        },

        /**
         * Re-reads the DOM and rebuilds _selectedKeys.
         * Call after AJAX responses that add/remove rows.
         */
        syncSelectedKeys : syncSelectedKeys,

        /** Internal log helper. */
        log : log,
    };

    // =========================================================================
    // bfcache restoration — restore checkout button after back-navigation
    // =========================================================================
    //
    // When the user clicks "Proceed to Checkout", the button is set to a
    // loading/disabled state and the browser navigates away. If the user then
    // presses the Back button, the browser may restore the cart page from its
    // back-forward cache (bfcache) — a frozen DOM snapshot — meaning no scripts
    // re-run and the button stays permanently disabled.
    //
    // The 'pageshow' event fires on both normal loads AND bfcache restores.
    // event.persisted === true means the page came from bfcache, so we restore
    // any checkout button that is still stuck in loading state.

    window.addEventListener( 'pageshow', function ( event ) {
        if ( event.persisted ) {
            // Page was restored from bfcache — reset any stuck loading states.
            // v1.1.3: pre-1.1.3 only reset the checkout button. Now we also
            // clear any qty/variation/coupon/save-for-later loading classes
            // that could have been frozen mid-AJAX when the user navigated
            // away.
            var $btn = $( '.zymarg-checkout-btn' );
            if ( $btn.hasClass( 'zymarg-btn-loading' ) || $btn.prop( 'disabled' ) ) {
                $btn.removeClass( 'zymarg-btn-loading' ).prop( 'disabled', false );
                log( 'bfcache restore detected — checkout button re-enabled.' );
            }
            $( '.zymarg-loading' ).removeClass( 'zymarg-loading' );
            $( '.zymarg-btn-loading' ).removeClass( 'zymarg-btn-loading' ).prop( 'disabled', false );
        }
    } );

    // =========================================================================
    // Scroll restoration after move-to-cart reload (v1.1.3)
    // =========================================================================
    //
    // moveToCart() in zymarg-cart-ajax.js stashes window.scrollY into
    // sessionStorage just before triggering window.location.reload(). On the
    // next load we read it back and scroll the user to where they were so a
    // long cart page does not feel like it lost their place.

    $( function () {
        try {
            var saved = sessionStorage.getItem( 'zymargCartScrollY' );
            if ( saved !== null ) {
                sessionStorage.removeItem( 'zymargCartScrollY' );
                var y = parseInt( saved, 10 );
                if ( ! isNaN( y ) && y > 0 ) {
                    window.scrollTo( 0, y );
                }
            }
        } catch ( err ) { /* sessionStorage may be blocked */ }
    } );

    // =========================================================================
    // Boot
    // =========================================================================

    $( function () {
        syncSelectedKeys();
        bindEvents();
        checkRestoreSentinel();
        log( 'ZymargCart initialised. Selected keys: ' + _selectedKeys.length );
    } );

} )( jQuery, zymargCartData );
