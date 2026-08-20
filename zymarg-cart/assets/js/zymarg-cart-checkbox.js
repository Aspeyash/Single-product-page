/**
 * ZYMARG Cart — Checkbox interconnection module.
 *
 * Manages the three-level checkbox hierarchy:
 *
 *   Master checkbox (Widget 3)
 *     └─ Vendor checkboxes (one per vendor group in Widget 2)
 *          └─ Product checkboxes (one per product row)
 *
 * Cascade rules:
 *   Product cb change  → recalculate vendor cb state → recalculate master cb state
 *                        → emit zymarg:selectionChanged → debounced getTotals
 *
 *   Vendor cb change   → set all its products checked/unchecked
 *                        → recalculate master cb state
 *                        → emit zymarg:selectionChanged → debounced getTotals
 *
 *   Master cb change   → set ALL vendor + product checkboxes to the same state
 *                        → emit zymarg:selectionChanged → debounced getTotals
 *
 * Edit-mode separation:
 *   When <body> has .zymarg-edit-mode, product cb changes only update the
 *   delete-button state — no selection cascade or totals recalculation occurs.
 *
 * Dependencies: jQuery (global), zymargCartData (wp_localize_script),
 *               ZymargCart (zymarg-cart.js), ZymargAjax (zymarg-cart-ajax.js).
 *
 * @package ZymargCart
 * @since   1.0.0
 */

/* global zymargCartData, ZymargCart, ZymargAjax */
( function ( $, cfg ) {
    'use strict';

    if ( ! cfg ) {
        return;
    }

    // =========================================================================
    // DOM selectors (centralised so any HTML rename is a one-line fix)
    // =========================================================================

    var SEL_PRODUCT_CB = '.zymarg-product-cb';
    var SEL_VENDOR_CB  = '.zymarg-vendor-cb';
    var SEL_MASTER_CB  = '.zymarg-master-cb';
    var SEL_PRODUCT_ROW = '.zymarg-product-row';
    var SEL_VENDOR_BLOCK = '.zymarg-vendor-block';

    // =========================================================================
    // State helpers
    // =========================================================================

    /**
     * Returns jQuery set of product checkboxes within a vendor group.
     *
     * @param  {string|number} vendorId  Dokan vendor ID.
     * @returns {jQuery}
     */
    function getProductCbsForVendor( vendorId ) {
        return $(
            SEL_VENDOR_BLOCK + '[data-vendor-id="' + vendorId + '"] ' + SEL_PRODUCT_CB
        );
    }

    /**
     * Returns an array of WC cart item keys for all checked product checkboxes.
     *
     * @returns {Array<string>}
     */
    function getCheckedKeys() {
        var keys = [];
        $( SEL_PRODUCT_CB ).each( function () {
            if ( this.checked ) {
                var key = $( this ).val() || $( this ).data( 'cart-key' );
                if ( key && keys.indexOf( key ) === -1 ) {
                    keys.push( key );
                }
            }
        } );
        return keys;
    }

    // =========================================================================
    // Checkbox state updaters
    // =========================================================================

    /**
     * Recalculates and applies the checked / indeterminate / unchecked state of
     * a vendor checkbox based on how many of its product checkboxes are checked.
     *
     * @param {string|number} vendorId
     */
    function updateVendorCbState( vendorId ) {
        var $vc       = $( SEL_VENDOR_CB + '[data-vendor-id="' + vendorId + '"]' );
        if ( ! $vc.length ) {
            return;
        }

        var $pcs    = getProductCbsForVendor( vendorId );
        var total   = $pcs.length;
        var checked = $pcs.filter( ':checked' ).length;

        if ( checked === 0 ) {
            $vc.prop( 'checked', false );
            $vc[ 0 ].indeterminate = false;
        } else if ( checked === total ) {
            $vc.prop( 'checked', true );
            $vc[ 0 ].indeterminate = false;
        } else {
            $vc.prop( 'checked', false );
            $vc[ 0 ].indeterminate = true;
        }
    }

    /**
     * Recalculates and applies the checked / indeterminate / unchecked state of
     * the master checkbox (Widget 3) based on ALL product checkboxes.
     */
    function updateMasterCbState() {
        var $mc     = $( SEL_MASTER_CB );
        if ( ! $mc.length ) {
            return;
        }

        var $all    = $( SEL_PRODUCT_CB );
        var total   = $all.length;
        var checked = $all.filter( ':checked' ).length;

        if ( checked === 0 ) {
            $mc.prop( 'checked', false );
            $mc[ 0 ].indeterminate = false;
        } else if ( checked === total ) {
            $mc.prop( 'checked', true );
            $mc[ 0 ].indeterminate = false;
        } else {
            $mc.prop( 'checked', false );
            $mc[ 0 ].indeterminate = true;
        }
    }

    /**
     * Recalculates ALL vendor checkbox states then the master checkbox state.
     * Cheap to call after bulk changes (vendor or master toggle).
     */
    function updateAllCbStates() {
        $( SEL_VENDOR_CB ).each( function () {
            var vid = $( this ).data( 'vendor-id' );
            if ( vid ) {
                updateVendorCbState( vid );
            }
        } );
        updateMasterCbState();
    }

    // =========================================================================
    // Selection change → broadcast + totals
    // =========================================================================

    /**
     * Called after any checkbox state change in normal (non-edit) mode.
     * Broadcasts the new selected-keys array and triggers a debounced
     * totals recalculation in Widget 3.
     */
    function onSelectionChanged() {
        var keys = getCheckedKeys();

        // Update ZymargCart global state.
        if ( window.ZymargCart ) {
            ZymargCart.updateSelectedKeys( keys );
        }

        // Debounced totals request (200 ms, defined in zymarg-cart-ajax.js).
        if ( window.ZymargAjax ) {
            ZymargAjax.getTotals( keys );
        }

        // Emit event so other modules can react (edit-mode, main module).
        var detail = { selectedKeys: keys };
        var ev;
        try {
            ev = new CustomEvent( 'zymarg:selectionChanged', {
                detail  : detail,
                bubbles : true,
            } );
        } catch ( err ) {
            ev = document.createEvent( 'CustomEvent' );
            ev.initCustomEvent( 'zymarg:selectionChanged', true, false, detail );
        }
        document.dispatchEvent( ev );
    }

    // =========================================================================
    // Edit-mode guard
    // =========================================================================

    /**
     * Returns true when the cart is in edit/delete mode (body has the CSS class).
     * In edit mode, product checkbox changes only update the delete button — they
     * must not trigger selection cascade or totals recalculation.
     *
     * @returns {boolean}
     */
    function isEditMode() {
        return document.body.classList.contains( 'zymarg-edit-mode' );
    }

    // =========================================================================
    // Event listeners
    // =========================================================================

    /**
     * Individual product checkbox change.
     * In edit mode → notify edit-mode module only.
     * In normal mode → cascade up to vendor + master, then broadcast.
     */
    $( document ).on( 'change', SEL_PRODUCT_CB, function () {
        if ( isEditMode() ) {
            // Delegate to edit-mode module for delete-button state management.
            var ev;
            try {
                ev = new CustomEvent( 'zymarg:editCheckboxChanged', { bubbles: true } );
            } catch ( err ) {
                ev = document.createEvent( 'CustomEvent' );
                ev.initCustomEvent( 'zymarg:editCheckboxChanged', true, false, null );
            }
            document.dispatchEvent( ev );
            return;
        }

        var vendorId = $( this ).data( 'vendor-id' ) ||
            $( this ).closest( SEL_PRODUCT_ROW ).data( 'vendor-id' );

        if ( vendorId ) {
            updateVendorCbState( vendorId );
        }
        updateMasterCbState();
        onSelectionChanged();
    } );

    /**
     * Vendor checkbox change → cascade down to all its products, then up.
     */
    $( document ).on( 'change', SEL_VENDOR_CB, function () {
        if ( isEditMode() ) {
            return;
        }

        var $vc      = $( this );
        var vendorId = $vc.data( 'vendor-id' );
        var checked  = $vc.prop( 'checked' );

        // Clear indeterminate.
        $vc[ 0 ].indeterminate = false;

        // Apply to all products in this vendor group.
        getProductCbsForVendor( vendorId ).prop( 'checked', checked );

        updateMasterCbState();
        onSelectionChanged();
    } );

    /**
     * Master checkbox change → cascade to ALL vendors and ALL products.
     */
    $( document ).on( 'change', SEL_MASTER_CB, function () {
        if ( isEditMode() ) {
            return;
        }

        var checked = $( this ).prop( 'checked' );

        // Clear indeterminate on master.
        this.indeterminate = false;

        // Set all product checkboxes.
        $( SEL_PRODUCT_CB ).prop( 'checked', checked );

        // Set all vendor checkboxes (clear their indeterminate too).
        $( SEL_VENDOR_CB ).each( function () {
            this.indeterminate = false;
            $( this ).prop( 'checked', checked );
        } );

        onSelectionChanged();
    } );

    // =========================================================================
    // Public sync — exposed for use after AJAX DOM mutations
    // =========================================================================

    /**
     * Rebuilds the entire checkbox state tree from current DOM.
     * Call after product rows are added or removed (e.g. move-to-cart reload).
     */
    window.ZymargCheckbox = {
        syncAll : function () {
            updateAllCbStates();
            onSelectionChanged();
        },
        updateVendorCbState  : updateVendorCbState,
        updateMasterCbState  : updateMasterCbState,
        getCheckedKeys       : getCheckedKeys,
    };

    // =========================================================================
    // Init — set correct states from server-rendered DOM
    // =========================================================================

    $( function () {
        updateAllCbStates();
        // Seed ZymargCart with initial selection without firing a getTotals call
        // (page just loaded, totals are already rendered by PHP).
        if ( window.ZymargCart ) {
            ZymargCart.updateSelectedKeys( getCheckedKeys() );
        }
    } );

} )( jQuery, zymargCartData );
