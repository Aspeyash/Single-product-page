/**
 * ZYMARG Store Page -- Product Grid Sections repeater.
 *
 * Ported from ZYMARG Single Product's own section repeater (same UX:
 * rows open locked, drag to reorder, Add Section, remove with
 * confirmation, one-step restore), adapted to this plugin's naming and to
 * its stricter rule: every shortcode here must use source="current_vendor",
 * because a store page always shows exactly one vendor's own products.
 *
 * Deliberately a separate script and a separate Ajax round trip from
 * zymarg-sp-admin.js's settings-form save: the section repeater has its
 * own validation and its own backup/restore, and should not depend on, or
 * be blocked by, anything else on the settings screen.
 *
 * @package ZYMARG_Store_Page
 */
( function ( $ ) {
	'use strict';

	var cfg = window.ZymargSPStoreSections || {};

	// Rows open locked. Nothing inside a row is writable until Edit is
	// pressed, so opening this screen to look at a section cannot change
	// what the store page renders. The snapshot below is taken once the
	// rows are locked, and is what Save diffs against to describe the
	// change set before asking for confirmation.
	var sectionsSnapshot = '[]';

	function collectSections() {
		var rows = [];

		$( '#zsp-store-sections .zsp-section-row' ).each( function () {
			var $row = $( this );
			rows.push( {
				id:        String( $row.attr( 'data-row-id' ) || '' ),
				label:     $row.find( '[data-row-field="label"]' ).val() || '',
				enabled:   $row.find( '[data-row-field="enabled"]' ).is( ':checked' ),
				heading:   $row.find( '[data-row-field="heading"]' ).val() || '',
				show_link: $row.find( '[data-row-field="show_link"]' ).is( ':checked' ),
				link_url:  $row.find( '[data-row-field="link_url"]' ).val() || '',
				shortcode: $row.find( '[data-row-field="shortcode"]' ).val() || ''
			} );
		} );

		return rows;
	}

	function attr( s ) {
		return String( s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	// Locking uses readonly for text and disabled for checkboxes. jQuery
	// .val() and :checked still read both, so collectSections() is
	// unaffected by lock state.
	function setRowLocked( $row, locked ) {
		$row.toggleClass( 'is-locked', !! locked );

		$row.find( '[data-row-field="label"], [data-row-field="heading"], [data-row-field="link_url"], [data-row-field="shortcode"]' )
			.prop( 'readonly', !! locked );

		$row.find( '[data-row-field="enabled"], [data-row-field="show_link"]' )
			.prop( 'disabled', !! locked );

		$row.find( '.zsp-section-row__edit' )
			.text( locked ? ( cfg.i18n && cfg.i18n.edit ) || 'Edit' : ( cfg.i18n && cfg.i18n.done ) || 'Done' )
			.toggleClass( 'is-editing', ! locked );
	}

	function rowMeta( $row ) {
		var code   = String( $row.find( '[data-row-field="shortcode"]' ).val() || '' );
		var src    = ( code.match( /\bsource=("|')(.*?)\1/ ) || [] )[ 2 ] || '?';
		var lay    = ( code.match( /\blayout=("|')(.*?)\1/ ) || [] )[ 2 ] || 'grid';
		var sub    = ( code.match( /\bcurrent_vendor_subset=("|')(.*?)\1/ ) || [] )[ 2 ] || 'all';
		var isAll  = ! code || 'all' === sub.toLowerCase();
		var text   = src.toLowerCase() + ' \u00b7 ' + lay.toLowerCase() + ' \u00b7 ' + sub.toLowerCase();

		if ( code && isAll ) {
			text += ' \u00b7 special: All Products';
		}

		$row.find( '[data-row-meta]' ).text( text );
	}

	// Client-side mirror of the server's rules, so a bad shortcode is
	// refused before it is saved rather than silently blanked afterwards.
	// Two independent checks, same as the PHP side: the tag must be
	// allow-listed, AND the source attribute must be exactly
	// "current_vendor" -- an absent source is also rejected, since that
	// resolves to the engine's catalogue-wide "all" source instead.
	function validateSections() {
		var errors  = [];
		var allowed = cfg.allowedShortcodes || [ 'zymarg_products' ];

		$( '#zsp-store-sections .zsp-section-row' ).each( function () {
			var $row  = $( this );
			var code  = String( $row.find( '[data-row-field="shortcode"]' ).val() || '' ).trim();
			var name  = $row.find( '[data-row-field="label"]' ).val() || ( cfg.i18n && cfg.i18n.untitled ) || 'this section';
			var $warn = $row.find( '.zsp-section-row__warn' );

			$row.removeClass( 'has-error' );
			$warn.attr( 'hidden', 'hidden' ).text( '' );

			// An empty box is legitimate: the section simply does not render.
			if ( '' === code ) { return; }

			var opens  = ( code.match( /\[/g ) || [] ).length;
			var closes = ( code.match( /\]/g ) || [] ).length;
			var quotes = ( code.match( /"/g ) || [] ).length;
			var tag    = ( code.match( /^\[\s*([a-z0-9_]+)/i ) || [] )[ 1 ] || '';
			var source = ( code.match( /\bsource=("|')(.*?)\1/ ) || [] )[ 2 ] || '';

			var msg = '';

			if ( '[' !== code.charAt( 0 ) || ']' !== code.charAt( code.length - 1 ) ) {
				msg = ( cfg.i18n && cfg.i18n.mustStartEnd ) || 'Must start with [ and end with ].';
			} else if ( opens !== closes ) {
				msg = ( cfg.i18n && cfg.i18n.unbalancedBrackets ) || 'Unbalanced square brackets.';
			} else if ( quotes % 2 !== 0 ) {
				msg = ( cfg.i18n && cfg.i18n.unbalancedQuotes ) || 'Unbalanced quotation marks.';
			} else if ( ! tag || allowed.indexOf( tag.toLowerCase() ) === -1 ) {
				msg = '"' + tag + '" ' + ( ( cfg.i18n && cfg.i18n.notAllowedTag ) || 'is not a ZYMARG Product Grid shortcode and would be discarded on save.' );
			} else if ( 'current_vendor' !== source.toLowerCase() ) {
				msg = '"' + tag + '" ' + ( ( cfg.i18n && cfg.i18n.mustBeCurrentVendor ) || 'must use source="current_vendor" — this is the only source a store page section can run, and would be discarded on save.' );
			}

			if ( msg ) {
				$row.addClass( 'has-error' );
				$warn.text( msg ).removeAttr( 'hidden' );
				errors.push( name + ' \u2014 ' + msg );
			}
		} );

		return errors;
	}

	// Plain-language diff of the row set, shown before the save goes out.
	function describeChanges() {
		var before;
		try { before = JSON.parse( sectionsSnapshot || '[]' ); } catch ( e ) { before = []; }

		var after = collectSections();
		var lines = [];
		var prev  = {};

		before.forEach( function ( r ) { prev[ r.id ] = r; } );

		var beforeIds = before.map( function ( r ) { return r.id; } );
		var afterIds  = after.map( function ( r ) { return r.id; } );

		after.forEach( function ( row ) {
			var was  = prev[ row.id ];
			var name = row.label || row.heading || row.id;

			if ( ! was ) { lines.push( 'Added section: ' + name ); return; }

			if ( was.shortcode !== row.shortcode ) { lines.push( 'SHORTCODE changed: ' + name ); }
			if ( was.heading   !== row.heading )   { lines.push( 'Heading changed: ' + name ); }
			if ( was.label     !== row.label )     { lines.push( 'Admin name changed: ' + name ); }
			if ( !! was.enabled !== !! row.enabled ) { lines.push( ( row.enabled ? 'Enabled: ' : 'DISABLED: ' ) + name ); }
			if ( !! was.show_link !== !! row.show_link ) { lines.push( 'Link toggle changed: ' + name ); }
			if ( ( was.link_url || '' ) !== ( row.link_url || '' ) ) { lines.push( 'Link URL changed: ' + name ); }
		} );

		before.forEach( function ( row ) {
			if ( afterIds.indexOf( row.id ) === -1 ) {
				lines.push( 'REMOVED section: ' + ( row.label || row.heading || row.id ) );
			}
		} );

		if ( beforeIds.join( '|' ) !== afterIds.join( '|' )
			&& beforeIds.length === afterIds.length ) {
			lines.push( 'Section order changed.' );
		}

		return lines;
	}

	function refreshSnapshot() {
		sectionsSnapshot = JSON.stringify( collectSections() );
	}

	function newRowMarkup( id ) {
		// Defaults to a valid, non-rejected shortcode: source="current_vendor"
		// with an explicit subset, so a freshly added row is never blanked
		// out by validateSections() before the admin has typed anything.
		var placeholder = '[zymarg_products source=&quot;current_vendor&quot; current_vendor_subset=&quot;all&quot; limit=&quot;8&quot;]';
		var defaultCode = '[zymarg_products source="current_vendor" current_vendor_subset="all" limit="8"]';

		return '<div class="zsp-section-row" data-row-id="' + attr( id ) + '">' +
			'<div class="zsp-section-row__head">' +
				'<span class="zsp-section-row__handle" aria-hidden="true">&#8942;&#8942;</span>' +
				'<input type="text" class="zymarg-sp-admin-input zsp-section-row__label" data-row-field="label" value="" placeholder="Section name (admin only)">' +
				'<span class="zsp-section-row__meta" data-row-meta>current_vendor \u00b7 grid \u00b7 all \u00b7 special: All Products</span>' +
				'<label class="zsp-toggle zsp-section-row__toggle">' +
					'<input type="checkbox" data-row-field="enabled" checked>' +
					'<span class="zsp-toggle__slider"></span>' +
				'</label>' +
				'<button type="button" class="zsp-section-row__edit">' + attr( ( cfg.i18n && cfg.i18n.done ) || 'Done' ) + '</button>' +
				'<button type="button" class="zsp-section-row__remove" aria-label="Remove section">&times;</button>' +
			'</div>' +
			'<div class="zsp-section-row__body">' +
				'<div class="zsp-section-row__field">' +
					'<label class="zsp-section-row__flabel">Section heading (shown on the page)</label>' +
					'<input type="text" class="zymarg-sp-admin-input" data-row-field="heading" value="" placeholder="Trending">' +
					'<p class="zsp-section-row__hint">Plain text only. Leave empty for no heading. Ignored on the All Products row.</p>' +
				'</div>' +
				'<div class="zsp-section-row__field zsp-section-row__field--inline">' +
					'<label class="zsp-toggle">' +
						'<input type="checkbox" data-row-field="show_link">' +
						'<span class="zsp-toggle__slider"></span>' +
					'</label>' +
					'<span class="zsp-section-row__flabel">Show the section link</span>' +
				'</div>' +
				'<div class="zsp-section-row__field">' +
					'<label class="zsp-section-row__flabel">Link URL</label>' +
					'<input type="url" class="zymarg-sp-admin-input" data-row-field="link_url" value="" placeholder="https://">' +
					'<p class="zsp-section-row__hint">Leave empty and no link renders at all. The link reads "Explore More".</p>' +
				'</div>' +
				'<div class="zsp-section-row__field">' +
					'<label class="zsp-section-row__flabel">Shortcode</label>' +
					'<textarea class="zymarg-sp-admin-input zsp-textarea zsp-section-row__shortcode" data-row-field="shortcode" rows="3" spellcheck="false" placeholder="' + placeholder + '">' + attr( defaultCode ) + '</textarea>' +
					'<p class="zsp-section-row__hint">Must use source="current_vendor". Available current_vendor_subset values: all, featured, trending, best_selling.</p>' +
					'<p class="zsp-section-row__warn" hidden></p>' +
				'</div>' +
			'</div>' +
		'</div>';
	}

	function showStatus( msg, isError ) {
		var $s = $( '#zsp-sections-status' );
		if ( ! $s.length ) { return; }
		$s.text( msg )
			.removeClass( 'zsp-save-status--ok zsp-save-status--err' )
			.addClass( isError ? 'zsp-save-status--err' : 'zsp-save-status--ok' );
		clearTimeout( $s.data( 'timer' ) );
		$s.data( 'timer', setTimeout( function () {
			$s.removeClass( 'zsp-save-status--ok zsp-save-status--err' ).text( '' );
		}, 4000 ) );
	}

	function initSections() {
		var $list = $( '#zsp-store-sections' );
		if ( ! $list.length ) { return; }

		$list.find( '.zsp-section-row' ).each( function () {
			setRowLocked( $( this ), true );
			rowMeta( $( this ) );
		} );

		refreshSnapshot();

		// Drag to reorder. Array order is render order, so no index field
		// to keep in sync.
		if ( $.fn.sortable ) {
			$list.sortable( {
				handle:               '.zsp-section-row__handle',
				items:                '> .zsp-section-row',
				axis:                 'y',
				opacity:              0.7,
				placeholder:          'zsp-section-row__placeholder',
				forcePlaceholderSize: true
			} );
		}

		$( '#zsp-add-store-section' ).on( 'click', function () {
			var id = 'sec_' + Date.now().toString( 36 );
			$list.append( newRowMarkup( id ) );
			var $row = $list.find( '.zsp-section-row' ).last();
			setRowLocked( $row, false );
			$row.find( '[data-row-field="label"]' ).trigger( 'focus' );
		} );

		$list.on( 'click', '.zsp-section-row__edit', function () {
			var $row   = $( this ).closest( '.zsp-section-row' );
			var locked = $row.hasClass( 'is-locked' );

			setRowLocked( $row, ! locked );

			if ( locked ) {
				$row.find( '[data-row-field="heading"]' ).trigger( 'focus' );
			} else {
				rowMeta( $row );
				validateSections();
			}
		} );

		$list.on( 'click', '.zsp-section-row__remove', function () {
			var $row = $( this ).closest( '.zsp-section-row' );
			var name = $row.find( '[data-row-field="label"]' ).val()
				|| $row.find( '[data-row-field="heading"]' ).val()
				|| ( cfg.i18n && cfg.i18n.untitled ) || 'this section';
			var tmpl = ( cfg.i18n && cfg.i18n.confirmRemove )
				|| 'Remove "%s"? It will stop rendering on the store page once you save.';

			if ( ! window.confirm( tmpl.replace( '%s', name ) ) ) { return; }

			$row.remove();
		} );

		$list.on( 'change', '[data-row-field="enabled"]', function () {
			$( this ).closest( '.zsp-section-row' )
				.toggleClass( 'is-disabled', ! this.checked );
		} );

		$list.on( 'input', '[data-row-field="shortcode"]', function () {
			rowMeta( $( this ).closest( '.zsp-section-row' ) );
		} );

		$list.on( 'blur', '[data-row-field="shortcode"]', function () {
			validateSections();
		} );

		$( '#zsp-save-store-sections' ).on( 'click', function () {
			var problems = validateSections();
			if ( problems.length ) {
				window.alert(
					( ( cfg.i18n && cfg.i18n.invalid ) || 'Fix these section problems before saving:' )
					+ '\n\n\u2022 ' + problems.join( '\n\u2022 ' )
				);
				return;
			}

			var changes = describeChanges();
			if ( changes.length ) {
				var head = ( cfg.i18n && cfg.i18n.confirmSave ) || 'Save these section changes?';
				if ( ! window.confirm( head + '\n\n\u2022 ' + changes.join( '\n\u2022 ' ) ) ) { return; }
			}

			var $btn = $( this ).prop( 'disabled', true );

			$.ajax( {
				url:  cfg.ajaxUrl,
				type: 'POST',
				data: {
					action:   'zymarg_sp_save_store_sections',
					nonce:    cfg.saveNonce,
					sections: JSON.stringify( collectSections() )
				},
				success: function ( res ) {
					$btn.prop( 'disabled', false );

					if ( res && res.success ) {
						showStatus( ( cfg.i18n && cfg.i18n.saved ) || 'Sections saved.', false );

						$list.find( '.zsp-section-row' ).each( function () {
							setRowLocked( $( this ), true );
						} );
						refreshSnapshot();
						return;
					}

					showStatus( ( res && res.data && res.data.message ) || ( cfg.i18n && cfg.i18n.failed ) || 'Error saving.', true );
				},
				error: function () {
					$btn.prop( 'disabled', false );
					showStatus( ( cfg.i18n && cfg.i18n.failed ) || 'Error saving. Please try again.', true );
				}
			} );
		} );

		$( '#zsp-restore-store-sections' ).on( 'click', function () {
			var msg = ( cfg.i18n && cfg.i18n.confirmRestore )
				|| 'Restore the section list from before your last save?';

			if ( ! window.confirm( msg ) ) { return; }

			var $btn = $( this ).prop( 'disabled', true );

			$.ajax( {
				url:  cfg.ajaxUrl,
				type: 'POST',
				data: {
					action: 'zymarg_sp_restore_store_sections',
					nonce:  cfg.restoreNonce
				},
				success: function ( res ) {
					if ( res && res.success ) {
						window.location.reload();
						return;
					}
					$btn.prop( 'disabled', false );
					showStatus( ( res && res.data && res.data.message ) || ( cfg.i18n && cfg.i18n.restoreFailed ) || 'Restore failed.', true );
				},
				error: function () {
					$btn.prop( 'disabled', false );
					showStatus( ( cfg.i18n && cfg.i18n.restoreFailed ) || 'Restore failed.', true );
				}
			} );
		} );
	}

	$( function () {
		initSections();
	} );

}( jQuery ) );
