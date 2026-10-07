import { useCallback, useMemo, useState } from '@wordpress/element';
import ActivityTab from './activity/ActivityTab';
import { AnnounceProvider } from './Announcer';
import Header from './Header';
import SettingsTab from './settings/SettingsTab';
import Setup from './setup/Setup';
import SupportTab from './support/SupportTab';
import TabNav from './TabNav';
import { getVisibleTabs, pickInitialTab, storeTab, tabUrl } from './tabList';

const NO_FEATURES = {};
const DEFAULT_BOOT = { features: NO_FEATURES, needsSetup: false };

/**
 * The boot data the PHP page prints, with a console warning when it is
 * missing, since the app then starts with every feature off.
 *
 * @param {Object} win The window to read from.
 * @return {Object|undefined} window.happyaccessBoot.
 */
export function readBoot( win = window ) {
	if ( ! win.happyaccessBoot ) {
		// eslint-disable-next-line no-console
		console.warn(
			'HappyAccess: window.happyaccessBoot is missing, so the admin app starts with its defaults.'
		);
	}
	return win.happyaccessBoot;
}

/**
 * The app shell: header, then either the setup screen or the tabs.
 *
 * @param {Object}  props            Props.
 * @param {Object}  props.boot       Boot data printed by the PHP page.
 * @param {boolean} props.loginReady Whether the Login tab exists yet.
 * @return {Element} The app.
 */
export default function App( { boot = DEFAULT_BOOT, loginReady = false } ) {
	// Both change while the app is open: Settings switches features, and
	// setup ends. Neither needs a page reload.
	const [ features, setFeatures ] = useState(
		() => boot.features || NO_FEATURES
	);
	const [ needsSetup, setNeedsSetup ] = useState( !! boot.needsSetup );
	const appBoot = useMemo(
		() => ( { ...boot, features } ),
		[ boot, features ]
	);
	const tabs = useMemo(
		() => getVisibleTabs( { features, loginReady } ),
		[ features, loginReady ]
	);
	const [ current, setCurrent ] = useState( () => pickInitialTab( tabs ) );

	// Bumped when Emergency lock ends every pass, so the list loads again.
	const [ lockCount, setLockCount ] = useState( 0 );

	const select = useCallback( ( slug, args ) => {
		setCurrent( slug );
		storeTab( slug );
		window.history.replaceState(
			window.history.state,
			'',
			tabUrl( slug, args )
		);
	}, [] );

	// The Activity tab reads `token` from the URL to show one pass.
	const openActivity = useCallback(
		( token ) => select( 'activity', token ? { token } : {} ),
		[ select ]
	);
	const openSettings = useCallback( () => select( 'settings' ), [ select ] );
	const onLocked = useCallback( () => setLockCount( ( n ) => n + 1 ), [] );

	// Keeps the boot data the page printed in step, for anything that reads it.
	const updateFeatures = useCallback( ( next ) => {
		setFeatures( { ...next } );
		const printed = window.happyaccessBoot || {};
		printed.features = Object.assign( printed.features || {}, next );
		window.happyaccessBoot = printed;
	}, [] );

	const finishSetup = useCallback(
		( saved ) => {
			if ( saved ) {
				updateFeatures( saved );
			}
			setNeedsSetup( false );
			select( 'support' );
		},
		[ updateFeatures, select ]
	);

	const active = tabs.find( ( tab ) => tab.slug === current ) || tabs[ 0 ];

	return (
		<AnnounceProvider>
			<Header onLocked={ onLocked } />
			{ needsSetup ? (
				<Setup onFinish={ finishSetup } />
			) : (
				<>
					<TabNav
						tabs={ tabs }
						current={ active.slug }
						onSelect={ select }
					/>
					{ 'support' === active.slug && (
						<SupportTab
							boot={ appBoot }
							refreshKey={ lockCount }
							onViewActivity={ openActivity }
						/>
					) }
					{ 'activity' === active.slug && (
						<ActivityTab
							boot={ appBoot }
							loginReady={ loginReady }
							onOpenSettings={ openSettings }
						/>
					) }
					{ 'settings' === active.slug && (
						<SettingsTab onFeaturesChange={ updateFeatures } />
					) }
					{ ! [ 'support', 'activity', 'settings' ].includes(
						active.slug
					) && (
						<section
							className="ha-panel"
							aria-labelledby="ha-panel-title"
						>
							<h2 id="ha-panel-title">{ active.label }</h2>
						</section>
					) }
				</>
			) }
		</AnnounceProvider>
	);
}
