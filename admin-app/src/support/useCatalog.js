import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { getCatalog } from '../api';

/**
 * The permission catalog, loaded once when first wanted and kept for as long
 * as the caller stays mounted.
 *
 * @param {boolean} wanted Whether something needs it yet.
 * @return {Object} { catalog, loading, error, retry }
 */
export function useCatalog( wanted ) {
	const [ state, setState ] = useState( {
		catalog: null,
		loading: false,
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

	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, [] );

	useEffect( () => {
		if ( wanted && ! started.current ) {
			load();
		}
	}, [ wanted, load ] );

	return { ...state, retry: load };
}
