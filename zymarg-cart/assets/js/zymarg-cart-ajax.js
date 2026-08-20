/**
 * ZYMARG Cart — Centralised AJAX module.
 *
 * Handles all HTTP communication between the frontend and the PHP AJAX
 * handlers registered in class-zymarg-cart-ajax.php.
 *
 * Responsibilities:
 *  - Core $.ajax wrapper with automatic nonce refresh.
 *  - Loading-state management for rows and buttons.
 *  - Targeted DOM updates after each successful response.
 *  - Inline coupon feedback display.
 *  - Error message rendering (WC notices or inline fallback).
 *  - Debounced quantity updates (400 ms) and totals recalculation (200 ms).
 *  - Public API consumed by zymarg-cart-checkbox.js, zymarg-cart-edit-mode.js,
 *    and zymarg-cart.js.
 *
 * Exposes: window.ZymargAjax
 *
 * Dependencies:
 *  - jQuery (global, enqueued by WordPress)
 *  - zymargCartData (wp_localize_script from Zymarg_Cart::build_localized_data)
 *
 * @package ZymargCart
 * @since   1.0.0
 */

/* global zymargCartData */
( function ( $, cfg ) {
    'use strict';

    if ( ! cfg || ! cfg.ajaxUrl ) {
        return; // zymargCartData not available — bail silently.
    }

    // =========================================================================
    // Module-level state
    // =========================================================================

    var _nonce       = cfg.nonce  || '';
    var _totalsTimer = null;

    // Per-row debounce timer for quantity updates (v1.1.3). Pre-1.1.3 this was
    // a single module-level timer shared by every product row, which meant
    // clicking +/- on Row B within 400 ms after Row A would silently cancel
    // Row A's pending AJAX and discard the change. Storing the timer on the
    // row's jQuery data() namespace gives each row its own debouncer.
    var QTY_TIMER_KEY = 'zymarg-qty-timer';

    /** Delay before firing a quantity update (ms). */
    var QTY_DEBOUNCE = 400;

    /** Delay before firing a totals recalculation (ms). */
    var TOTALS_DEBOUNCE = 200;

    // =========================================================================
    // Core request wrapper
    // =========================================================================

    /**
     * Sends a POST to WordPress admin-ajax.php and returns the jqXHR.
     * Automatically attaches the current nonce and refreshes it from every
     * response so long sessions never hit a 403.
     *
     * @param  {string} action  WordPress AJAX action name (e.g. 'zymarg_get_totals').
     * @param  {Object} payload Additional POST key/value pairs.
     * @return {jQuery.jqXHR}
     */
    function request( action, payload ) {
        return $.ajax( {
            url    : cfg.ajaxUrl,
            method : 'POST',
            data   : $.extend( { action: action, nonce: _nonce }, payload || {} ),
        } )
        .done( function ( res ) {
            if ( res && res.nonce ) {
                _nonce = res.nonce;
            }
        } )
        .fail( function ( jqXHR, status ) {
            log( 'Request failed: ' + action + ' [' + status + ']' );
        } );
    }

    // =========================================================================
    // Loading state helpers
    // =========================================================================

    /**
     * Toggles the .zymarg-loading CSS class on any element.
     *
     * @param {jQuery}  $el     Target element.
     * @param {boolean} active  True to add, false to remove.
     */
    function setLoading( $el, active ) {
        $el.toggleClass( 'zymarg-loading', active );
    }

    /**
     * Disables/re-enables a button and applies the .zymarg-btn-loading class.
     *
     * @param {jQuery}  $btn    Button element.
     * @param {boolean} active  True for loading state, false to restore.
     */
    function setButtonLoading( $btn, active ) {
        $btn.toggleClass( 'zymarg-btn-loading', active ).prop( 'disabled', active );
    }

    // =========================================================================
    // DOM update helpers
    // =========================================================================

    /**
     * Fades out and removes a row element, then runs an optional callback.
     *
     * @param {jQuery}   $row      Row element to remove.
     * @param {Function} [after]   Called after the element is removed.
     */
    function removeRow( $row, after ) {
        $row.addClass( 'zymarg-removing' ).fadeOut( 260, function () {
            $( this ).remove();
            if ( typeof after === 'function' ) {
                after();
            }
        } );
    }

    /**
     * Updates the subtotal cell in a product row identified by cart item key.
     *
     * @param {string} cartKey WC cart item key.
     * @param {string} html    Formatted price HTML from wc_price().
     */
    function updateRowSubtotal( cartKey, html ) {
        $( '[data-cart-key="' + cartKey + '"] .zymarg-col-subtotal' ).html( html );
    }

    /**
     * Updates the unit-price element(s) in a product row.
     *
     * v1.2.0: targets `.zymarg-product-price` (used by both the desktop
     * placement under the title and the mobile placement under the image).
     * Pre-1.2.0 targeted `.zymarg-unit-price-value` which was the small
     * "× qty" breakdown line that has been removed.
     *
     * @param {string} cartKey WC cart item key.
     * @param {string} html    Formatted price HTML.
     */
    function updateRowUnitPrice( cartKey, html ) {
        $( '[data-cart-key="' + cartKey + '"] .zymarg-product-price' ).html( html );
    }

    /**
     * Updates the × qty part of the unit-price breakdown in a product row.
     *
     * v1.2.0 NO-OP — the "× qty" breakdown line was removed in v1.2.0.
     * Function kept as a no-op so existing callers (changeVariation,
     * updateQuantity) continue to work without further refactoring.
     *
     * @param {string} cartKey WC cart item key (unused).
     * @param {number} qty     Quantity (unused).
     */
    function updateRowUnitPriceQty( cartKey, qty ) {
        // no-op (× qty line removed in v1.2.0).
    }

    /**
     * Updates the vendor subtotal row at the bottom of a vendor group.
     *
     * @param {number} vendorId      Dokan vendor user ID.
     * @param {string} subtotalHtml  Formatted price HTML.
     */
    function updateVendorSubtotal( vendorId, subtotalHtml ) {
        $( '.zymarg-vendor-block[data-vendor-id="' + vendorId + '"] .zymarg-vendor-subtotal-value' )
            .html( subtotalHtml );
    }

    /**
     * Pushes all totals data into Widget 3's breakdown panel and action bar.
     *
     * @param {Object} totals  The `totals` object from the PHP response.
     */
    function applyTotals( totals ) {
        if ( ! totals ) {
            return;
        }

        // ── Part A: breakdown rows ─────────────────────────────────────────
        $( '.zymarg-total-row--subtotal .zymarg-total-value' )
            .html( totals.subtotal_html || '' );

        var $discRow = $( '.zymarg-total-row--discount' );
        if ( totals.discount && totals.discount > 0 ) {
            $discRow.show()
                .find( '.zymarg-total-value' )
                .html( totals.discount_html || '' );
        } else {
            $discRow.hide();
        }

        $( '.zymarg-total-row--shipping .zymarg-total-value' )
            .html( totals.shipping_html || '' );
        $( '.zymarg-total-row--tax .zymarg-total-value' )
            .html( totals.tax_html || '' );
        $( '.zymarg-total-row--grand .zymarg-total-value' )
            .html( totals.grand_total_html || '' );

        // ── Part B: action bar ─────────────────────────────────────────────
        $( '.zymarg-action-grand-total' ).html( totals.grand_total_html || '' );

        // Selection count label: "2 of 4 selected".
        // Exclude rows that are mid-fadeout (have .zymarg-removing) so the
        // total reflects what will be visible after animations complete —
        // avoids a brief "0 of 5 selected" → "0 of 4 selected" flicker
        // immediately after a delete (v1.1.3).
        var total    = $( '.zymarg-product-row:not(.zymarg-removing)' ).length;
        var selected = totals.selected_count || 0;
        var label    = ( cfg.i18n.selectedOf || '%1$d of %2$d selected' )
            .replace( '%1$d', selected )
            .replace( '%2$d', total );
        $( '.zymarg-selected-label' ).text( label );

        // ── Subtotal bar (top of Widget 3) — v1.5.2 ───────────────────────
        // The subtotal bar's count + running amount were never being updated
        // by applyTotals(), so they stayed at their server-rendered "(0 of N
        // selected) — 0" values forever. Update them in step with the action
        // bar so selection changes reflect everywhere in Widget 3.
        $( '.zymarg-subtotal-bar-count' ).text( '(' + label + ')' );
        $( '.zymarg-subtotal-bar-amount' ).html( totals.subtotal_html || '' );

        // ── Per-vendor subtotals (selection-aware) (v1.0.8) ──────────────
        if ( totals.vendor_subtotals_html && typeof totals.vendor_subtotals_html === 'object' ) {
            $.each( totals.vendor_subtotals_html, function ( vendorId, html ) {
                updateVendorSubtotal( parseInt( vendorId, 10 ), html );
            } );
        }

        // ── Applied coupons list ───────────────────────────────────────────
        if ( Array.isArray( totals.coupons ) ) {
            applyCouponList( totals.coupons );
        }
    }

    /**
     * Re-renders the list of applied coupons in Widget 3.
     *
     * @param {Array} coupons  Array of coupon objects from PHP.
     */
    function applyCouponList( coupons ) {
        var $list = $( '.zymarg-applied-coupons' );
        if ( ! $list.length ) {
            return;
        }
        $list.empty();
        $.each( coupons, function ( _, c ) {
            $list.append(
                $( '<div>' )
                    .addClass( 'zymarg-applied-coupon' )
                    .attr( 'data-coupon', c.code )
                    .html(
                        '<span class="zymarg-coupon-code">' + escHtml( c.code ) + '</span>' +
                        '<span class="zymarg-coupon-disc">\u2013\u00a0' + c.discount_html + '</span>' +
                        '<button class="zymarg-remove-coupon" data-coupon="' + escAttr( c.code ) + '" ' +
                        'aria-label="' + escAttr( cfg.i18n.remove || 'Remove' ) + '">' +
                        '\u00d7</button>'
                    )
            );
        } );
    }

    /**
     * Updates Widget 1's item count text (e.g. "(4 items)").
     * Also triggers wc_fragment_refresh to keep the mini-cart in sync.
     *
     * @param {number} count New total cart item count.
     */
    function updateItemCount( count ) {
        var label = '(' + count + '\u00a0' + ( count === 1 ? 'item' : 'items' ) + ')';
        $( '.zymarg-item-count' ).text( label );
        $( document.body ).trigger( 'wc_fragment_refresh' );
    }

    /**
     * Updates the Saved-for-Later badge count.
     *
     * @param {number} count Current saved item count.
     */
    function updateSavedCount( count ) {
        $( '.zymarg-saved-count' ).text( count );
        $( '.zymarg-saved-section' ).toggleClass( 'zymarg-saved-empty', count === 0 );
    }

    /**
     * Shows the empty-cart illustration and hides the cart body + totals.
     * Called when the last cart item is removed.
     */
    function showEmptyState() {
        $( '.zymarg-cart-body, .zymarg-cart-total-widget' ).hide();
        $( '.zymarg-cart-empty' ).show();
    }

    // =========================================================================
    // Coupon feedback
    // =========================================================================

    /**
     * Shows inline coupon feedback (success / error) within a product row.
     *
     * @param {jQuery} $row      Product row containing .zymarg-coupon-feedback.
     * @param {Object} response  Full AJAX response object.
     */
    function showCouponFeedback( $row, response ) {
        var $fb = $row.find( '.zymarg-coupon-feedback' );
        if ( ! $fb.length ) {
            return;
        }
        $fb.stop( true )
            .removeClass( 'zymarg-coupon-success zymarg-coupon-error zymarg-coupon-expired' )
            .addClass( response.success ? 'zymarg-coupon-success' : 'zymarg-coupon-error' )
            .html( escHtml( response.message || '' ) )
            .fadeIn( 150 );

        if ( response.success ) {
            setTimeout( function () {
                $fb.fadeOut( 300 );
            }, 4000 );
        }
    }

    // =========================================================================
    // Error display
    // =========================================================================

    /**
     * Shows an error message using the WooCommerce notices area when available,
     * falling back to a temporary inline banner at the top of the cart wrapper.
     *
     * @param {string} message Human-readable error text (HTML allowed).
     */
    function showError( message ) {
        var $notices = $( '.woocommerce-notices-wrapper' ).first();
        if ( $notices.length ) {
            $notices.html(
                '<ul class="woocommerce-error" role="alert"><li>' + message + '</li></ul>'
            );
            $( 'html, body' ).animate(
                { scrollTop: ( $notices.offset() ? $notices.offset().top : 0 ) - 80 },
                300
            );
            return;
        }

        var $wrapper = $( '.zymarg-cart-wrapper' );
        var $err = $(
            '<div class="zymarg-inline-error" role="alert">' + message + '</div>'
        );
        $wrapper.prepend( $err );
        setTimeout( function () {
            $err.fadeOut( 300, function () { $( this ).remove(); } );
        }, 5000 );
    }

    // =========================================================================
    // Stock warning helper (used after variation change)
    // =========================================================================

    /**
     * Updates the stock-warning element in a product row based on PHP stock data.
     *
     * @param {jQuery} $row   Product row element.
     * @param {Object} stock  Stock info object { is_in_stock, low_stock, qty }.
     */
    function applyStockWarning( $row, stock ) {
        var $w = $row.find( '.zymarg-stock-warning' );
        if ( ! $w.length || ! stock ) {
            return;
        }
        if ( ! stock.is_in_stock ) {
            $w.html( cfg.i18n.outOfStock || 'Out of stock' )
                .addClass( 'zymarg-out-of-stock' )
                .show();
        } else if ( stock.low_stock && stock.qty !== null ) {
            var msg = ( cfg.i18n.lowStock || 'Only %d left' ).replace( '%d', stock.qty );
            $w.html( msg )
                .removeClass( 'zymarg-out-of-stock' )
                .addClass( 'zymarg-low-stock' )
                .show();
        } else {
            $w.hide();
        }
    }

    // =========================================================================
    // Utility
    // =========================================================================

    /** Escapes a string for safe insertion as HTML content. */
    function escHtml( str ) {
        return String( str )
            .replace( /&/g,  '&amp;'  )
            .replace( /</g,  '&lt;'   )
            .replace( />/g,  '&gt;'   )
            .replace( /"/g,  '&quot;' );
    }

    /**
     * Escapes a string for safe use in an HTML attribute value.
     *
     * v1.1.3: pre-1.1.3 only escaped single-quote on top of escHtml's basic set,
     * leaving some attribute-context edge cases under-escaped. Now performs the
     * full set used by every modern templating library.
     */
    function escAttr( str ) {
        return String( str || '' )
            .replace( /&/g,  '&amp;'  )
            .replace( /"/g,  '&quot;' )
            .replace( /'/g,  '&#39;'  )
            .replace( /</g,  '&lt;'   )
            .replace( />/g,  '&gt;'   );
    }

    /**
     * Dispatches a custom DOM event so other modules can react without tight
     * coupling. Falls back gracefully on browsers without CustomEvent.
     *
     * @param {string} name   Event name (e.g. 'zymarg:itemRemoved').
     * @param {*}      detail Arbitrary data attached to event.detail.
     */
    function emit( name, detail ) {
        var ev;
        try {
            ev = new CustomEvent( name, { detail: detail, bubbles: true } );
        } catch ( e ) {
            ev = document.createEvent( 'CustomEvent' );
            ev.initCustomEvent( name, true, false, detail );
        }
        document.dispatchEvent( ev );
    }

    /** Logs to console when cfg.debug is true. */
    function log( msg, extra ) {
        if ( cfg.debug && window.console ) {
            // eslint-disable-next-line no-console
            console.log( '[ZymargCart/Ajax] ' + msg, extra !== undefined ? extra : '' );
        }
    }

    // =========================================================================
    // Public API — window.ZymargAjax
    // =========================================================================

    window.ZymargAjax = {

        // ── Quantity ──────────────────────────────────────────────────────────

        /**
         * Updates a cart item's quantity (debounced 400 ms).
         *
         * @param {jQuery} $stepper   The qty stepper container element.
         * @param {string} cartKey    WC cart item key.
         * @param {number} qty        New quantity value (≥ 1).
         * @param {Array}  selected   Currently selected cart item keys.
         */
        updateQuantity : function ( $stepper, cartKey, qty, selected ) {
            var $row = $stepper.closest( '.zymarg-product-row' );

            // Cancel any prior pending update for THIS row only — other rows
            // each have their own timer and are unaffected (v1.1.3).
            var existingTimer = $row.data( QTY_TIMER_KEY );
            if ( existingTimer ) {
                clearTimeout( existingTimer );
            }

            var newTimer = setTimeout( function () {
                $row.removeData( QTY_TIMER_KEY );
                setLoading( $row, true );
                request( 'zymarg_update_quantity', {
                    cart_item_key : cartKey,
                    quantity      : qty,
                    selected_keys : JSON.stringify( selected || [] ),
                } ).done( function ( res ) {
                    if ( res.success ) {
                        var d = res.data;
                        updateRowSubtotal( cartKey, d.subtotal_html );
                        updateVendorSubtotal( d.vendor_id, d.vendor_subtotal_html );
                        applyTotals( d.totals );
                        updateItemCount( d.item_count );
                        emit( 'zymarg:quantityUpdated', d );
                    } else {
                        showError( res.message || cfg.i18n.error );
                        // Revert stepper to the stored previous value.
                        $row.find( '.zymarg-qty-value' ).text( $row.data( 'qty' ) || qty );
                    }
                } ).fail( function () {
                    showError( cfg.i18n.error );
                    // Revert stepper on network failure too (v1.1.3).
                    $row.find( '.zymarg-qty-value' ).text( $row.data( 'qty' ) || qty );
                } ).always( function () {
                    setLoading( $row, false );
                } );
            }, QTY_DEBOUNCE );

            $row.data( QTY_TIMER_KEY, newTimer );
        },

        // ── Variation ─────────────────────────────────────────────────────────

        /**
         * Replaces a cart item's variation (remove old + add new with new data).
         *
         * @param {jQuery} $select      The variation <select> element.
         * @param {string} cartKey      Current WC cart item key.
         * @param {number} variationId  New variation product ID.
         * @param {Object} attributes   Variation attribute map { 'attribute_pa_*': 'value' }.
         * @param {Array}  selected     Currently selected cart item keys.
         */
        changeVariation : function ( $select, cartKey, variationId, attributes, selected ) {
            var $row = $select.closest( '.zymarg-product-row' );

            // Snapshot the old cart-key so we can repair _selectedKeys if the
            // cart-key changes (v1.1.3 — fix for selectedKeys-during-flight race).
            var oldKey = cartKey;

            // Optimistically remove the old key from the global selected-keys
            // array immediately. If the user fires another action (e.g. qty
            // stepper) while this variation request is in flight, that other
            // action would otherwise send the now-stale old cart-key in its
            // selected_keys payload and the server would fail to find it.
            // The new key is added back when this request succeeds.
            if ( window.ZymargCart && Array.isArray( selected ) && selected.indexOf( oldKey ) !== -1 ) {
                var pruned = selected.filter( function ( k ) { return k !== oldKey; } );
                ZymargCart.updateSelectedKeys( pruned );
            }

            setLoading( $row, true );

            request( 'zymarg_change_variation', {
                cart_item_key : cartKey,
                variation_id  : variationId,
                attributes    : JSON.stringify( attributes || {} ),
                selected_keys : JSON.stringify( selected || [] ),
            } ).done( function ( res ) {
                if ( res.success ) {
                    var d = res.data;
                    var newKey = d.new_cart_key || cartKey;

                    // Update data-cart-key if the WC hash changed.
                    if ( d.new_cart_key && d.new_cart_key !== cartKey ) {
                        $row.attr( 'data-cart-key', d.new_cart_key );

                        // Also update the product checkbox inside this row.
                        // getCheckedKeys() reads checkbox.value and data-cart-key
                        // to build the selected-keys array sent to Widget 3.
                        // If these are not updated, the checkbox remains visually
                        // checked but carries the old (now invalid) WC cart hash,
                        // so Widget 3 excludes the item from the count and total —
                        // producing "2 of 5 selected" instead of "3 of 5".
                        $row.find( '.zymarg-product-cb' )
                            .val( d.new_cart_key )
                            .attr( 'data-cart-key', d.new_cart_key );
                    }

                    // Clear jQuery's internal data cache for 'variations' so the
                    // next variation change re-reads data-variations from the DOM
                    // attribute instead of returning the stale page-load value.
                    // Without this, the second variation switch resolves variationId = 0
                    // and silently aborts, leaving the old price on screen.
                    $row.removeData( 'variations' );

                    // Update prev-val attribute (NOT jQuery .data()) to the
                    // newly committed selection so any rollback on a future
                    // failed request uses the correct value (v1.1.3 — pre-1.1.3
                    // this overwrote prev-val with the NEW value BEFORE the
                    // AJAX call, making rollback a no-op).
                    $select.attr( 'data-prev-val', $select.val() );

                    // Sync selected keys with the server's authoritative list.
                    // If the server returned an updated set, use that. Otherwise
                    // re-add the new cart-key (we pruned the old one optimistically
                    // before sending the request).
                    if ( d.updated_selected_keys && window.ZymargCart ) {
                        ZymargCart.updateSelectedKeys( d.updated_selected_keys );
                    } else if ( window.ZymargCart && d.new_cart_key && Array.isArray( selected ) && selected.indexOf( oldKey ) !== -1 ) {
                        var current = ZymargCart.getSelectedKeys();
                        if ( current.indexOf( d.new_cart_key ) === -1 ) {
                            current.push( d.new_cart_key );
                            ZymargCart.updateSelectedKeys( current );
                        }
                    }

                    if ( d.merged ) {
                        // WooCommerce merged this row into an existing same-variation
                        // row. The surviving row (new_cart_key) already exists in the
                        // DOM. We update it with the combined quantity + subtotal, then
                        // fade out and remove the current (now-duplicate) row.
                        var $surviving = $( '[data-cart-key="' + newKey + '"]' );

                        if ( $surviving.length ) {
                            // Update surviving row's quantity stepper display.
                            // The stepper shows qty in .zymarg-qty-value (a <span>),
                            // not .zymarg-qty-input or [data-qty] — use correct selector.
                            $surviving.find( '.zymarg-qty-value' ).text( d.merged_quantity );
                            // Update product image to the new variation's image.
                            if ( d.variation_image_url ) {
                                $surviving.find( '.zymarg-product-img' )
                                    .attr( 'src', d.variation_image_url );
                            }
                            // Update price breakdown on surviving row.
                            updateRowUnitPrice( newKey, d.unit_price_html );
                            updateRowUnitPriceQty( newKey, d.merged_quantity );
                            updateRowSubtotal( newKey, d.merged_subtotal_html );
                            // Update the vendor subtotal bar at the bottom of the
                            // vendor block — was never updated on merge before this fix.
                            if ( d.vendor_id && d.vendor_subtotal_html ) {
                                updateVendorSubtotal( d.vendor_id, d.vendor_subtotal_html );
                            }
                        }

                        // Fade out and remove the changed (now-absorbed) row.
                        removeRow( $row, function () {
                            // Update vendor item count label.
                            var $vendorBlock = $surviving.closest( '.zymarg-vendor-block' );
                            if ( $vendorBlock.length ) {
                                var remaining = $vendorBlock.find( '.zymarg-product-row' ).length;
                                $vendorBlock.find( '.zymarg-vendor-item-count' ).text( remaining );
                            }
                        } );
                    } else {
                        // Normal variation switch — update the current row in place.
                        // Update product image to the newly selected variation's image.
                        if ( d.variation_image_url ) {
                            $row.find( '.zymarg-product-img' )
                                .attr( 'src', d.variation_image_url );
                        }
                        updateRowUnitPrice( newKey, d.unit_price_html );
                        updateRowUnitPriceQty( newKey, d.merged_quantity || $row.find( '.zymarg-qty-value' ).text() );
                        updateRowSubtotal( newKey, d.subtotal_html );
                        $row.find( '.zymarg-variation-labels' ).text( d.variation_labels || '' );
                        $row.find( '.zymarg-product-sku' ).text( d.sku || '' );
                        applyStockWarning( $row, d.stock );
                    }

                    applyTotals( d.totals );
                    emit( 'zymarg:variationChanged', d );
                } else {
                    showError( res.message || cfg.i18n.error );
                    // Roll back: read the previous value from the HTML attribute
                    // (which the template seeded and we update only on success).
                    // Pre-1.1.3 read from $select.data('prev-val'), which was
                    // overwritten with the NEW value before the request fired,
                    // so rollback was a silent no-op.
                    $select.val( $select.attr( 'data-prev-val' ) );
                    // Restore the original selected-keys (we pruned oldKey
                    // optimistically before the request).
                    if ( window.ZymargCart && Array.isArray( selected ) ) {
                        ZymargCart.updateSelectedKeys( selected );
                    }
                }
            } ).fail( function () {
                showError( cfg.i18n.error );
                $select.val( $select.attr( 'data-prev-val' ) );
                // Restore the original selected-keys on network failure too.
                if ( window.ZymargCart && Array.isArray( selected ) ) {
                    ZymargCart.updateSelectedKeys( selected );
                }
            } ).always( function () {
                setLoading( $row, false );
            } );
        },

        // ── Remove item ───────────────────────────────────────────────────────

        /**
         * Removes a cart item via the delete button in edit mode.
         *
         * Returns the underlying jQuery deferred so callers (notably the
         * edit-mode bulk-delete loop) can await each request before firing the
         * next one — this prevents the race condition where two parallel
         * remove requests both report `vendor_empty: false` because each one
         * read the cart before the other had committed (v1.1.3).
         *
         * @param  {jQuery} $row      The product row element.
         * @param  {string} cartKey   WC cart item key.
         * @param  {Array}  selected  Currently selected cart item keys.
         * @return {jQuery.jqXHR}     The AJAX deferred.
         */
        removeItem : function ( $row, cartKey, selected ) {
            setLoading( $row, true );
            return request( 'zymarg_remove_item', {
                cart_item_key : cartKey,
                selected_keys : JSON.stringify( selected || [] ),
            } ).done( function ( res ) {
                if ( res.success ) {
                    var d = res.data;
                    removeRow( $row, function () {
                        if ( d.vendor_empty ) {
                            $( '.zymarg-vendor-block[data-vendor-id="' + d.vendor_id + '"]' )
                                .fadeOut( 260, function () { $( this ).remove(); } );
                        } else {
                            // Defensive belt-and-suspenders (v1.1.3): even if the
                            // server reports the vendor still has items, double-
                            // check the DOM. This protects against any future
                            // race where the response's vendor_empty flag was
                            // computed from a stale read.
                            var $vBlock = $( '.zymarg-vendor-block[data-vendor-id="' + d.vendor_id + '"]' );
                            if ( $vBlock.length && $vBlock.find( '.zymarg-product-row' ).length === 0 ) {
                                $vBlock.fadeOut( 260, function () { $( this ).remove(); } );
                            }
                        }
                        applyTotals( d.totals );
                        updateItemCount( d.item_count );
                        if ( d.cart_empty ) {
                            showEmptyState();
                        }
                        emit( 'zymarg:itemRemoved', d );
                    } );
                } else {
                    setLoading( $row, false );
                    showError( res.message || cfg.i18n.error );
                }
            } ).fail( function () {
                setLoading( $row, false );
                showError( cfg.i18n.error );
            } );
        },

        // ── Coupons ───────────────────────────────────────────────────────────

        /**
         * Applies a coupon code to the cart.
         *
         * @param {jQuery} $row        Row containing the coupon input field.
         * @param {string} couponCode  Raw coupon code string.
         * @param {number} productId   Context product ID (0 = none).
         * @param {number} vendorId    Context vendor ID (0 = none).
         * @param {Array}  selected    Currently selected cart item keys.
         */
        applyCoupon : function ( $row, couponCode, productId, vendorId, selected ) {
            var $btn = $row.find( '.zymarg-coupon-apply' );
            setButtonLoading( $btn, true );

            request( 'zymarg_apply_coupon', {
                coupon_code   : couponCode,
                product_id    : productId || 0,
                vendor_id     : vendorId  || 0,
                selected_keys : JSON.stringify( selected || [] ),
            } ).done( function ( res ) {
                showCouponFeedback( $row, res );
                if ( res.success ) {
                    applyTotals( res.data.totals );
                    $row.find( '.zymarg-coupon-input' ).val( '' );
                    emit( 'zymarg:couponApplied', res.data );
                }
            } ).fail( function () {
                showCouponFeedback( $row, { success: false, message: cfg.i18n.error } );
            } ).always( function () {
                setButtonLoading( $btn, false );
            } );
        },

        /**
         * Removes an applied coupon from the cart.
         *
         * @param {string} couponCode  The coupon code to remove.
         * @param {Array}  selected    Currently selected cart item keys.
         */
        removeCoupon : function ( couponCode, selected ) {
            request( 'zymarg_remove_coupon', {
                coupon_code   : couponCode,
                selected_keys : JSON.stringify( selected || [] ),
            } ).done( function ( res ) {
                if ( res.success ) {
                    applyTotals( res.data.totals );
                    emit( 'zymarg:couponRemoved', { couponCode: couponCode } );
                } else {
                    showError( res.message || cfg.i18n.error );
                }
            } ).fail( function () {
                // v1.1.3: pre-1.1.3 silently ignored network failures here,
                // leaving the user uncertain whether the coupon was removed.
                showError( cfg.i18n.error || 'Error' );
            } );
        },

        // ── Save for later ────────────────────────────────────────────────────

        /**
         * Saves a cart item for later (removes from active cart).
         *
         * @param {jQuery} $row      Product row element.
         * @param {string} cartKey   WC cart item key.
         * @param {Array}  selected  Currently selected cart item keys.
         */
        saveForLater : function ( $row, cartKey, selected ) {
            setLoading( $row, true );
            request( 'zymarg_save_for_later', {
                cart_item_key : cartKey,
                selected_keys : JSON.stringify( selected || [] ),
            } ).done( function ( res ) {
                if ( res.success ) {
                    var d = res.data;
                    removeRow( $row, function () {
                        if ( d.vendor_empty ) {
                            $( '.zymarg-vendor-block[data-vendor-id="' + d.vendor_id + '"]' )
                                .fadeOut( 260, function () { $( this ).remove(); } );
                        }

                        // Inject the rendered saved item row HTML into the saved section
                        // immediately — no page reload needed.
                        if ( d.saved_item_html ) {
                            var $savedSection = $( '.zymarg-saved-section' );
                            var $savedItems   = $savedSection.find( '.zymarg-saved-items' );

                            if ( $savedItems.length ) {
                                // Section already exists — append the new row.
                                $savedItems.append( d.saved_item_html );
                            } else {
                                // Section not yet in DOM (first save) — build the
                                // wrapper and append after the last vendor block.
                                var $wrapper = $(
                                    '<div class="zymarg-saved-section">' +
                                        '<div class="zymarg-saved-header">' +
                                            '<span class="zymarg-saved-title">Saved for Later</span>' +
                                        '</div>' +
                                        '<div class="zymarg-saved-items"></div>' +
                                    '</div>'
                                );
                                $wrapper.find( '.zymarg-saved-items' ).append( d.saved_item_html );
                                $( '.zymarg-cart-body' ).append( $wrapper );
                                $savedSection = $wrapper;
                            }

                            // Ensure the section is visible.
                            $savedSection.removeClass( 'zymarg-saved-empty' ).show();
                        }

                        applyTotals( d.totals );
                        updateItemCount( d.item_count );
                        updateSavedCount( d.saved_count );
                        if ( d.cart_empty ) {
                            showEmptyState();
                        }
                        emit( 'zymarg:itemSaved', d );
                    } );
                } else {
                    setLoading( $row, false );
                    showError( res.message || cfg.i18n.error );
                }
            } ).fail( function () {
                setLoading( $row, false );
                showError( cfg.i18n.error );
            } );
        },

        /**
         * Moves a saved item back into the active WC cart.
         * Triggers a full page reload to rebuild vendor-grouped cart DOM.
         *
         * @param {jQuery} $savedRow  The saved item row element.
         * @param {string} itemKey    Save-for-Later item key (MD5 hash).
         * @param {Array}  selected   Currently selected cart item keys.
         */
        moveToCart : function ( $savedRow, itemKey, selected ) {
            setLoading( $savedRow, true );
            request( 'zymarg_move_to_cart', {
                item_key      : itemKey,
                selected_keys : JSON.stringify( selected || [] ),
            } ).done( function ( res ) {
                if ( res.success ) {
                    // v1.1.3: preserve scroll position across the reload so the
                    // user does not lose their place on a long cart page. The
                    // saved value is consumed by the page-load handler in
                    // zymarg-cart.js. Full AJAX-based DOM injection is planned
                    // for v1.2.0 — see Issue #7 in the v1.1.3 audit.
                    try {
                        sessionStorage.setItem( 'zymargCartScrollY', String( window.scrollY || window.pageYOffset || 0 ) );
                    } catch ( err ) { /* sessionStorage may be blocked */ }
                    window.location.reload();
                } else {
                    setLoading( $savedRow, false );
                    showError( res.message || cfg.i18n.error );
                }
            } ).fail( function () {
                setLoading( $savedRow, false );
                showError( cfg.i18n.error );
            } );
        },

        /**
         * Permanently removes an item from the Save-for-Later list.
         *
         * @param {jQuery} $savedRow  Saved item row element.
         * @param {string} itemKey    Save-for-Later item key.
         */
        removeSaved : function ( $savedRow, itemKey ) {
            setLoading( $savedRow, true );
            request( 'zymarg_remove_saved', { item_key: itemKey } )
                .done( function ( res ) {
                    if ( res.success ) {
                        removeRow( $savedRow, function () {
                            updateSavedCount( res.data.saved_count );
                            emit( 'zymarg:savedRemoved', res.data );
                        } );
                    } else {
                        setLoading( $savedRow, false );
                        showError( res.message || cfg.i18n.error );
                    }
                } ).fail( function () {
                    setLoading( $savedRow, false );
                    showError( cfg.i18n.error );
                } );
        },

        // ── Totals ────────────────────────────────────────────────────────────

        /**
         * Recalculates Widget 3 totals for the current selection (debounced 200 ms).
         * Called by zymarg-cart-checkbox.js on every checkbox state change.
         *
         * @param {Array} selectedKeys Array of currently selected cart item keys.
         */
        getTotals : function ( selectedKeys ) {
            clearTimeout( _totalsTimer );
            _totalsTimer = setTimeout( function () {
                request( 'zymarg_get_totals', {
                    selected_keys : JSON.stringify( selectedKeys || [] ),
                } ).done( function ( res ) {
                    if ( res.success ) {
                        applyTotals( res.data.totals );
                    }
                } ).fail( function () {
                    // v1.1.3: pre-1.1.3 silently ignored network failures here,
                    // leaving stale totals on screen with no user feedback.
                    showError( cfg.i18n.error || 'Error' );
                } );
            }, TOTALS_DEBOUNCE );
        },

        // ── Partial checkout ──────────────────────────────────────────────────

        /**
         * Initiates Solution 1 partial checkout:
         * backs up full cart, strips unselected items, redirects to checkout.
         *
         * @param {Array}  selectedKeys  Cart item keys to check out.
         * @param {jQuery} $button       The "Proceed to Checkout" button.
         */
        partialCheckout : function ( selectedKeys, $button ) {
            if ( ! selectedKeys || selectedKeys.length === 0 ) {
                showError(
                    cfg.i18n.selectItems ||
                    'Please select at least one item to checkout.'
                );
                return;
            }
            setButtonLoading( $button, true );
            request( 'zymarg_partial_checkout', {
                selected_keys : JSON.stringify( selectedKeys ),
            } ).done( function ( res ) {
                if ( res.success && res.data && res.data.checkout_url ) {
                    window.location.href = res.data.checkout_url;
                    // Keep button loading during redirect.
                } else {
                    setButtonLoading( $button, false );
                    showError( res.message || cfg.i18n.error );
                }
            } ).fail( function () {
                setButtonLoading( $button, false );
                showError( cfg.i18n.error );
            } );
        },

        // ── Cart restore ──────────────────────────────────────────────────────

        /**
         * Explicitly triggers a server-side cart restore via AJAX.
         * Reloads the page after a successful restore to rebuild the DOM.
         */
        restoreCart : function () {
            request( 'zymarg_restore_cart', {} ).done( function ( res ) {
                if ( res.success && res.data && res.data.restored ) {
                    window.location.reload();
                }
            } );
        },

        // ── Exposed helpers for other modules ────────────────────────────────

        /**
         * Directly applies a totals object to the DOM.
         * Called by other modules that receive totals as part of a larger response.
         *
         * @param {Object} totals Totals object from PHP.
         */
        applyTotals : applyTotals,

        /**
         * Shows an error message.
         * @param {string} message
         */
        showError : showError,

        /** @private Internal log helper. */
        log : function ( msg, extra ) {
            log( msg, extra );
        },
    };

} )( jQuery, zymargCartData );
