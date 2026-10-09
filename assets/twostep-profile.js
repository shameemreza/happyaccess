/**
 * HappyAccess two-step section on the user's own profile, and in
 * WooCommerce My Account, which prints the same section with its own classes.
 *
 * Each button posts to a happyaccess/v1/twostep route for the current user.
 * Email codes turn on in two steps: email/begin sends a code, and
 * email/confirm turns them on once that code comes back.
 * A change that needs a fresh re-check opens the inline re-check, then runs
 * again. The QR code and the backup code tools come from twostep-setup.js
 * (window.happyaccessTwoStep). After a change the page reloads, unless new
 * backup codes came back: those show once, and Done reloads.
 *
 * The section sits inside the profile form, so Enter in one of its fields
 * runs the panel's primary button instead of saving the profile.
 */
( function () {
	'use strict';

	var config = window.happyaccessTwoStepProfile || {};
	var strings = config.strings || {};
	var shared = window.happyaccessTwoStep || {};
	var root = null;
	var pending = null;
	var mode = 'password';

	function find( selector ) {
		return root.querySelector( selector );
	}

	function panel( name ) {
		return find( '[data-happyaccess-panel="' + name + '"]' );
	}

	function errorBox() {
		return document.getElementById( 'happyaccess-ts-profile-error' );
	}

	function showError( text, field ) {
		var box = errorBox();
		if ( ! box ) {
			return;
		}
		box.querySelector( 'p' ).textContent = text || strings.failed || '';
		box.hidden = false;
		if ( field ) {
			field.setAttribute( 'aria-describedby', box.id );
			field.focus();
		}
	}

	function clearError() {
		var box = errorBox();
		if ( box ) {
			box.hidden = true;
			box.querySelector( 'p' ).textContent = '';
		}
	}

	function announce( text ) {
		var live = find( '[data-happyaccess-live]' );
		if ( ! live ) {
			return;
		}
		live.textContent = '';
		// A fresh text node each time, so screen readers announce a repeat.
		window.setTimeout( function () {
			live.textContent = text || '';
		}, 50 );
	}

	function setBusy( busy ) {
		var buttons = root.querySelectorAll( 'button[data-happyaccess-action]' );
		root.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		for ( var i = 0; i < buttons.length; i++ ) {
			if ( 'done' !== buttons[ i ].getAttribute( 'data-happyaccess-action' ) ) {
				buttons[ i ].disabled = busy;
			}
		}
	}

	function post( route, body ) {
		return window
			.fetch( config.restUrl + route, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce,
				},
				body: JSON.stringify( body || {} ),
			} )
			.then( function ( response ) {
				return response.json().then(
					function ( data ) {
						return { ok: response.ok, data: data || {} };
					},
					function () {
						return { ok: false, data: {} };
					}
				);
			} );
	}

	/**
	 * Runs one route. A refusal for a missing re-check keeps the action and
	 * opens the re-check. Resolves with the data on success, else null.
	 */
	function run( action, route, body, field ) {
		clearError();
		setBusy( true );
		return post( route, body ).then(
			function ( result ) {
				setBusy( false );
				if ( result.ok ) {
					return result.data;
				}
				if ( 'happyaccess_recheck_required' === result.data.code ) {
					pending = action;
					openRecheck();
					return null;
				}
				showError( result.data.message, field );
				return null;
			},
			function () {
				setBusy( false );
				showError( strings.failed, field );
				return null;
			}
		);
	}

	function reload() {
		window.location.reload();
	}

	function hidePanels() {
		var panels = root.querySelectorAll( '[data-happyaccess-panel]' );
		for ( var i = 0; i < panels.length; i++ ) {
			panels[ i ].hidden = true;
		}
	}

	function showCodes( codes ) {
		var box = panel( 'codes' );
		var list = box.querySelector( '.happyaccess-ts-codes' );
		list.textContent = '';
		for ( var i = 0; i < codes.length; i++ ) {
			var item = document.createElement( 'li' );
			var code = document.createElement( 'code' );
			code.className = 'happyaccess-ts-backup';
			code.textContent = codes[ i ];
			item.appendChild( code );
			list.appendChild( item );
		}
		hidePanels();
		var methods = find( '[data-happyaccess-methods]' );
		if ( methods ) {
			methods.hidden = true;
		}
		box.hidden = false;
		if ( 'function' === typeof shared.setUpCodes ) {
			shared.setUpCodes( box );
		}
		var heading = box.querySelector( '.happyaccess-ts-heading' );
		if ( heading ) {
			heading.focus();
		}
		announce( strings.codesShown );
	}

	function finish( data ) {
		if ( ! data ) {
			return;
		}
		if ( data.codes && data.codes.length ) {
			showCodes( data.codes );
			return;
		}
		announce( strings.saving );
		reload();
	}

	function openRecheck() {
		var box = panel( 'recheck' );
		box.hidden = false;
		box.querySelector( 'input' ).focus();
	}

	function setMode( next ) {
		var box = panel( 'recheck' );
		var input = box.querySelector( 'input' );
		var label = box.querySelector( 'label' );
		var toggle = box.querySelector( '[data-happyaccess-action="recheck-mode"]' );
		mode = next;
		input.value = '';
		input.type = 'code' === mode ? 'text' : 'password';
		input.setAttribute( 'autocomplete', 'code' === mode ? 'one-time-code' : 'current-password' );
		label.textContent = label.getAttribute( 'data-label-' + mode ) || '';
		toggle.textContent = toggle.getAttribute( 'data-label-' + mode ) || '';
		input.focus();
	}

	var actions = {
		'app-begin': function () {
			run( 'app-begin', 'app/begin' ).then( function ( data ) {
				if ( ! data ) {
					return;
				}
				var box = panel( 'app' );
				var qr = box.querySelector( '.happyaccess-ts-qr' );
				qr.textContent = '';
				qr.hidden = true;
				qr.setAttribute( 'data-happyaccess-uri', data.uri );
				if ( 'function' === typeof shared.drawQr ) {
					shared.drawQr( qr );
				}
				box.querySelector( '.happyaccess-ts-key code' ).textContent = String( data.secret ).replace( /(.{4})(?=.)/g, '$1 ' );
				box.hidden = false;
				find( '[data-happyaccess-action="app-begin"]' ).hidden = true;
				box.querySelector( 'input' ).focus();
				announce( strings.scan );
			} );
		},
		'app-confirm': function () {
			var field = panel( 'app' ).querySelector( 'input' );
			if ( '' === field.value.trim() ) {
				showError( strings.enterCode, field );
				return;
			}
			run( 'app-confirm', 'app/confirm', { code: field.value.trim() }, field ).then( finish );
		},
		'app-disable': function () {
			run( 'app-disable', 'app/disable' ).then( finish );
		},
		'email-begin': function () {
			run( 'email-begin', 'email/begin' ).then( function ( data ) {
				if ( ! data ) {
					return;
				}
				var box = panel( 'email' );
				var input = box.querySelector( 'input' );
				input.value = '';
				box.hidden = false;
				find( '.happyaccess-ts-row [data-happyaccess-action="email-begin"]' ).hidden = true;
				input.focus();
				announce( strings.emailSent );
			} );
		},
		'email-confirm': function () {
			var field = panel( 'email' ).querySelector( 'input' );
			if ( '' === field.value.trim() ) {
				showError( strings.enterEmail, field );
				return;
			}
			run( 'email-confirm', 'email/confirm', { code: field.value.trim() }, field ).then( finish );
		},
		'email-disable': function () {
			run( 'email-disable', 'email/disable' ).then( finish );
		},
		'backup-regenerate': function () {
			run( 'backup-regenerate', 'backup/regenerate' ).then( finish );
		},
		recheck: function () {
			var field = panel( 'recheck' ).querySelector( 'input' );
			var value = field.value;
			if ( '' === value ) {
				showError( strings.enterRecheck, field );
				return;
			}
			var body = 'code' === mode ? { code: value } : { password: value };
			run( 'recheck', 'recheck', body, field ).then( function ( data ) {
				if ( ! data ) {
					return;
				}
				field.value = '';
				panel( 'recheck' ).hidden = true;
				var next = pending;
				pending = null;
				if ( next && actions[ next ] ) {
					actions[ next ]();
				}
			} );
		},
		'recheck-mode': function () {
			setMode( 'code' === mode ? 'password' : 'code' );
		},
		cancel: function ( button ) {
			var box = button.closest( '[data-happyaccess-panel]' );
			if ( box ) {
				box.hidden = true;
				var input = box.querySelector( 'input' );
				if ( input ) {
					input.value = '';
				}
			}
			var begins = root.querySelectorAll( '.happyaccess-ts-row [data-happyaccess-action$="-begin"]' );
			for ( var i = 0; i < begins.length; i++ ) {
				begins[ i ].hidden = false;
			}
			pending = null;
			clearError();
			// The profile's heading, or in My Account the section itself.
			var top = document.getElementById( 'happyaccess-twostep' );
			if ( top ) {
				top.focus();
			}
		},
		done: reload,
	};

	function onClick( event ) {
		var button = event.target.closest( 'button[data-happyaccess-action]' );
		if ( ! button || ! root.contains( button ) ) {
			return;
		}
		var action = actions[ button.getAttribute( 'data-happyaccess-action' ) ];
		if ( action ) {
			event.preventDefault();
			action( button );
		}
	}

	function onKey( event ) {
		if ( 'Enter' !== event.key || ! event.target.matches( 'input' ) ) {
			return;
		}
		var box = event.target.closest( '[data-happyaccess-panel]' );
		if ( ! box ) {
			return;
		}
		// Enter would submit the profile form around the section.
		event.preventDefault();
		var primary = box.querySelector( '[data-happyaccess-primary]' );
		if ( primary && ! primary.disabled ) {
			primary.click();
		}
	}

	function init() {
		root = document.querySelector( '[data-happyaccess-twostep]' );
		if ( ! root || ! config.restUrl || ! window.fetch ) {
			return;
		}
		root.addEventListener( 'click', onClick );
		root.addEventListener( 'keydown', onKey );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
