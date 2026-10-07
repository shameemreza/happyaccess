import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import * as api from '../api';

const REFRESH_MS = 60000;

// Fields that exist only on the create and regenerate responses.
const SECRET_KEYS = [ 'code', 'link_url', 'code_url', 'message', 'emailed' ];

function withoutSecrets( grant ) {
	const clean = { ...grant };
	SECRET_KEYS.forEach( ( key ) => delete clean[ key ] );
	return clean;
}

// A new pass goes to the top. An action only updates a pass still listed,
// so it can't bring back one a refresh removed while it ran.
function replaceItem( list, grant, insert = false ) {
	const clean = withoutSecrets( grant );
	if ( list.some( ( item ) => item.id === clean.id ) ) {
		return list.map( ( item ) => ( item.id === clean.id ? clean : item ) );
	}
	return insert ? [ clean, ...list ] : list;
}

/**
 * The list of current passes, with the actions on them. It lives in the
 * app-level DataProvider, so the list survives a switch of tabs. It loads
 * once, then every minute while the browser tab is visible.
 *
 * Plain codes and link keys come back from create and act, but never enter
 * the list state, so they live only where the caller keeps them.
 *
 * @param {boolean} enabled Whether to load. Off until setup is done.
 * @return {Object} { grants, loading, error, refresh, create, act }
 */
export function useGrantsStore( enabled = true ) {
	const [ grants, setGrants ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const mounted = useRef( true );
	// Bumped on every local change, so a slower list response can't undo it.
	const version = useRef( 0 );

	const refresh = useCallback( async () => {
		const started = version.current;
		try {
			const result = await api.listGrants();
			if ( mounted.current && started === version.current ) {
				setGrants( result.items );
				setError( null );
			}
		} catch ( e ) {
			if ( mounted.current ) {
				setError( e );
			}
		} finally {
			if ( mounted.current ) {
				setLoading( false );
			}
		}
	}, [] );

	useEffect( () => {
		mounted.current = true;
		if ( ! enabled ) {
			return () => {
				mounted.current = false;
			};
		}
		refresh();
		const timer = setInterval( () => {
			if ( 'visible' === document.visibilityState ) {
				refresh();
			}
		}, REFRESH_MS );
		// Passes may have ended while the tab was in the background.
		const onVisibility = () => {
			if ( 'visible' === document.visibilityState ) {
				refresh();
			}
		};
		document.addEventListener( 'visibilitychange', onVisibility );
		return () => {
			mounted.current = false;
			clearInterval( timer );
			document.removeEventListener( 'visibilitychange', onVisibility );
		};
	}, [ refresh, enabled ] );

	const create = useCallback( async ( form ) => {
		const result = await api.createGrant( form );
		version.current++;
		if ( mounted.current ) {
			setGrants( ( list ) => replaceItem( list, result, true ) );
		}
		return result;
	}, [] );

	const act = useCallback( async ( id, action, arg ) => {
		let result;
		switch ( action ) {
			case 'extend':
				result = await api.extendGrant( id, arg );
				break;
			case 'suspend':
				result = await api.suspendGrant( id );
				break;
			case 'resume':
				result = await api.resumeGrant( id );
				break;
			case 'regenerate':
				result = await api.regenerateGrant( id, arg );
				break;
			case 'revoke':
				result = await api.revokeGrant( id );
				break;
			default:
				// Same shape as a server error, so callers can show `message`.
				throw {
					code: 'unknown_action',
					message: __(
						'Something went wrong. Try again.',
						'happyaccess'
					),
					status: 0,
				};
		}

		version.current++;
		if ( mounted.current ) {
			if ( 'revoke' === action ) {
				setGrants( ( list ) =>
					list.filter( ( item ) => item.id !== id )
				);
			} else {
				setGrants( ( list ) => replaceItem( list, result ) );
			}
		}
		return result;
	}, [] );

	return { grants, loading, error, refresh, create, act };
}
