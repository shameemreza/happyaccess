import { __, _n, sprintf } from '@wordpress/i18n';

export const OFF = 'off';
export const OPTIONAL = 'optional';
export const REQUIRED = 'required';

/**
 * The server defaults of the `two_step` group, for settings saved before
 * the group existed.
 */
export const TWO_STEP_DEFAULTS = {
	role_policy: {},
	grace_type: 'logins',
	grace_logins: 3,
	grace_days: 7,
	block_xmlrpc: true,
	device_alert_roles: [ 'administrator' ],
};

/**
 * @param {string[]} a One list of role slugs.
 * @param {string[]} b Another.
 * @return {boolean} Whether both hold the same roles, in any order.
 */
export function sameRoles( a, b ) {
	return a.length === b.length && a.every( ( slug ) => b.includes( slug ) );
}

/**
 * @return {Array<{value: string, label: string}>} The three choices for a role.
 */
export function roleChoices() {
	return [
		{ value: OFF, label: __( 'Off', 'happyaccess' ) },
		{ value: OPTIONAL, label: __( 'Optional', 'happyaccess' ) },
		{ value: REQUIRED, label: __( 'Required', 'happyaccess' ) },
	];
}

/**
 * @param {string} type  `logins` or `days`.
 * @param {number} count Logins or days.
 * @return {string} A select value for the pair.
 */
export const graceValue = ( type, count ) => `${ type }:${ count }`;

/**
 * Reads a select value back into the settings it stands for.
 *
 * @param {string} value A value from graceValue().
 * @return {Object} `grace_type` plus `grace_logins` or `grace_days`.
 */
export function graceSettings( value ) {
	const [ type, count ] = value.split( ':' );
	return 'days' === type
		? { grace_type: 'days', grace_days: Number( count ) }
		: { grace_type: 'logins', grace_logins: Number( count ) };
}

/**
 * @param {string} type  `logins` or `days`.
 * @param {number} count Logins or days.
 * @return {string} "3 logins" or "7 days".
 */
function graceLabel( type, count ) {
	return 'days' === type
		? sprintf(
				/* translators: %d: number of days. */
				_n( '%d day', '%d days', count, 'happyaccess' ),
				count
			)
		: sprintf(
				/* translators: %d: number of logins. */
				_n( '%d login', '%d logins', count, 'happyaccess' ),
				count
			);
}

/**
 * The grace period select options. Pass the saved settings, not the edited
 * ones, so a saved period that matches no preset stays selectable as a
 * "Custom" option.
 *
 * @param {string} type   Saved `two_step.grace_type`.
 * @param {number} logins Saved `two_step.grace_logins`.
 * @param {number} days   Saved `two_step.grace_days`.
 * @return {Array<{value: string, label: string}>} Select options.
 */
export function graceOptions( type, logins, days ) {
	const presets = [
		[ 'logins', 3 ],
		[ 'logins', 5 ],
		[ 'days', 7 ],
		[ 'days', 14 ],
	];
	const options = presets.map( ( [ presetType, count ] ) => ( {
		value: graceValue( presetType, count ),
		label: graceLabel( presetType, count ),
	} ) );
	const count = 'days' === type ? days : logins;
	const current = graceValue( 'days' === type ? 'days' : 'logins', count );
	if ( ! options.some( ( option ) => option.value === current ) ) {
		options.push( {
			value: current,
			label: sprintf(
				/* translators: %s: a grace period, like "4 logins" or "10 days". */
				__( 'Custom (%s)', 'happyaccess' ),
				graceLabel( 'days' === type ? 'days' : 'logins', count )
			),
		} );
	}
	return options;
}

/**
 * @param {string[]} names Plugin names.
 * @return {string} "A", "A and B" or "A, B and C".
 */
export function listNames( names ) {
	if ( names.length < 2 ) {
		return names[ 0 ] || '';
	}
	return sprintf(
		/* translators: 1: plugin names, comma separated, 2: the last plugin name. */
		__( '%1$s and %2$s', 'happyaccess' ),
		names.slice( 0, -1 ).join( ', ' ),
		names[ names.length - 1 ]
	);
}

/**
 * The notice for the active two-step plugins of another vendor.
 *
 * @param {string[]} names Plugin names.
 * @return {string} The text.
 */
export function otherPluginsNotice( names ) {
	return sprintf(
		/* translators: %s: plugin names, like "WP 2FA" or "WP 2FA and Kadence Security". */
		_n(
			'%s already adds two-step login. HappyAccess skips accounts that use it, so nobody is asked twice.',
			'%s already add two-step login. HappyAccess skips accounts that use them, so nobody is asked twice.',
			names.length,
			'happyaccess'
		),
		listNames( names )
	);
}

/**
 * The question before two-step login turns on next to another plugin's.
 *
 * @param {string[]} names Plugin names.
 * @return {string} The text.
 */
export function otherPluginsQuestion( names ) {
	return sprintf(
		/* translators: %s: plugin names, like "WP 2FA" or "WP 2FA and Kadence Security". */
		_n(
			'%s already adds two-step login. HappyAccess skips accounts that use it, so nobody is asked twice. Turn on HappyAccess two-step login anyway?',
			'%s already add two-step login. HappyAccess skips accounts that use them, so nobody is asked twice. Turn on HappyAccess two-step login anyway?',
			names.length,
			'happyaccess'
		),
		listNames( names )
	);
}
