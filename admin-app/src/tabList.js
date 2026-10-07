import { __ } from '@wordpress/i18n';

const STORAGE_KEY = 'happyaccess.tab';

/**
 * Every tab the app can show. `visible` decides, from the boot data, whether
 * this site shows it.
 *
 * @return {Array} Tab definitions.
 */
export function getTabs() {
	return [
		{
			slug: 'support',
			label: __( 'Support access', 'happyaccess' ),
			visible: ( { features } ) => !! features.support_access,
		},
		{
			slug: 'activity',
			label: __( 'Activity', 'happyaccess' ),
			visible: () => true,
		},
		{
			slug: 'login',
			label: __( 'Login', 'happyaccess' ),
			// Hidden until passwordless or two-step login exists.
			visible: ( { features, loginReady } ) =>
				loginReady &&
				( !! features.passwordless || !! features.two_step ),
		},
		{
			slug: 'settings',
			label: __( 'Settings', 'happyaccess' ),
			visible: () => true,
		},
	];
}

/**
 * @param {Object}  context            Visibility inputs.
 * @param {Object}  context.features   Feature switches from the boot data.
 * @param {boolean} context.loginReady Whether the Login tab exists yet.
 * @return {Array} The tabs to show.
 */
export function getVisibleTabs( context ) {
	return getTabs().filter( ( tab ) => tab.visible( context ) );
}

/**
 * @return {string} The tab slug in the current URL, or an empty string.
 */
export function readQueryTab() {
	return new URLSearchParams( window.location.search ).get( 'tab' ) || '';
}

/**
 * @return {string} The remembered tab slug, or an empty string.
 */
export function readStoredTab() {
	try {
		return window.localStorage.getItem( STORAGE_KEY ) || '';
	} catch {
		return '';
	}
}

/**
 * @param {string} slug Tab to remember.
 */
export function storeTab( slug ) {
	try {
		window.localStorage.setItem( STORAGE_KEY, slug );
	} catch {
		// Storage can be blocked. The app works without it.
	}
}

/**
 * @param {string} slug Tab slug.
 * @return {string} The current URL with the tab query arg set.
 */
export function tabUrl( slug ) {
	const url = new URL( window.location.href );
	url.searchParams.set( 'tab', slug );
	return url.pathname + url.search + url.hash;
}

/**
 * Picks the starting tab: the URL first, then the remembered one, then the first shown.
 *
 * @param {Array} visibleTabs Tabs this site shows.
 * @return {string} Tab slug.
 */
export function pickInitialTab( visibleTabs ) {
	const slugs = visibleTabs.map( ( tab ) => tab.slug );
	const wanted = [ readQueryTab(), readStoredTab() ].find( ( slug ) =>
		slugs.includes( slug )
	);
	return wanted || slugs[ 0 ];
}
