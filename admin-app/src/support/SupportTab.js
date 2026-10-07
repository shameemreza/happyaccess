import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { revokeAll } from '../api';
import { useGrants } from '../hooks/useGrants';
import { useNow } from '../hooks/useNow';
import ActiveList from './ActiveList';
import GrantForm from './GrantForm';
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
 * @param {number}                props.refreshKey     Changes when something outside ended every pass, so the list loads again.
 * @param {(id?: number) => void} props.onViewActivity Opens the Activity tab, optionally for one pass.
 * @return {Element} The tab.
 */
export default function SupportTab( {
	boot = NO_BOOT,
	refreshKey = 0,
	onViewActivity = noop,
} ) {
	const grants = useGrants();
	const { refresh } = grants;
	const now = useNow();
	const [ result, setResult ] = useState( null );
	// Bumped for every new result, so each one gets a fresh card with focus on its heading.
	const [ shown, setShown ] = useState( 0 );
	// Bumped for every change to the secrets, so the activity footer loads again.
	const [ events, setEvents ] = useState( 0 );
	const labelRef = useRef( null );
	const focusForm = useRef( false );
	const startKey = useRef( refreshKey );

	// Everything ended elsewhere, so the secrets on screen no longer work.
	useEffect( () => {
		if ( refreshKey !== startKey.current ) {
			startKey.current = refreshKey;
			setResult( null );
			refresh();
		}
	}, [ refreshKey, refresh ] );

	// After "Done" the form is back. Put the cursor in its first field.
	useEffect( () => {
		if ( null === result && focusForm.current ) {
			focusForm.current = false;
			labelRef.current?.focus();
		}
	}, [ result ] );

	const { act } = grants;
	const showResult = useCallback( ( next ) => {
		setShown( ( count ) => count + 1 );
		setEvents( ( count ) => count + 1 );
		setResult( next );
	}, [] );
	const resultId = result ? result.id : 0;
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
		await revokeAll();
		setResult( null );
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

	let statusText = countLine( live.length );
	if ( 0 === grants.grants.length ) {
		if ( grants.loading ) {
			statusText = __( 'Checking who has access.', 'happyaccess' );
		} else if ( grants.error ) {
			// An empty list after a failed load says nothing about who has access.
			statusText = '';
		}
	}

	return (
		<div className="ha-support">
			<p className="ha-support__status">
				<strong>{ statusText }</strong>
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
			<div className="ha-support__cols">
				<div className="ha-support__main">
					{ result ? (
						<ResultCard
							key={ shown }
							result={ result }
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
