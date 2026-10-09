/* global happyaccessBar */
( function() {
	var s = window.happyaccessBar;
	if ( ! s ) {
		return;
	}
	// wp-i18n picks the plural form by the rules of the site language.
	var _n = window.wp && window.wp.i18n ? window.wp.i18n._n : null;
	var units = {
		min: function( n ) {
			/* translators: %d: number of minutes. */
			return _n( '%d min', '%d mins', n, 'happyaccess' );
		},
		hour: function( n ) {
			/* translators: %d: number of hours. */
			return _n( '%d hour', '%d hours', n, 'happyaccess' );
		},
		day: function( n ) {
			/* translators: %d: number of days. */
			return _n( '%d day', '%d days', n, 'happyaccess' );
		},
	};

	function unit( n, kind ) {
		return units[ kind ]( n ).replace( '%d', n );
	}

	// Same rules as AdminBar::time_left(): two units under three days or three
	// hours, so it never shows a day less than is left, then the nearest unit.
	function span( secs ) {
		var hours, mins, days;
		if ( secs < 60 ) {
			return s.less;
		}
		if ( secs < 3600 ) {
			return unit( Math.floor( secs / 60 ), 'min' );
		}
		if ( secs < 86400 ) {
			hours = Math.floor( secs / 3600 );
			if ( hours < 3 ) {
				mins = Math.floor( ( secs % 3600 ) / 60 );
				return mins > 0 ? unit( hours, 'hour' ) + ' ' + unit( mins, 'min' ) : unit( hours, 'hour' );
			}
			hours = Math.round( secs / 3600 );
			return hours >= 24 ? unit( 1, 'day' ) : unit( hours, 'hour' );
		}
		days = Math.floor( secs / 86400 );
		if ( days < 3 ) {
			hours = Math.floor( ( secs % 86400 ) / 3600 );
			return hours > 0 ? unit( days, 'day' ) + ' ' + unit( hours, 'hour' ) : unit( days, 'day' );
		}
		return unit( Math.round( secs / 86400 ), 'day' );
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
		if ( _n && document.getElementById( 'wp-admin-bar-happyaccess-timer' ) ) {
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
