/**
 * HappyAccess inline login with an emailed code.
 *
 * Runs every [data-happyaccess-pl] block on the page. The block holds no
 * form element, because WooCommerce prints it inside its own login form, so
 * Enter in a field is caught here instead of submitting that form.
 */
( function () {
	'use strict';

	var config = window.happyaccessLogin;
	if ( ! config || ! window.fetch ) {
		return;
	}

	function post( path, data ) {
		var headers = { 'Content-Type': 'application/json' };
		headers[ config.header ] = '1';
		return window
			.fetch( config.url + path, {
				method: 'POST',
				credentials: 'same-origin',
				headers: headers,
				body: JSON.stringify( data ),
			} )
			.then( function ( response ) {
				return response
					.json()
					.catch( function () {
						return {};
					} )
					.then( function ( body ) {
						return { ok: response.ok, body: body || {} };
					} );
			} );
	}

	function init( root ) {
		var find = function ( selector ) {
			return root.querySelector( selector );
		};
		var toggle = find( '.happyaccess-pl__toggle' );
		var toggleRow = find( '.happyaccess-pl__toggle-row' );
		var fallback = find( '.happyaccess-pl__fallback' );
		var panel = find( '.happyaccess-pl__panel' );
		var steps = {
			request: find( '[data-step="request"]' ),
			verify: find( '[data-step="verify"]' ),
		};
		var login = find( '[data-field="login"]' );
		var code = find( '[data-field="code"]' );
		var remember = find( '[data-field="remember"]' );
		var message = find( '.happyaccess-pl__message' );
		var busy = false;

		if ( ! toggle || ! panel || ! login || ! code || ! message ) {
			return;
		}

		// The plain link stays until the script is sure it can run the toggle.
		if ( toggleRow ) {
			toggleRow.hidden = false;
		}
		if ( fallback ) {
			fallback.hidden = true;
		}

		// Text goes in first, focus moves on the next tick, so a screen
		// reader announces the live region before it reads the focused field.
		function say( text, isError, target ) {
			message.textContent = text || '';
			message.classList.toggle( 'is-error', !! isError );
			if ( isError && text ) {
				target = message;
			}
			if ( target ) {
				window.setTimeout( function () {
					target.focus();
				}, 0 );
			}
		}

		function show( name ) {
			steps.request.hidden = 'request' !== name;
			steps.verify.hidden = 'verify' !== name;
		}

		function run( button, path, data, done ) {
			if ( busy ) {
				return;
			}
			busy = true;
			button.disabled = true;
			root.setAttribute( 'aria-busy', 'true' );
			post( path, data )
				.then( function ( result ) {
					if ( result.ok ) {
						done( result.body );
					} else {
						say( result.body.message || config.i18n.error, true );
					}
				} )
				.catch( function () {
					say( config.i18n.error, true );
				} )
				.then( function () {
					busy = false;
					button.disabled = false;
					root.removeAttribute( 'aria-busy' );
				} );
		}

		function request() {
			var typed = login.value.trim();
			if ( ! typed ) {
				say( config.i18n.empty, true );
				return;
			}
			run( find( '[data-action="request"]' ), 'request', { login: typed }, function ( body ) {
				show( 'verify' );
				code.value = '';
				say( body.message, false, code );
			} );
		}

		function verify() {
			if ( ! code.value.trim() ) {
				say( config.i18n.noCode, true );
				return;
			}
			run(
				find( '[data-action="verify"]' ),
				'verify',
				{
					code: code.value.trim(),
					remember: !! ( remember && remember.checked ),
					redirect_to: root.getAttribute( 'data-redirect' ) || '',
				},
				function ( body ) {
					if ( body.redirect ) {
						window.location.assign( body.redirect );
					} else {
						say( config.i18n.error, true );
					}
				}
			);
		}

		toggle.addEventListener( 'click', function () {
			var open = 'true' !== toggle.getAttribute( 'aria-expanded' );
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			panel.hidden = ! open;
			if ( open ) {
				( steps.verify.hidden ? login : code ).focus();
			}
		} );

		root.addEventListener( 'click', function ( event ) {
			var target = event.target.closest( '[data-action]' );
			if ( ! target || ! root.contains( target ) ) {
				return;
			}
			event.preventDefault();
			var action = target.getAttribute( 'data-action' );
			if ( 'request' === action ) {
				request();
			} else if ( 'verify' === action ) {
				verify();
			} else if ( 'restart' === action ) {
				show( 'request' );
				code.value = '';
				say( '', false, login );
			}
		} );

		root.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' !== event.key ) {
				return;
			}
			if ( event.target === login ) {
				event.preventDefault();
				request();
			} else if ( event.target === code ) {
				event.preventDefault();
				verify();
			}
		} );
	}

	function start() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-happyaccess-pl]' ), init );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
