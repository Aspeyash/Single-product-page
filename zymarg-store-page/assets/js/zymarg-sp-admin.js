/**
 * ZYMARG Store Page -- admin settings: Ajax save, hero repeater, colour fields.
 *
 * Saves the settings form without a page reload. The form keeps its
 * action="options.php" markup, so if this script fails to load or JavaScript
 * is off, the browser submits normally and WordPress saves through the
 * Settings API exactly as before. Ajax is a progressive enhancement here,
 * never a hard dependency.
 *
 * There is no spinner anywhere: the spec bans them. Progress is text.
 *
 * FIELD COLLECTION (changed in 1.20.0)
 * ------------------------------------
 * This used to name the four settings it posted, one line each. That worked
 * while there were four. The Flash Sale hero adds around thirty more plus a
 * repeater of arbitrary length, and a hand-maintained list would mean every new
 * control silently failing to save until somebody remembered to add it here --
 * with the screen still reporting success.
 *
 * So the whole form is posted instead, under the real field names, and the
 * nesting PHP needs comes for free from those names. Adding a control is now a
 * PHP-only change.
 *
 * @package ZYMARG_Store_Page
 */
( function () {
	'use strict';

	var cfg = window.ZymargSPAdmin || {};

	/**
	 * Hidden fields the WordPress Settings API adds for the non-Ajax path.
	 *
	 * They must not reach admin-ajax.php. 'action' is the dangerous one: the
	 * Settings API sets it to "update", and admin-ajax.php routes on that key,
	 * so leaving it in place would send this save to the wrong handler entirely.
	 */
	var SETTINGS_API_FIELDS = [ 'action', 'option_page', '_wpnonce', '_wp_http_referer' ];

	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.getElementById( 'zsp-settings-form' );
		if ( ! form ) {
			return;
		}

		var button = form.querySelector( '.zsp-save-btn' );
		var status = document.getElementById( 'zsp-save-status' );
		var busy   = false;

		/**
		 * Writes the save state. The status region is aria-live, so screen
		 * readers hear the result even though nothing visually moves.
		 */
		function setStatus( text, kind ) {
			if ( ! status ) {
				return;
			}
			status.textContent = text;
			status.className   = 'zsp-save-status' + ( kind ? ' zsp-save-status--' + kind : '' );
		}

		// ── Toggles ──────────────────────────────────────────────────────

		/**
		 * Keep a toggle's Enabled/Disabled word in sync with its checkbox.
		 * State is never communicated by the pill colour alone.
		 *
		 * Delegated from the form rather than bound per element, so the hero's
		 * toggles are covered without a second registration pass.
		 */
		form.addEventListener( 'change', function ( event ) {
			var input = event.target;

			if ( ! input || 'checkbox' !== input.type || ! input.closest( '.zsp-toggle' ) ) {
				return;
			}

			var wrap  = input.closest( '.zsp-toggle-wrap' );
			var label = wrap ? wrap.querySelector( '.zsp-toggle-state' ) : null;

			if ( label ) {
				label.textContent = input.checked
					? ( cfg.i18n && cfg.i18n.enabled ) || 'Enabled'
					: ( cfg.i18n && cfg.i18n.disabled ) || 'Disabled';
			}

			syncStatusRow( input.name, input.checked );
		} );

		/**
		 * Mirrors a toggle into the Plugin Status card so the sidebar cannot
		 * drift out of date now that the page no longer reloads on save.
		 */
		function syncStatusRow( fieldName, on ) {
			if ( ! fieldName ) {
				return;
			}

			var key = fieldName.replace( 'zymarg_sp_options[', '' ).replace( ']', '' );
			var row = document.querySelector( '[data-zsp-status="' + key + '"]' );
			if ( ! row ) {
				return;
			}
			var dot   = row.querySelector( '.zsp-status-dot' );
			var value = row.querySelector( '.zsp-status-value' );
			if ( dot ) {
				dot.className = 'zsp-status-dot zsp-status-dot--' + ( on ? 'ok' : 'err' );
			}
			if ( value ) {
				value.className   = 'zsp-status-value zsp-status-value--' + ( on ? 'ok' : 'err' );
				value.textContent = on
					? ( cfg.i18n && cfg.i18n.on ) || 'ON'
					: ( cfg.i18n && cfg.i18n.off ) || 'OFF';
			}
		}

		// ── Colour fields ────────────────────────────────────────────────

		/**
		 * Show the hex beside the swatch, and keep it current.
		 *
		 * A bare colour swatch gives an administrator no way to read the value
		 * they have chosen, let alone copy it into another plugin's settings, so
		 * the hex is printed as text next to it.
		 */
		function syncColourValue( input ) {
			var wrap = input.closest( '.zfs-colour' );
			var out  = wrap ? wrap.querySelector( '.zfs-colour__value' ) : null;

			if ( out ) {
				out.textContent = input.value;
			}
		}

		form.addEventListener( 'input', function ( event ) {
			if ( event.target && 'color' === event.target.type ) {
				syncColourValue( event.target );
			}
		} );

		/**
		 * Reset one control to the default the registry declared.
		 *
		 * The default travels in data-zfs-default rather than being restated
		 * here, so PHP stays the single source of truth for what "default"
		 * means and this cannot disagree with the emitter.
		 */
		form.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest ? event.target.closest( '[data-zfs-reset]' ) : null;

			if ( ! btn ) {
				return;
			}

			event.preventDefault();

			var field = document.getElementById( btn.getAttribute( 'data-zfs-reset' ) );

			if ( ! field ) {
				return;
			}

			field.value = field.getAttribute( 'data-zfs-default' ) || '';

			if ( 'color' === field.type ) {
				syncColourValue( field );
			}
		} );

		// ── Hero slide repeater ──────────────────────────────────────────

		form.addEventListener( 'click', function ( event ) {
			var add = event.target.closest ? event.target.closest( '.zfs-repeater__add' ) : null;

			if ( ! add ) {
				return;
			}

			event.preventDefault();

			var repeater = add.closest( '.zfs-repeater' );
			var rows     = repeater ? repeater.querySelector( '.zfs-repeater__rows' ) : null;
			var template = repeater ? repeater.querySelector( '.zfs-repeater__template' ) : null;

			if ( ! rows || ! template ) {
				return;
			}

			/*
			 * Index from the highest index already present, not from the row
			 * count. After a deletion those two disagree, and reusing an index
			 * would make two rows write to the same key and overwrite each other
			 * on save.
			 */
			var next = 0;

			Array.prototype.forEach.call( rows.querySelectorAll( '.zfs-repeater__row' ), function ( row ) {
				var index = parseInt( row.getAttribute( 'data-index' ), 10 );

				if ( ! isNaN( index ) && index >= next ) {
					next = index + 1;
				}
			} );

			rows.insertAdjacentHTML(
				'beforeend',
				template.innerHTML.replace( /__INDEX__/g, String( next ) )
			);

			var fresh = rows.querySelector( '.zfs-repeater__row:last-child input' );

			if ( fresh ) {
				fresh.focus();
			}
		} );

		form.addEventListener( 'click', function ( event ) {
			var remove = event.target.closest ? event.target.closest( '.zfs-repeater__remove' ) : null;

			if ( ! remove ) {
				return;
			}

			event.preventDefault();

			var row = remove.closest( '.zfs-repeater__row' );

			if ( row ) {
				row.remove();
			}
		} );

		// ── Save ─────────────────────────────────────────────────────────

		/**
		 * Build the payload from the form as it currently stands.
		 *
		 * @return {FormData}
		 */
		function collect() {
			var body = new FormData( form );

			SETTINGS_API_FIELDS.forEach( function ( name ) {
				body.delete( name );
			} );

			/*
			 * An unchecked checkbox is absent from a form submission entirely,
			 * which PHP cannot tell apart from "this control was not on the
			 * screen". Every unchecked box is therefore sent explicitly as 0, so
			 * turning something off saves as reliably as turning it on.
			 *
			 * Boxes inside the repeater's clone template are not a concern: that
			 * markup lives in a <script type="text/html"> and is never parsed
			 * into the DOM, so it is not part of the form.
			 */
			Array.prototype.forEach.call(
				form.querySelectorAll( 'input[type="checkbox"]' ),
				function ( box ) {
					if ( box.name && ! box.checked ) {
						body.append( box.name, '0' );
					}
				}
			);

			body.append( 'action', 'zymarg_sp_save_settings' );
			body.append( 'nonce', cfg.nonce );

			return body;
		}

		form.addEventListener( 'submit', function ( event ) {
			// Without an endpoint or nonce, fall through to the normal POST.
			if ( ! cfg.ajaxUrl || ! cfg.nonce ) {
				return;
			}

			event.preventDefault();

			if ( busy ) {
				return;
			}
			busy = true;

			if ( button ) {
				button.disabled = true;
			}
			setStatus( ( cfg.i18n && cfg.i18n.saving ) || 'Saving...', '' );

			var perPage = form.querySelector( '[name="zymarg_sp_options[products_per_page]"]' );
			var slug    = form.querySelector( '[name="zymarg_sp_options[no_results_slug]"]' );

			var done = function () {
				busy = false;
				if ( button ) {
					button.disabled = false;
				}
			};

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: collect()
			} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'HTTP ' + response.status );
					}
					return response.json();
				} )
				.then( function ( result ) {
					done();

					if ( ! result || ! result.success ) {
						var why = result && result.data && result.data.message
							? result.data.message
							: ( cfg.i18n && cfg.i18n.failed ) || 'Could not save. Please try again.';
						setStatus( why, 'err' );
						return;
					}

					// Reflect the sanitised values the server actually stored,
					// so a clamped number visibly corrects itself in the field.
					var saved = result.data && result.data.options ? result.data.options : null;
					if ( saved ) {
						if ( perPage && typeof saved.products_per_page !== 'undefined' ) {
							perPage.value = saved.products_per_page;
						}
						if ( slug && typeof saved.no_results_slug !== 'undefined' ) {
							slug.value = saved.no_results_slug;
						}
					}

					/*
					 * Same for the hero, which clamps a good deal more: a height
					 * of 9999 comes back as 600 and a malformed colour comes back
					 * as the brand default. Writing those back is what stops the
					 * screen from showing a value the site is not using.
					 */
					var hero = result.data && result.data.hero ? result.data.hero : null;
					if ( hero ) {
						Object.keys( hero ).forEach( function ( key ) {
							var value = hero[ key ];

							// Slides and other structures are left alone: the
							// rows on screen already match what was just sent.
							if ( null === value || 'object' === typeof value ) {
								return;
							}

							var field = form.querySelector(
								'[name="zymarg_sp_flash_hero[' + key + ']"]'
							);

							if ( ! field || 'checkbox' === field.type ) {
								return;
							}

							field.value = value;

							if ( 'color' === field.type ) {
								syncColourValue( field );
							}
						} );
					}

					setStatus(
						( result.data && result.data.message ) ||
							( cfg.i18n && cfg.i18n.saved ) ||
							'Settings saved.',
						'ok'
					);
				} )
				.catch( function () {
					done();
					setStatus(
						( cfg.i18n && cfg.i18n.failed ) || 'Could not save. Please try again.',
						'err'
					);
				} );
		} );
	} );
}() );
