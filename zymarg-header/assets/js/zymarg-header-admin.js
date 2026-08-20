/**
 * ZYMARG Header — Admin JS
 * Tab switching, AJAX save, WP color picker init, logo media upload,
 * display-condition rule builder.
 *
 * v1.1.13: Tab switching, color picker, media upload.
 * v1.1.17: Display condition rule builder.
 * v1.1.21: Fully AJAX — form saves without page reload. Tab state preserved.
 *
 * @package ZymargHeader
 */
( function ( $ ) {
	'use strict';

	var STORAGE_KEY = 'zymarg_admin_tab';

	/* ── Tab switching ──────────────────────────────────────────── */

	function activateTab( tabId ) {
		$( '.zymarg-tab-btn' ).removeClass( 'is-active' ).attr( 'aria-selected', 'false' );
		$( '.zymarg-tab-panel' ).hide().attr( 'hidden', '' );

		var $btn   = $( '.zymarg-tab-btn[data-tab="' + tabId + '"]' );
		var $panel = $( '#zymarg-tab-' + tabId );

		if ( ! $btn.length || ! $panel.length ) {
			$btn   = $( '.zymarg-tab-btn' ).first();
			$panel = $( '.zymarg-tab-panel' ).first();
			tabId  = $btn.data( 'tab' );
		}

		$btn.addClass( 'is-active' ).attr( 'aria-selected', 'true' );
		$panel.removeAttr( 'hidden' ).show();

		try { localStorage.setItem( STORAGE_KEY, tabId ); } catch ( e ) {}
	}

	$( '.zymarg-tab-btn' ).on( 'click', function () {
		activateTab( $( this ).data( 'tab' ) );
	} );

	var savedTab = '';
	try { savedTab = localStorage.getItem( STORAGE_KEY ) || ''; } catch ( e ) {}
	activateTab( savedTab || 'general' );

	/* ── WP Color Picker ────────────────────────────────────────── */

	$( '.zymarg-color-field' ).wpColorPicker();

	/* ── Logo media upload ──────────────────────────────────────── */

	var mediaFrame;

	$( '#zymarg_logo_upload' ).on( 'click', function ( e ) {
		e.preventDefault();
		if ( mediaFrame ) { mediaFrame.open(); return; }
		mediaFrame = wp.media( {
			title  : 'Select Logo Image',
			button : { text: 'Use this image' },
			multiple: false,
		} );
		mediaFrame.on( 'select', function () {
			var att   = mediaFrame.state().get( 'selection' ).first().toJSON();
			var thumb = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
			$( '#zymarg_logo_id' ).val( att.id );
			$( '#zymarg_logo_preview' ).html( '<img src="' + thumb + '" style="max-height:60px;display:block">' );
			$( '#zymarg_logo_remove' ).show();
		} );
		mediaFrame.open();
	} );

	$( '#zymarg_logo_remove' ).on( 'click', function ( e ) {
		e.preventDefault();
		$( '#zymarg_logo_id' ).val( '0' );
		$( '#zymarg_logo_preview' ).empty();
		$( this ).hide();
	} );

	/* ── Display Condition Rule Builder ─────────────────────────── */

	var TYPE_LABELS = {
		front_page         : 'Homepage (front page)',
		blog               : 'Blog / Posts page',
		'404'              : '404 page',
		search             : 'Search results',
		archive            : 'Any archive page',
		singular           : 'Any singular post / page',
		woo_shop           : 'WooCommerce — Shop page',
		woo_product        : 'WooCommerce — Single product pages',
		woo_cart           : 'WooCommerce — Cart page',
		woo_checkout       : 'WooCommerce — Checkout page',
		woo_account        : 'WooCommerce — My Account page',
		dokan_store        : 'Dokan — Single vendor store page',
		dokan_store_listing: 'Dokan — All stores listing page',
		dokan_dashboard    : 'Dokan — Vendor dashboard (all sub-pages)',
		dokan_orders       : 'Dokan — Vendor orders sub-page',
		dokan_settings_page: 'Dokan — Vendor settings sub-page',
		page               : 'Specific page (enter page ID)…',
		post_type          : 'Post type slug (e.g. "product")…',
		url                : 'URL contains / wildcard (e.g. "/shop/")…',
	};

	var NEEDS_VALUE = { page: true, post_type: true, url: true };

	var $rulesList = $( '#zymarg-rules-list' );
	var $rulesJson = $( '#zymarg-display-rules-json' );
	var $addBtn    = $( '#zymarg-add-rule' );

	var rules = [];
	if ( $rulesList.length ) {
		try {
			var parsed = JSON.parse( $rulesJson.val() || '[]' );
			if ( Array.isArray( parsed ) ) { rules = parsed; }
		} catch ( e ) {}
	}

	function buildTypeSelect( sel ) {
		var groups = {
			'General'    : [ 'front_page', 'blog', '404', 'search', 'archive', 'singular' ],
			'WooCommerce': [ 'woo_shop', 'woo_product', 'woo_cart', 'woo_checkout', 'woo_account' ],
			'Dokan'      : [ 'dokan_store', 'dokan_store_listing', 'dokan_dashboard', 'dokan_orders', 'dokan_settings_page' ],
			'Custom'     : [ 'page', 'post_type', 'url' ],
		};
		var html = '<select class="zymarg-rule-type">';
		$.each( groups, function ( g, types ) {
			html += '<optgroup label="' + g + '">';
			$.each( types, function ( i, t ) {
				html += '<option value="' + t + '"' + ( t === sel ? ' selected' : '' ) + '>' + TYPE_LABELS[ t ] + '</option>';
			} );
			html += '</optgroup>';
		} );
		return html + '</select>';
	}

	function valueField( type, value ) {
		if ( NEEDS_VALUE[ type ] ) {
			return '<input type="text" class="zymarg-rule-value" placeholder="Enter value" value="' + $( '<span>' ).text( value ).html() + '">';
		}
		return '<span class="zymarg-rule-value" style="flex:1;color:#534152;font-size:12px;font-style:italic">No extra value needed</span>';
	}

	function buildRow( rule ) {
		var type = rule.type || 'front_page';
		return $(
			'<div class="zymarg-rule-row">' +
				buildTypeSelect( type ) +
				valueField( type, rule.value || '' ) +
				'<button type="button" class="zymarg-rule-remove" title="Remove">✕</button>' +
			'</div>'
		);
	}

	function renderRules() {
		if ( ! $rulesList.length ) { return; }
		$rulesList.empty();
		if ( ! rules.length ) {
			$rulesList.html( '<p style="color:#857183;font-style:italic;margin:0 0 8px">No conditions yet — click "+ Add Condition" to start.</p>' );
			return;
		}
		$.each( rules, function ( i, r ) { $rulesList.append( buildRow( r ) ); } );
	}

	/**
	 * Sync rule rows → hidden JSON field before saving.
	 * Called by the AJAX save handler — not wired to form submit any more.
	 */
	function serializeRules() {
		if ( ! $rulesList.length ) { return; }
		var out = [];
		$rulesList.find( '.zymarg-rule-row' ).each( function () {
			var $r = $( this );
			var t  = $r.find( '.zymarg-rule-type' ).val();
			var $v = $r.find( '.zymarg-rule-value' );
			out.push( { type: t, value: $v.is( 'input' ) ? $v.val() : '' } );
		} );
		rules = out;
		$rulesJson.val( JSON.stringify( rules ) );
	}

	if ( $rulesList.length ) {
		$rulesList.on( 'change', '.zymarg-rule-type', function () {
			var $row = $( this ).closest( '.zymarg-rule-row' );
			var type = $( this ).val();
			var $old = $row.find( '.zymarg-rule-value' );
			if ( NEEDS_VALUE[ type ] ) {
				if ( ! $old.is( 'input' ) ) { $old.replaceWith( '<input type="text" class="zymarg-rule-value" placeholder="Enter value">' ); }
			} else {
				if ( $old.is( 'input' ) ) { $old.replaceWith( '<span class="zymarg-rule-value" style="flex:1;color:#534152;font-size:12px;font-style:italic">No extra value needed</span>' ); }
			}
		} );

		$rulesList.on( 'click', '.zymarg-rule-remove', function () {
			$( this ).closest( '.zymarg-rule-row' ).remove();
			if ( ! $rulesList.find( '.zymarg-rule-row' ).length ) {
				$rulesList.html( '<p style="color:#857183;font-style:italic;margin:0 0 8px">No conditions yet — click "+ Add Condition" to start.</p>' );
			}
		} );

		$addBtn.on( 'click', function () {
			$rulesList.find( 'p' ).remove();
			$rulesList.append( buildRow( { type: 'front_page', value: '' } ) );
		} );

		renderRules();
	}

	/* ── AJAX Save ──────────────────────────────────────────────── */

	var $form       = $( '.zymarg-admin-wrap form' );
	var $saveBtn    = $( '#zymarg-save-btn' );
	var $saveNotice = $( '#zymarg-save-notice' );
	var noticeTimer = null;

	/**
	 * Show the inline notice next to the save button.
	 * Auto-hides after 3 s.
	 */
	function showNotice( msg, isSuccess ) {
		clearTimeout( noticeTimer );
		$saveNotice
			.removeClass( 'zymarg-notice-success zymarg-notice-error' )
			.addClass( isSuccess ? 'zymarg-notice-success' : 'zymarg-notice-error' )
			.css( {
				display: 'inline-flex',
				alignItems: 'center',
				gap: '6px',
				color: isSuccess ? '#1a6b2e' : '#8b1a1a',
				fontWeight: '600',
				fontSize: '13px',
			} )
			.html( ( isSuccess ? '✓ ' : '✕ ' ) + msg );

		noticeTimer = setTimeout( function () {
			$saveNotice.fadeOut( 300, function () {
				$( this ).css( 'display', '' );
			} );
		}, 3000 );
	}

	$form.on( 'submit', function ( e ) {
		e.preventDefault(); // No page reload

		// Sync display rules to hidden JSON field before serializing
		serializeRules();

		// Lock button and show saving state
		$saveBtn.prop( 'disabled', true ).val( zymargAdmin.savingMsg );
		$saveNotice.hide();

		$.ajax( {
			url    : zymargAdmin.ajaxUrl,
			type   : 'POST',
			data   : {
				action    : 'zymarg_header_save',
				nonce     : zymargAdmin.nonce,
				form_data : $form.serialize(),
			},
			success: function ( res ) {
				if ( res && res.success ) {
					showNotice( zymargAdmin.savedMsg, true );
				} else {
					var msg = ( res && res.data && res.data.message ) ? res.data.message : zymargAdmin.errorMsg;
					showNotice( msg, false );
				}
			},
			error: function () {
				showNotice( zymargAdmin.errorMsg, false );
			},
			complete: function () {
				// Restore button — tab stays where it is
				$saveBtn.prop( 'disabled', false ).val( 'Save Settings' );
			},
		} );
	} );

} )( jQuery );
