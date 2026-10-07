import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

const BASE = '/happyaccess/v1';
const NETWORK_CODES = [ 'fetch_error', 'offline_error' ];

/**
 * Turns whatever apiFetch rejected with into { code, message, status }.
 * A WP_Error response arrives as { code, message, data: { status } }.
 *
 * @param {unknown} error The rejection.
 * @return {{code: string, message: string, status: number}} Normalized error.
 */
export function normalizeError( error ) {
	const code = error && typeof error.code === 'string' ? error.code : '';

	if ( NETWORK_CODES.includes( code ) ) {
		return {
			code: 'network',
			message: __(
				'Could not reach the server. Check your connection and try again.',
				'happyaccess'
			),
			status: 0,
		};
	}
	if ( ! code ) {
		// A thrown Error (a bug in the app, not the connection) has no code.
		return {
			code: 'unknown',
			message: __( 'Something went wrong. Try again.', 'happyaccess' ),
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

const isResponse = ( value ) =>
	!! value &&
	'function' === typeof value.text &&
	'number' === typeof value.status;

async function readJson( response ) {
	if ( 204 === response.status ) {
		return null;
	}
	const text = await response.text();
	if ( '' === text && response.ok ) {
		return null;
	}
	try {
		return JSON.parse( text );
	} catch {
		throw {
			code: 'invalid_json',
			message: sprintf(
				/* translators: %d: HTTP status code, like 500. */
				__(
					'The server sent a reply the app could not read (HTTP %d).',
					'happyaccess'
				),
				response.status
			),
			data: { status: response.status },
		};
	}
}

/**
 * apiFetch middleware for our own routes. It reads the reply itself, so a
 * body that is not JSON (a PHP fatal error page, a firewall block) keeps its
 * HTTP status. A WP_Error body is thrown as it came, so the nonce retry in
 * apiFetch still sees its code.
 *
 * @param {Object}                                options apiFetch options.
 * @param {(options: Object) => Promise<unknown>} next    The next middleware.
 * @return {Promise} The parsed body.
 */
export function keepStatus( options, next ) {
	const path = String( options.path || '' );
	if ( false === options.parse || ! path.startsWith( BASE + '/' ) ) {
		return next( options );
	}
	return next( { ...options, parse: false } ).then(
		( response ) =>
			isResponse( response ) ? readJson( response ) : response,
		async ( error ) => {
			if ( ! isResponse( error ) ) {
				throw error;
			}
			throw await readJson( error );
		}
	);
}

apiFetch.use( keepStatus );

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
