import { useCallback, useMemo, useState } from '@wordpress/element';
import { AnnounceProvider } from './Announcer';
import Header from './Header';
import GrantForm from './support/GrantForm';
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

	const select = useCallback( ( slug ) => {
		setCurrent( slug );
		storeTab( slug );
		window.history.replaceState( window.history.state, '', tabUrl( slug ) );
	}, [] );

	const active = tabs.find( ( tab ) => tab.slug === current ) || tabs[ 0 ];

	return (
		<AnnounceProvider>
			<Header />
			{ boot.needsSetup ? (
				<SetupPlaceholder />
			) : (
				<>
					<TabNav
						tabs={ tabs }
						current={ active.slug }
						onSelect={ select }
					/>
					{ /* Temporary mount. The Support access tab replaces it. */ }
					{ 'support' === active.slug ? (
						<GrantForm boot={ boot } onCreated={ () => {} } />
					) : (
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
