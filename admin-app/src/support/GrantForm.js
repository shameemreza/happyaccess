import {
	useEffect,
	useId,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	DateTimePicker,
	Notice,
	TextControl,
} from '@wordpress/components';
import {
	date as siteDate,
	dateI18n,
	getDate,
	getSettings,
} from '@wordpress/date';
import { __, _n, sprintf } from '@wordpress/i18n';
import { createGrant } from '../api';
import { useCatalog } from '../data/DataProvider';
import { useAnnounce } from '../hooks/useAnnounce';
import { useNow } from '../hooks/useNow';
import MoreOptions from './MoreOptions';
import PassPreview from './PassPreview';
import PermissionEditor from './PermissionEditor';

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
	if ( ! offset ) {
		return ` (${ timezone })`;
	}
	const hours = Number( offset[ 2 ] );
	const mins = Number( offset[ 3 ] || 0 );
	const minutes = ( '-' === offset[ 1 ] ? -1 : 1 ) * ( hours * 60 + mins );
	if ( minutes === -new Date().getTimezoneOffset() ) {
		return '';
	}
	// "+06:00" reads better as "UTC+6", and "+00:00" as plain "UTC".
	if ( 0 === minutes ) {
		return ' (UTC)';
	}
	const minutePart = mins ? `:${ String( mins ).padStart( 2, '0' ) }` : '';
	return ` (UTC${ offset[ 1 ] }${ hours }${ minutePart })`;
}

const naive = ( ms ) => siteDate( NAIVE_FORMAT, new Date( ms ) );

// Close to what the server accepts: something@something.tld, no spaces.
const looksLikeEmail = ( value ) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value );

/**
 * Why Create is blocked, in the words shown under it.
 *
 * @param {string} blocker The first thing missing.
 * @return {string} The hint, or an empty string.
 */
function blockerHint( blocker ) {
	switch ( blocker ) {
		case 'label':
			return __( "Add who it's for", 'happyaccess' );
		case 'email':
			return __( 'Fix the email address', 'happyaccess' );
		case 'catalog':
			return __( 'The permissions have not loaded yet', 'happyaccess' );
		case 'caps':
			return __( 'Tick at least one permission', 'happyaccess' );
		case 'trust':
			return __( 'Tick the trust box to continue', 'happyaccess' );
		default:
			return '';
	}
}

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
	const [ emailTouched, setEmailTouched ] = useState( false );
	const mounted = useRef( true );
	const ids = useId();
	const levels = useMemo( getLevels, [] );
	const durations = useMemo( getDurations, [] );

	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, [] );

	const set = ( patch ) => setForm( ( prev ) => ( { ...prev, ...patch } ) );

	const isCustom = 'custom' === form.level;
	// Loads on the first visit to Custom and stays for as long as the form does.
	const { catalog, loading, error: catalogError, retry } = useCatalog();

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

	const emailInvalid = '' !== email && ! looksLikeEmail( email );
	let blocker = '';
	if ( ! form.label.trim() ) {
		blocker = 'label';
	} else if ( emailInvalid ) {
		blocker = 'email';
	} else if ( isCustom && ! catalog ) {
		blocker = 'catalog';
	} else if ( isCustom && 0 === caps.length ) {
		blocker = 'caps';
	} else if ( needsTrust && ! form.confirmFull ) {
		blocker = 'trust';
	}
	const hint = blockerHint( blocker );
	const hintId = `${ ids }-hint`;
	const showEmailError = emailInvalid && emailTouched;

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
					required
					aria-required="true"
					value={ form.label }
					onChange={ ( label ) => set( { label } ) }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<TextControl
					className={
						showEmailError ? 'ha-field has-error' : 'ha-field'
					}
					type="email"
					label={ __( 'Their email (optional)', 'happyaccess' ) }
					help={
						showEmailError
							? __(
									'Enter a full email address, like name@example.com.',
									'happyaccess'
								)
							: __(
									'Only used if you send the access by email.',
									'happyaccess'
								)
					}
					aria-invalid={ showEmailError || undefined }
					placeholder="support@example.com"
					value={ form.email }
					onChange={ ( value ) => set( { email: value } ) }
					onBlur={ () => setEmailTouched( true ) }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>

				<fieldset className="ha-fieldset">
					<legend className="ha-legend">
						{ __( 'What they can do', 'happyaccess' ) }
					</legend>
					{ levels.map( ( level ) => {
						const on = level.id === form.level;
						const nameId = `${ ids }-level-${ level.id }`;
						return (
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
									aria-labelledby={ nameId }
									aria-describedby={ `${ nameId }-desc` }
									onChange={ () => pickLevel( level.id ) }
								/>
								<span className="ha-level__text">
									<span
										className="ha-level__name"
										id={ nameId }
									>
										{ level.name }
									</span>
									<span
										className="ha-level__desc"
										id={ `${ nameId }-desc` }
									>
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

				<MoreOptions
					form={ form }
					set={ set }
					menus={ menus }
					roles={ roles }
				/>

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
					accessibleWhenDisabled
					disabled={ '' !== blocker || submitting }
					isBusy={ submitting }
					aria-describedby={ hint ? hintId : undefined }
				>
					{ __( 'Create support pass', 'happyaccess' ) }
				</Button>
				{ hint && (
					<p className="ha-help ha-create__hint" id={ hintId }>
						{ hint }
					</p>
				) }
			</form>
		</section>
	);
}
