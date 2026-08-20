/**
 * ZYMARG Cart — Edit mode (delete mode) toggle module.
 *
 * Manages the two-state edit toggle in Widget 1 (Cart Header):
 *
 * NORMAL MODE (default):
 *   Product checkboxes → checkout selection.
 *   Delete button hidden.
 *   Edit button shows "Edit" label.
 *
 * EDIT MODE (.zymarg-edit-mode on <body>):
 *   Product checkboxes → deletion selection (no totals recalc).
 *   Delete button visible, disabled until ≥ 1 product is checked.
 *   Edit button shows "Done" label.
 *   Delete button click → optional confirmation → removes selected items
 *     sequentially via ZymargAjax.removeItem() → exits edit mode when done.
 *
 * CSS responsibilities (defined in zymarg-cart.css, not here):
 *   .zymarg-edit-mode .zymarg-delete-btn   { display: flex; }
 *   .zymarg-edit-mode .zymarg-save-later-btn { display: none; }
 *   .zymarg-edit-mode .zymarg-product-row  { ... visual cues ... }
 *
 * Dependencies: jQuery (global), zymargCartData (wp_localize_script),
 *               ZymargAjax (zymarg-cart-ajax.js).
 *
 * @package ZymargCart
 * @since   1.0.0
 */

/* global zymargCartData, ZymargAjax, ZymargCheckbox */
( function ( $, cfg ) {
    'use strict';

    if ( ! cfg ) {
        return;
    }

    // =========================================================================
    // Constants
    // =========================================================================

    var EDIT_CLASS     = 'zymarg-edit-mode';
    var SEL_EDIT_BTN   = '.zymarg-edit-btn';
    var SEL_DEL_BTN    = '.zymarg-delete-btn';
    var SEL_PRODUCT_CB = '.zymarg-product-cb';

    // =========================================================================
    // Enter edit mode
    // =========================================================================

    /**
     * Activates edit/delete mode.
     *  - Adds EDIT_CLASS to <body> (CSS shows delete button, hides save-for-later).
     *  - Unchecks all product checkboxes (clean slate for deletion selection).
     *  - Updates edit button label to "Done".
     *  - Disables delete button (nothing selected yet).
     *
     * @param {jQuery} $editBtn  The edit button that was clicked.
     */
    function enterEditMode( $editBtn ) {
        document.body.classList.add( EDIT_CLASS );

        // Uncheck all product checkboxes — fresh deletion selection state.
        $( SEL_PRODUCT_CB ).prop( 'checked', false );

        // v1.1.3: also clear the global selected-keys array. Pre-1.1.3 the
        // unchecking above did not propagate to ZymargCart._selectedKeys
        // because the checkbox module's edit-mode guard short-circuits the
        // selection cascade — so any code reading getSelectedKeys() while in
        // edit mode would receive a stale list.
        if ( window.ZymargCart ) {
            ZymargCart.updateSelectedKeys( [] );
        }

        // Swap label: "Edit" → "Done".
        $editBtn.find( '.zymarg-btn-label' )
            .text( $editBtn.data( 'done-label' ) || cfg.i18n.done || 'Done' );
        $editBtn
            .find( '.zymarg-btn-icon' )
            .removeClass( 'ti-edit' )
            .addClass( 'ti-check' );
        $editBtn.attr( 'aria-pressed', 'true' );

        // Locate and disable the delete button.
        var $delBtn = findDeleteBtn( $editBtn );
        $delBtn.prop( 'disabled', true ).attr( 'aria-disabled', 'true' );
    }

    // =========================================================================
    // Exit edit mode
    // =========================================================================

    /**
     * Deactivates edit/delete mode.
     *  - Removes EDIT_CLASS from <body>.
     *  - Re-checks all product checkboxes (restore checkout selection).
     *  - Updates edit button label back to "Edit".
     *  - Resyncs vendor + master checkbox states.
     *
     * @param {jQuery} $editBtn  The edit button.
     */
    function exitEditMode( $editBtn ) {
        document.body.classList.remove( EDIT_CLASS );

        // Do NOT re-check checkboxes on exit — checkboxes should only be checked
        // when the user explicitly selects them. Re-checking here was causing all
        // remaining items to appear selected after a deletion, contradicting the
        // "unchecked by default" behaviour.

        // Swap label: "Done" → "Edit".
        $editBtn.find( '.zymarg-btn-label' )
            .text( $editBtn.data( 'edit-label' ) || cfg.i18n.edit || 'Edit' );
        $editBtn
            .find( '.zymarg-btn-icon' )
            .removeClass( 'ti-check' )
            .addClass( 'ti-edit' );
        $editBtn.attr( 'aria-pressed', 'false' );

        // Resyncs vendor + master checkboxes and fires a getTotals call.
        if ( window.ZymargCheckbox ) {
            ZymargCheckbox.syncAll();
        }
    }

    // =========================================================================
    // Delete button state
    // =========================================================================

    /**
     * Enables or disables the delete button based on whether any product
     * checkboxes are currently checked in edit mode.
     */
    function updateDeleteBtnState() {
        var anyChecked = $( SEL_PRODUCT_CB + ':checked' ).length > 0;
        $( SEL_DEL_BTN )
            .prop( 'disabled', ! anyChecked )
            .attr( 'aria-disabled', anyChecked ? 'false' : 'true' );
    }

    // =========================================================================
    // Delete selected items
    // =========================================================================

    /**
     * Collects all checked product rows and removes them sequentially via AJAX.
     * After all deletions, exits edit mode.
     *
     * Sequential (not parallel) deletion prevents race conditions in WC cart
     * session recalculations and ensures each removeItem response is processed
     * before the next call fires.
     *
     * @param {jQuery} $delBtn  The delete button that was pressed.
     */
    function deleteSelectedItems( $delBtn ) {
        // Collect targets before any DOM changes.
        var targets = [];
        $( SEL_PRODUCT_CB + ':checked' ).each( function () {
            var $row = $( this ).closest( '.zymarg-product-row' );
            var key  = $row.data( 'cart-key' );
            if ( key ) {
                targets.push( { key: key, $row: $row } );
            }
        } );

        if ( ! targets.length ) {
            return;
        }

        // Confirmation dialog (if enabled on the delete button).
        if ( '1' === String( $delBtn.data( 'confirm' ) ) ) {
            var confirmText = $delBtn.data( 'confirm-text' ) ||
                cfg.i18n.confirmDelete ||
                'Are you sure you want to remove the selected items?';

            // Use branded modal instead of native window.confirm().
            ZymargConfirmModal.show( {
                message:     confirmText,
                count:       targets.length,
                onConfirm:   function () { proceedDelete( $delBtn, targets ); },
            } );
            return; // Modal will call proceedDelete on confirm.
        }

        proceedDelete( $delBtn, targets );
    }

    /**
     * Called after the user confirms deletion (from the modal or directly
     * when confirm dialog is disabled). Shared so the code path is identical
     * whether or not a confirmation step is needed.
     *
     * @param {jQuery} $delBtn
     * @param {Array}  targets
     */
    function proceedDelete( $delBtn, targets ) {
        // Disable the delete button during the operation.
        $delBtn.prop( 'disabled', true ).attr( 'aria-disabled', 'true' );

        var $editBtn = findEditBtn( $delBtn );
        var index    = 0;

        /**
         * Recursively removes items one at a time, awaiting each AJAX response
         * before firing the next. v1.1.3: pre-1.1.3 used setTimeout(120) which
         * fired the next request before the previous one had committed, causing
         * race conditions where two parallel deletions both reported
         * vendor_empty: false (each read the cart before the other had
         * committed) and the empty vendor block stayed visible — and one of
         * the two items might not actually be deleted server-side at all.
         */
        function removeNext() {
            if ( index >= targets.length ) {
                // All done — exit edit mode.
                exitEditMode( $editBtn );
                return;
            }

            var target = targets[ index++ ];

            if ( ! target.$row.length || ! target.$row.parent().length ) {
                // Row already removed (e.g. vendor group cleared by earlier call).
                setTimeout( removeNext, 0 );
                return;
            }

            if ( ! window.ZymargAjax ) {
                // ZymargAjax not loaded — bail.
                exitEditMode( $editBtn );
                return;
            }

            var deferred = ZymargAjax.removeItem(
                target.$row,
                target.key,
                [] // Empty selectedKeys — we're deleting, not checking out.
            );

            // True sequential — wait for the server to finish before firing the
            // next request. Use .always() so a failed request also advances the
            // queue (otherwise a single error would freeze the whole bulk
            // delete). A small 80 ms gap between iterations gives the
            // remove-row fade animation visual breathing room.
            if ( deferred && typeof deferred.always === 'function' ) {
                deferred.always( function () {
                    setTimeout( removeNext, 80 );
                } );
            } else {
                // Fallback for any caller / version that doesn't return a
                // deferred — keep the original behaviour rather than hanging.
                setTimeout( removeNext, 400 );
            }
        }

        removeNext();
    }

    // =========================================================================
    // DOM helpers
    // =========================================================================

    /**
     * Finds the delete button in the same header row as the edit button.
     *
     * @param  {jQuery} $editBtn
     * @returns {jQuery}
     */
    function findDeleteBtn( $editBtn ) {
        return $editBtn.closest( '.zymarg-header-right, .zymarg-cart-header' )
                       .find( SEL_DEL_BTN );
    }

    /**
     * Finds the edit button in the same header row as the delete button.
     *
     * @param  {jQuery} $delBtn
     * @returns {jQuery}
     */
    function findEditBtn( $delBtn ) {
        return $delBtn.closest( '.zymarg-header-right, .zymarg-cart-header' )
                      .find( SEL_EDIT_BTN );
    }

    // =========================================================================
    // Event listeners
    // =========================================================================

    /** Edit button click — toggle edit mode on/off. */
    $( document ).on( 'click', SEL_EDIT_BTN, function ( e ) {
        e.preventDefault();
        var $btn    = $( this );
        var inEdit  = document.body.classList.contains( EDIT_CLASS );
        inEdit ? exitEditMode( $btn ) : enterEditMode( $btn );
    } );

    /** Delete button click — remove selected items. */
    $( document ).on( 'click', SEL_DEL_BTN + ':not([disabled])', function ( e ) {
        e.preventDefault();
        deleteSelectedItems( $( this ) );
    } );

    /**
     * Product checkbox change in edit mode → update delete button state.
     * The checkbox module fires this event to avoid direct coupling.
     */
    document.addEventListener( 'zymarg:editCheckboxChanged', function () {
        if ( document.body.classList.contains( EDIT_CLASS ) ) {
            updateDeleteBtnState();
        }
    } );

    /**
     * Keyboard: Escape key exits edit mode from anywhere on the page.
     */
    $( document ).on( 'keydown', function ( e ) {
        if ( e.key === 'Escape' && document.body.classList.contains( EDIT_CLASS ) ) {
            var $editBtn = $( SEL_EDIT_BTN ).first();
            if ( $editBtn.length ) {
                exitEditMode( $editBtn );
            }
        }
    } );

    // =========================================================================
    // Init — ensure delete button is hidden on load (CSS also handles this,
    // but set as a safe fallback in case CSS is slow to load).
    // =========================================================================

    $( function () {
        // Only hide if <body> doesn't already have the edit class.
        if ( ! document.body.classList.contains( EDIT_CLASS ) ) {
            $( SEL_DEL_BTN ).hide();
        }
    } );

} )( jQuery, zymargCartData );

/* =============================================================================
   ZymargConfirmModal — branded delete confirmation modal.
   Replaces the native browser window.confirm() with a ZYMARG-styled dialog.
   Pure visual upgrade — identical confirmation logic, zero functionality change.
   v2.0.2
   ============================================================================= */

window.ZymargConfirmModal = ( function () {
    'use strict';

    var modalEl   = null;
    var overlayEl = null;
    var _onConfirm = null;

    // ── Build modal HTML (once) ───────────────────────────────────────────────
    function build() {
        if ( modalEl ) return;

        // Overlay
        overlayEl = document.createElement( 'div' );
        overlayEl.className = 'zcm-overlay';
        overlayEl.setAttribute( 'role', 'presentation' );
        overlayEl.setAttribute( 'aria-hidden', 'true' );

        // Modal
        modalEl = document.createElement( 'div' );
        modalEl.className  = 'zcm-modal';
        modalEl.setAttribute( 'role', 'alertdialog' );
        modalEl.setAttribute( 'aria-modal', 'true' );
        modalEl.setAttribute( 'aria-labelledby', 'zcm-title' );
        modalEl.setAttribute( 'aria-describedby', 'zcm-message' );
        modalEl.setAttribute( 'tabindex', '-1' );

        modalEl.innerHTML = [
            '<div class="zcm-icon-wrap" aria-hidden="true">',
            '  <svg class="zcm-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"',
            '       stroke-width="2" stroke-linecap="round" stroke-linejoin="round">',
            '    <path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/>',
            '    <path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2l1-12"/>',
            '    <path d="M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/>',
            '  </svg>',
            '</div>',
            '<h2 class="zcm-title" id="zcm-title">Remove Items</h2>',
            '<p class="zcm-message" id="zcm-message"></p>',
            '<div class="zcm-count-badge" id="zcm-count"></div>',
            '<div class="zcm-actions">',
            '  <button type="button" class="zcm-btn zcm-btn--cancel" id="zcm-cancel">Keep Items</button>',
            '  <button type="button" class="zcm-btn zcm-btn--confirm" id="zcm-confirm">Yes, Remove</button>',
            '</div>',
        ].join( '\n' );

        document.body.appendChild( overlayEl );
        document.body.appendChild( modalEl );

        // Inject styles
        injectStyles();

        // Wire events
        document.getElementById( 'zcm-cancel' ).addEventListener( 'click', hide );
        document.getElementById( 'zcm-confirm' ).addEventListener( 'click', function () {
            // Capture the callback BEFORE hide() nulls _onConfirm.
            var callback = _onConfirm;
            hide();
            if ( typeof callback === 'function' ) {
                callback();
            }
        } );

        overlayEl.addEventListener( 'click', hide );

        document.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Escape' && overlayEl && overlayEl.classList.contains( 'zcm-visible' ) ) {
                hide();
            }
        } );
    }

    // ── Show ─────────────────────────────────────────────────────────────────
    function show( opts ) {
        build();

        opts       = opts || {};
        _onConfirm = opts.onConfirm || null;

        var count   = opts.count || 0;
        var message = opts.message ||
            'Are you sure you want to remove the selected items? This cannot be undone.';

        document.getElementById( 'zcm-message' ).textContent = message;

        var countBadge = document.getElementById( 'zcm-count' );
        if ( count > 0 ) {
            countBadge.textContent = count === 1
                ? '1 item will be removed'
                : count + ' items will be removed';
            countBadge.style.display = 'inline-flex';
        } else {
            countBadge.style.display = 'none';
        }

        overlayEl.classList.add( 'zcm-visible' );
        modalEl.classList.add( 'zcm-visible' );
        modalEl.focus();

        // Trap focus inside modal
        trapFocus( modalEl );
    }

    // ── Hide ─────────────────────────────────────────────────────────────────
    function hide() {
        if ( ! modalEl ) return;
        overlayEl.classList.remove( 'zcm-visible' );
        modalEl.classList.remove( 'zcm-visible' );
        _onConfirm = null;
    }

    // ── Focus trap ───────────────────────────────────────────────────────────
    function trapFocus( el ) {
        var focusable = el.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        );
        if ( ! focusable.length ) return;
        focusable[ focusable.length - 1 ].focus(); // Focus confirm by default
    }

    // ── Inject CSS ───────────────────────────────────────────────────────────
    function injectStyles() {
        if ( document.getElementById( 'zcm-styles' ) ) return;

        var css = [
            '/* ZymargConfirmModal styles v2.0.2 */',

            /* Overlay */
            '.zcm-overlay {',
            '  position: fixed; inset: 0; z-index: 99998;',
            '  background: rgba(19, 27, 46, 0.52);',
            '  backdrop-filter: blur(3px);',
            '  opacity: 0; visibility: hidden;',
            '  transition: opacity 0.22s ease, visibility 0.22s ease;',
            '}',
            '.zcm-overlay.zcm-visible {',
            '  opacity: 1; visibility: visible;',
            '}',

            /* Modal */
            '.zcm-modal {',
            '  position: fixed; z-index: 99999;',
            '  top: 50%; left: 50%;',
            '  transform: translate(-50%, -48%) scale(0.96);',
            '  width: 90%; max-width: 400px;',
            '  background: #ffffff;',
            '  border-radius: 20px;',
            '  padding: 32px 28px 24px;',
            '  text-align: center;',
            '  box-shadow: 0 24px 60px rgba(19, 27, 46, 0.20), 0 4px 16px rgba(149, 0, 165, 0.12);',
            '  border: 0.5px solid #e8d5f5;',
            '  opacity: 0; visibility: hidden;',
            '  transition: opacity 0.22s ease, transform 0.22s ease, visibility 0.22s ease;',
            '  outline: none;',
            '}',
            '.zcm-modal.zcm-visible {',
            '  opacity: 1; visibility: visible;',
            '  transform: translate(-50%, -50%) scale(1);',
            '}',

            /* Icon */
            '.zcm-icon-wrap {',
            '  width: 64px; height: 64px;',
            '  border-radius: 50%;',
            '  background: linear-gradient(135deg, #fcebeb 0%, #faeeda 100%);',
            '  border: 2px solid #f5c1c1;',
            '  display: flex; align-items: center; justify-content: center;',
            '  margin: 0 auto 18px;',
            '}',
            '.zcm-icon {',
            '  width: 28px; height: 28px;',
            '  color: #a32d2d;',
            '  stroke: #a32d2d;',
            '}',

            /* Title */
            '.zcm-title {',
            '  font-size: 18px; font-weight: 700;',
            '  color: #131b2e;',
            '  margin: 0 0 10px;',
            '  letter-spacing: -0.01em;',
            '}',

            /* Message */
            '.zcm-message {',
            '  font-size: 14px; line-height: 1.6;',
            '  color: #534152;',
            '  margin: 0 0 14px;',
            '}',

            /* Count badge */
            '.zcm-count-badge {',
            '  display: inline-flex; align-items: center;',
            '  background: #fcebeb;',
            '  color: #a32d2d;',
            '  font-size: 12px; font-weight: 600;',
            '  padding: 4px 14px;',
            '  border-radius: 20px;',
            '  border: 1px solid #f5c1c1;',
            '  margin-bottom: 22px;',
            '}',

            /* Action row */
            '.zcm-actions {',
            '  display: flex; gap: 10px;',
            '}',

            /* Shared button base */
            '.zcm-btn {',
            '  flex: 1;',
            '  border: none; border-radius: 12px;',
            '  font-size: 14px; font-weight: 600;',
            '  padding: 12px 16px;',
            '  cursor: pointer;',
            '  transition: opacity 0.15s, box-shadow 0.15s, transform 0.15s;',
            '  font-family: inherit;',
            '  line-height: 1;',
            '}',
            '.zcm-btn:active { transform: scale(0.98); }',

            /* Cancel — outlined with brand color */
            '.zcm-btn--cancel {',
            '  background: #faf8ff;',
            '  color: #9500a5;',
            '  border: 1.5px solid #d8bfd3;',
            '}',
            '.zcm-btn--cancel:hover {',
            '  border-color: #9500a5;',
            '  background: #ffd6fb;',
            '  box-shadow: 0 2px 10px rgba(149, 0, 165, 0.10);',
            '}',

            /* Confirm — red danger gradient */
            '.zcm-btn--confirm {',
            '  background: linear-gradient(135deg, #c0392b 0%, #e74c3c 100%);',
            '  color: #ffffff;',
            '  box-shadow: 0 4px 14px rgba(192, 57, 43, 0.28);',
            '}',
            '.zcm-btn--confirm:hover {',
            '  background: linear-gradient(135deg, #a93226 0%, #c0392b 100%);',
            '  box-shadow: 0 6px 20px rgba(192, 57, 43, 0.38);',
            '}',

            /* Focus rings */
            '.zcm-btn:focus-visible {',
            '  outline: 2px solid #9500a5;',
            '  outline-offset: 3px;',
            '}',
        ].join( '\n' );

        var style = document.createElement( 'style' );
        style.id        = 'zcm-styles';
        style.textContent = css;
        document.head.appendChild( style );
    }

    return { show: show, hide: hide };

}() );
