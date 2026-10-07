import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useAnnounce } from '../hooks/useAnnounce';
import { tabUrl } from '../tabList';
import {
	formatEnd,
	levelName,
	passLength,
	statusLabel,
	timeAgo,
} from './passFormat';
import Ring, { longLeft } from './Ring';

const DAY = 86400;
const EXTEND_DAYS = [ 1, 3, 7 ];

function metaLine( grant, now ) {
	if ( grant.last_login_at > 0 ) {
		const ago = timeAgo( grant.last_login_at, now );
		if ( grant.one_time ) {
			return sprintf(
				/* translators: %s: how long ago, like "5 hours ago". */
				__( 'One-time pass, logged in %s', 'happyaccess' ),
				ago
			);
		}
		return sprintf(
			/* translators: 1: how long ago, like "14 minutes ago". 2: the login count. */
			__( 'Last login %1$s, %2$s', 'happyaccess' ),
			ago,
			sprintf(
				/* translators: %d: number of logins. */
				_n( '%d login', '%d logins', grant.login_count, 'happyaccess' ),
				grant.login_count
			)
		);
	}
	return __( 'Never logged in', 'happyaccess' );
}

/**
 * An inline question with a safe way out. Focus lands on the safe button.
 *
 * @param {Object}     props           Props.
 * @param {Element}    props.children  The question.
 * @param {string}     props.keepLabel Text of the cancel button.
 * @param {string}     props.doLabel   Text of the confirm button.
 * @param {boolean}    props.busy      Whether the request is running.
 * @param {() => void} props.onKeep    Called on cancel.
 * @param {() => void} props.onConfirm Called on confirm.
 * @param {boolean}    props.danger    Whether the confirm button is destructive.
 * @return {Element} The confirm.
 */
export function InlineConfirm( {
	children,
	keepLabel,
	doLabel,
	busy,
	onKeep,
	onConfirm,
	danger = true,
} ) {
	const keep = useRef( null );
	useEffect( () => {
		keep.current?.focus();
	}, [] );

	return (
		<div className="ha-confirm" role="alert">
			<span className="ha-confirm__text">{ children }</span>
			<Button
				ref={ keep }
				variant="secondary"
				size="compact"
				accessibleWhenDisabled
				disabled={ busy }
				onClick={ onKeep }
			>
				{ keepLabel }
			</Button>
			<Button
				variant="primary"
				size="compact"
				isDestructive={ danger }
				isBusy={ busy }
				accessibleWhenDisabled
				disabled={ busy }
				onClick={ onConfirm }
			>
				{ doLabel }
			</Button>
		</div>
	);
}

/**
 * One pass: its ring, status, times and actions.
 *
 * @param {Object}                                                 props                Props.
 * @param {Object}                                                 props.grant          Grant from the REST list.
 * @param {number}                                                 props.now            Current Unix time in seconds.
 * @param {Object}                                                 props.boot           Boot data: roles and maxDays.
 * @param {(id: number, action: string, arg?: unknown) => Promise} props.onAct          Runs an action on a pass.
 * @param {(id: number) => void}                                   props.onViewActivity Opens the Activity tab for this pass.
 * @return {Element} The list item.
 */
export default function GrantRow( {
	grant,
	now,
	boot = {},
	onAct,
	onViewActivity,
} ) {
	const announce = useAnnounce();
	const [ panel, setPanel ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const mounted = useRef( true );
	const triggers = useRef( {} );
	const { roles = [], maxDays = 30 } = boot;

	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, [] );

	const suspended = 'suspended' === grant.status;
	const secondsLeft = Math.max( 0, grant.expires_at - now );
	const capSeconds = now + maxDays * DAY;
	const extendDays = EXTEND_DAYS.filter(
		( days ) => grant.expires_at + days * DAY <= capSeconds
	);

	const run = async ( action, arg, message ) => {
		setBusy( true );
		setError( null );
		try {
			await onAct( grant.id, action, arg );
			announce( message );
			if ( mounted.current ) {
				setPanel( '' );
			}
		} catch ( e ) {
			if ( mounted.current ) {
				setError( e );
			}
		} finally {
			if ( mounted.current ) {
				setBusy( false );
			}
		}
	};

	const open = ( name ) => {
		setError( null );
		setPanel( panel === name ? '' : name );
	};
	const close = ( name ) => {
		setPanel( '' );
		triggers.current[ name ]?.focus();
	};
	const trigger = ( name ) => ( node ) => {
		triggers.current[ name ] = node;
	};

	const toggle = () =>
		suspended
			? run(
					'resume',
					undefined,
					sprintf(
						/* translators: %s: who the pass is for. */
						__( 'Access resumed for %s', 'happyaccess' ),
						grant.label
					)
				)
			: run(
					'suspend',
					undefined,
					sprintf(
						/* translators: %s: who the pass is for. */
						__( 'Access suspended for %s', 'happyaccess' ),
						grant.label
					)
				);

	const extend = ( days ) =>
		run(
			'extend',
			days * DAY,
			sprintf(
				/* translators: 1: who the pass is for. 2: number of days added. */
				_n(
					'Access for %1$s extended by %2$d day',
					'Access for %1$s extended by %2$d days',
					days,
					'happyaccess'
				),
				grant.label,
				days
			)
		);

	return (
		<li className="ha-row" aria-busy={ busy || undefined }>
			<div className="ha-row__main">
				<Ring
					secondsLeft={ secondsLeft }
					total={ grant.duration }
					state={ suspended ? 'suspended' : 'active' }
				/>
				<div className="ha-row__who">
					<div className="ha-row__name">
						<span className="ha-row__label">{ grant.label }</span>
						<span
							className={ `ha-pill ha-pill--${ grant.status }` }
						>
							{ statusLabel( grant.status ) }
						</span>
					</div>
					<div className="ha-row__meta">
						{ levelName( grant, roles ) }
						{ ' · ' }
						{ metaLine( grant, now ) }
					</div>
				</div>
				<div className="ha-row__ends">
					<div
						className="ha-row__left"
						title={ sprintf(
							/* translators: %s: date and time the pass ends. */
							__( 'Access ends %s', 'happyaccess' ),
							formatEnd( grant.expires_at )
						) }
					>
						{ longLeft( secondsLeft ) }
					</div>
					<div>{ passLength( grant.duration ) }</div>
				</div>
			</div>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error.message }
				</Notice>
			) }

			{ 'revoke' === panel && (
				<InlineConfirm
					keepLabel={ __( 'Keep access', 'happyaccess' ) }
					doLabel={ __( 'Revoke now', 'happyaccess' ) }
					busy={ busy }
					onKeep={ () => close( 'revoke' ) }
					onConfirm={ () =>
						run(
							'revoke',
							undefined,
							sprintf(
								/* translators: %s: who the pass was for. */
								__( 'Access revoked for %s', 'happyaccess' ),
								grant.label
							)
						)
					}
				>
					{ sprintf(
						/* translators: %s: who the pass is for. */
						__(
							"Revoke access for %s? They're logged out right away and the account is deleted.",
							'happyaccess'
						),
						grant.label
					) }
				</InlineConfirm>
			) }

			{ 'regenerate' === panel && (
				<InlineConfirm
					keepLabel={ __( 'Keep the current ones', 'happyaccess' ) }
					doLabel={ __( 'Make new ones', 'happyaccess' ) }
					busy={ busy }
					danger={ false }
					onKeep={ () => close( 'regenerate' ) }
					onConfirm={ () =>
						run(
							'regenerate',
							false,
							sprintf(
								/* translators: %s: who the pass is for. */
								__(
									'New link and code made for %s',
									'happyaccess'
								),
								grant.label
							)
						)
					}
				>
					{ __(
						'This makes a new link and code. The old ones stop working and anyone using this pass is logged out. A suspended pass stays suspended.',
						'happyaccess'
					) }
				</InlineConfirm>
			) }

			{ 'extend' === panel && (
				<div className="ha-extend">
					{ extendDays.length > 0 ? (
						<>
							<span className="ha-extend__lead">
								{ __( 'Add time:', 'happyaccess' ) }
							</span>
							{ extendDays.map( ( days ) => (
								<Button
									key={ days }
									variant="secondary"
									size="compact"
									accessibleWhenDisabled
									disabled={ busy }
									onClick={ () => extend( days ) }
								>
									{ sprintf(
										/* translators: %d: number of days to add. */
										_n(
											'+%d day',
											'+%d days',
											days,
											'happyaccess'
										),
										days
									) }
								</Button>
							) ) }
						</>
					) : (
						<span>
							{ sprintf(
								/* translators: %d: the most days a pass can last. */
								__(
									'This pass already ends at the %d-day limit.',
									'happyaccess'
								),
								maxDays
							) }
						</span>
					) }
					<span className="ha-help">
						{ sprintf(
							/* translators: %d: the most days a pass can last. */
							_n(
								"Never more than %d day from now. If they're logged in before it ends, their session carries on.",
								"Never more than %d days from now. If they're logged in before it ends, their session carries on.",
								maxDays,
								'happyaccess'
							),
							maxDays
						) }
					</span>
					<span className="ha-help">
						{ grant.one_time && 'used' === grant.status
							? __(
									'This one-time pass is used. For another login, use New link and code.',
									'happyaccess'
								)
							: __(
									'If they are not logged in, they use the link or code as before.',
									'happyaccess'
								) }
					</span>
				</div>
			) }

			<div
				className="ha-row__actions"
				role="group"
				aria-label={ sprintf(
					/* translators: %s: who the pass is for. */
					__( 'Actions for %s', 'happyaccess' ),
					grant.label
				) }
			>
				<Button
					ref={ trigger( 'extend' ) }
					variant="secondary"
					size="compact"
					accessibleWhenDisabled
					disabled={ busy }
					aria-expanded={ 'extend' === panel }
					onClick={ () => open( 'extend' ) }
				>
					{ __( 'Extend', 'happyaccess' ) }
				</Button>
				<Button
					variant="secondary"
					size="compact"
					accessibleWhenDisabled
					disabled={ busy }
					onClick={ toggle }
				>
					{ suspended
						? __( 'Resume', 'happyaccess' )
						: __( 'Suspend', 'happyaccess' ) }
				</Button>
				<Button
					ref={ trigger( 'regenerate' ) }
					variant="secondary"
					size="compact"
					accessibleWhenDisabled
					disabled={ busy }
					aria-expanded={ 'regenerate' === panel }
					onClick={ () => open( 'regenerate' ) }
				>
					{ __( 'New link and code', 'happyaccess' ) }
				</Button>
				<a
					className="ha-row__link"
					href={ tabUrl( 'activity', { token: grant.id } ) }
					onClick={ ( event ) => {
						event.preventDefault();
						onViewActivity( grant.id );
					} }
				>
					{ __( 'View activity', 'happyaccess' ) }
				</a>
				<span className="ha-row__spacer" />
				<Button
					ref={ trigger( 'revoke' ) }
					variant="tertiary"
					size="compact"
					isDestructive
					accessibleWhenDisabled
					disabled={ busy }
					aria-expanded={ 'revoke' === panel }
					onClick={ () => open( 'revoke' ) }
				>
					{ __( 'Revoke', 'happyaccess' ) }
				</Button>
			</div>
		</li>
	);
}
