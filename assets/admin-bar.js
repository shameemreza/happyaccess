/* global happyaccessBar */
( function() {
	var s = window.happyaccessBar;
	if ( ! s ) {
		return;
	}

	function unit( n, pair ) {
		return pair[ n === 1 ? 0 : 1 ].replace( '%d', n );
	}

	function span( secs ) {
		if ( secs < 60 ) {
			return s.less;
		}
		if ( secs < 3600 ) {
			return unit( Math.floor( secs / 60 ), s.min );
		}
		if ( secs < 86400 ) {
			return unit( Math.floor( secs / 3600 ), s.hour );
		}
		return unit( Math.floor( secs / 86400 ), s.day );
	}

	function tick() {
		var el = document.querySelector( '#wp-admin-bar-happyaccess-timer [data-expires]' );
		if ( ! el ) {
			return;
		}
		var left = parseInt( el.getAttribute( 'data-expires' ), 10 ) - Math.floor( Date.now() / 1000 );
		el.textContent = left > 0 ? s.ends.replace( '%s', span( left ) ) : s.ended;
	}

	function init() {
		var lock = document.querySelector( '.happyaccess-lock a' );
		if ( lock ) {
			lock.addEventListener( 'click', function( e ) {
				if ( ! window.confirm( s.confirm ) ) {
					e.preventDefault();
				}
			} );
		}
		if ( document.getElementById( 'wp-admin-bar-happyaccess-timer' ) ) {
			tick();
			setInterval( tick, 30000 );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
