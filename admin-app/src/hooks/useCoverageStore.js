import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { getCoverage } from '../api';

/**
 * Who has two-step login, per role. It lives in the app-level DataProvider
 * and loads the first time the Login and security tab asks for it, since
 * the route exists only while two-step login is on. The page preloads that
 * first answer.
 *
 * @return {Object} { coverage, loading, error, loadOnce, retry, refresh }
 */
export function useCoverageStore() {
	const [ state, setState ] = useState( {
		coverage: null,
		loading: false,
		error: null,
	} );
	const started = useRef( false );
	const mounted = useRef( true );

	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, [] );

	const load = useCallback( async () => {
		started.current = true;
		setState( ( prev ) => ( { ...prev, loading: true, error: null } ) );
		try {
			const coverage = await getCoverage();
			if ( mounted.current ) {
				setState( { coverage, loading: false, error: null } );
			}
		} catch ( error ) {
			if ( mounted.current ) {
				setState( ( prev ) => ( { ...prev, loading: false, error } ) );
			}
		}
	}, [] );

	const loadOnce = useCallback( () => {
		if ( ! started.current ) {
			load();
		}
	}, [ load ] );

	// A quiet reload after a settings save, once the counts were asked for.
	const refresh = useCallback( async () => {
		if ( ! started.current ) {
			return;
		}
		try {
			const coverage = await getCoverage();
			if ( mounted.current ) {
				setState( { coverage, loading: false, error: null } );
			}
		} catch {
			// The counts on screen are at most a few minutes old.
		}
	}, [] );

	return { ...state, loadOnce, retry: load, refresh };
}
