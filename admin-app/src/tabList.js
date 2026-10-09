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
			label: __( 'Temporary access', 'happyaccess' ),
			visible: ( { features } ) => !! features.support_access,
		},
		{
			slug: 'activity',
			label: __( 'Activity', 'happyaccess' ),
			visible: () => true,
		},
		{
			slug: 'login',
			label: __( 'Login and security', 'happyaccess' ),
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
 * The tab to show for the current one. A Login tab that just went away
 * (Passwordless switched off) leaves you on Settings, where the switch is.
 * Any other tab that is gone falls back to the first one shown.
 *
 * @param {Array}  visibleTabs Tabs this site shows.
 * @param {string} current     The tab slug in use.
 * @return {string} Tab slug.
 */
export function resolveCurrentTab( visibleTabs, current ) {
	const slugs = visibleTabs.map( ( tab ) => tab.slug );
	if ( slugs.includes( current ) ) {
		return current;
	}
	return 'login' === current && slugs.includes( 'settings' )
		? 'settings'
		: slugs[ 0 ];
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
 * @param {Object} args Extra query args for the tab, like { token: 7 }. Args from an earlier tab are dropped.
 * @return {string} The current URL with the tab query arg set.
 */
export function tabUrl( slug, args = {} ) {
	const url = new URL( window.location.href );
	url.searchParams.set( 'tab', slug );
	url.searchParams.delete( 'token' );
	Object.entries( args ).forEach( ( [ key, value ] ) => {
		url.searchParams.set( key, String( value ) );
	} );
	return url.pathname + url.search + url.hash;
}

/**
 * Whether a click on one of our tab links should switch in place. A click
 * with a modifier key or another button opens a new tab or window, so the
 * browser keeps it.
 *
 * @param {MouseEvent} event Click event.
 * @return {boolean} True for a plain left click.
 */
export function isPlainClick( event ) {
	return (
		! event.defaultPrevented &&
		0 === event.button &&
		! event.metaKey &&
		! event.ctrlKey &&
		! event.shiftKey &&
		! event.altKey
	);
}

/**
 * @return {number} The pass id in the current URL's `token` arg, or 0.
 */
export function readQueryToken() {
	const token = parseInt(
		new URLSearchParams( window.location.search ).get( 'token' ) || '',
		10
	);
	return token > 0 ? token : 0;
}

/**
 * Takes `token` out of the address bar once the Activity tab has used it.
 */
export function clearQueryToken() {
	window.history.replaceState(
		window.history.state,
		'',
		tabUrl( 'activity' )
	);
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
