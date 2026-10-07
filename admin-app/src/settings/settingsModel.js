import { __, _n, sprintf } from '@wordpress/i18n';

const DAY = 86400;
const HOUR = 3600;

/**
 * The pairs of "wrong codes" and pause length that the select offers. The
 * first is the server default.
 *
 * @return {Array} Options with `tries` and `seconds`.
 */
export function getLockoutPairs() {
	return [
		{
			tries: 5,
			seconds: 1800,
			label: __( '5 tries, then 30 minutes', 'happyaccess' ),
		},
		{
			tries: 3,
			seconds: 1800,
			label: __( '3 tries, then 30 minutes', 'happyaccess' ),
		},
		{
			tries: 10,
			seconds: 900,
			label: __( '10 tries, then 15 minutes', 'happyaccess' ),
		},
	];
}

/**
 * @param {number} tries   Wrong codes allowed.
 * @param {number} seconds Pause length in seconds.
 * @return {string} A select value for the pair.
 */
export const pairValue = ( tries, seconds ) => `${ tries }:${ seconds }`;

/**
 * The lockout select options. Pass the saved pair, not the edited one, so a
 * stored pair that matches no preset stays selectable as a "Custom" option.
 *
 * @param {number} tries   Saved `security.max_attempts`.
 * @param {number} seconds Saved `security.lockout_duration`.
 * @return {Array<{value: string, label: string}>} Select options.
 */
export function lockoutOptions( tries, seconds ) {
	const options = getLockoutPairs().map( ( pair ) => ( {
		value: pairValue( pair.tries, pair.seconds ),
		label: pair.label,
	} ) );
	const current = pairValue( tries, seconds );
	if ( ! options.some( ( option ) => option.value === current ) ) {
		const minutes = Math.round( seconds / 60 );
		options.push( {
			value: current,
			label: sprintf(
				/* translators: 1: number of wrong codes, 2: number of minutes. */
				__( 'Custom (%1$d tries, %2$d minutes)', 'happyaccess' ),
				tries,
				minutes
			),
		} );
	}
	return options;
}

/**
 * @param {number} days Number of days.
 * @return {string} "30 days".
 */
function daysLabel( days ) {
	return sprintf(
		/* translators: %d: number of days. */
		_n( '%d day', '%d days', days, 'happyaccess' ),
		days
	);
}

/**
 * @param {number} days Stored `privacy.retention_days`.
 * @return {Array<{value: string, label: string}>} 30, 90 and 365 days, plus the stored value when it is another.
 */
export function retentionOptions( days ) {
	const values = [ 30, 90, 365 ];
	if ( days > 0 && ! values.includes( days ) ) {
		values.push( days );
	}
	return values.map( ( value ) => ( {
		value: String( value ),
		label:
			365 === value ? __( '1 year', 'happyaccess' ) : daysLabel( value ),
	} ) );
}

/**
 * @param {number} seconds Stored `support.default_duration`.
 * @return {Array<{value: string, label: string}>} 1, 3 and 7 days, plus the stored value when it is another.
 */
export function durationOptions( seconds ) {
	const values = [ DAY, 3 * DAY, 7 * DAY ];
	if ( seconds > 0 && ! values.includes( seconds ) ) {
		values.push( seconds );
	}
	return values.map( ( value ) => {
		let label;
		if ( 0 === value % DAY ) {
			label = daysLabel( value / DAY );
		} else if ( 0 === value % HOUR ) {
			label = sprintf(
				/* translators: %d: number of hours. */
				_n( '%d hour', '%d hours', value / HOUR, 'happyaccess' ),
				value / HOUR
			);
		} else {
			label = sprintf(
				/* translators: %d: number of minutes. */
				_n(
					'%d minute',
					'%d minutes',
					Math.round( value / 60 ),
					'happyaccess'
				),
				Math.round( value / 60 )
			);
		}
		return { value: String( value ), label };
	} );
}

/**
 * @return {Array<{value: string, label: string}>} The four allowed `security.proxy_header` values.
 */
export function getProxyOptions() {
	return [
		{
			value: '',
			label: __( 'Direct connection (most sites)', 'happyaccess' ),
		},
		{
			value: 'HTTP_CF_CONNECTING_IP',
			label: __( 'Cloudflare', 'happyaccess' ),
		},
		{
			value: 'HTTP_X_FORWARDED_FOR',
			label: __( 'X-Forwarded-For header', 'happyaccess' ),
		},
		{
			value: 'HTTP_X_REAL_IP',
			label: __( 'X-Real-IP header', 'happyaccess' ),
		},
	];
}

/**
 * @param {Object} source A nested object.
 * @param {string} path   Dotted path, like "security.max_attempts".
 * @return {unknown} The value, or undefined.
 */
export function getPath( source, path ) {
	return path
		.split( '.' )
		.reduce(
			( value, key ) =>
				value && 'object' === typeof value ? value[ key ] : undefined,
			source
		);
}

/**
 * Turns the changed fields into the nested patch the REST route takes, so
 * only the keys that changed are sent.
 *
 * @param {Object} edits Dotted path to new value.
 * @return {Object} A nested patch.
 */
export function buildPatch( edits ) {
	const patch = {};
	Object.entries( edits ).forEach( ( [ path, value ] ) => {
		const [ group, key ] = path.split( '.' );
		patch[ group ] = { ...patch[ group ], [ key ]: value };
	} );
	return patch;
}
