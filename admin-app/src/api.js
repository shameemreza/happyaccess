import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const BASE = '/happyaccess/v1';

/**
 * Turns whatever apiFetch rejected with into { code, message, status }.
 * A WP_Error response arrives as { code, message, data: { status } }.
 *
 * @param {unknown} error The rejection.
 * @return {{code: string, message: string, status: number}} Normalized error.
 */
export function normalizeError( error ) {
	const code = error && typeof error.code === 'string' ? error.code : '';
	const isNetwork = ! code || 'fetch_error' === code;

	if ( isNetwork ) {
		return {
			code: 'network',
			message: __(
				'Could not reach the server. Check your connection and try again.',
				'happyaccess'
			),
			status: 0,
		};
	}

	const status = Number( error?.data?.status ?? error?.status ?? 0 );
	return {
		code,
		message:
			typeof error.message === 'string' && error.message
				? error.message
				: __( 'Something went wrong. Try again.', 'happyaccess' ),
		status: Number.isFinite( status ) ? status : 0,
	};
}

async function request( path, method = 'GET', data ) {
	const options = { path: BASE + path, method };
	if ( undefined !== data ) {
		options.data = data;
	}
	try {
		return await apiFetch( options );
	} catch ( error ) {
		throw normalizeError( error );
	}
}

function queryString( filters = {} ) {
	const params = new URLSearchParams();
	Object.entries( filters ).forEach( ( [ key, value ] ) => {
		if ( '' === value || null === value || undefined === value ) {
			return;
		}
		params.append( key, String( value ) );
	} );
	const query = params.toString();
	return query ? '?' + query : '';
}

function splitList( value ) {
	if ( Array.isArray( value ) ) {
		return value.map( ( item ) => String( item ).trim() ).filter( Boolean );
	}
	return String( value || '' )
		.split( /[,\n]/ )
		.map( ( item ) => item.trim() )
		.filter( Boolean );
}

/**
 * Maps the create form state to the REST body.
 *
 * @param {Object} form Form state.
 * @return {Object} Request body.
 */
export function buildGrantBody( form ) {
	const level = form.level || 'protected';
	const body = { label: form.label, level };

	const email = String( form.email || '' ).trim();
	if ( email ) {
		body.email = email;
	}
	if (
		'custom' === level &&
		Array.isArray( form.caps ) &&
		form.caps.length
	) {
		body.caps = form.caps;
	}
	if ( 'protected' !== level && form.confirmFull ) {
		body.confirm_full = true;
	}
	if ( 'protected' === level && form.role && 'administrator' !== form.role ) {
		body.role = form.role;
	}
	if ( form.durationSeconds ) {
		body.duration = Number( form.durationSeconds );
	}
	if ( undefined !== form.oneTime ) {
		body.one_time = Boolean( form.oneTime );
	}
	if ( form.notify ) {
		body.notify = form.notify;
	}
	const menus = splitList( form.menus );
	if ( menus.length ) {
		body.menus = menus;
	}
	const ips = splitList( form.ips );
	if ( ips.length ) {
		body.ips = ips;
	}
	const redirect = String( form.redirectTo || '' ).trim();
	if ( redirect ) {
		body.redirect_to = redirect;
	}
	if ( undefined !== form.sendEmail ) {
		body.send_email = Boolean( form.sendEmail );
	}
	return body;
}

export const listGrants = () => request( '/grants' );
export const createGrant = ( form ) =>
	request( '/grants', 'POST', buildGrantBody( form ) );
export const getGrant = ( id ) => request( `/grants/${ id }` );
export const extendGrant = ( id, seconds ) =>
	request( `/grants/${ id }/extend`, 'POST', { seconds } );
export const suspendGrant = ( id ) =>
	request( `/grants/${ id }/suspend`, 'POST' );
export const resumeGrant = ( id ) =>
	request( `/grants/${ id }/resume`, 'POST' );
export const regenerateGrant = ( id, sendEmail = false ) =>
	request( `/grants/${ id }/regenerate`, 'POST', {
		send_email: Boolean( sendEmail ),
	} );
export const revokeGrant = ( id ) => request( `/grants/${ id }`, 'DELETE' );
export const revokeAll = () => request( '/grants/revoke-all', 'POST' );

export const listActivity = ( filters ) =>
	request( '/activity' + queryString( filters ) );
export const activitySummary = ( tokenId ) =>
	request( '/activity/summary' + queryString( { token_id: tokenId } ) );

/**
 * @param {Object} filters Same filters as listActivity.
 * @return {Promise<{filename: string, csv: string}>} The file name and CSV text.
 */
export const exportActivity = ( filters ) =>
	request( '/activity/export' + queryString( filters ) );

export const getSettings = () => request( '/settings' );
export const saveSettings = ( patch ) => request( '/settings', 'POST', patch );
export const runSetup = ( { features, consent } ) =>
	request( '/setup', 'POST', { features, consent: Boolean( consent ) } );
export const getCatalog = () => request( '/catalog' );
export const emergencyLock = () => request( '/lock', 'POST' );
