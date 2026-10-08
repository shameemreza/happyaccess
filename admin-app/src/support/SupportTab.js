import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useGrants } from '../data/DataProvider';
import { useDelayedFlag } from '../hooks/useDelayedFlag';
import { useNow } from '../hooks/useNow';
import ActiveList from './ActiveList';
import GrantForm from './GrantForm';
import { LOADING_DELAY } from '../LoadingLine';
import { timeAgo } from './passFormat';
import ResultCard from './ResultCard';

const NO_BOOT = {};
const noop = () => {};

function countLine( count ) {
	if ( 0 === count ) {
		return __( 'Nobody has access.', 'happyaccess' );
	}
	return sprintf(
		/* translators: %d: number of people with a working pass. */
		_n(
			'%d person has access.',
			'%d people have access.',
			count,
			'happyaccess'
		),
		count
	);
}

/**
 * The Support access tab: the form or the result card on one side, the list
 * of passes on the other.
 *
 * The plain code and link of a new pass are held here, in state, for as long
 * as the result card is open. Closing it, or ending the passes, drops them.
 *
 * @param {Object}                props                Props.
 * @param {Object}                props.boot           Boot data.
 * @param {boolean}               props.focusOnOpen    Whether the form's first field takes focus when the tab opens, as it does after setup.
 * @param {number}                props.refreshKey     Changes when something outside ended every pass, so an open result card is dropped. The app reloads the list.
 * @param {(id?: number) => void} props.onViewActivity Opens the Activity tab, optionally for one pass.
 * @return {Element} The tab.
 */
export default function SupportTab( {
	boot = NO_BOOT,
	focusOnOpen = false,
	refreshKey = 0,
	onViewActivity = noop,
} ) {
	const grants = useGrants();
	const { refresh } = grants;
	// A quick load leaves no text, only a quiet block the height of the line.
	const checking = grants.loading && 0 === grants.grants.length;
	const showChecking = useDelayedFlag( checking, LOADING_DELAY );
	const now = useNow();
	const [ result, setResult ] = useState( null );
	// Bumped for every new result, so each one gets a fresh card with focus on its heading.
	const [ shown, setShown ] = useState( 0 );
	// Bumped for every change to the secrets, so the activity footer loads again.
	const [ events, setEvents ] = useState( 0 );
	const labelRef = useRef( null );
	const focusForm = useRef( focusOnOpen );
	const startKey = useRef( refreshKey );

	// Everything ended elsewhere, so the secrets on screen no longer work.
	useEffect( () => {
		if ( refreshKey !== startKey.current ) {
			startKey.current = refreshKey;
			setResult( null );
		}
	}, [ refreshKey ] );

	// After "Done", or on opening the tab when asked, put the cursor in the form's first field.
	useEffect( () => {
		if ( null === result && focusForm.current ) {
			focusForm.current = false;
			labelRef.current?.focus();
		}
	}, [ result ] );

	const { act, revokeAll } = grants;
	const showResult = useCallback( ( next ) => {
		setShown( ( count ) => count + 1 );
		setEvents( ( count ) => count + 1 );
		setResult( next );
	}, [] );
	const resultId = result ? result.id : 0;

	// The card follows the list. The end time comes from the list, so an
	// Extend shows on the card. A pass that a refresh no longer lists has
	// ended, so its code and link no longer work and the card goes.
	const listed = resultId
		? grants.grants.find( ( grant ) => grant.id === resultId )
		: null;
	const listedEnd = listed ? listed.expires_at : 0;
	const listLoaded = ! grants.loading;
	useEffect( () => {
		if ( resultId && listLoaded && ! listed ) {
			setResult( null );
		}
	}, [ resultId, listLoaded, listed ] );
	const card = useMemo( () => {
		if ( ! result || ! listedEnd || listedEnd === result.expires_at ) {
			return result;
		}
		return { ...result, expires_at: listedEnd };
	}, [ result, listedEnd ] );
	const handleAct = useCallback(
		async ( id, action, arg ) => {
			const next = await act( id, action, arg );
			if ( 'regenerate' === action ) {
				showResult( next );
			} else if ( 'revoke' === action && id === resultId ) {
				setResult( null );
			}
			return next;
		},
		[ act, resultId, showResult ]
	);

	// New secrets for the card that is open. It keeps its own state, so no new key.
	const sendEmail = async () => {
		const next = await act( result.id, 'regenerate', true );
		setEvents( ( count ) => count + 1 );
		setResult( next );
		return next;
	};

	const handleRevokeAll = async () => {
		const ended = await revokeAll();
		// A pass created while the call was out keeps its card.
		setResult( ( current ) =>
			current && ended.includes( current.id ) ? null : current
		);
		await refresh();
	};

	const done = () => {
		focusForm.current = true;
		setResult( null );
	};

	const live = grants.grants.filter(
		( grant ) => 'suspended' !== grant.status
	);
	let latest = null;
	grants.grants.forEach( ( grant ) => {
		if (
			grant.last_login_at > 0 &&
			( ! latest || grant.last_login_at > latest.last_login_at )
		) {
			latest = grant;
		}
	} );

	// With no passes at all the empty list says "Nobody has access.", and after
	// a failed load an empty list says nothing about who has access.
	let statusText = countLine( live.length );
	if ( 0 === grants.grants.length ) {
		statusText = showChecking
			? __( 'Checking who has access.', 'happyaccess' )
			: '';
	}

	return (
		<div className="ha-support">
			{ ( statusText || latest || checking ) && (
				<p className="ha-support__status">
					{ checking && ! showChecking && (
						<span className="ha-skeleton" aria-hidden="true" />
					) }
					{ statusText && <strong>{ statusText }</strong> }
					{ latest && (
						<span className="ha-support__last">
							{ ' ' }
							{ sprintf(
								/* translators: 1: who the pass is for. 2: how long ago, like "14 minutes ago". */
								__( '%1$s logged in %2$s.', 'happyaccess' ),
								latest.label,
								timeAgo( latest.last_login_at, now )
							) }
						</span>
					) }
				</p>
			) }
			<div className="ha-support__cols">
				<div className="ha-support__main">
					{ card ? (
						<ResultCard
							key={ shown }
							result={ card }
							boot={ boot }
							onDone={ done }
							onSendEmail={ sendEmail }
						/>
					) : (
						<GrantForm
							boot={ boot }
							create={ grants.create }
							labelRef={ labelRef }
							onCreated={ showResult }
						/>
					) }
				</div>
				<div className="ha-support__side">
					<ActiveList
						grants={ grants.grants }
						loading={ grants.loading }
						error={ grants.error }
						onRetry={ refresh }
						refreshToken={ `${ refreshKey }:${ events }` }
						now={ now }
						boot={ boot }
						onAct={ handleAct }
						onRevokeAll={ handleRevokeAll }
						onViewActivity={ onViewActivity }
					/>
				</div>
			</div>
		</div>
	);
}
