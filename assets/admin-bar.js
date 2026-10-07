/* global happyaccessBar */
( function() {
	var s = window.happyaccessBar;
	if ( ! s ) {
		return;
	}

	function unit( n, pair ) {
		return pair[ n === 1 ? 0 : 1 ].replace( '%d', n );
	}

	// Same rules as AdminBar::time_left(): two units under three days or three
	// hours, so it never shows a day less than is left, then the nearest unit.
	function span( secs ) {
		var hours, mins, days;
		if ( secs < 60 ) {
			return s.less;
		}
		if ( secs < 3600 ) {
			return unit( Math.floor( secs / 60 ), s.min );
		}
		if ( secs < 86400 ) {
			hours = Math.floor( secs / 3600 );
			if ( hours < 3 ) {
				mins = Math.floor( ( secs % 3600 ) / 60 );
				return mins > 0 ? unit( hours, s.hour ) + ' ' + unit( mins, s.min ) : unit( hours, s.hour );
			}
			hours = Math.round( secs / 3600 );
			return hours >= 24 ? unit( 1, s.day ) : unit( hours, s.hour );
		}
		days = Math.floor( secs / 86400 );
		if ( days < 3 ) {
			hours = Math.floor( ( secs % 86400 ) / 3600 );
			return hours > 0 ? unit( days, s.day ) + ' ' + unit( hours, s.hour ) : unit( days, s.day );
		}
		return unit( Math.round( secs / 86400 ), s.day );
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
