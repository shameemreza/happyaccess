import { date, dateI18n, getSettings } from '@wordpress/date';
import { __ } from '@wordpress/i18n';

export const PER_PAGE = 25;

const WARN_EVENTS = [ 'login_failed', 'access_blocked', 'wc_key_blocked' ];

const ICONS = {
	login: 'M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3',
	gear: 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8zM4 12h2M18 12h2M12 4v2M12 18v2',
	plug: 'M9 2v6M15 2v6M6 8h12v4a6 6 0 0 1-12 0zM12 18v4',
	doc: 'M14 3H6v18h12V7zM14 3v4h4M9 13h6M9 17h6',
	shield: 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z',
	key: 'M8 12a4 4 0 1 0 0 .01M12 12h9M18 12v3',
};

/**
 * @param {number} seconds Unix time in seconds.
 * @return {string} The calendar day as Y-m-d, in the site's timezone.
 */
export function siteDay( seconds ) {
	return date( 'Y-m-d', new Date( seconds * 1000 ) );
}

/**
 * @param {string} day   A Y-m-d day.
 * @param {number} delta Days to move, negative for earlier.
 * @return {string} The moved day, as Y-m-d.
 */
export function shiftDay( day, delta ) {
	const [ year, month, dayOfMonth ] = day.split( '-' ).map( Number );
	return new Date( Date.UTC( year, month - 1, dayOfMonth + delta ) )
		.toISOString()
		.slice( 0, 10 );
}

/**
 * @param {number} seconds Unix time in seconds.
 * @return {string} The clock time, in the site's timezone and time format.
 */
export function formatClock( seconds ) {
	return dateI18n( getSettings().formats.time, new Date( seconds * 1000 ) );
}

/**
 * Groups items into days in the site's timezone. Items keep their order, so
 * a newest-first list gives newest-first days.
 *
 * @param {Array}  items Items from the REST list.
 * @param {number} now   Current Unix time in seconds.
 * @return {Array} Groups of { day, title, items }.
 */
export function groupByDay( items, now ) {
	const today = siteDay( now );
	const yesterday = shiftDay( today, -1 );
	const groups = [];

	items.forEach( ( item ) => {
		const day = siteDay( item.time );
		let group = groups.find( ( entry ) => entry.day === day );
		if ( ! group ) {
			let title;
			if ( day === today ) {
				title = __( 'Today', 'happyaccess' );
			} else if ( day === yesterday ) {
				title = __( 'Yesterday', 'happyaccess' );
			} else {
				title = dateI18n(
					getSettings().formats.date,
					new Date( item.time * 1000 )
				);
			}
			group = { day, title, items: [] };
			groups.push( group );
		}
		group.items.push( item );
	} );

	return groups;
}

/**
 * The since and until filters for the When choice, as site-local Y-m-d days.
 * "Last 7 days" is today and the six days before it.
 *
 * @param {string} when  '7', '30' or 'custom'.
 * @param {string} from  Custom range start, Y-m-d or empty.
 * @param {string} to    Custom range end, Y-m-d or empty.
 * @param {string} today Today in the site's timezone, as Y-m-d.
 * @return {Object} { since, until }, leaving out what is not set.
 */
export function rangeFilters( when, from, to, today ) {
	if ( 'custom' === when ) {
		const range = {};
		if ( from ) {
			range.since = from;
		}
		if ( to ) {
			range.until = to;
		}
		return range;
	}
	const days = '30' === when ? 30 : 7;
	return { since: shiftDay( today, 1 - days ), until: today };
}

/**
 * @param {Object} item Item from the REST list.
 * @return {boolean} Whether a site admin did this, rather than a pass.
 */
export function isAdminItem( item ) {
	return 'admin' === item.kind;
}

/**
 * @param {Object} item Item from the REST list.
 * @return {{key: string, label: string}} The feature tag.
 */
export function featureTag( item ) {
	if ( isAdminItem( item ) ) {
		return { key: 'admin', label: __( 'Admin', 'happyaccess' ) };
	}
	switch ( item.feature ) {
		case 'support':
			return {
				key: 'support',
				label: __( 'Support access', 'happyaccess' ),
			};
		case 'passwordless':
			return {
				key: 'passwordless',
				label: __( 'Passwordless', 'happyaccess' ),
			};
		case 'two_step':
			return { key: 'two_step', label: __( 'Two-step', 'happyaccess' ) };
		case 'core':
			return { key: 'admin', label: __( 'HappyAccess', 'happyaccess' ) };
		default:
			return { key: 'admin', label: __( 'Other', 'happyaccess' ) };
	}
}

/**
 * @param {Object} item Item from the REST list.
 * @return {boolean} Whether this is a refused or failed attempt.
 */
export function isWarning( item ) {
	return WARN_EVENTS.includes( item.event );
}

/**
 * @param {Object} item Item from the REST list.
 * @return {string} SVG path data for the row's icon.
 */
export function iconPath( item ) {
	const event = item.event || '';
	if ( isWarning( item ) ) {
		return ICONS.shield;
	}
	if ( 0 === event.indexOf( 'login' ) || 'logout' === event ) {
		return ICONS.login;
	}
	if ( /^(plugin|upgrader|theme)/.test( event ) ) {
		return ICONS.plug;
	}
	if ( /^(post|order|wc_|privacy)/.test( event ) ) {
		return ICONS.doc;
	}
	if ( isAdminItem( item ) || /^(user|admin|roles)/.test( event ) ) {
		return ICONS.key;
	}
	return ICONS.gear;
}

/**
 * Who did it: the site admin for their own actions, otherwise the pass.
 *
 * @param {Object} item Item from the REST list.
 * @return {string} The name to show.
 */
export function whoLabel( item ) {
	const actor = item.actor && item.actor.name ? item.actor.name : '';
	const pass = item.pass || '';
	if ( isAdminItem( item ) ) {
		return actor || __( 'System', 'happyaccess' );
	}
	return pass || actor || __( 'System', 'happyaccess' );
}

/**
 * Counts the rows of a CSV, not counting the header. A line break inside a
 * quoted cell is not a new row.
 *
 * @param {string} csv CSV text.
 * @return {number} Number of data rows.
 */
export function countCsvRows( csv ) {
	let rows = 0;
	let quoted = false;
	let pending = false;
	for ( const char of csv ) {
		if ( '"' === char ) {
			quoted = ! quoted;
			pending = true;
		} else if ( '\n' === char && ! quoted ) {
			rows++;
			pending = false;
		} else {
			pending = true;
		}
	}
	if ( pending ) {
		rows++;
	}
	return Math.max( 0, rows - 1 );
}

/**
 * Hands CSV text to the browser as a file download.
 *
 * @param {string} csv      CSV text.
 * @param {string} filename File name to save as.
 */
export function downloadCsv( csv, filename ) {
	const blob = new Blob( [ csv ], { type: 'text/csv;charset=utf-8' } );
	const url = URL.createObjectURL( blob );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	link.hidden = true;
	document.body.appendChild( link );
	link.click();
	link.remove();
	// Gives the browser a moment to start the download before the URL goes.
	setTimeout( () => URL.revokeObjectURL( url ), 1000 );
}
