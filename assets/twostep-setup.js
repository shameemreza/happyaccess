/**
 * HappyAccess two-step setup at login.
 *
 * Draws the QR code from the otpauth address with the bundled
 * qrcode-generator library, and runs the backup code tools: Copy, Download
 * as text, and the "I saved these codes" check that enables Continue.
 * Without this script the setup key and the codes still show as text, and
 * the checkbox is required by the browser.
 *
 * The profile section reuses drawQr() and setUpCodes() through
 * window.happyaccessTwoStep, after it fills the boxes from the REST routes.
 */
( function () {
	'use strict';

	var SVG = 'http://www.w3.org/2000/svg';

	function drawQr( box ) {
		var uri = box.getAttribute( 'data-happyaccess-uri' );
		if ( ! uri || 'function' !== typeof window.qrcode ) {
			return;
		}

		var qr;
		try {
			qr = window.qrcode( 0, 'M' );
			qr.addData( uri );
			qr.make();
		} catch ( e ) {
			return;
		}

		var count = qr.getModuleCount();
		var quiet = 4;
		var size = count + quiet * 2;
		var path = '';
		for ( var row = 0; row < count; row++ ) {
			for ( var col = 0; col < count; col++ ) {
				if ( qr.isDark( row, col ) ) {
					path += 'M' + ( col + quiet ) + ' ' + ( row + quiet ) + 'h1v1h-1z';
				}
			}
		}

		var svg = document.createElementNS( SVG, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 ' + size + ' ' + size );
		svg.setAttribute( 'shape-rendering', 'crispEdges' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );

		var back = document.createElementNS( SVG, 'rect' );
		back.setAttribute( 'width', String( size ) );
		back.setAttribute( 'height', String( size ) );
		back.setAttribute( 'fill', '#fff' );
		svg.appendChild( back );

		var dark = document.createElementNS( SVG, 'path' );
		dark.setAttribute( 'd', path );
		dark.setAttribute( 'fill', '#000' );
		svg.appendChild( dark );

		box.appendChild( svg );
		box.hidden = false;
	}

	function codesText( list ) {
		var codes = [];
		var items = list.querySelectorAll( 'code' );
		for ( var i = 0; i < items.length; i++ ) {
			codes.push( items[ i ].textContent );
		}
		return codes.join( '\n' );
	}

	function say( status, key ) {
		if ( ! status ) {
			return;
		}
		status.textContent = '';
		// A fresh text node each time, so screen readers announce a repeat.
		window.setTimeout( function () {
			status.textContent = status.getAttribute( key ) || '';
		}, 50 );
	}

	function copyText( text ) {
		if ( window.navigator.clipboard && window.isSecureContext ) {
			return window.navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			var area = document.createElement( 'textarea' );
			area.value = text;
			area.setAttribute( 'readonly', '' );
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.select();
			var done = false;
			try {
				done = document.execCommand( 'copy' );
			} catch ( e ) {
				done = false;
			}
			document.body.removeChild( area );
			if ( done ) {
				resolve();
			} else {
				reject();
			}
		} );
	}

	function setUpCodes( form ) {
		if ( form.getAttribute( 'data-happyaccess-ready' ) ) {
			return;
		}
		form.setAttribute( 'data-happyaccess-ready', '1' );

		var list = form.querySelector( '.happyaccess-ts-codes' );
		var tools = form.querySelector( '.happyaccess-ts-tools' );
		var status = form.querySelector( '.happyaccess-ts-status' );
		var saved = form.querySelector( '#happyaccess-ts-saved' );
		var next = form.querySelector( '#happyaccess-ts-continue' );

		if ( saved && next ) {
			var sync = function () {
				next.disabled = ! saved.checked;
			};
			saved.addEventListener( 'change', sync );
			sync();
		}

		if ( ! list || ! tools ) {
			return;
		}

		var copy = tools.querySelector( '[data-happyaccess-copy]' );
		var download = tools.querySelector( '[data-happyaccess-download]' );

		if ( copy ) {
			copy.addEventListener( 'click', function () {
				copyText( codesText( list ) ).then(
					function () {
						say( status, 'data-copied' );
					},
					function () {
						say( status, 'data-copy-failed' );
					}
				);
			} );
		}

		if ( download && window.Blob && window.URL && window.URL.createObjectURL ) {
			download.addEventListener( 'click', function () {
				var heading = download.getAttribute( 'data-heading' ) || '';
				var text = ( heading ? heading + '\n\n' : '' ) + codesText( list ) + '\n';
				var url = window.URL.createObjectURL( new window.Blob( [ text ], { type: 'text/plain;charset=utf-8' } ) );
				var link = document.createElement( 'a' );
				link.href = url;
				link.download = download.getAttribute( 'data-filename' ) || 'backup-codes.txt';
				document.body.appendChild( link );
				link.click();
				document.body.removeChild( link );
				window.setTimeout( function () {
					window.URL.revokeObjectURL( url );
				}, 1000 );
			} );
		} else if ( download ) {
			download.hidden = true;
		}

		tools.hidden = false;
	}

	window.happyaccessTwoStep = {
		drawQr: drawQr,
		setUpCodes: setUpCodes,
	};

	function init() {
		var boxes = document.querySelectorAll( '.happyaccess-ts-qr[data-happyaccess-uri]' );
		for ( var i = 0; i < boxes.length; i++ ) {
			drawQr( boxes[ i ] );
		}
		var form = document.getElementById( 'happyaccess-ts-codes-form' );
		if ( form ) {
			setUpCodes( form );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
