/**
 * ZYMARG Reviews Engine - admin control page.
 *
 * Tab switching and saving both happen without a page reload. Tabs are mirrored
 * into the URL hash so a reload or a bookmark returns to the same tab.
 */
( function () {
	'use strict';

	var CFG = window.ZymargREAdmin || {};
	var I18N = CFG.i18n || {};

	var form = document.querySelector( '[data-re-form]' );
	if ( ! form ) {
		return;
	}

	var status = form.querySelector( '[data-re-status]' );
	var saveBtn = form.querySelector( '[data-re-save]' );
	var tabs = Array.prototype.slice.call( document.querySelectorAll( '[data-re-tab]' ) );
	var panels = Array.prototype.slice.call( document.querySelectorAll( '[data-re-panel]' ) );
	var dirty = false;
	var statusTimer = null;

	// -- status line ---------------------------------------------------------
	function setStatus( text, tone ) {
		if ( ! status ) {
			return;
		}
		window.clearTimeout( statusTimer );
		status.textContent = text || '';
		status.className = 'zymarg-re__status' + ( tone ? ' is-' + tone : '' );
		if ( 'ok' === tone ) {
			statusTimer = window.setTimeout( function () {
				status.textContent = '';
				status.className = 'zymarg-re__status';
			}, 2500 );
		}
	}

	// -- tabs ----------------------------------------------------------------
	function activate( id, pushHash ) {
		var found = false;

		tabs.forEach( function ( tab ) {
			var on = tab.getAttribute( 'data-re-tab' ) === id;
			tab.classList.toggle( 'is-active', on );
			tab.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			if ( on ) {
				found = true;
			}
		} );

		if ( ! found ) {
			return;
		}

		panels.forEach( function ( panel ) {
			panel.classList.toggle( 'is-active', panel.getAttribute( 'data-re-panel' ) === id );
		} );

		if ( pushHash && window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', '#' + id );
		}
	}

	tabs.forEach( function ( tab ) {
		tab.addEventListener( 'click', function () {
			activate( tab.getAttribute( 'data-re-tab' ), true );
		} );
	} );

	if ( window.location.hash ) {
		activate( window.location.hash.replace( /^#/, '' ), false );
	}

	// -- collect + save ------------------------------------------------------
	function collect() {
		var out = {};
		var fields = form.querySelectorAll( '[data-re-key]' );

		Array.prototype.forEach.call( fields, function ( el ) {
			var key = el.getAttribute( 'data-re-key' );
			if ( 'checkbox' === el.type ) {
				out[ key ] = el.checked ? 1 : 0;
			} else {
				out[ key ] = el.value;
			}
		} );

		return out;
	}

	function post( action, payload, onDone ) {
		var body = new window.FormData();
		body.append( 'action', action );
		body.append( 'nonce', CFG.nonce || '' );
		Object.keys( payload ).forEach( function ( k ) {
			body.append( k, payload[ k ] );
		} );

		window.fetch( CFG.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( json ) {
				onDone( json && json.success, json && json.data ? json.data : {} );
			} )
			.catch( function () {
				onDone( false, {} );
			} );
	}

	function applyValues( values ) {
		if ( ! values ) {
			return;
		}
		var fields = form.querySelectorAll( '[data-re-key]' );
		Array.prototype.forEach.call( fields, function ( el ) {
			var key = el.getAttribute( 'data-re-key' );
			if ( ! ( key in values ) ) {
				return;
			}
			if ( 'checkbox' === el.type ) {
				el.checked = !! values[ key ];
			} else {
				el.value = values[ key ];
			}
		} );

		var box = form.querySelector( '[data-re-json]' );
		if ( box ) {
			box.value = JSON.stringify( values, null, 2 );
		}
	}

	function save() {
		if ( saveBtn ) {
			saveBtn.disabled = true;
		}
		setStatus( I18N.saving || 'Saving...', 'busy' );

		post( 'zymarg_re_save', { settings: JSON.stringify( collect() ) }, function ( ok, data ) {
			if ( saveBtn ) {
				saveBtn.disabled = false;
			}
			if ( ok ) {
				dirty = false;
				applyValues( data.settings );
				setStatus( data.message || I18N.saved || 'Saved', 'ok' );
			} else {
				setStatus( ( data && data.message ) || I18N.failed || 'Could not save.', 'err' );
			}
		} );
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		save();
	} );

	form.addEventListener( 'change', function ( e ) {
		if ( e.target && e.target.hasAttribute( 'data-re-key' ) ) {
			dirty = true;
			setStatus( I18N.dirty || 'Unsaved changes', 'warn' );
		}
	} );

	form.addEventListener( 'input', function ( e ) {
		if ( e.target && e.target.hasAttribute( 'data-re-key' ) ) {
			dirty = true;
		}
	} );

	// Cmd/Ctrl+S saves.
	document.addEventListener( 'keydown', function ( e ) {
		if ( ( e.metaKey || e.ctrlKey ) && 's' === String( e.key ).toLowerCase() ) {
			e.preventDefault();
			save();
		}
	} );

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( ! dirty ) {
			return undefined;
		}
		e.preventDefault();
		e.returnValue = '';
		return '';
	} );

	// -- reset + import ------------------------------------------------------
	var resetBtn = form.querySelector( '[data-re-reset]' );
	if ( resetBtn ) {
		resetBtn.addEventListener( 'click', function () {
			if ( ! window.confirm( I18N.confirm || 'Reset all settings?' ) ) {
				return;
			}
			post( 'zymarg_re_reset', {}, function ( ok, data ) {
				if ( ok ) {
					dirty = false;
					applyValues( data.settings );
					setStatus( data.message || 'Reset', 'ok' );
				} else {
					setStatus( I18N.failed || 'Could not reset.', 'err' );
				}
			} );
		} );
	}

	var importBtn = form.querySelector( '[data-re-import]' );
	if ( importBtn ) {
		importBtn.addEventListener( 'click', function () {
			var box = form.querySelector( '[data-re-json]' );
			if ( ! box ) {
				return;
			}
			try {
				JSON.parse( box.value );
			} catch ( err ) {
				setStatus( I18N.badJson || 'Invalid JSON.', 'err' );
				return;
			}
			post( 'zymarg_re_import', { json: box.value }, function ( ok, data ) {
				if ( ok ) {
					dirty = false;
					applyValues( data.settings );
					setStatus( data.message || I18N.imported || 'Imported', 'ok' );
				} else {
					setStatus( ( data && data.message ) || I18N.badJson || 'Import failed.', 'err' );
				}
			} );
		} );
	}
}() );
