/**
 * ZYMARG Vendor Dashboard -- Product Attributes (Variable Product Stage 1).
 *
 * Handles the product type toggle (show/hide pricing card + attributes card)
 * and the dynamic attribute builder UI (add/remove rows, global vs custom).
 * Vanilla JS, no dependencies.
 */
( function () {
	'use strict';

	var globalTermsData = {};

	/**
	 * Load global attribute terms from the embedded JSON.
	 */
	function loadGlobalTerms() {
		var el = document.getElementById( 'zymarg-zpe-global-attr-terms' );
		if ( el ) {
			try {
				globalTermsData = JSON.parse( el.textContent ) || {};
			} catch ( e ) {
				globalTermsData = {};
			}
		}
	}

	/**
	 * Get the next attribute row index.
	 */
	function getNextIndex() {
		var rows = document.querySelectorAll( '#zymarg-zpe-attr-list .zymarg-zpe-attr-row' );
		var max = -1;
		for ( var i = 0; i < rows.length; i++ ) {
			var idx = parseInt( rows[ i ].getAttribute( 'data-index' ), 10 );
			if ( ! isNaN( idx ) && idx > max ) {
				max = idx;
			}
		}
		return max + 1;
	}

	/**
	 * Initialize product type toggle (pricing vs attributes cards).
	 */
	function initTypeToggle() {
		var typeSelect = document.getElementById( 'zymarg-zpe-product-type' );
		var pricingCard = document.getElementById( 'zymarg-zpe-pricing' );
		var attributesCard = document.getElementById( 'zymarg-zpe-attributes' );

		if ( ! typeSelect ) {
			return;
		}

		function sync() {
			var isVariable = typeSelect.value === 'variable';
			if ( pricingCard ) {
				if ( isVariable ) {
					pricingCard.setAttribute( 'hidden', 'hidden' );
				} else {
					pricingCard.removeAttribute( 'hidden' );
				}
			}
			if ( attributesCard ) {
				if ( isVariable ) {
					attributesCard.removeAttribute( 'hidden' );
				} else {
					attributesCard.setAttribute( 'hidden', 'hidden' );
				}
			}
		}

		typeSelect.addEventListener( 'change', sync );
		sync();
	}

	/**
	 * Create a taxonomy attribute row with term checkboxes.
	 */
	function createTaxonomyRow( index, taxonomyName, label ) {
		var terms = globalTermsData[ taxonomyName ] || [];
		var row = document.createElement( 'div' );
		row.className = 'zymarg-zpe-attr-row';
		row.setAttribute( 'data-index', index );

		var termsHtml = '';
		for ( var i = 0; i < terms.length; i++ ) {
			termsHtml += '<label class="zymarg-zp-check zymarg-zpe-attr-term">'
				+ '<input type="checkbox" name="attribute_term_' + escAttr( taxonomyName ) + '[]" value="' + escAttr( terms[ i ].slug ) + '">'
				+ escHtml( terms[ i ].name )
				+ '</label>';
		}

		row.innerHTML = '<div class="zymarg-zpe-attr-row__header">'
			+ '<span class="zymarg-zpe-attr-row__name-display">' + escHtml( label ) + '</span>'
			+ '<button type="button" class="zymarg-zpe-attr-row__remove">&times;</button>'
			+ '</div>'
			+ '<div class="zymarg-zpe-attr-row__body">'
			+ '<input type="hidden" name="attribute_names[]" value="' + escAttr( taxonomyName ) + '">'
			+ '<input type="hidden" name="attribute_is_taxonomy[]" value="1">'
			+ '<input type="hidden" name="attribute_position[]" value="' + index + '">'
			+ '<div class="zymarg-zpe-attr-row__values-field zymarg-zpe-attr-row__values-field--taxonomy" data-taxonomy="' + escAttr( taxonomyName ) + '">'
			+ '<label class="zymarg-zp-field">'
			+ '<span class="zymarg-zp-field__label">Select terms</span>'
			+ '<div class="zymarg-zpe-attr-terms">' + termsHtml + '</div>'
			+ '</label>'
			+ '<input type="hidden" class="zymarg-zpe-attr-values" name="attribute_values[]" value="">'
			+ '</div>'
			+ '<label class="zymarg-zp-check">'
			+ '<input type="checkbox" class="zymarg-zpe-attr-variation" name="attribute_variation[]" value="' + index + '" checked>'
			+ ' Used for variations'
			+ '</label>'
			+ '</div>';

		return row;
	}

	/**
	 * Create a custom attribute row from template.
	 */
	function createCustomRow( index ) {
		var template = document.getElementById( 'zymarg-zpe-attr-row-template' );
		if ( ! template ) {
			return null;
		}

		var html = template.innerHTML
			.replace( /__INDEX__/g, String( index ) );

		var wrapper = document.createElement( 'div' );
		wrapper.innerHTML = html;
		var row = wrapper.firstElementChild;

		// Bind the name input to update the header display and the hidden name field.
		var nameInput = row.querySelector( '.zymarg-zpe-attr-name' );
		var nameHidden = row.querySelector( 'input[name="attribute_names[]"]' );
		var nameDisplay = row.querySelector( '.zymarg-zpe-attr-row__name-display' );

		if ( nameInput ) {
			nameInput.addEventListener( 'input', function () {
				var val = nameInput.value.trim();
				if ( nameHidden ) {
					nameHidden.value = val;
				}
				if ( nameDisplay ) {
					nameDisplay.textContent = val || 'Custom attribute';
				}
			} );
			// Set initial display.
			if ( nameDisplay ) {
				nameDisplay.textContent = 'Custom attribute';
			}
		}

		return row;
	}

	/**
	 * Initialize the add-attribute button.
	 */
	function initAddAttribute() {
		var addBtn = document.getElementById( 'zymarg-zpe-attr-add-btn' );
		var sourceSelect = document.getElementById( 'zymarg-zpe-attr-source' );
		var list = document.getElementById( 'zymarg-zpe-attr-list' );

		if ( ! addBtn || ! list ) {
			return;
		}

		addBtn.addEventListener( 'click', function () {
			var index = getNextIndex();
			var source = sourceSelect ? sourceSelect.value : '';
			var row;

			if ( source && source.indexOf( 'pa_' ) === 0 ) {
				// Check if this taxonomy attribute already exists.
				var existing = list.querySelector( 'input[name="attribute_names[]"][value="' + source + '"]' );
				if ( existing ) {
					// Already added - just flash it.
					var existingRow = existing.closest( '.zymarg-zpe-attr-row' );
					if ( existingRow ) {
						existingRow.style.outline = '2px solid #9500A5';
						setTimeout( function () {
							existingRow.style.outline = '';
						}, 1500 );
					}
					return;
				}

				// Find the label from the select option.
				var label = source;
				if ( sourceSelect ) {
					var opt = sourceSelect.querySelector( 'option[value="' + source + '"]' );
					if ( opt ) {
						label = opt.textContent;
					}
				}
				row = createTaxonomyRow( index, source, label );
			} else {
				row = createCustomRow( index );
			}

			if ( row ) {
				list.appendChild( row );
				bindRemoveButton( row );
			}

			// Reset source select.
			if ( sourceSelect ) {
				sourceSelect.value = '';
			}
		} );
	}

	/**
	 * Bind the remove button on an attribute row.
	 */
	function bindRemoveButton( row ) {
		var btn = row.querySelector( '.zymarg-zpe-attr-row__remove' );
		if ( btn ) {
			btn.addEventListener( 'click', function () {
				row.parentNode.removeChild( row );
			} );
		}
	}

	/**
	 * Bind all existing remove buttons on page load.
	 */
	function initExistingRows() {
		var rows = document.querySelectorAll( '#zymarg-zpe-attr-list .zymarg-zpe-attr-row' );
		for ( var i = 0; i < rows.length; i++ ) {
			bindRemoveButton( rows[ i ] );
		}

		// Also bind name inputs on existing custom rows.
		for ( var j = 0; j < rows.length; j++ ) {
			var nameInput = rows[ j ].querySelector( '.zymarg-zpe-attr-name' );
			var nameHidden = rows[ j ].querySelector( 'input[name="attribute_names[]"]' );
			var nameDisplay = rows[ j ].querySelector( '.zymarg-zpe-attr-row__name-display' );
			if ( nameInput && nameHidden ) {
				( function ( input, hidden, display ) {
					input.addEventListener( 'input', function () {
						var val = input.value.trim();
						hidden.value = val;
						if ( display ) {
							display.textContent = val || 'Custom attribute';
						}
					} );
				} )( nameInput, nameHidden, nameDisplay );
			}
		}
	}

	/**
	 * Escape HTML for safe insertion.
	 */
	function escHtml( str ) {
		var div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str ) );
		return div.innerHTML;
	}

	/**
	 * Escape for HTML attribute context.
	 */
	function escAttr( str ) {
		return str
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	function init() {
		loadGlobalTerms();
		initTypeToggle();
		initAddAttribute();
		initExistingRows();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	// Re-init on SPA section load.
	document.addEventListener( 'zymarg-vd:section-loaded', init );
}() );
