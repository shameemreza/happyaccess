import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import * as api from '../api';

/**
 * The settings, with save and first-run setup. Both replace the settings
 * with the response, which is the full, clamped result.
 *
 * @return {Object} { settings, loading, saving, error, refresh, save, setup }
 */
export function useSettings() {
	const [ settings, setSettings ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const mounted = useRef( true );

	const refresh = useCallback( async () => {
		try {
			const result = await api.getSettings();
			if ( mounted.current ) {
				setSettings( result );
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
		refresh();
		return () => {
			mounted.current = false;
		};
	}, [ refresh ] );

	const write = useCallback( async ( call ) => {
		setSaving( true );
		try {
			const result = await call();
			if ( mounted.current ) {
				setSettings( result );
			}
			return result;
		} finally {
			if ( mounted.current ) {
				setSaving( false );
			}
		}
	}, [] );

	const save = useCallback(
		( patch ) => write( () => api.saveSettings( patch ) ),
		[ write ]
	);
	const setup = useCallback(
		( payload ) => write( () => api.runSetup( payload ) ),
		[ write ]
	);

	return { settings, loading, saving, error, refresh, save, setup };
}
