import { useCallback, useMemo, useState } from '@wordpress/element';
import ActivityTab from './activity/ActivityTab';
import { AnnounceProvider } from './Announcer';
import Header from './Header';
import SupportTab from './support/SupportTab';
import SetupPlaceholder from './SetupPlaceholder';
import TabNav from './TabNav';
import { getVisibleTabs, pickInitialTab, storeTab, tabUrl } from './tabList';

const NO_FEATURES = {};
const DEFAULT_BOOT = { features: NO_FEATURES, needsSetup: false };

/**
 * The app shell: header, then either the setup screen or the tabs.
 *
 * @param {Object}  props            Props.
 * @param {Object}  props.boot       Boot data printed by the PHP page.
 * @param {boolean} props.loginReady Whether the Login tab exists yet.
 * @return {Element} The app.
 */
export default function App( { boot = DEFAULT_BOOT, loginReady = false } ) {
	const features = boot.features || NO_FEATURES;
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

	const active = tabs.find( ( tab ) => tab.slug === current ) || tabs[ 0 ];

	return (
		<AnnounceProvider>
			<Header onLocked={ onLocked } />
			{ boot.needsSetup ? (
				<SetupPlaceholder />
			) : (
				<>
					<TabNav
						tabs={ tabs }
						current={ active.slug }
						onSelect={ select }
					/>
					{ 'support' === active.slug && (
						<SupportTab
							boot={ boot }
							refreshKey={ lockCount }
							onViewActivity={ openActivity }
						/>
					) }
					{ 'activity' === active.slug && (
						<ActivityTab
							boot={ boot }
							loginReady={ loginReady }
							onOpenSettings={ openSettings }
						/>
					) }
					{ ! [ 'support', 'activity' ].includes( active.slug ) && (
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
