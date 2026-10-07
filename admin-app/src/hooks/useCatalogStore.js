import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { getCatalog } from '../api';

/**
 * The permission catalog. It lives in the app-level DataProvider, loads once
 * and stays for the whole visit.
 *
 * @param {boolean} enabled Whether to load. Off until setup is done.
 * @return {Object} { catalog, loading, error, retry, refresh }
 */
export function useCatalogStore( enabled = true ) {
	const [ state, setState ] = useState( {
		catalog: null,
		loading: true,
		error: null,
	} );
	const started = useRef( false );
	const mounted = useRef( true );

	const load = useCallback( async () => {
		started.current = true;
		setState( ( prev ) => ( { ...prev, loading: true, error: null } ) );
		try {
			const catalog = await getCatalog();
			if ( mounted.current ) {
				setState( { catalog, loading: false, error: null } );
			}
		} catch ( error ) {
			if ( mounted.current ) {
				setState( { catalog: null, loading: false, error } );
			}
		}
	}, [] );

	// A quiet reload that keeps what is on screen, even if it fails.
	const refresh = useCallback( async () => {
		try {
			const catalog = await getCatalog();
			if ( mounted.current ) {
				setState( { catalog, loading: false, error: null } );
			}
		} catch {
			// The catalog on screen is still good.
		}
	}, [] );

	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, [] );

	useEffect( () => {
		if ( enabled && ! started.current ) {
			load();
		}
	}, [ enabled, load ] );

	return { ...state, retry: load, refresh };
}
