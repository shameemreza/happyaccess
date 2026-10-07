import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { listActivity } from '../api';
import { useAnnounce } from '../hooks/useAnnounce';
import { isPlainClick, tabUrl } from '../tabList';
import GrantRow, { InlineConfirm } from './GrantRow';
import { timeAgo } from './passFormat';

const ACTIVITY_REFRESH_MS = 60000;

/**
 * The newest support activity line, fetched on mount and then every minute
 * while the tab is visible.
 *
 * @param {number} now   Current Unix time in seconds.
 * @param {string} token Changes when something happened, so the line loads again.
 * @return {string} The line, or an empty string.
 */
function useLatestLine( now, token ) {
	const [ item, setItem ] = useState( null );

	useEffect( () => {
		let live = true;
		const load = async () => {
			try {
				const result = await listActivity( {
					per_page: 1,
					feature: 'support',
				} );
				if ( live ) {
					setItem( result.items[ 0 ] || null );
				}
			} catch {
				// The footer is a nicety. A failure leaves it as it was.
			}
		};
		load();
		const timer = setInterval( () => {
			if ( 'visible' === document.visibilityState ) {
				load();
			}
		}, ACTIVITY_REFRESH_MS );
		return () => {
			live = false;
			clearInterval( timer );
		};
	}, [ token ] );

	if ( ! item ) {
		return '';
	}
	return sprintf(
		/* translators: 1: what happened, 2: how long ago, like "9 minutes ago". */
		__( 'Latest: %1$s, %2$s', 'happyaccess' ),
		item.summary || item.event_label,
		timeAgo( item.time, now )
	);
}

/**
 * "Who has access": every current pass, with Revoke all and the latest activity.
 *
 * @param {Object}                                                 props                Props.
 * @param {Array}                                                  props.grants         Passes from useGrants.
 * @param {boolean}                                                props.loading        Whether the first load is running.
 * @param {Object|null}                                            props.error          Load error from useGrants.
 * @param {() => void}                                             props.onRetry        Loads the list again.
 * @param {string}                                                 props.refreshToken   Changes when a pass was made or ended elsewhere, so the footer loads again.
 * @param {number}                                                 props.now            Current Unix time in seconds.
 * @param {Object}                                                 props.boot           Boot data: roles and maxDays.
 * @param {(id: number, action: string, arg?: unknown) => Promise} props.onAct          Runs an action on one pass.
 * @param {() => Promise}                                          props.onRevokeAll    Ends every pass.
 * @param {(id?: number) => void}                                  props.onViewActivity Opens the Activity tab, optionally for one pass.
 * @return {Element} The list card.
 */
export default function ActiveList( {
	grants,
	loading,
	error,
	onRetry,
	refreshToken = '',
	now,
	boot,
	onAct,
	onRevokeAll,
	onViewActivity,
} ) {
	const announce = useAnnounce();
	const [ confirming, setConfirming ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ allError, setAllError ] = useState( null );
	const heading = useRef( null );
	const revokeAllButton = useRef( null );
	const [ acted, setActed ] = useState( 0 );
	const latest = useLatestLine( now, `${ refreshToken }:${ acted }` );

	const act = async ( id, action, arg ) => {
		const result = await onAct( id, action, arg );
		setActed( ( count ) => count + 1 );
		if ( 'revoke' === action ) {
			// The row is gone, so its buttons can't hold focus.
			heading.current?.focus();
		}
		return result;
	};

	const revokeAll = async () => {
		setBusy( true );
		setAllError( null );
		try {
			await onRevokeAll();
			setActed( ( count ) => count + 1 );
			setConfirming( false );
			announce( __( 'All access revoked', 'happyaccess' ) );
			heading.current?.focus();
		} catch ( e ) {
			setAllError( e );
		} finally {
			setBusy( false );
		}
	};

	const keepAccess = () => {
		setConfirming( false );
		revokeAllButton.current?.focus();
	};

	return (
		<section className="ha-active" aria-labelledby="ha-active-title">
			<div className="ha-active__head">
				<h2 id="ha-active-title" ref={ heading } tabIndex={ -1 }>
					{ __( 'Who has access', 'happyaccess' ) }
				</h2>
				<span
					className="ha-active__count"
					aria-label={ sprintf(
						/* translators: %d: number of passes in the list. */
						__( '%d in the list', 'happyaccess' ),
						grants.length
					) }
				>
					{ grants.length }
				</span>
				<span className="ha-row__spacer" />
				{ grants.length > 0 && (
					<Button
						ref={ revokeAllButton }
						variant="tertiary"
						isDestructive
						accessibleWhenDisabled
						disabled={ busy }
						aria-expanded={ confirming }
						onClick={ () => {
							setAllError( null );
							setConfirming( ! confirming );
						} }
					>
						{ __( 'Revoke all', 'happyaccess' ) }
					</Button>
				) }
			</div>

			{ confirming && (
				<div className="ha-active__confirm">
					<InlineConfirm
						keepLabel={ __( 'Keep access', 'happyaccess' ) }
						doLabel={ __( 'Revoke all now', 'happyaccess' ) }
						busy={ busy }
						onKeep={ keepAccess }
						onConfirm={ revokeAll }
					>
						{ __(
							'Revoke every pass below? Everyone using a pass is logged out and their accounts are deleted.',
							'happyaccess'
						) }
					</InlineConfirm>
				</div>
			) }
			{ allError && (
				<div className="ha-active__confirm">
					<Notice status="error" isDismissible={ false }>
						{ allError.message }
					</Notice>
				</div>
			) }
			{ error && (
				<div className="ha-active__confirm">
					<Notice status="error" isDismissible={ false }>
						{ error.message }
					</Notice>
					<Button variant="link" onClick={ onRetry }>
						{ __( 'Try again', 'happyaccess' ) }
					</Button>
				</div>
			) }

			{ loading && 0 === grants.length && (
				<p className="ha-active__empty">
					{ __( 'Loading passes', 'happyaccess' ) }
				</p>
			) }

			{ grants.length > 0 && (
				<ul className="ha-rows">
					{ grants.map( ( grant ) => (
						<GrantRow
							key={ grant.id }
							grant={ grant }
							now={ now }
							boot={ boot }
							onAct={ act }
							locked={ busy }
							onViewActivity={ onViewActivity }
						/>
					) ) }
				</ul>
			) }

			{ ! loading && ! error && 0 === grants.length && (
				<div className="ha-active__empty">
					<div className="ha-active__empty-title">
						{ __( 'Nobody has access.', 'happyaccess' ) }
					</div>
					<div>
						{ __(
							'Passes you create show here, with the time each one has left.',
							'happyaccess'
						) }
					</div>
				</div>
			) }

			<div className="ha-active__foot">
				<span className="ha-active__latest">{ latest }</span>
				<a
					href={ tabUrl( 'activity' ) }
					onClick={ ( event ) => {
						if ( isPlainClick( event ) ) {
							event.preventDefault();
							onViewActivity();
						}
					} }
				>
					{ __( 'See all activity', 'happyaccess' ) }
				</a>
			</div>
		</section>
	);
}
