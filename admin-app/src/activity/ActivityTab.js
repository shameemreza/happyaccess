import {
	createInterpolateElement,
	useEffect,
	useId,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { exportActivity } from '../api';
import { useGrants, useSettings } from '../data/DataProvider';
import { useActivity } from '../hooks/useActivity';
import { useAnnounce } from '../hooks/useAnnounce';
import { useNow } from '../hooks/useNow';
import LoadingLine from '../LoadingLine';
import {
	clearQueryToken,
	isPlainClick,
	readQueryToken,
	tabUrl,
} from '../tabList';
import ActivityRow from './ActivityRow';
import {
	activityKey,
	countCsvRows,
	downloadCsv,
	groupByDay,
	PER_PAGE,
	rangeFilters,
	siteDay,
} from './activityFormat';
import SessionSummary from './SessionSummary';

const NO_BOOT = {};
const noop = () => {};
const SEARCH_DELAY = 300;

function countText( count ) {
	return sprintf(
		/* translators: %d: number of events. */
		_n( '%d event', '%d events', count, 'happyaccess' ),
		count
	);
}

/**
 * The Activity tab: filters, an optional session summary and the timeline.
 *
 * @param {Object}     props                Props.
 * @param {Object}     props.boot           Boot data.
 * @param {boolean}    props.loginReady     Whether the Login tab exists yet.
 * @param {() => void} props.onOpenSettings Opens the Settings tab.
 * @return {Element} The tab.
 */
export default function ActivityTab( {
	boot = NO_BOOT,
	loginReady = false,
	onOpenSettings = noop,
} ) {
	const announce = useAnnounce();
	const now = useNow();
	const today = siteDay( now );
	const ids = useId();

	// A pass chosen from "View activity" arrives as ?token=, then leaves the URL.
	const [ startToken ] = useState( readQueryToken );
	useEffect( () => {
		if ( startToken ) {
			clearQueryToken();
		}
	}, [ startToken ] );

	const [ feature, setFeature ] = useState( '' );
	const [ who, setWho ] = useState(
		startToken ? `pass:${ startToken }` : 'all'
	);
	// A pass can run for weeks, so one picked from a link starts with a longer window.
	const [ when, setWhen ] = useState( startToken ? '30' : '7' );
	const [ from, setFrom ] = useState( '' );
	const [ to, setTo ] = useState( '' );
	const [ searchText, setSearchText ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ openId, setOpenId ] = useState( 0 );
	// Without the list the Who choice still offers everyone and you. Until it
	// arrives, a pass from the URL has no name yet.
	const { grants, loading: grantsLoading } = useGrants();
	const grantsLoaded = ! grantsLoading;
	const { settings } = useSettings();
	const keptDays = Number( settings?.privacy?.retention_days );
	// Without the settings the footer leaves out how long events are kept.
	const retention = keptDays > 0 ? keptDays : 0;
	const [ exporting, setExporting ] = useState( false );
	const [ exportError, setExportError ] = useState( '' );
	const headingRef = useRef( null );
	const speak = useRef( false );

	// Waits for a pause in typing, so one search is one request.
	const committed = useRef( '' );
	useEffect( () => {
		const timer = setTimeout( () => {
			const next = searchText.trim();
			if ( next !== committed.current ) {
				committed.current = next;
				speak.current = true;
				setSearch( next );
				setPage( 1 );
				setOpenId( 0 );
			}
		}, SEARCH_DELAY );
		return () => clearTimeout( timer );
	}, [ searchText ] );

	// A change to the filters starts again at the first page.
	const change = ( current, setter ) => ( value ) => {
		if ( value === current ) {
			return;
		}
		speak.current = true;
		setter( value );
		setPage( 1 );
		setOpenId( 0 );
	};
	const pickFeature = change( feature, setFeature );
	const pickWho = change( who, setWho );
	const pickWhen = change( when, setWhen );
	const pickFrom = change( from, setFrom );
	const pickTo = change( to, setTo );

	const filters = useMemo( () => {
		const next = { feature, ...rangeFilters( when, from, to, today ) };
		if ( who.startsWith( 'pass:' ) ) {
			next.token_id = Number( who.slice( 5 ) );
		} else if ( who.startsWith( 'user:' ) ) {
			next.user_id = Number( who.slice( 5 ) );
		}
		if ( search ) {
			next.search = search;
		}
		return next;
	}, [ feature, who, when, from, to, today, search ] );

	const request = { ...filters, page, per_page: PER_PAGE };
	const activity = useActivity( request );
	const { items, total, loading, error, loadedKey } = activity;

	// Say how many events a change of filters found, once the list for those
	// filters is the one on screen. Until then the total is the old one.
	const requestKey = activityKey( request );
	useEffect( () => {
		if (
			speak.current &&
			! loading &&
			! error &&
			loadedKey === requestKey
		) {
			speak.current = false;
			announce( countText( total ) );
		}
	}, [ loading, error, loadedKey, requestKey, total, announce ] );

	const days = useMemo( () => groupByDay( items, now ), [ items, now ] );

	const passId = who.startsWith( 'pass:' ) ? Number( who.slice( 5 ) ) : 0;
	const picked = grants.find( ( grant ) => grant.id === passId );
	let passLabel = __( 'Loading pass', 'happyaccess' );
	if ( picked ) {
		passLabel = picked.label;
	} else if ( grantsLoaded ) {
		passLabel = sprintf(
			/* translators: %d: the id of a support pass. */
			__( 'Pass #%d', 'happyaccess' ),
			passId
		);
	}

	const chips = [
		[ '', __( 'All', 'happyaccess' ) ],
		[ 'support', __( 'Temporary access', 'happyaccess' ) ],
	];
	if ( loginReady && boot.features?.passwordless ) {
		chips.push( [ 'passwordless', __( 'Passwordless', 'happyaccess' ) ] );
	}
	if ( loginReady && boot.features?.two_step ) {
		chips.push( [ 'two_step', __( 'Two-step', 'happyaccess' ) ] );
	}
	chips.push( [ 'admin', __( 'Admin', 'happyaccess' ) ] );

	const exportCsv = async () => {
		setExporting( true );
		setExportError( '' );
		try {
			const { csv, filename } = await exportActivity( filters );
			downloadCsv( csv, filename );
			announce(
				sprintf(
					/* translators: %s: number of events, like "12 events". */
					__( 'Exported %s', 'happyaccess' ),
					countText( countCsvRows( csv ) )
				)
			);
		} catch ( e ) {
			setExportError( e.message );
		} finally {
			setExporting( false );
		}
	};

	const turn = ( step ) => {
		setPage( ( current ) => current + step );
		setOpenId( 0 );
		headingRef.current?.focus();
	};
	const lastPage = Math.max( 1, Math.ceil( total / PER_PAGE ) );

	const keptLine =
		retention > 0
			? createInterpolateElement(
					sprintf(
						/* translators: %d: number of days events are kept. The link text is the Settings tab. */
						_n(
							'Kept for %d day, change it in <a>Settings</a>.',
							'Kept for %d days, change it in <a>Settings</a>.',
							retention,
							'happyaccess'
						),
						retention
					),
					{
						a: (
							// eslint-disable-next-line jsx-a11y/anchor-has-content
							<a
								href={ tabUrl( 'settings' ) }
								onClick={ ( event ) => {
									if ( isPlainClick( event ) ) {
										event.preventDefault();
										onOpenSettings();
									}
								} }
							/>
						),
					}
				)
			: null;

	const currentUser = boot.currentUser;

	return (
		<div className="ha-activity">
			{ passId > 0 && (
				<SessionSummary tokenId={ passId } label={ passLabel } />
			) }

			<section className="ha-log" aria-labelledby={ `${ ids }-title` }>
				<div className="ha-log__head">
					<div className="ha-log__bar">
						<h2
							id={ `${ ids }-title` }
							ref={ headingRef }
							tabIndex={ -1 }
						>
							{ __( 'Activity', 'happyaccess' ) }
						</h2>
						<span className="ha-log__spacer" />
						<label
							className="screen-reader-text"
							htmlFor={ `${ ids }-search` }
						>
							{ __( 'Search activity', 'happyaccess' ) }
						</label>
						<input
							id={ `${ ids }-search` }
							type="search"
							className="ha-log__search"
							placeholder={ __(
								'Search activity',
								'happyaccess'
							) }
							value={ searchText }
							onChange={ ( event ) =>
								setSearchText( event.target.value )
							}
						/>
						<Button
							variant="secondary"
							isBusy={ exporting }
							accessibleWhenDisabled
							disabled={ exporting }
							onClick={ exportCsv }
						>
							{ __( 'Export CSV', 'happyaccess' ) }
						</Button>
					</div>

					<div className="ha-log__filters">
						<div
							className="ha-chips"
							role="group"
							aria-label={ __(
								'Type of activity',
								'happyaccess'
							) }
						>
							{ chips.map( ( [ value, label ] ) => (
								<button
									key={ value || 'all' }
									type="button"
									className="ha-chip"
									aria-pressed={ feature === value }
									onClick={ () => pickFeature( value ) }
								>
									{ label }
								</button>
							) ) }
						</div>
						<span className="ha-log__spacer" />
						<label htmlFor={ `${ ids }-who` }>
							{ __( 'Who', 'happyaccess' ) }
						</label>
						<select
							id={ `${ ids }-who` }
							value={ who }
							onChange={ ( event ) =>
								pickWho( event.target.value )
							}
						>
							<option value="all">
								{ __( 'Everyone', 'happyaccess' ) }
							</option>
							{ currentUser?.id > 0 && (
								<option value={ `user:${ currentUser.id }` }>
									{ sprintf(
										/* translators: %s: the name of the person using the screen. */
										__( '%s (you)', 'happyaccess' ),
										currentUser.name
									) }
								</option>
							) }
							{ grants.map( ( grant ) => (
								<option
									key={ grant.id }
									value={ `pass:${ grant.id }` }
								>
									{ grant.label }
								</option>
							) ) }
							{ passId > 0 && ! picked && (
								<option value={ who }>{ passLabel }</option>
							) }
						</select>
						<label htmlFor={ `${ ids }-when` }>
							{ __( 'When', 'happyaccess' ) }
						</label>
						<select
							id={ `${ ids }-when` }
							value={ when }
							onChange={ ( event ) =>
								pickWhen( event.target.value )
							}
						>
							<option value="7">
								{ __( 'Last 7 days', 'happyaccess' ) }
							</option>
							<option value="30">
								{ __( 'Last 30 days', 'happyaccess' ) }
							</option>
							<option value="custom">
								{ __( 'Custom range', 'happyaccess' ) }
							</option>
						</select>
						{ 'custom' === when && (
							<>
								<label htmlFor={ `${ ids }-from` }>
									{ __( 'From', 'happyaccess' ) }
								</label>
								<input
									id={ `${ ids }-from` }
									type="date"
									value={ from }
									max={ to || undefined }
									onChange={ ( event ) =>
										pickFrom( event.target.value )
									}
								/>
								<label htmlFor={ `${ ids }-to` }>
									{ __( 'To', 'happyaccess' ) }
								</label>
								<input
									id={ `${ ids }-to` }
									type="date"
									value={ to }
									min={ from || undefined }
									onChange={ ( event ) =>
										pickTo( event.target.value )
									}
								/>
							</>
						) }
					</div>

					{ exportError && (
						<Notice status="error" isDismissible={ false }>
							{ exportError }
						</Notice>
					) }
				</div>

				<div className="ha-log__body" aria-busy={ loading }>
					{ error && (
						<div className="ha-log__notice">
							<Notice status="error" isDismissible={ false }>
								{ error.message }
							</Notice>
							<Button
								variant="secondary"
								onClick={ activity.refresh }
							>
								{ __( 'Try again', 'happyaccess' ) }
							</Button>
						</div>
					) }
					{ ! error && 0 === items.length && loading && (
						<LoadingLine loading className="ha-log__empty">
							{ __( 'Loading activity.', 'happyaccess' ) }
						</LoadingLine>
					) }
					{ ! error && 0 === items.length && ! loading && (
						<p className="ha-log__empty">
							{ __(
								'No activity matches these filters.',
								'happyaccess'
							) }
						</p>
					) }
					<div
						className={
							loading ? 'ha-log__days is-loading' : 'ha-log__days'
						}
					>
						{ days.map( ( day ) => (
							<div className="ha-day" key={ day.day }>
								<h3 className="ha-day__title">{ day.title }</h3>
								<ol className="ha-day__rows">
									{ day.items.map( ( item ) => (
										<ActivityRow
											key={ item.id }
											item={ item }
											expanded={ openId === item.id }
											onToggle={ () =>
												setOpenId(
													openId === item.id
														? 0
														: item.id
												)
											}
										/>
									) ) }
								</ol>
							</div>
						) ) }
					</div>
				</div>

				<div className="ha-log__foot">
					<span className="ha-log__count">
						<bdi>
							{ sprintf(
								/* translators: 1: events on this page. 2: events in all. */
								_n(
									'Showing %1$d of %2$d event.',
									'Showing %1$d of %2$d events.',
									total,
									'happyaccess'
								),
								items.length,
								total
							) }
						</bdi>
						{ keptLine && ' ' }
						{ keptLine }
					</span>
					<Button
						variant="secondary"
						size="compact"
						accessibleWhenDisabled
						disabled={ page <= 1 }
						aria-label={ sprintf(
							/* translators: 1: the current page number. 2: the number of pages. */
							__(
								'Previous page, page %1$d of %2$d',
								'happyaccess'
							),
							page,
							lastPage
						) }
						onClick={ () => turn( -1 ) }
					>
						{ __( 'Previous', 'happyaccess' ) }
					</Button>
					<Button
						variant="secondary"
						size="compact"
						accessibleWhenDisabled
						disabled={ page >= lastPage }
						aria-label={ sprintf(
							/* translators: 1: the current page number. 2: the number of pages. */
							__( 'Next page, page %1$d of %2$d', 'happyaccess' ),
							page,
							lastPage
						) }
						onClick={ () => turn( 1 ) }
					>
						{ __( 'Next', 'happyaccess' ) }
					</Button>
				</div>
			</section>
		</div>
	);
}
