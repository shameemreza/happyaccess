import { dateI18n, humanTimeDiff } from '@wordpress/date';
import { __, _n, sprintf } from '@wordpress/i18n';

const DAY = 86400;
const HOUR = 3600;
const END_FORMAT = 'D, M j, g:i a';

/**
 * @param {number} seconds Unix time in seconds.
 * @return {string} The date and time in the site's timezone and locale.
 */
export function formatEnd( seconds ) {
	return dateI18n( END_FORMAT, new Date( seconds * 1000 ) );
}

/**
 * How long ago a moment was, like "14 minutes ago".
 *
 * @param {number} seconds Unix time in seconds.
 * @param {number} now     Current Unix time in seconds.
 * @return {string} Relative time, in the site's locale.
 */
export function timeAgo( seconds, now ) {
	return humanTimeDiff( Math.min( seconds, now ) * 1000, now * 1000 );
}

/**
 * The access level as a person reads it, with the role a protected pass uses.
 *
 * @param {Object} grant Grant from the REST list.
 * @param {Array}  roles boot.roles, as { slug, name }.
 * @return {string} Level name.
 */
export function levelName( grant, roles = [] ) {
	if ( 'full' === grant.level ) {
		return __( 'Administrator (full access)', 'happyaccess' );
	}
	if ( 'custom' === grant.level ) {
		return __( 'Custom access', 'happyaccess' );
	}
	if ( grant.role && 'administrator' !== grant.role ) {
		const picked = roles.find( ( item ) => item.slug === grant.role );
		return picked ? picked.name : grant.role;
	}
	return __( 'Administrator (protected)', 'happyaccess' );
}

/**
 * The length of a whole pass: "3 day pass".
 *
 * @param {number} seconds Pass length in seconds.
 * @return {string} Length label.
 */
export function passLength( seconds ) {
	if ( seconds >= DAY - HOUR ) {
		const days = Math.max( 1, Math.round( seconds / DAY ) );
		return sprintf(
			/* translators: %d: number of days the pass lasts. */
			_n( '%d day pass', '%d day pass', days, 'happyaccess' ),
			days
		);
	}
	if ( seconds >= HOUR ) {
		const hours = Math.round( seconds / HOUR );
		return sprintf(
			/* translators: %d: number of hours the pass lasts. */
			_n( '%d hour pass', '%d hour pass', hours, 'happyaccess' ),
			hours
		);
	}
	const minutes = Math.max( 1, Math.round( seconds / 60 ) );
	return sprintf(
		/* translators: %d: number of minutes the pass lasts. */
		_n( '%d minute pass', '%d minute pass', minutes, 'happyaccess' ),
		minutes
	);
}

/**
 * @param {string} status Grant status from the REST list.
 * @return {string} Pill text.
 */
export function statusLabel( status ) {
	if ( 'used' === status ) {
		return __( 'Used once', 'happyaccess' );
	}
	if ( 'suspended' === status ) {
		return __( 'Suspended', 'happyaccess' );
	}
	return __( 'Active', 'happyaccess' );
}
