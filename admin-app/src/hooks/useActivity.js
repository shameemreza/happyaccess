import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { listActivity } from '../api';

/**
 * A page of the activity log. Fetches again when the filters change.
 *
 * @param {Object} filters Filters plus optional page and per_page.
 * @return {Object} { items, total, page, perPage, loadedKey, loading, error, refresh }
 */
export function useActivity( filters = {} ) {
	const [ data, setData ] = useState( {
		items: [],
		total: 0,
		page: 1,
		perPage: 25,
	} );
	// The filters the items on screen belong to. It trails `key` while a request runs.
	const [ loadedKey, setLoadedKey ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const mounted = useRef( true );
	const latest = useRef( 0 );
	const key = JSON.stringify( filters );

	const refresh = useCallback( async () => {
		const request = ++latest.current;
		setLoading( true );
		try {
			const result = await listActivity( JSON.parse( key ) );
			if ( mounted.current && request === latest.current ) {
				setData( {
					items: result.items,
					total: result.total,
					page: result.page,
					perPage: result.per_page,
				} );
				setLoadedKey( key );
				setError( null );
			}
		} catch ( e ) {
			if ( mounted.current && request === latest.current ) {
				// Rows from other filters must not sit under the error.
				setData( ( previous ) => ( {
					...previous,
					items: [],
					total: 0,
				} ) );
				setLoadedKey( '' );
				setError( e );
			}
		} finally {
			if ( mounted.current && request === latest.current ) {
				setLoading( false );
			}
		}
	}, [ key ] );

	useEffect( () => {
		mounted.current = true;
		refresh();
		return () => {
			mounted.current = false;
		};
	}, [ refresh ] );

	return { ...data, loadedKey, loading, error, refresh };
}
