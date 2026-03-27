/**
 * Admin bar countdown timer for temporary user sessions.
 *
 * @package HappyAccess
 * @since   1.0.5
 */

/* global happyaccessCountdown */

( function() {
	var timer = document.getElementById( 'happyaccess-timer' );
	if ( ! timer ) {
		return;
	}

	var expires = parseInt( timer.getAttribute( 'data-expires' ), 10 ) * 1000;
	var sessionStart = parseInt( timer.getAttribute( 'data-session-start' ), 10 ) * 1000;
	var logoutUrl = timer.getAttribute( 'data-logout' );
	var strings = ( typeof happyaccessCountdown !== 'undefined' ) ? happyaccessCountdown : {};

	function formatTime( ms ) {
		var seconds = Math.floor( ms / 1000 );
		if ( seconds < 0 ) {
			seconds = 0;
		}
		var days = Math.floor( seconds / 86400 );
		var hours = Math.floor( ( seconds % 86400 ) / 3600 );
		var mins = Math.floor( ( seconds % 3600 ) / 60 );
		var secs = seconds % 60;

		if ( days > 0 ) {
			return days + 'd ' + hours + 'h ' + mins + 'm';
		} else if ( hours > 0 ) {
			return hours + 'h ' + mins + 'm ' + secs + 's';
		}
		return mins + 'm ' + secs + 's';
	}

	function updateTimer() {
		var now = Date.now();
		var remaining = expires - now;
		var active = now - sessionStart;

		if ( remaining <= 0 ) {
			timer.innerHTML = '\u23F1\uFE0F ' + ( strings.expired || 'Access expired, logging out...' );
			setTimeout( function() {
				window.location.href = logoutUrl;
			}, 1500 );
			return;
		}

		timer.innerHTML = '\u23F1\uFE0F ' + ( strings.expiresIn || 'Access expires in' ) +
			' <strong>' + formatTime( remaining ) + '</strong> \u00B7 ' +
			( strings.session || 'Current session' ) +
			' <strong>' + formatTime( active ) + '</strong>';
	}

	updateTimer();
	setInterval( updateTimer, 1000 );
} )();
