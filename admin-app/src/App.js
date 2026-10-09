import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import ActivityTab from './activity/ActivityTab';
import { AnnounceProvider } from './Announcer';
import { AuthorCardProvider } from './AuthorCard';
import DataProvider, { useActivityCache, useGrants } from './data/DataProvider';
import Header from './Header';
import LoginTab from './login/LoginTab';
import SettingsTab from './settings/SettingsTab';
import Setup from './setup/Setup';
import SupportTab from './support/SupportTab';
import TabNav from './TabNav';
import {
	getVisibleTabs,
	pickInitialTab,
	resolveCurrentTab,
	storeTab,
	tabUrl,
} from './tabList';

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
 * Reloads the pass list and the kept Activity pages when Emergency lock ends
 * every pass, so a tab opened later does not show passes that are gone.
 *
 * @param {Object} props       Props.
 * @param {number} props.count Changes each time the lock runs.
 * @return {null} Nothing to show.
 */
function RefreshOnLock( { count } ) {
	const { refresh } = useGrants();
	const activity = useActivityCache();
	const seen = useRef( count );

	useEffect( () => {
		if ( count !== seen.current ) {
			seen.current = count;
			activity.markStale();
			refresh();
		}
	}, [ count, refresh, activity ] );

	return null;
}

/**
 * The author card's provider, fed with the pass list the app already
 * keeps, so its lines about passes need no request of their own.
 *
 * @param {Object}  props          Props.
 * @param {Object}  props.card     authorCard from the boot data.
 * @param {Object}  props.features The feature switches as they are now.
 * @param {Element} props.children The app.
 * @return {Element} The provider.
 */
function AuthorCardWithPasses( { card, features, children } ) {
	const { grants, loading } = useGrants();
	const passes = useMemo(
		() => ( { items: grants, loading } ),
		[ grants, loading ]
	);
	return (
		<AuthorCardProvider
			card={ card }
			features={ features }
			passes={ passes }
		>
			{ children }
		</AuthorCardProvider>
	);
}

/**
 * The app shell: header, then either the setup screen or the tabs.
 *
 * @param {Object}  props            Props.
 * @param {Object}  props.boot       Boot data printed by the PHP page.
 * @param {boolean} props.loginReady Whether the Login tab exists. Defaults to boot.loginReady.
 * @return {Element} The app.
 */
export default function App( { boot = DEFAULT_BOOT, loginReady: readyProp } ) {
	const loginReady =
		undefined === readyProp ? !! boot.loginReady : !! readyProp;
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

	// True right after setup, so the Support access form takes focus once.
	const [ afterSetup, setAfterSetup ] = useState( false );

	const select = useCallback( ( slug, args ) => {
		setAfterSetup( false );
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

	// On a subsite that follows the main site, a save answers with the
	// subsite's own two-step switch, which doesn't apply, so the main site's
	// value from the boot data stays.
	const sharedTwoStep = 'main' === boot.twoStepNetwork;
	const bootTwoStep = !! boot.features?.two_step;

	// Keeps the boot data the page printed in step, for anything that reads it.
	const updateFeatures = useCallback(
		( saved ) => {
			const next = sharedTwoStep
				? { ...saved, two_step: bootTwoStep }
				: { ...saved };
			setFeatures( next );
			const printed = window.happyaccessBoot || {};
			printed.features = Object.assign( printed.features || {}, next );
			window.happyaccessBoot = printed;
		},
		[ sharedTwoStep, bootTwoStep ]
	);

	// Setup ends on the tab its last button names. Only the Temporary access
	// form takes focus, as the first thing to do there.
	const finishSetup = useCallback(
		( saved, tab = 'support' ) => {
			if ( saved ) {
				updateFeatures( saved );
			}
			setNeedsSetup( false );
			select( tab );
			setAfterSetup( 'support' === tab );
		},
		[ updateFeatures, select ]
	);

	const activeSlug = resolveCurrentTab( tabs, current );
	const active = tabs.find( ( tab ) => tab.slug === activeSlug );

	// The Login tab can go away while it is open. Settings takes over, and
	// the address bar and remembered tab follow.
	useEffect( () => {
		if ( 'login' === current && 'login' !== activeSlug ) {
			select( activeSlug );
		}
	}, [ current, activeSlug, select ] );

	return (
		<AnnounceProvider>
			<DataProvider enabled={ ! needsSetup }>
				<AuthorCardWithPasses
					card={ boot.authorCard }
					features={ features }
				>
					<RefreshOnLock count={ lockCount } />
					<Header onLocked={ onLocked } />
					{ needsSetup ? (
						<Setup
							onFinish={ finishSetup }
							twoStepNetwork={ boot.twoStepNetwork }
							twoStepSetupUrl={ boot.twoStepSetupUrl }
						/>
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
									focusOnOpen={ afterSetup }
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
							{ 'login' === active.slug && (
								<LoginTab boot={ appBoot } />
							) }
							{ 'settings' === active.slug && (
								<SettingsTab
									onFeaturesChange={ updateFeatures }
									otherTwoStep={ appBoot.otherTwoStep }
									twoStepNetwork={ appBoot.twoStepNetwork }
								/>
							) }
							{ ! [
								'support',
								'activity',
								'login',
								'settings',
							].includes( active.slug ) && (
								<section
									className="ha-panel"
									aria-labelledby="ha-panel-title"
								>
									<h2 id="ha-panel-title">
										{ active.label }
									</h2>
								</section>
							) }
						</>
					) }
				</AuthorCardWithPasses>
			</DataProvider>
		</AnnounceProvider>
	);
}
