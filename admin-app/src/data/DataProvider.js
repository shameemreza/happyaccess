import {
	createContext,
	useCallback,
	useContext,
	useEffect,
	useMemo,
} from '@wordpress/element';
import {
	activityKey,
	defaultActivityRequest,
} from '../activity/activityFormat';
import { useCatalogStore } from '../hooks/useCatalogStore';
import { useCoverageStore } from '../hooks/useCoverageStore';
import { useGrantsStore } from '../hooks/useGrantsStore';
import { useSettingsStore } from '../hooks/useSettingsStore';
import { createActivityCache } from './activityCache';

const DataContext = createContext( null );

/**
 * Keeps the app's data above the tabs, so switching tabs shows what each one
 * already has instead of fetching it again.
 *
 * - Grants load once and then every minute while the browser tab is visible.
 * - Settings and the permission catalog load once. A settings save replaces
 *   the settings and quietly reloads the catalog and grants it may change.
 * - The last Activity page for each set of filters is kept, and the Activity
 *   tab refreshes it in the background.
 * - Who has two-step login loads when the Login and security tab first asks,
 *   and quietly again after a settings save.
 *
 * Plain codes and link keys are not kept here. They stay in SupportTab.
 *
 * @param {Object}  props          Props.
 * @param {boolean} props.enabled  Whether to load. Off while the setup screen shows.
 * @param {Element} props.children The app.
 * @return {Element} The provider.
 */
export default function DataProvider( { enabled = true, children } ) {
	const grants = useGrantsStore( enabled );
	const settings = useSettingsStore( enabled );
	const catalog = useCatalogStore( enabled );
	const coverage = useCoverageStore();
	const activity = useMemo( () => createActivityCache(), [] );

	const {
		create: createGrant,
		act: actOnGrant,
		refresh: refreshGrants,
	} = grants;
	const { save: saveSettings } = settings;
	const { refresh: refreshCatalog } = catalog;
	const { refresh: refreshCoverage } = coverage;

	// The first Activity page is in the page already. Taking it now uses it
	// up before it can go stale, and the Activity tab then opens with it.
	useEffect( () => {
		if ( ! enabled ) {
			return;
		}
		const request = defaultActivityRequest(
			Math.floor( Date.now() / 1000 )
		);
		const key = activityKey( request );
		if ( activity.isFresh( key ) ) {
			return;
		}
		activity.load( key, request ).catch( () => {
			// The Activity tab asks for it itself.
		} );
	}, [ enabled, activity ] );

	// Making or changing a pass logs events, so kept pages need a refresh.
	const create = useCallback(
		async ( form ) => {
			const result = await createGrant( form );
			activity.markStale();
			return result;
		},
		[ createGrant, activity ]
	);
	const act = useCallback(
		async ( id, action, arg ) => {
			const result = await actOnGrant( id, action, arg );
			activity.markStale();
			return result;
		},
		[ actOnGrant, activity ]
	);
	const save = useCallback(
		async ( patch ) => {
			const result = await saveSettings( patch );
			activity.markStale();
			// A save can end passes, so the list loads again, and so does the
			// catalog. A role policy change moves the two-step counts.
			refreshGrants();
			refreshCatalog();
			refreshCoverage();
			return result;
		},
		[
			saveSettings,
			activity,
			refreshGrants,
			refreshCatalog,
			refreshCoverage,
		]
	);

	const grantsValue = useMemo(
		() => ( { ...grants, create, act } ),
		[ grants, create, act ]
	);
	const settingsValue = useMemo(
		() => ( { ...settings, save } ),
		[ settings, save ]
	);

	const value = useMemo(
		() => ( {
			grants: grantsValue,
			settings: settingsValue,
			catalog,
			activity,
			coverage,
		} ),
		[ grantsValue, settingsValue, catalog, activity, coverage ]
	);

	return (
		<DataContext.Provider value={ value }>
			{ children }
		</DataContext.Provider>
	);
}

function useData() {
	const value = useContext( DataContext );
	if ( ! value ) {
		throw new Error( 'HappyAccess data hooks need a DataProvider.' );
	}
	return value;
}

/**
 * @return {Object} The shared pass list: { grants, loading, error, refresh, create, act, revokeAll }.
 */
export const useGrants = () => useData().grants;

/**
 * @return {Object} The shared settings: { settings, loading, saving, error, refresh, save, setup }.
 */
export const useSettings = () => useData().settings;

/**
 * @return {Object} The shared permission catalog: { catalog, loading, error, retry }.
 */
export const useCatalog = () => useData().catalog;

/**
 * @return {Object} The kept Activity pages: { get, set, isFresh, markStale }.
 */
export const useActivityCache = () => useData().activity;

/**
 * @return {Object} Who has two-step login: { coverage, loading, error, loadOnce, retry, refresh }.
 */
export const useCoverage = () => useData().coverage;
