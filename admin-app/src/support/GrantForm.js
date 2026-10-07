import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	DateTimePicker,
	FormTokenField,
	Notice,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import {
	date as siteDate,
	dateI18n,
	getDate,
	getSettings,
} from '@wordpress/date';
import { __, _n, isRTL, sprintf } from '@wordpress/i18n';
import { Icon, chevronDown, chevronLeft, chevronRight } from '@wordpress/icons';
import { createGrant } from '../api';
import { useAnnounce } from '../hooks/useAnnounce';
import { useNow } from '../hooks/useNow';
import PassPreview from './PassPreview';
import PermissionEditor from './PermissionEditor';
import { useCatalog } from './useCatalog';

const DAY_MS = 86400000;
const MIN_MS = 3600000;
const END_FORMAT = 'D, M j, g:i a';
const NAIVE_FORMAT = 'Y-m-d\\TH:i:s';
const DEFAULT_CUSTOM_DAYS = 14;
const NO_MENUS = [];

const getLevels = () => [
	{
		id: 'protected',
		name: __( 'Protected admin', 'happyaccess' ),
		text: __(
			'Can fix almost anything. The risky actions are blocked. Best for most support.',
			'happyaccess'
		),
		roleName: __( 'Administrator (protected)', 'happyaccess' ),
	},
	{
		id: 'custom',
		name: __( 'Custom access', 'happyaccess' ),
		text: __(
			'Pick exactly what they can do, permission by permission.',
			'happyaccess'
		),
		roleName: __( 'Custom access', 'happyaccess' ),
	},
	{
		id: 'full',
		name: __( 'Full admin, no limits', 'happyaccess' ),
		text: __(
			'For teams you fully trust, like your host or developer.',
			'happyaccess'
		),
		roleName: __( 'Administrator (full access)', 'happyaccess' ),
	},
];

const getDurations = () => [
	{ key: '1', days: 1, label: __( '1 day', 'happyaccess' ) },
	{ key: '3', days: 3, label: __( '3 days', 'happyaccess' ) },
	{ key: '7', days: 7, label: __( '7 days', 'happyaccess' ) },
	{ key: 'custom', days: 0, label: __( 'Custom', 'happyaccess' ) },
];

const initialForm = () => ( {
	label: '',
	email: '',
	level: 'protected',
	base: 'administrator',
	caps: null,
	confirmFull: false,
	duration: '3',
	customEnd: '',
	more: false,
	oneTime: false,
	notify: 'first',
	menus: [],
	ips: '',
	redirectTo: '',
	role: 'administrator',
	sendEmail: false,
} );

/**
 * Menu slugs and the labels the token field shows for them.
 *
 * @param {Array} menus boot.menus: top-level items with their children.
 * @return {{labels: string[], bySlug: Map, byLabel: Map}} Lookups.
 */
function buildMenuLookups( menus ) {
	const labels = [];
	const bySlug = new Map();
	const byLabel = new Map();
	const add = ( slug, label ) => {
		if ( ! slug || byLabel.has( label ) ) {
			return;
		}
		labels.push( label );
		bySlug.set( slug, label );
		byLabel.set( label, slug );
	};
	( menus || [] ).forEach( ( menu ) => {
		const parent = menu.title || menu.slug;
		add( menu.slug, parent );
		( menu.children || [] ).forEach( ( child ) => {
			add(
				child.slug,
				sprintf(
					/* translators: 1: parent menu name, 2: sub menu name. */
					__( '%1$s › %2$s', 'happyaccess' ),
					parent,
					child.title || child.slug
				)
			);
		} );
	} );
	return { labels, bySlug, byLabel };
}

const isTwelveHour = () =>
	/a/i.test( String( getSettings().formats.time ).replace( /\\./g, '' ) );

// A site timezone is only worth naming when it is not the browser's own.
function timezoneNote( timezone ) {
	if ( ! timezone ) {
		return '';
	}
	let browser = '';
	try {
		browser = Intl.DateTimeFormat().resolvedOptions().timeZone;
	} catch {
		browser = '';
	}
	if ( timezone === browser ) {
		return '';
	}
	const offset = /^([+-])(\d{1,2}):?(\d{2})?$/.exec( timezone );
	if ( offset ) {
		const minutes =
			( '-' === offset[ 1 ] ? -1 : 1 ) *
			( Number( offset[ 2 ] ) * 60 + Number( offset[ 3 ] || 0 ) );
		if ( minutes === -new Date().getTimezoneOffset() ) {
			return '';
		}
	}
	return ` (${ timezone })`;
}

const naive = ( ms ) => siteDate( NAIVE_FORMAT, new Date( ms ) );

/**
 * The red box that asks the creator to vouch for the person.
 *
 * @param {Object}                   props          Props.
 * @param {Element}                  props.children The warning text.
 * @param {boolean}                  props.checked  Whether the box is ticked.
 * @param {(value: boolean) => void} props.onChange Called with the new value.
 * @return {Element} The box.
 */
function TrustBox( { children, checked, onChange } ) {
	return (
		<div className="ha-trust" role="alert">
			<p>{ children }</p>
			<CheckboxControl
				label={ __(
					'I trust this person with full access to my site',
					'happyaccess'
				) }
				checked={ checked }
				onChange={ onChange }
				__nextHasNoMarginBottom
			/>
		</div>
	);
}

/**
 * The form that creates a support pass.
 *
 * @param {Object}                            props           Props.
 * @param {(result: Object) => void}          props.onCreated Called with the create response, which holds the plain code and link.
 * @param {(form: Object) => Promise<Object>} props.create    Sends the form to the server. Pass the one from useGrants to keep the list in step.
 * @param {Object}                            props.boot      Boot data. Defaults to window.happyaccessBoot.
 * @param {Object}                            props.labelRef  Ref that receives the "Who is it for" input, so the parent can focus it.
 * @return {Element} The form.
 */
export default function GrantForm( {
	onCreated,
	create = createGrant,
	boot = window.happyaccessBoot,
	labelRef,
} ) {
	const {
		menus = NO_MENUS,
		roles = NO_MENUS,
		maxDays = 30,
		timezone = '',
	} = boot || {};
	const announce = useAnnounce();
	const now = useNow();
	const [ form, setForm ] = useState( initialForm );
	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState( null );
	const mounted = useRef( true );
	const levels = useMemo( getLevels, [] );
	const durations = useMemo( getDurations, [] );
	const lookups = useMemo( () => buildMenuLookups( menus ), [ menus ] );

	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, [] );

	const set = ( patch ) => setForm( ( prev ) => ( { ...prev, ...patch } ) );

	const isCustom = 'custom' === form.level;
	// Loads on the first visit to Custom and stays for as long as the form does.
	const {
		catalog,
		loading,
		error: catalogError,
		retry,
	} = useCatalog( isCustom );

	const presets = catalog ? catalog.presets : {};
	const baseKey = presets[ form.base ]
		? form.base
		: Object.keys( presets )[ 0 ] || form.base;
	const caps = form.caps || presets[ baseKey ] || [];

	const trustCaps = useMemo(
		() =>
			new Set(
				( catalog ? catalog.groups : [] ).flatMap( ( group ) =>
					group.caps
						.filter( ( item ) => item.trust )
						.map( ( item ) => item.cap )
				)
			),
		[ catalog ]
	);
	const hasTrustCap = ( list ) =>
		list.some( ( cap ) => trustCaps.has( cap ) );
	const needsTrust =
		'full' === form.level || ( isCustom && hasTrustCap( caps ) );

	// Time. The custom end is kept as a plain site-time string for the picker.
	const nowMs = now * 1000;
	const minMs = nowMs + MIN_MS;
	const maxMs = nowMs + maxDays * DAY_MS;
	const clamp = ( ms ) => Math.min( maxMs, Math.max( minMs, ms ) );
	const preset = durations.find( ( item ) => item.key === form.duration );
	const defaultCustomMs =
		nowMs + Math.min( DEFAULT_CUSTOM_DAYS, maxDays ) * DAY_MS;
	let endMs = nowMs + preset.days * DAY_MS;
	if ( 'custom' === form.duration ) {
		endMs = clamp(
			form.customEnd
				? getDate( form.customEnd ).getTime()
				: defaultCustomMs
		);
	}
	const zoneNote = timezoneNote( timezone );
	const endText = dateI18n( END_FORMAT, new Date( endMs ) );
	const endsLine =
		'1' === form.duration
			? sprintf(
					/* translators: %s: date and time the pass ends. */
					__( 'Ends tomorrow, %s', 'happyaccess' ),
					endText
				)
			: sprintf(
					/* translators: %s: date and time the pass ends. */
					__( 'Ends %s', 'happyaccess' ),
					endText
				);

	const email = form.email.trim();
	const levelInfo = levels.find( ( item ) => item.id === form.level );
	let levelName = levelInfo.roleName;
	if ( 'protected' === form.level && 'administrator' !== form.role ) {
		const picked = roles.find( ( item ) => item.slug === form.role );
		levelName = picked ? picked.name : levelName;
	}

	let blocker = '';
	if ( ! form.label.trim() ) {
		blocker = 'label';
	} else if ( isCustom && ( ! catalog || 0 === caps.length ) ) {
		blocker = 'caps';
	} else if ( needsTrust && ! form.confirmFull ) {
		blocker = 'trust';
	}

	const pickLevel = ( level ) => {
		if ( level !== form.level ) {
			set( { level, confirmFull: false } );
		}
	};

	const changeCaps = ( next ) => {
		set( {
			caps: next,
			confirmFull: hasTrustCap( next ) ? form.confirmFull : false,
		} );
	};

	const changeBase = ( key ) => {
		const next = presets[ key ] || [];
		set( {
			base: key,
			caps: null,
			confirmFull: hasTrustCap( next ) ? form.confirmFull : false,
		} );
	};

	const pickDuration = ( key ) => {
		const patch = { duration: key };
		if ( 'custom' === key && ! form.customEnd ) {
			patch.customEnd = naive( clamp( defaultCustomMs ) );
		}
		set( patch );
	};

	const pickDate = ( value ) => {
		if ( value ) {
			set( { customEnd: naive( clamp( getDate( value ).getTime() ) ) } );
		}
	};

	const isInvalidDate = ( day ) =>
		day.getTime() > maxMs || day.getTime() < nowMs - DAY_MS;

	const submit = async ( event ) => {
		event.preventDefault();
		if ( blocker || submitting ) {
			return;
		}
		const seconds =
			'custom' === form.duration
				? Math.round( ( endMs - Date.now() ) / 1000 )
				: preset.days * 86400;
		const maxSeconds = maxDays * 86400;
		setSubmitting( true );
		setError( null );
		try {
			const result = await create( {
				label: form.label.trim(),
				email,
				level: form.level,
				caps: isCustom ? caps : undefined,
				confirmFull: needsTrust && form.confirmFull,
				durationSeconds: Math.min(
					maxSeconds,
					Math.max( 3600, seconds )
				),
				oneTime: form.oneTime,
				notify: form.notify,
				menus: form.menus,
				ips: form.ips,
				redirectTo: form.redirectTo,
				role: form.role,
				sendEmail: Boolean( email && form.sendEmail ),
			} );
			announce(
				sprintf(
					/* translators: %s: who the pass is for. */
					__( 'Support pass created for %s', 'happyaccess' ),
					form.label.trim()
				)
			);
			if ( onCreated ) {
				onCreated( result );
			}
		} catch ( e ) {
			if ( mounted.current ) {
				setError( e );
			}
		} finally {
			if ( mounted.current ) {
				setSubmitting( false );
			}
		}
	};

	const closedIcon = isRTL() ? chevronLeft : chevronRight;
	const roleOptions = [
		{
			value: 'administrator',
			label: __( 'Administrator (uses the level above)', 'happyaccess' ),
		},
		...roles
			.filter( ( item ) => 'administrator' !== item.slug )
			.map( ( item ) => ( { value: item.slug, label: item.name } ) ),
	];

	return (
		<section className="ha-grant" aria-labelledby="ha-grant-title">
			<div className="ha-grant__head">
				<h2 id="ha-grant-title">
					{ __( 'Give support access', 'happyaccess' ) }
				</h2>
				<p>
					{ __(
						'Creates a login link and an 8-digit code. No passwords to share, and it ends by itself.',
						'happyaccess'
					) }
				</p>
			</div>
			<form className="ha-form" onSubmit={ submit } noValidate>
				<TextControl
					ref={ labelRef }
					className="ha-field"
					label={ __( 'Who is it for', 'happyaccess' ) }
					value={ form.label }
					onChange={ ( label ) => set( { label } ) }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<TextControl
					className="ha-field"
					type="email"
					label={ __( 'Their email (optional)', 'happyaccess' ) }
					help={ __(
						'Only used if you send the access by email.',
						'happyaccess'
					) }
					placeholder="support@example.com"
					value={ form.email }
					onChange={ ( value ) => set( { email: value } ) }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>

				<fieldset className="ha-fieldset">
					<legend className="ha-legend">
						{ __( 'What they can do', 'happyaccess' ) }
					</legend>
					{ levels.map( ( level ) => {
						const on = level.id === form.level;
						return (
							// The visible text sits in nested spans, which the rule can't see.
							// eslint-disable-next-line jsx-a11y/label-has-associated-control
							<label
								htmlFor={ `ha-level-${ level.id }` }
								key={ level.id }
								className={ [
									'ha-level',
									on ? 'is-selected' : '',
									'full' === level.id ? 'is-full' : '',
								]
									.filter( Boolean )
									.join( ' ' ) }
							>
								<input
									id={ `ha-level-${ level.id }` }
									type="radio"
									name="ha-level"
									value={ level.id }
									checked={ on }
									onChange={ () => pickLevel( level.id ) }
								/>
								<span className="ha-level__text">
									<span className="ha-level__name">
										{ level.name }
									</span>
									<span className="ha-level__desc">
										{ level.text }
									</span>
								</span>
							</label>
						);
					} ) }

					{ 'protected' === form.level && (
						<div className="ha-note">
							<span className="ha-note__title">
								{ __(
									'Always blocked on this pass',
									'happyaccess'
								) }
							</span>
							<span>
								{ __(
									'Changing passwords or emails, editing or deleting admins, deleting users, erasing the activity log, and turning off HappyAccess.',
									'happyaccess'
								) }
							</span>
						</div>
					) }

					{ isCustom && (
						<PermissionEditor
							catalog={ catalog }
							loading={ loading }
							error={ catalogError }
							onRetry={ retry }
							caps={ caps }
							base={ baseKey }
							onBaseChange={ changeBase }
							onCapsChange={ changeCaps }
						/>
					) }
					{ isCustom && needsTrust && (
						<TrustBox
							checked={ form.confirmFull }
							onChange={ ( confirmFull ) =>
								set( { confirmFull } )
							}
						>
							<strong>
								{ __(
									'Admin-level permissions are on.',
									'happyaccess'
								) }
							</strong>{ ' ' }
							{ __(
								'With these, they can change site settings, install code or manage users, which can lead to full control of your site.',
								'happyaccess'
							) }
						</TrustBox>
					) }
					{ 'full' === form.level && (
						<TrustBox
							checked={ form.confirmFull }
							onChange={ ( confirmFull ) =>
								set( { confirmFull } )
							}
						>
							<strong>
								{ __( 'No limits.', 'happyaccess' ) }
							</strong>{ ' ' }
							{ __(
								'They can do anything an admin can, including installing code or creating another admin account. The pass still ends on time, every login sends you an alert, and any new admin account is flagged and emailed to you.',
								'happyaccess'
							) }
						</TrustBox>
					) }
				</fieldset>

				<fieldset className="ha-fieldset">
					<legend className="ha-legend">
						{ __( 'Access ends after', 'happyaccess' ) }
					</legend>
					<div className="ha-segments">
						{ durations.map( ( item ) => (
							<button
								key={ item.key }
								type="button"
								className="ha-segment"
								aria-pressed={ item.key === form.duration }
								onClick={ () => pickDuration( item.key ) }
							>
								{ item.label }
							</button>
						) ) }
					</div>
					{ 'custom' === form.duration && (
						<div className="ha-custom-date">
							<p className="ha-help">
								{ sprintf(
									/* translators: %d: the most days a pass can last. */
									_n(
										'Pick a date up to %d day ahead.',
										'Pick a date up to %d days ahead.',
										maxDays,
										'happyaccess'
									),
									maxDays
								) }
							</p>
							<DateTimePicker
								currentDate={ naive( endMs ) }
								onChange={ pickDate }
								isInvalidDate={ isInvalidDate }
								is12Hour={ isTwelveHour() }
							/>
						</div>
					) }
					<span className="ha-help" data-testid="ha-ends">
						{ endsLine }
						{ zoneNote }
					</span>
				</fieldset>

				<button
					type="button"
					className="ha-disclosure"
					aria-expanded={ form.more }
					onClick={ () => set( { more: ! form.more } ) }
				>
					<Icon
						icon={ form.more ? chevronDown : closedIcon }
						size={ 20 }
					/>
					{ __( 'More options', 'happyaccess' ) }
				</button>
				{ form.more && (
					<div className="ha-more">
						<CheckboxControl
							label={ __( 'One-time use', 'happyaccess' ) }
							help={ __(
								'The link and code work for one login. That session can carry on until access ends.',
								'happyaccess'
							) }
							checked={ form.oneTime }
							onChange={ ( oneTime ) => set( { oneTime } ) }
							__nextHasNoMarginBottom
						/>
						{ 'protected' === form.level && (
							<SelectControl
								label={ __(
									'Or give a different role',
									'happyaccess'
								) }
								value={ form.role }
								options={ roleOptions }
								onChange={ ( role ) => set( { role } ) }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						) }
						<div className="ha-more__pair">
							<SelectControl
								label={ __( 'Login alerts', 'happyaccess' ) }
								value={ form.notify }
								options={ [
									{
										value: 'first',
										label: __(
											'First login',
											'happyaccess'
										),
									},
									{
										value: 'every',
										label: __(
											'Every login',
											'happyaccess'
										),
									},
									{
										value: 'off',
										label: __( 'Off', 'happyaccess' ),
									},
								] }
								onChange={ ( notify ) => set( { notify } ) }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
							<FormTokenField
								label={ __(
									'Hide admin screens',
									'happyaccess'
								) }
								value={ form.menus
									.map( ( slug ) =>
										lookups.bySlug.get( slug )
									)
									.filter( Boolean ) }
								suggestions={ lookups.labels }
								onChange={ ( tokens ) =>
									set( {
										menus: [
											...new Set(
												tokens
													.map( ( token ) =>
														lookups.byLabel.get(
															'string' ===
																typeof token
																? token
																: token.value
														)
													)
													.filter( Boolean )
											),
										],
									} )
								}
								__next40pxDefaultSize
								__nextHasNoMarginBottom
							/>
						</div>
						<TextControl
							label={ __(
								'Only allow these IP addresses',
								'happyaccess'
							) }
							help={ __(
								'Separate several with commas.',
								'happyaccess'
							) }
							placeholder={ __(
								'Any IP address',
								'happyaccess'
							) }
							value={ form.ips }
							onChange={ ( ips ) => set( { ips } ) }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<TextControl
							label={ __( 'After login, open', 'happyaccess' ) }
							help={ __(
								'A path like /wp-admin/edit.php. Leave it empty to open the dashboard.',
								'happyaccess'
							) }
							placeholder={ __( 'Dashboard', 'happyaccess' ) }
							value={ form.redirectTo }
							onChange={ ( redirectTo ) => set( { redirectTo } ) }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						{ '' !== email && (
							<ToggleControl
								label={ __(
									'Email it to them now',
									'happyaccess'
								) }
								checked={ form.sendEmail }
								onChange={ ( sendEmail ) =>
									set( { sendEmail } )
								}
								__nextHasNoMarginBottom
							/>
						) }
					</div>
				) }

				<PassPreview
					label={ form.label }
					levelName={ levelName }
					validUntil={ endText + zoneNote }
				/>

				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error.message }
					</Notice>
				) }
				<Button
					className="ha-create"
					variant="primary"
					type="submit"
					disabled={ '' !== blocker || submitting }
					isBusy={ submitting }
				>
					{ __( 'Create support pass', 'happyaccess' ) }
				</Button>
			</form>
		</section>
	);
}
