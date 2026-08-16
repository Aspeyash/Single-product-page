/**
 * ZYMARG Vendor Dashboard — Native Settings (v1.28.0 Phase 1).
 *
 * Powers the accordion shell rendered by includes/settings-hub.php:
 *   1. Account                         (AJAX zymarg_vd_settings_save_account)
 *   2. Change Password                 (AJAX zymarg_vd_settings_change_password)
 *   3. Notification Preferences        (AJAX zymarg_vd_settings_save_notifications — includes Push)
 *   4-11. Store Prefs / Store Profile / Business / SEO / Social / Data Export / Danger Zone / Login & Security
 *
 * (v1.31.0: Section 11 "Push Notification Opt-in" was removed — it
 * duplicated the Push column in Section 3 above.)
 * (v1.32.0: Section 5 "Store Profile" ADDED — folded in from the old
 * standalone Store Settings screen. Danger Zone's own vacation toggle was
 * removed at the same time; see saveStoreProfile() below.)
 *
 * Uses jQuery.ajax so requests slide cleanly through Dokan's XHR wrapper
 * (same reason payouts/store-settings talk over it). Re-initialises on the
 * `zymarg-vd:section-loaded` event so it also works after an SPA swap.
 */
( function ( $ ) {
	'use strict';

	if ( ! window.jQuery ) {
		// Hard dep — bail loud in the console rather than throw a syntax error.
		if ( window.console && window.console.warn ) {
			window.console.warn( '[zymarg-vd] vendor-settings.js needs jQuery' );
		}
		return;
	}

	var CFG_VENDOR = window.ZymargVendor || {};
	var CFG_LOCAL = window.ZymargVendorSettings || {};
	var I18N = CFG_LOCAL.i18n || {};
	var AJAX_URL = CFG_VENDOR.ajaxUrl || ( window.ajaxurl || '' );
	var NONCE = CFG_VENDOR.nonce || '';

	// Delegated handlers guard: bind exactly once even across SPA swaps.
	var boundDelegates = false;

	/* -----------------------------------------------------------------
	 * Small DOM helpers
	 * ----------------------------------------------------------------- */

	function setFlash( $flash, text, ok ) {
		if ( ! $flash || ! $flash.length ) { return; }
		$flash.text( text || '' ).removeClass( 'is-ok is-err' );
		if ( ! text ) {
			return;
		}
		$flash.addClass( ok ? 'is-ok' : 'is-err' );
		if ( ok ) {
			// Fade the green success after 2s.
			window.setTimeout( function () {
				if ( $flash.hasClass( 'is-ok' ) ) {
					$flash.text( '' ).removeClass( 'is-ok is-err' );
				}
			}, 2000 );
		}
	}

	function isMobile() {
		return window.matchMedia && window.matchMedia( '(max-width: 899px)' ).matches;
	}

	/* -----------------------------------------------------------------
	 * Accordion
	 * ----------------------------------------------------------------- */

	function openCard( $card, focusToggle ) {
		$card.addClass( 'is-open' ).attr( 'data-vs-open', '1' );
		$card.find( '> .zymarg-vs-card__toggle' ).attr( 'aria-expanded', 'true' );
		if ( focusToggle ) {
			$card.find( '> .zymarg-vs-card__toggle' ).trigger( 'focus' );
		}
	}
	function closeCard( $card ) {
		$card.removeClass( 'is-open' ).attr( 'data-vs-open', '0' );
		$card.find( '> .zymarg-vs-card__toggle' ).attr( 'aria-expanded', 'false' );
	}

	function initAccordion() {
		// Delegated so it survives SPA swaps.
		$( document ).off( 'click.vsAcc' ).on( 'click.vsAcc', '.zymarg-vs-card__toggle', function ( e ) {
			var $btn = $( this );
			var $card = $btn.closest( '.zymarg-vs-card' );
			if ( ! $card.length ) { return; }
			e.preventDefault();

			var willOpen = ! $card.hasClass( 'is-open' );

			if ( isMobile() && willOpen ) {
				// Mobile: only ONE card open at a time.
				$card.siblings( '.zymarg-vs-card.is-open' ).each( function () {
					closeCard( $( this ) );
				} );
			}

			if ( willOpen ) {
				openCard( $card );
			} else {
				closeCard( $card );
			}
		} );
	}

	/* -----------------------------------------------------------------
	 * Password: eye toggle + strength meter
	 * ----------------------------------------------------------------- */

	function initPwToggles() {
		$( document ).off( 'click.vsEye' ).on( 'click.vsEye', '[data-vs-eye]', function ( e ) {
			e.preventDefault();
			var $btn = $( this );
			var $input = $btn.closest( '.zymarg-vs-pw-wrap' ).find( 'input' );
			if ( ! $input.length ) { return; }
			var showing = $input.attr( 'type' ) === 'text';
			$input.attr( 'type', showing ? 'password' : 'text' );
			$btn.attr(
				'aria-label',
				showing
					? ( I18N.showPassword || 'Show password' )
					: ( I18N.hidePassword || 'Hide password' )
			);
			$btn.toggleClass( 'is-showing', ! showing );
		} );
	}

	// Strength score: 0..4 lit segments.
	function scorePassword( pw ) {
		if ( ! pw ) { return 0; }
		var score = 0;
		if ( pw.length >= 8 ) { score = 1; }
		if ( /[a-z]/.test( pw ) && /[A-Z]/.test( pw ) ) { score = Math.max( score, 2 ); }
		if ( /\d/.test( pw ) ) { score = Math.max( score, 3 ); }
		if ( /[^A-Za-z0-9]/.test( pw ) ) { score = Math.max( score, 4 ); }
		// Very short passwords never light up regardless of variety.
		if ( pw.length < 8 && score > 1 ) { score = 1; }
		return score;
	}

	function paintStrength( $bar, score ) {
		if ( ! $bar || ! $bar.length ) { return; }
		$bar.removeClass( 'is-lit-1 is-lit-2 is-lit-3 is-lit-4' );
		if ( score > 0 ) {
			$bar.addClass( 'is-lit-' + score );
		}
	}

	function initStrengthMeter() {
		$( document ).off( 'input.vsStrength' ).on( 'input.vsStrength', '#zymarg-vs-pw-new', function () {
			var $bar = $( this ).closest( '.zymarg-zp-field' ).find( '[data-vs-strength]' );
			paintStrength( $bar, scorePassword( $( this ).val() ) );
		} );
	}

	/* -----------------------------------------------------------------
	 * Section 1 — Account
	 * ----------------------------------------------------------------- */

	function initEmailChangeReveal() {
		$( document ).off( 'click.vsEmail' ).on( 'click.vsEmail', '#zymarg-vs-email-change', function ( e ) {
			e.preventDefault();
			var $email = $( '#zymarg-vs-email' );
			var $confirm = $( '#zymarg-vs-email-confirm' );
			$email.prop( 'disabled', false ).trigger( 'focus' );
			$confirm.removeAttr( 'hidden' );
			$( this ).attr( 'hidden', 'hidden' );
		} );
	}

	function initAvatarChange() {
		// The Change Avatar button plugs into store-upload.js by dispatching a
		// custom event. If the uploader isn't around we fall back to a plain
		// gallery pick via a hidden file input.
		$( document ).off( 'click.vsAvatar' ).on( 'click.vsAvatar', '#zymarg-vs-avatar-change', function ( e ) {
			e.preventDefault();

			// 1. Prefer the existing store-upload API when available.
			if ( window.ZymargVDUpload && typeof window.ZymargStoreUploaderOpen === 'function' ) {
				window.ZymargStoreUploaderOpen( { target: 'avatar' } );
				return;
			}

			// 2. Otherwise fire a public event the uploader can subscribe to.
			var evt;
			try {
				evt = new CustomEvent( 'zymarg-vd:open-store-uploader', { detail: { target: 'avatar' } } );
			} catch ( _err ) {
				evt = document.createEvent( 'CustomEvent' );
				evt.initCustomEvent( 'zymarg-vd:open-store-uploader', true, true, { target: 'avatar' } );
			}
			document.dispatchEvent( evt );
		} );

		// When the uploader finishes, refresh the preview.
		document.addEventListener( 'zymarg-vd:store-avatar-updated', function ( e ) {
			var url = ( e && e.detail && e.detail.url ) || '';
			if ( url ) {
				$( '#zymarg-vs-avatar-preview' ).attr( 'src', url );
			}
		} );
	}

	function validateAccount( data ) {
		if ( ! data.display_name || data.display_name.length < 1 ) {
			return I18N.error || 'Display name is required.';
		}
		if ( data.email ) {
			// Cheap client-side sanity check; server does the real one.
			var emailRe = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;
			if ( ! emailRe.test( data.email ) ) {
				return I18N.emailInvalid || 'Invalid email';
			}
		}
		if ( data.phone && ! /^\d{10}$/.test( data.phone ) ) {
			return I18N.phoneInvalid || 'Invalid phone';
		}
		return '';
	}

	function saveAccount( e ) {
		e.preventDefault();
		var $form = $( '#zymarg-vs-account-form' );
		var $flash = $form.find( '[data-vs-flash]' );
		var $btn = $form.find( 'button[type="submit"]' );

		var data = {
			action: 'zymarg_vd_settings_save_account',
			nonce: NONCE,
			display_name: $.trim( $form.find( '[name="display_name"]' ).val() || '' ),
			email: $.trim( $form.find( '[name="email"]' ).val() || '' ),
			phone: $.trim( $form.find( '[name="phone"]' ).val() || '' ).replace( /\D+/g, '' ),
			current_password: $form.find( '[name="current_password"]' ).val() || ''
		};

		var err = validateAccount( data );
		if ( err ) {
			setFlash( $flash, err, false );
			return;
		}

		$btn.prop( 'disabled', true );
		setFlash( $flash, I18N.saving || 'Saving…', true );

		$.ajax( {
			url: AJAX_URL,
			type: 'POST',
			dataType: 'json',
			data: data
		} ).done( function ( res ) {
			$btn.prop( 'disabled', false );
			if ( res && res.success ) {
				setFlash( $flash, ( res.data && res.data.message ) || I18N.saved, true );
				// Reset the password field + collapse the reveal on success.
				$form.find( '[name="current_password"]' ).val( '' );
				$( '#zymarg-vs-email-confirm' ).attr( 'hidden', 'hidden' );
				$( '#zymarg-vs-email' ).prop( 'disabled', true );
				$( '#zymarg-vs-email-change' ).removeAttr( 'hidden' );
			} else {
				setFlash( $flash, ( res && res.data && res.data.message ) || I18N.error, false );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			setFlash( $flash, I18N.error || 'Error', false );
		} );
	}

	function initAccountSubmit() {
		$( document ).off( 'submit.vsAccount' ).on( 'submit.vsAccount', '#zymarg-vs-account-form', saveAccount );
	}

	/* -----------------------------------------------------------------
	 * Section 2 — Change Password
	 * ----------------------------------------------------------------- */

	function changePassword( e ) {
		e.preventDefault();
		var $form = $( '#zymarg-vs-password-form' );
		var $flash = $form.find( '[data-vs-flash]' );
		var $btn = $form.find( 'button[type="submit"]' );

		var current = $form.find( '[name="current_password"]' ).val() || '';
		var next = $form.find( '[name="new_password"]' ).val() || '';
		var confirmPw = $form.find( '[name="confirm_password"]' ).val() || '';

		if ( ! current || ! next || ! confirmPw ) {
			setFlash( $flash, I18N.error || 'All fields are required.', false );
			return;
		}
		if ( next.length < 8 ) {
			setFlash( $flash, I18N.pwTooShort || 'Password too short.', false );
			return;
		}
		if ( next !== confirmPw ) {
			setFlash( $flash, I18N.pwMismatch || 'Passwords do not match.', false );
			return;
		}

		$btn.prop( 'disabled', true );
		setFlash( $flash, I18N.saving || 'Saving…', true );

		$.ajax( {
			url: AJAX_URL,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'zymarg_vd_settings_change_password',
				nonce: NONCE,
				current_password: current,
				new_password: next,
				confirm_password: confirmPw
			}
		} ).done( function ( res ) {
			$btn.prop( 'disabled', false );
			if ( res && res.success ) {
				setFlash( $flash, ( res.data && res.data.message ) || I18N.passwordUpdated || I18N.saved, true );
				$form[ 0 ].reset();
				paintStrength( $form.find( '[data-vs-strength]' ), 0 );
			} else {
				setFlash( $flash, ( res && res.data && res.data.message ) || I18N.error, false );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			setFlash( $flash, I18N.error || 'Error', false );
		} );
	}

	function initPasswordSubmit() {
		$( document ).off( 'submit.vsPassword' ).on( 'submit.vsPassword', '#zymarg-vs-password-form', changePassword );
	}

	/* -----------------------------------------------------------------
	 * Section 3 — Notification Preferences
	 * ----------------------------------------------------------------- */

	function saveNotifications( e ) {
		e.preventDefault();
		var $form = $( '#zymarg-vs-notif-form' );
		var $flash = $form.find( '[data-vs-flash]' );
		var $btn = $form.find( 'button[type="submit"]' );

		var prefs = {};
		$form.find( '.zymarg-vs-notif-grid__row' ).each( function () {
			var $row = $( this );
			var key = $row.attr( 'data-event' );
			if ( ! key ) { return; }
			prefs[ key ] = {
				email: $row.find( 'input[name="prefs[' + key + '][email]"]' ).is( ':checked' ) ? 1 : 0,
				push:  $row.find( 'input[name="prefs[' + key + '][push]"]' ).is( ':checked' ) ? 1 : 0,
				sms:   $row.find( 'input[name="prefs[' + key + '][sms]"]' ).is( ':checked' ) ? 1 : 0
			};
		} );

		$btn.prop( 'disabled', true );
		setFlash( $flash, I18N.saving || 'Saving…', true );

		$.ajax( {
			url: AJAX_URL,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'zymarg_vd_settings_save_notifications',
				nonce: NONCE,
				prefs: prefs
			}
		} ).done( function ( res ) {
			$btn.prop( 'disabled', false );
			if ( res && res.success ) {
				setFlash( $flash, ( res.data && res.data.message ) || I18N.saved, true );
			} else {
				setFlash( $flash, ( res && res.data && res.data.message ) || I18N.error, false );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			setFlash( $flash, I18N.error || 'Error', false );
		} );
	}

	function initNotificationsSubmit() {
		$( document ).off( 'submit.vsNotif' ).on( 'submit.vsNotif', '#zymarg-vs-notif-form', saveNotifications );
	}

	/* -----------------------------------------------------------------
	 * v1.29.0 — Shared helpers for Sections 4-7.
	 * ----------------------------------------------------------------- */

	// Wire a live character counter to a text input / textarea.
	// The counter element re-renders on every 'input' event and flips
	// .is-over as soon as the max is reached.
	function bindCounter( inputSelector, counterSelector, max ) {
		var ns = counterSelector.replace( /[^a-z0-9]/gi, '' );
		$( document ).off( 'input.vs-counter-' + ns ).on( 'input.vs-counter-' + ns, inputSelector, function () {
			var len = ( $( this ).val() || '' ).length;
			var $c = $( counterSelector );
			$c.text( len + '/' + max );
			$c.toggleClass( 'is-over', len >= max );
		} );
	}

	// Reset every per-field inline error under the given form.
	function clearFieldErrors( $form ) {
		$form.find( '[data-vs-err]' ).text( '' );
	}
	function setFieldError( $input, msg ) {
		$input.closest( '.zymarg-zp-field' ).find( '[data-vs-err]' ).text( msg || '' );
	}

	/* -----------------------------------------------------------------
	 * Section 4 — Store Preferences
	 * ----------------------------------------------------------------- */

	function saveStorePreferences( e ) {
		e.preventDefault();
		var $form = $( '#zymarg-vs-store-preferences-form' );
		var $flash = $form.find( '[data-vs-flash]' );
		var $btn = $form.find( 'button[type="submit"]' );

		var data = {
			action: 'zymarg_vd_settings_save_store_preferences',
			nonce: NONCE,
			auto_accept: $form.find( '[name="auto_accept"]' ).is( ':checked' ) ? 1 : 0,
			min_order_value: $.trim( $form.find( '[name="min_order_value"]' ).val() || '' ),
			default_order_note: $form.find( '[name="default_order_note"]' ).val() || ''
		};

		$btn.prop( 'disabled', true );
		setFlash( $flash, I18N.saving || 'Saving…', true );

		$.ajax( {
			url: AJAX_URL,
			type: 'POST',
			dataType: 'json',
			data: data
		} ).done( function ( res ) {
			$btn.prop( 'disabled', false );
			if ( res && res.success ) {
				setFlash( $flash, ( res.data && res.data.message ) || I18N.saved, true );
			} else {
				setFlash( $flash, ( res && res.data && res.data.message ) || I18N.error, false );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			setFlash( $flash, I18N.error || 'Error', false );
		} );
	}

	/* -----------------------------------------------------------------
	 * Section 5 — Store Profile.
	 *
	 * v1.33.0: the "Public phone" and "Show my email" fields were removed
	 * entirely (they displayed real vendor contact details to customers on
	 * the public storefront — this marketplace's policy is that buyers only
	 * ever reach a vendor through Contact Seller messaging). The banner
	 * field was also removed FROM THIS FORM — it now uploads instantly via
	 * store-upload.js's shared crop+compress+AJAX flow (the same one that
	 * powers the sidebar avatar), generalized in v1.33.0 to a second
	 * 'banner' target, instead of the WordPress admin Media Library picker
	 * this used before (wrong tool for a vendor-facing, mobile picker).
	 * initBannerMediaPicker() and its wp.media() code were removed along
	 * with the old banner field.
	 * ----------------------------------------------------------------- */

	/* -----------------------------------------------------------------
	 * Story word counters
	 *
	 * The server enforces these same limits; this is only so the seller
	 * sees the problem while typing instead of after pressing Save. The
	 * word count deliberately splits on whitespace rather than using a
	 * Latin-only word regex, so it counts Bangla the same way the PHP
	 * side does.
	 * ----------------------------------------------------------------- */

	function countWords( text ) {
		var t = $.trim( String( text == null ? '' : text ) );
		if ( ! t ) {
			return 0;
		}
		return t.split( /\s+/ ).length;
	}

	function readStoryCounter( $counter ) {
		var key = $counter.attr( 'data-word-counter-for' );
		var min = parseInt( $counter.attr( 'data-word-min' ), 10 ) || 0;
		var max = parseInt( $counter.attr( 'data-word-max' ), 10 ) || 0;
		var $field = $( '#zymarg-vs-store-profile-form' ).find( '[name="' + key + '"]' );

		if ( ! $field.length ) {
			return null;
		}

		var words = countWords( $field.val() );
		var state = 'ok';

		if ( 0 === words ) {
			state = 'empty';
		} else if ( words < min ) {
			state = 'under';
		} else if ( words > max ) {
			state = 'over';
		}

		return { key: key, min: min, max: max, words: words, state: state, $field: $field };
	}

	function paintStoryCounter( $counter ) {
		var info = readStoryCounter( $counter );
		if ( ! info ) {
			return null;
		}

		var text;
		if ( 'empty' === info.state ) {
			text = info.min + '-' + info.max + ' words';
		} else if ( 'under' === info.state ) {
			text = info.words + ' words - at least ' + info.min + ' needed';
		} else if ( 'over' === info.state ) {
			text = info.words + ' words - ' + ( info.words - info.max ) + ' over the limit';
		} else {
			text = info.words + ' / ' + info.max + ' words';
		}

		$counter
			.text( text )
			.toggleClass( 'is-over', 'over' === info.state )
			.toggleClass( 'is-under', 'under' === info.state )
			.toggleClass( 'is-ok', 'ok' === info.state );

		info.$field.toggleClass( 'has-word-error', 'over' === info.state || 'under' === info.state );

		return info;
	}

	function refreshStoryCounters() {
		var problems = [];

		$( '.zymarg-vs-wordcount[data-word-counter-for]' ).each( function () {
			var info = paintStoryCounter( $( this ) );
			if ( info && ( 'over' === info.state || 'under' === info.state ) ) {
				problems.push( info );
			}
		} );

		return problems;
	}

	function saveStoreProfile( e ) {
		e.preventDefault();
		var $form = $( '#zymarg-vs-store-profile-form' );
		var $flash = $form.find( '[data-vs-flash]' );

		// Stop here if any story field breaks its word limits.
		var wordProblems = refreshStoryCounters();
		if ( wordProblems.length ) {
			var first = wordProblems[0];
			var msg = 'under' === first.state
				? 'Please write at least ' + first.min + ' words in that field.'
				: 'Please shorten that field to ' + first.max + ' words or fewer.';
			setFlash( $flash, msg, false );
			if ( first.$field && first.$field.length ) {
				first.$field.trigger( 'focus' );
			}
			return;
		}
		var $btn = $form.find( 'button[type="submit"]' );

		var data = {
			action: 'zymarg_vd_settings_save_store_profile',
			nonce: NONCE,
			store_name: $.trim( $form.find( '[name="store_name"]' ).val() || '' ),
			store_tagline: $.trim( $form.find( '[name="store_tagline"]' ).val() || '' ),
			story_headline: $.trim( $form.find( '[name="story_headline"]' ).val() || '' ),
			story_short: $.trim( $form.find( '[name="story_short"]' ).val() || '' ),
			story_more: $.trim( $form.find( '[name="story_more"]' ).val() || '' ),
			'address[street_1]': $form.find( '[name="address[street_1]"]' ).val() || '',
			'address[street_2]': $form.find( '[name="address[street_2]"]' ).val() || '',
			'address[city]': $form.find( '[name="address[city]"]' ).val() || '',
			'address[zip]': $form.find( '[name="address[zip]"]' ).val() || '',
			'address[state]': $form.find( '[name="address[state]"]' ).val() || '',
			'address[country]': $form.find( '[name="address[country]"]' ).val() || '',
			vacation_on: $form.find( '[name="vacation_on"]' ).is( ':checked' ) ? 1 : 0,
			vacation_message: $form.find( '[name="vacation_message"]' ).val() || '',
			vacation_disable_cart: $form.find( '[name="vacation_disable_cart"]' ).is( ':checked' ) ? 1 : 0
		};

		$btn.prop( 'disabled', true );
		setFlash( $flash, I18N.saving || 'Saving…', true );

		$.ajax( {
			url: AJAX_URL,
			type: 'POST',
			dataType: 'json',
			data: data
		} ).done( function ( res ) {
			$btn.prop( 'disabled', false );
			if ( res && res.success ) {
				setFlash( $flash, ( res.data && res.data.message ) || I18N.saved, true );
			} else {
				setFlash( $flash, ( res && res.data && res.data.message ) || I18N.error, false );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			setFlash( $flash, I18N.error || 'Error', false );
		} );
	}

	/* -----------------------------------------------------------------
	 * Section 6 — Tax & Business Info
	 * ----------------------------------------------------------------- */

	function saveBusiness( e ) {
		e.preventDefault();
		var $form = $( '#zymarg-vs-tax-business-form' );
		var $flash = $form.find( '[data-vs-flash]' );
		var $btn = $form.find( 'button[type="submit"]' );

		var data = {
			action: 'zymarg_vd_settings_save_business',
			nonce: NONCE,
			business_bin: $.trim( $form.find( '[name="business_bin"]' ).val() || '' ),
			business_tin: $.trim( $form.find( '[name="business_tin"]' ).val() || '' ),
			business_name: $.trim( $form.find( '[name="business_name"]' ).val() || '' ),
			business_trade_license: $.trim( $form.find( '[name="business_trade_license"]' ).val() || '' ),
			business_address: $form.find( '[name="business_address"]' ).val() || ''
		};

		$btn.prop( 'disabled', true );
		setFlash( $flash, I18N.saving || 'Saving…', true );

		$.ajax( {
			url: AJAX_URL,
			type: 'POST',
			dataType: 'json',
			data: data
		} ).done( function ( res ) {
			$btn.prop( 'disabled', false );
			if ( res && res.success ) {
				setFlash( $flash, ( res.data && res.data.message ) || I18N.saved, true );
			} else {
				setFlash( $flash, ( res && res.data && res.data.message ) || I18N.error, false );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			setFlash( $flash, I18N.error || 'Error', false );
		} );
	}

	/* -----------------------------------------------------------------
	 * Section 6 — SEO & Store Meta (WP media picker + save)
	 * ----------------------------------------------------------------- */

	// wp.media() frame is created lazily so we don't pay for it on stores
	// where the vendor never touches the SEO card.
	var ogMediaFrame = null;

	function initOgMediaPicker() {
		$( document ).off( 'click.vsOgChoose' ).on( 'click.vsOgChoose', '.zymarg-vs-og-choose', function ( e ) {
			e.preventDefault();
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}
			if ( ! ogMediaFrame ) {
				ogMediaFrame = wp.media( {
					title: 'Choose social share image',
					button: { text: 'Use this image' },
					library: { type: 'image' },
					multiple: false
				} );
				ogMediaFrame.on( 'select', function () {
					var att = ogMediaFrame.state().get( 'selection' ).first().toJSON();
					var url = ( att.sizes && att.sizes.medium ) ? att.sizes.medium.url : att.url;
					$( '#zymarg-vs-og-image-id' ).val( att.id );
					$( '.zymarg-vs-og-preview' ).attr( 'src', url ).css( 'display', 'block' );
					$( '.zymarg-vs-og-remove' ).css( 'display', '' );
				} );
			}
			ogMediaFrame.open();
		} );

		$( document ).off( 'click.vsOgRemove' ).on( 'click.vsOgRemove', '.zymarg-vs-og-remove', function ( e ) {
			e.preventDefault();
			$( '#zymarg-vs-og-image-id' ).val( '' );
			$( '.zymarg-vs-og-preview' ).css( 'display', 'none' ).attr( 'src', '' );
			$( this ).css( 'display', 'none' );
		} );
	}

	function saveSeo( e ) {
		e.preventDefault();
		var $form = $( '#zymarg-vs-seo-form' );
		var $flash = $form.find( '[data-vs-flash]' );
		var $btn = $form.find( 'button[type="submit"]' );

		var data = {
			action: 'zymarg_vd_settings_save_seo',
			nonce: NONCE,
			seo_title: $.trim( $form.find( '[name="seo_title"]' ).val() || '' ),
			seo_desc: $form.find( '[name="seo_desc"]' ).val() || '',
			og_image_id: $.trim( $form.find( '[name="og_image_id"]' ).val() || '' ),
			og_title: $.trim( $form.find( '[name="og_title"]' ).val() || '' ),
			og_desc: $form.find( '[name="og_desc"]' ).val() || ''
		};

		$btn.prop( 'disabled', true );
		setFlash( $flash, I18N.saving || 'Saving…', true );

		$.ajax( {
			url: AJAX_URL,
			type: 'POST',
			dataType: 'json',
			data: data
		} ).done( function ( res ) {
			$btn.prop( 'disabled', false );
			if ( res && res.success ) {
				setFlash( $flash, ( res.data && res.data.message ) || I18N.saved, true );
			} else {
				setFlash( $flash, ( res && res.data && res.data.message ) || I18N.error, false );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			setFlash( $flash, I18N.error || 'Error', false );
		} );
	}

	/* -----------------------------------------------------------------
	 * Section 7 — Social Links
	 * ----------------------------------------------------------------- */

	var URL_RE = /^https?:\/\/[^\s]+$/i;

	function saveSocial( e ) {
		e.preventDefault();
		var $form = $( '#zymarg-vs-social-links-form' );
		var $flash = $form.find( '[data-vs-flash]' );
		var $btn = $form.find( 'button[type="submit"]' );

		clearFieldErrors( $form );

		var urls = {
			social_fb:        $.trim( $form.find( '[name="social_fb"]' ).val() || '' ),
			social_instagram: $.trim( $form.find( '[name="social_instagram"]' ).val() || '' ),
			social_youtube:   $.trim( $form.find( '[name="social_youtube"]' ).val() || '' ),
			social_twitter:   $.trim( $form.find( '[name="social_twitter"]' ).val() || '' ),
			social_tiktok:    $.trim( $form.find( '[name="social_tiktok"]' ).val() || '' )
		};
		var whatsapp = $.trim( $form.find( '[name="social_whatsapp"]' ).val() || '' ).replace( /\D+/g, '' );

		// Validate every URL that isn't empty.
		var hasError = false;
		$.each( urls, function ( name, val ) {
			if ( val && ! URL_RE.test( val ) ) {
				setFieldError( $form.find( '[name="' + name + '"]' ), I18N.error || 'Enter a valid URL starting with http:// or https://' );
				hasError = true;
			}
		} );
		if ( whatsapp && ! /^\d{10}$/.test( whatsapp ) ) {
			setFieldError( $form.find( '[name="social_whatsapp"]' ), I18N.phoneInvalid || 'Invalid WhatsApp number' );
			hasError = true;
		}
		if ( hasError ) {
			setFlash( $flash, I18N.error || 'Please fix the highlighted fields.', false );
			return;
		}

		var data = $.extend( {}, urls, {
			action: 'zymarg_vd_settings_save_social',
			nonce: NONCE,
			social_whatsapp: whatsapp
		} );

		$btn.prop( 'disabled', true );
		setFlash( $flash, I18N.saving || 'Saving…', true );

		$.ajax( {
			url: AJAX_URL,
			type: 'POST',
			dataType: 'json',
			data: data
		} ).done( function ( res ) {
			$btn.prop( 'disabled', false );
			if ( res && res.success ) {
				setFlash( $flash, ( res.data && res.data.message ) || I18N.saved, true );
			} else {
				setFlash( $flash, ( res && res.data && res.data.message ) || I18N.error, false );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			setFlash( $flash, I18N.error || 'Error', false );
		} );
	}

	/* -----------------------------------------------------------------
	 * v1.30.0 — Section 8 (Data Export): export_error toast on load.
	 * ----------------------------------------------------------------- */

	function checkExportError() {
		try {
			var url = new URL( window.location.href );
			var err = url.searchParams.get( 'export_error' );
			if ( ! err ) { return; }
			// Toast: reuse the Section 8 card flash-note area OR alert-fallback.
			var $card = $( '[data-vs-section="data-export"]' );
			if ( $card.length ) {
				openCard( $card );
				var $note = $card.find( '.zymarg-vs-export-note' );
				var $errBox = $card.find( '.zymarg-vs-export-error' );
				if ( ! $errBox.length ) {
					$errBox = $( '<div class="zymarg-vs-export-error" role="alert"></div>' ).insertBefore( $note.length ? $note : $card.find( '.zymarg-vs-card__body' ).children().last() );
				}
				$errBox.text( err );
			} else if ( window.console && window.console.warn ) {
				window.console.warn( '[zymarg-vd] export error:', err );
			}
			url.searchParams.delete( 'export_error' );
			window.history.replaceState( {}, '', url.toString() );
		} catch ( _e ) {
			// URL constructor unavailable — silently no-op.
		}
	}

	/* -----------------------------------------------------------------
	 * v1.30.0 — Section 9 (Danger Zone).
	 * ----------------------------------------------------------------- */

	// Enable the destructive submit button only when the typed-confirm
	// input matches the expected string (case-insensitive for close,
	// exact-match for delete because the expected is ALL CAPS).
	function initTypedConfirms() {
		$( document ).off( 'input.vsTypedConfirm' ).on( 'input.vsTypedConfirm', '.zymarg-vs-typedconfirm', function () {
			var $input = $( this );
			var expected = String( $input.attr( 'data-vs-expected' ) || '' );
			var val = String( $input.val() || '' );
			var match;
			// Delete uses ALL CAPS string, so require exact match. Store name is case-insensitive.
			if ( expected === 'DELETE MY ACCOUNT' ) {
				match = ( val === expected );
			} else {
				match = ( val.toLowerCase().trim() === expected.toLowerCase().trim() );
			}
			$input.toggleClass( 'is-match', match );
			var $form = $input.closest( 'form' );
			$form.find( '[data-vs-close-submit], [data-vs-delete-submit]' ).prop( 'disabled', ! match );
		} );
	}

	/* v1.32.0: toggleVacation() (Danger Zone's own "Deactivate Store" button)
	 * was removed along with that button — it wrote a typo'd meta key that
	 * was disconnected from the real storefront effects. Vacation mode now
	 * lives exclusively in Section 5 "Store Profile" (saveStoreProfile(),
	 * above), which uses the correct key. */

	function submitCloseForm( e ) {
		e.preventDefault();
		var $form = $( this );
		var $flash = $form.find( '[data-vs-flash]' );
		var $btn = $form.find( '[data-vs-close-submit]' );
		$btn.prop( 'disabled', true );
		setFlash( $flash, I18N.saving || 'Saving…', true );
		$.ajax( {
			url: AJAX_URL,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'zymarg_vd_settings_request_close',
				nonce: NONCE,
				close_reason: $form.find( '[name="close_reason"]' ).val() || '',
				close_confirm: $form.find( '[name="close_confirm"]' ).val() || ''
			}
		} ).done( function ( res ) {
			$btn.prop( 'disabled', false );
			if ( res && res.success ) {
				setFlash( $flash, ( res.data && res.data.message ) || I18N.saved, true );
				var html = '<div class="zymarg-vs-danger-status zymarg-vs-danger-status--pending">' +
					'<p class="zymarg-vs-danger-status__title">Closure request submitted.</p>' +
					'<p class="zymarg-vs-danger-status__desc">An admin will contact you within 3 business days. To cancel, click below.</p>' +
					'<button type="button" class="zymarg-vs-btn zymarg-vs-btn--ghost" data-vs-cancel-close>Cancel Request</button>' +
					'<span class="zymarg-vs-flash" data-vs-flash></span>' +
					'</div>';
				$form.replaceWith( html );
			} else {
				setFlash( $flash, ( res && res.data && res.data.message ) || I18N.error, false );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			setFlash( $flash, I18N.error || 'Error', false );
		} );
	}

	function cancelClose( e ) {
		e.preventDefault();
		var $btn = $( this );
		var $flash = $btn.siblings( '[data-vs-flash]' );
		$btn.prop( 'disabled', true );
		setFlash( $flash, I18N.saving || 'Saving…', true );
		$.ajax( {
			url: AJAX_URL,
			type: 'POST',
			dataType: 'json',
			data: { action: 'zymarg_vd_settings_cancel_close', nonce: NONCE }
		} ).done( function ( res ) {
			$btn.prop( 'disabled', false );
			if ( res && res.success ) {
				setFlash( $flash, ( res.data && res.data.message ) || I18N.saved, true );
				// Reload the section quickly by reload — the safest way to get the form back.
				window.setTimeout( function () { window.location.reload(); }, 800 );
			} else {
				setFlash( $flash, ( res && res.data && res.data.message ) || I18N.error, false );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			setFlash( $flash, I18N.error || 'Error', false );
		} );
	}

	function submitDeleteForm( e ) {
		e.preventDefault();
		var $form = $( this );
		var $flash = $form.find( '[data-vs-flash]' );
		var $btn = $form.find( '[data-vs-delete-submit]' );
		if ( ! window.confirm( 'This will permanently delete your account 7 days from now. Are you absolutely sure?' ) ) {
			return;
		}
		$btn.prop( 'disabled', true );
		setFlash( $flash, I18N.saving || 'Saving…', true );
		$.ajax( {
			url: AJAX_URL,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'zymarg_vd_settings_schedule_delete',
				nonce: NONCE,
				delete_confirm: $form.find( '[name="delete_confirm"]' ).val() || ''
			}
		} ).done( function ( res ) {
			$btn.prop( 'disabled', false );
			if ( res && res.success ) {
				setFlash( $flash, ( res.data && res.data.message ) || I18N.saved, true );
				var dstr = ( res.data && res.data.display ) || '';
				var html = '<div class="zymarg-vs-danger-status zymarg-vs-danger-status--countdown">' +
					'<p class="zymarg-vs-danger-status__title">Account scheduled for deletion.</p>' +
					'<p class="zymarg-vs-danger-status__desc">Your account will be permanently deleted on ' + $( '<div/>' ).text( dstr ).html() + '. Click below to cancel.</p>' +
					'<button type="button" class="zymarg-vs-btn zymarg-vs-btn--danger-lg" data-vs-cancel-delete>Cancel Deletion</button>' +
					'<span class="zymarg-vs-flash" data-vs-flash></span>' +
					'</div>';
				$form.replaceWith( html );
			} else {
				setFlash( $flash, ( res && res.data && res.data.message ) || I18N.error, false );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			setFlash( $flash, I18N.error || 'Error', false );
		} );
	}

	function cancelDelete( e ) {
		e.preventDefault();
		var $btn = $( this );
		var $flash = $btn.siblings( '[data-vs-flash]' );
		$btn.prop( 'disabled', true );
		setFlash( $flash, I18N.saving || 'Saving…', true );
		$.ajax( {
			url: AJAX_URL,
			type: 'POST',
			dataType: 'json',
			data: { action: 'zymarg_vd_settings_cancel_delete', nonce: NONCE }
		} ).done( function ( res ) {
			$btn.prop( 'disabled', false );
			if ( res && res.success ) {
				setFlash( $flash, ( res.data && res.data.message ) || I18N.saved, true );
				window.setTimeout( function () { window.location.reload(); }, 800 );
			} else {
				setFlash( $flash, ( res && res.data && res.data.message ) || I18N.error, false );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			setFlash( $flash, I18N.error || 'Error', false );
		} );
	}

	// "Download your data first" link — scroll & open Section 8.
	function jumpToDataExport( e ) {
		e.preventDefault();
		var $target = $( '[data-vs-section="data-export"]' );
		if ( ! $target.length ) { return; }
		openCard( $target );
		if ( $target[ 0 ].scrollIntoView ) {
			$target[ 0 ].scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}
	}

	/* -----------------------------------------------------------------
	 * v1.31.0 — Section 11 (Push Opt-in) removed. savePushOptin() /
	 * sendTestPush() and their bindings were deleted along with it — the
	 * Push column in Section 3's Notification Preferences form
	 * (saveNotifications(), above) now covers push preferences.
	 * ----------------------------------------------------------------- */

	/* -----------------------------------------------------------------
	 * v1.30.2 — Section 10: Revoke session + Remove passkey handlers.
	 * ----------------------------------------------------------------- */

	function initRevokeSession() {
		$( document ).off( 'click.vsZlsRevoke' ).on( 'click.vsZlsRevoke', '.zymarg-vs-zls-revoke', function ( e ) {
			e.preventDefault();
			var $btn = $( this );
			if ( ! window.confirm( I18N.confirmRevoke || 'Revoke this session?' ) ) { return; }
			$btn.prop( 'disabled', true );
			var tokenId = $btn.data( 'tokenId' );
			$.ajax( {
				url: AJAX_URL,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'zymarg_vd_settings_revoke_session',
					nonce: NONCE,
					token_id: tokenId
				}
			} ).done( function ( res ) {
				if ( res && res.success ) {
					var $row = $btn.closest( 'tr' );
					if ( $row.length ) { $row.remove(); }
				} else {
					$btn.prop( 'disabled', false );
					window.alert( ( res && res.data && res.data.message ) ? res.data.message : 'Error revoking session.' );
				}
			} ).fail( function () {
				$btn.prop( 'disabled', false );
				window.alert( 'Request failed.' );
			} );
		} );
	}

	function initRemovePasskey() {
		$( document ).off( 'click.vsZlsRemovePk' ).on( 'click.vsZlsRemovePk', '.zymarg-vs-zls-remove-pk', function ( e ) {
			e.preventDefault();
			var $btn = $( this );
			if ( ! window.confirm( I18N.confirmRemovePasskey || 'Remove this passkey?' ) ) { return; }
			$btn.prop( 'disabled', true );
			var pkId = $btn.data( 'passkeyId' );
			$.ajax( {
				url: AJAX_URL,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'zymarg_vd_settings_remove_passkey',
					nonce: NONCE,
					passkey_id: pkId
				}
			} ).done( function ( res ) {
				if ( res && res.success ) {
					var $row = $btn.closest( 'tr' );
					if ( $row.length ) { $row.remove(); }
				} else {
					$btn.prop( 'disabled', false );
					window.alert( ( res && res.data && res.data.message ) ? res.data.message : 'Error removing passkey.' );
				}
			} ).fail( function () {
				$btn.prop( 'disabled', false );
				window.alert( 'Request failed.' );
			} );
		} );
	}

	/* -----------------------------------------------------------------
	 * Init
	 * ----------------------------------------------------------------- */

	function initVendorSettings() {
		// Nothing to do if there's no Settings shell in the current DOM.
		if ( ! document.querySelector( '.zymarg-vs' ) ) {
			return;
		}
		// Delegated handlers are bound once, but re-binding is safe (off first).
		initAccordion();
		initPwToggles();
		initStrengthMeter();
		initEmailChangeReveal();
		initAvatarChange();
		initAccountSubmit();
		initPasswordSubmit();
		initNotificationsSubmit();

		// v1.29.0/v1.32.0 — Sections 4-8 submit handlers.
		$( document ).off( 'submit.vsStorePrefs' ).on( 'submit.vsStorePrefs', '#zymarg-vs-store-preferences-form', saveStorePreferences );
		$( document ).off( 'submit.vsStoreProfile' ).on( 'submit.vsStoreProfile', '#zymarg-vs-store-profile-form', saveStoreProfile );

		$( document )
			.off( 'input.vsStoryWords' )
			.on( 'input.vsStoryWords', '#zymarg-vs-store-profile-form [name="store_tagline"], #zymarg-vs-store-profile-form [name="story_headline"], #zymarg-vs-store-profile-form [name="story_short"], #zymarg-vs-store-profile-form [name="story_more"]', function () {
				var name = $( this ).attr( 'name' );
				paintStoryCounter( $( '.zymarg-vs-wordcount[data-word-counter-for="' + name + '"]' ) );
			} );

		// Paint the counters once on load so the limits are visible before typing.
		refreshStoryCounters();
		$( document ).off( 'submit.vsBusiness' ).on( 'submit.vsBusiness', '#zymarg-vs-tax-business-form', saveBusiness );
		$( document ).off( 'submit.vsSeo' ).on( 'submit.vsSeo', '#zymarg-vs-seo-form', saveSeo );
		$( document ).off( 'submit.vsSocial' ).on( 'submit.vsSocial', '#zymarg-vs-social-links-form', saveSocial );

		// v1.29.0 — SEO's OG-image media picker + live counters. (v1.33.0:
		// the banner media picker was removed — the banner now uploads via
		// store-upload.js's shared crop+compress flow, initialized separately
		// on 'zymarg-vd:section-loaded' from within that file itself.)
		initOgMediaPicker();
		bindCounter( '#zymarg-vs-seo-title', '#zymarg-vs-seo-title-counter', 60 );
		bindCounter( '#zymarg-vs-seo-desc', '#zymarg-vs-seo-desc-counter', 160 );
		bindCounter( '#zymarg-vs-og-title', '#zymarg-vs-og-title-counter', 100 );
		bindCounter( '#zymarg-vs-og-desc', '#zymarg-vs-og-desc-counter', 200 );
		bindCounter( '#zymarg-vs-default-order-note', '#zymarg-vs-default-order-note-counter', 500 );

		// v1.30.0 — Sections 8-11 delegated handlers.
		checkExportError();
		initTypedConfirms();

		$( document ).off( 'submit.vsCloseForm' ).on( 'submit.vsCloseForm', '[data-vs-close-form]', submitCloseForm );
		$( document ).off( 'click.vsCancelClose' ).on( 'click.vsCancelClose', '[data-vs-cancel-close]', cancelClose );
		$( document ).off( 'submit.vsDeleteForm' ).on( 'submit.vsDeleteForm', '[data-vs-delete-form]', submitDeleteForm );
		$( document ).off( 'click.vsCancelDelete' ).on( 'click.vsCancelDelete', '[data-vs-cancel-delete]', cancelDelete );
		$( document ).off( 'click.vsJumpTo' ).on( 'click.vsJumpTo', '[data-vs-jump-to="data-export"]', jumpToDataExport );

		// v1.30.2 — Section 10 action buttons.
		initRevokeSession();
		initRemovePasskey();

		boundDelegates = true;

		// Paint the strength meter on first render if a value is already there
		// (e.g. browser autofill).
		var pw = document.getElementById( 'zymarg-vs-pw-new' );
		if ( pw && pw.value ) {
			paintStrength( $( pw ).closest( '.zymarg-zp-field' ).find( '[data-vs-strength]' ), scorePassword( pw.value ) );
		}
	}

	// Initial load.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initVendorSettings );
	} else {
		initVendorSettings();
	}

	// Re-init after an SPA section swap (only when the settings section landed).
	document.addEventListener( 'zymarg-vd:section-loaded', function ( e ) {
		if ( e && e.detail && e.detail.section === 'settings' ) {
			initVendorSettings();
		} else if ( ! boundDelegates ) {
			// First-ever swap that isn't 'settings' — do nothing.
		}
	} );

}( window.jQuery ) );
