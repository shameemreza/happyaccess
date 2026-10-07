import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import * as api from '../api';

/**
 * The settings, with save and first-run setup. Both replace the settings
 * with the response, which is the full, clamped result. It lives in the
 * app-level DataProvider and loads once.
 *
 * @param {boolean} enabled Whether to load. Off until setup is done.
 * @return {Object} { settings, loading, saving, error, refresh, save, setup }
 */
export function useSettingsStore( enabled = true ) {
	const [ settings, setSettings ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const mounted = useRef( true );
	// Saves in flight. `saving` stays true until the last one is done.
	const pending = useRef( 0 );

	const refresh = useCallback( async () => {
		setLoading( true );
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
		if ( enabled ) {
			refresh();
		}
		return () => {
			mounted.current = false;
		};
	}, [ refresh, enabled ] );

	const write = useCallback( async ( call ) => {
		pending.current++;
		setSaving( true );
		try {
			const result = await call();
			if ( mounted.current ) {
				setSettings( result );
				// The reply holds the full settings, so an old load error is stale.
				setError( null );
			}
			return result;
		} finally {
			pending.current--;
			if ( mounted.current && 0 === pending.current ) {
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
