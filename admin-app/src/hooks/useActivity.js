import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { activityKey } from '../activity/activityFormat';
import { useActivityCache } from '../data/DataProvider';

const NO_ROWS = { items: [], total: 0, page: 1, perPage: 25 };

const toData = ( result ) => ( {
	items: result.items,
	total: result.total,
	page: result.page,
	perPage: result.per_page,
} );

/**
 * A page of the activity log, kept for each set of filters. Coming back to a
 * page shows the kept copy at once and refreshes it in the background, so
 * `loading` is true only while there is nothing yet to show for the filters.
 *
 * @param {Object} filters Filters plus optional page and per_page.
 * @return {Object} { items, total, page, perPage, loadedKey, loading, refreshing, error, refresh }
 */
export function useActivity( filters = {} ) {
	const cache = useActivityCache();
	const json = JSON.stringify( filters );
	const key = activityKey( filters );
	const entry = cache.get( key );

	// The newest result this hook fetched, even when it is for other filters,
	// so the rows stay on screen while a new set of filters loads.
	const [ last, setLast ] = useState( {
		key: '',
		data: NO_ROWS,
	} );
	const [ failure, setFailure ] = useState( null );
	const [ fetching, setFetching ] = useState( false );
	const mounted = useRef( true );
	const latest = useRef( 0 );
	const arrived = useRef( true );

	const refresh = useCallback( async () => {
		const request = ++latest.current;
		const asked = JSON.parse( json );
		const askedKey = activityKey( asked );
		setFetching( true );
		setFailure( null );
		try {
			const result = await cache.load( askedKey, asked );
			if ( mounted.current && request === latest.current ) {
				setLast( { key: askedKey, data: toData( result ) } );
			}
		} catch ( e ) {
			if ( mounted.current && request === latest.current ) {
				setFailure( { key: askedKey, error: e } );
			}
		} finally {
			if ( mounted.current && request === latest.current ) {
				setFetching( false );
			}
		}
	}, [ json, cache ] );

	useEffect( () => {
		mounted.current = true;
		// On arriving at the tab a page loaded a moment ago stands. After
		// that, every change of filters asks again and shows the kept page meanwhile.
		if ( ! arrived.current || ! cache.isFresh( key ) ) {
			refresh();
		}
		arrived.current = false;
		return () => {
			mounted.current = false;
		};
	}, [ key, refresh, cache ] );

	const kept = useMemo(
		() => ( entry ? toData( entry.result ) : null ),
		[ entry ]
	);
	// A failed refresh leaves a kept page alone. Only an empty screen shows the error.
	const error =
		! kept && failure && failure.key === key ? failure.error : null;

	let shown = { key: last.key, data: last.data };
	if ( kept ) {
		shown = { key, data: kept };
	} else if ( error ) {
		// Rows from other filters must not sit under the error.
		shown = { key: '', data: { ...last.data, items: [], total: 0 } };
	}

	return {
		...shown.data,
		loadedKey: shown.key,
		loading: ! kept && ! error,
		refreshing: fetching && !! kept,
		error,
		refresh,
	};
}
