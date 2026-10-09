import { createInterpolateElement, useId, useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useSettings } from '../data/DataProvider';
import { useAnnounce } from '../hooks/useAnnounce';
import Switch from '../settings/Switch';
import {
	OPTIONAL,
	REQUIRED,
	TWO_STEP_DEFAULTS,
	graceOptions,
	graceSettings,
	graceValue,
	otherPluginsNotice,
	roleChoices,
	sameRoles,
} from './twoStepModel';

const GRACE_KEYS = [ 'grace_type', 'grace_logins', 'grace_days' ];

/**
 * How to get a locked-out person back in. The command and the wp-config
 * line stay out of the translated text. The line sits on its own, so it
 * never breaks inside, and scrolls sideways when the screen is too narrow.
 *
 * @return {Element} The note.
 */
function RecoveryNote() {
	return (
		<div className="ha-login__recovery">
			<p className="ha-help">
				{ createInterpolateElement(
					__(
						'If someone is locked out, turn it off on their profile, run <cli />, or add this line to wp-config.php:',
						'happyaccess'
					),
					{
						cli: (
							<code>
								{ 'wp happyaccess twostep reset <user>' }
							</code>
						),
					}
				) }
			</p>
			{ /* It can scroll, so the keyboard needs to reach it. */ }
			<code className="ha-login__line" tabIndex={ 0 }>
				{ "define( 'HAPPYACCESS_DISABLE_TWOSTEP', true );" }
			</code>
		</div>
	);
}

/**
 * One row: the role name as the label, and its select.
 *
 * @param {Object}                  props          Props.
 * @param {Object}                  props.role     { slug, name }.
 * @param {string}                  props.value    The role's choice.
 * @param {(value: string) => void} props.onChange Called with the new choice.
 * @return {Element} The row.
 */
function RoleRow( { role, value, onChange } ) {
	const id = useId();

	return (
		<li className="ha-roles__row">
			<label className="ha-roles__name" htmlFor={ id }>
				{ role.name }
			</label>
			<SelectControl
				id={ id }
				className="ha-roles__control"
				value={ value }
				options={ roleChoices() }
				onChange={ onChange }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
		</li>
	);
}

/**
 * The two-step login part of the Login tab: the choice per role, the grace
 * period, the XML-RPC switch, the roles that get new device alerts and how
 * to recover a locked-out account.
 *
 * A role missing from the saved policy shows as "Optional", the server's
 * default. Nothing saves until "Save changes", which sends only what
 * changed inside the `two_step` group: the roles as a map of slug to
 * choice, the grace period as its type with its count, and the alert roles
 * as the whole list, which replaces the saved one.
 *
 * @param {Object} props      Props.
 * @param {Object} props.boot Boot data: loginRoles and otherTwoStep.
 * @return {Element|null} The section, or nothing before the settings load.
 */
export default function TwoStepSection( { boot } ) {
	const announce = useAnnounce();
	const { settings, save } = useSettings();
	const ids = useId();

	// Changed fields: the grace keys and `block_xmlrpc`.
	const [ edits, setEdits ] = useState( {} );
	// Changed roles, by slug.
	const [ roleEdits, setRoleEdits ] = useState( {} );
	// The edited alert roles, or null while they match the saved ones.
	const [ alertEdit, setAlertEdit ] = useState( null );
	const [ saveError, setSaveError ] = useState( null );
	const [ justSaved, setJustSaved ] = useState( false );
	// Its own, so a save in the passwordless form doesn't show here.
	const [ saving, setSaving ] = useState( false );

	if ( ! settings ) {
		return null;
	}

	const stored = settings.two_step || {};
	const savedPolicy = stored.role_policy || TWO_STEP_DEFAULTS.role_policy;
	const saved = ( key ) =>
		undefined === stored[ key ] ? TWO_STEP_DEFAULTS[ key ] : stored[ key ];
	const val = ( key ) => ( key in edits ? edits[ key ] : saved( key ) );
	const savedRole = ( slug ) => savedPolicy[ slug ] || OPTIONAL;
	const roleValue = ( slug ) =>
		slug in roleEdits ? roleEdits[ slug ] : savedRole( slug );
	const savedAlerts = Array.isArray( stored.device_alert_roles )
		? stored.device_alert_roles
		: TWO_STEP_DEFAULTS.device_alert_roles;
	const alertRoles = null === alertEdit ? savedAlerts : alertEdit;

	const savedGrace = () => {
		const type = saved( 'grace_type' );
		return graceValue(
			type,
			'days' === type ? saved( 'grace_days' ) : saved( 'grace_logins' )
		);
	};
	const graceNow = () => {
		const type = val( 'grace_type' );
		return graceValue(
			type,
			'days' === type ? val( 'grace_days' ) : val( 'grace_logins' )
		);
	};

	const setValue = ( key, value ) => {
		setJustSaved( false );
		setEdits( ( previous ) => {
			const next = { ...previous };
			if ( value === saved( key ) ) {
				delete next[ key ];
			} else {
				next[ key ] = value;
			}
			return next;
		} );
	};
	// The grace period is one choice that sets two keys, so it is sent as
	// a pair, or not at all when it matches the saved one.
	const setGrace = ( value ) => {
		setJustSaved( false );
		setEdits( ( previous ) => {
			const next = { ...previous };
			GRACE_KEYS.forEach( ( key ) => delete next[ key ] );
			return value === savedGrace()
				? next
				: { ...next, ...graceSettings( value ) };
		} );
	};
	const setRole = ( slug, value ) => {
		setJustSaved( false );
		setRoleEdits( ( previous ) => {
			const next = { ...previous };
			if ( value === savedRole( slug ) ) {
				delete next[ slug ];
			} else {
				next[ slug ] = value;
			}
			return next;
		} );
	};

	const setAlert = ( slug, checked ) => {
		setJustSaved( false );
		const rest = alertRoles.filter( ( item ) => item !== slug );
		const next = checked ? [ ...rest, slug ] : rest;
		setAlertEdit( sameRoles( next, savedAlerts ) ? null : next );
	};

	const dirty =
		Object.keys( edits ).length > 0 ||
		Object.keys( roleEdits ).length > 0 ||
		null !== alertEdit;

	const submit = async ( event ) => {
		event.preventDefault();
		if ( ! dirty || saving ) {
			return;
		}
		setSaveError( null );
		const group = { ...edits };
		if ( Object.keys( roleEdits ).length > 0 ) {
			group.role_policy = { ...roleEdits };
		}
		if ( null !== alertEdit ) {
			group.device_alert_roles = [ ...alertEdit ];
		}
		setSaving( true );
		try {
			await save( { two_step: group } );
			setEdits( {} );
			setRoleEdits( {} );
			setAlertEdit( null );
			setJustSaved( true );
			announce( __( 'Settings saved', 'happyaccess' ) );
		} catch ( e ) {
			setSaveError( e );
		} finally {
			setSaving( false );
		}
	};

	const roles = boot?.loginRoles || [];
	const others = Array.isArray( boot?.otherTwoStep ) ? boot.otherTwoStep : [];
	const anyRequired = roles.some(
		( role ) => REQUIRED === roleValue( role.slug )
	);

	return (
		<section
			className="ha-card"
			aria-labelledby={ `${ ids }-twostep-title` }
		>
			<form
				className="ha-card__body ha-settings__form"
				onSubmit={ submit }
				noValidate
			>
				<h2 id={ `${ ids }-twostep-title` }>
					{ __( 'Two-step login', 'happyaccess' ) }
				</h2>
				{ others.length > 0 && (
					<Notice status="info" isDismissible={ false }>
						{ otherPluginsNotice( others ) }
					</Notice>
				) }

				<div
					className="ha-login__group"
					role="group"
					aria-labelledby={ `${ ids }-twostep-roles` }
				>
					<h3
						id={ `${ ids }-twostep-roles` }
						className="ha-login__heading"
					>
						{ __(
							'How each role uses two-step login',
							'happyaccess'
						) }
					</h3>
					{ roles.length ? (
						<ul className="ha-roles">
							{ roles.map( ( role ) => (
								<RoleRow
									key={ role.slug }
									role={ role }
									value={ roleValue( role.slug ) }
									onChange={ ( value ) =>
										setRole( role.slug, value )
									}
								/>
							) ) }
						</ul>
					) : (
						<p className="ha-help">
							{ __( 'No roles found.', 'happyaccess' ) }
						</p>
					) }
					{ anyRequired && (
						<p className="ha-help ha-login__note">
							{ __(
								'People in these roles set up two-step login the next time they log in.',
								'happyaccess'
							) }
						</p>
					) }
				</div>

				<div className="ha-settings__grid">
					<SelectControl
						className="ha-field"
						label={ __(
							'Grace period for required roles',
							'happyaccess'
						) }
						value={ graceNow() }
						options={ graceOptions(
							saved( 'grace_type' ),
							Number( saved( 'grace_logins' ) ),
							Number( saved( 'grace_days' ) )
						) }
						onChange={ setGrace }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</div>

				<div className="ha-login__place ha-login__switch">
					<span id={ `${ ids }-xmlrpc` }>
						{ __(
							'Block XML-RPC login for accounts with two-step login',
							'happyaccess'
						) }
					</span>
					<Switch
						checked={ !! val( 'block_xmlrpc' ) }
						onChange={ ( next ) =>
							setValue( 'block_xmlrpc', next )
						}
						aria-labelledby={ `${ ids }-xmlrpc` }
					/>
				</div>

				<div
					className="ha-login__group"
					role="group"
					aria-labelledby={ `${ ids }-alerts` }
					aria-describedby={ `${ ids }-alerts-desc ${ ids }-alerts-help` }
				>
					<h3 id={ `${ ids }-alerts` } className="ha-login__heading">
						{ __( 'New device alerts', 'happyaccess' ) }
					</h3>
					<p id={ `${ ids }-alerts-desc` } className="ha-help">
						{ __(
							'Email a user when their account logs in from a new device',
							'happyaccess'
						) }
					</p>
					{ roles.length > 0 && (
						<div className="ha-settings__checks ha-login__alerts">
							{ roles.map( ( role ) => (
								<CheckboxControl
									key={ role.slug }
									label={ role.name }
									checked={ alertRoles.includes( role.slug ) }
									onChange={ ( checked ) =>
										setAlert( role.slug, checked )
									}
									__nextHasNoMarginBottom
								/>
							) ) }
						</div>
					) }
					<p id={ `${ ids }-alerts-help` } className="ha-help">
						{ __(
							'The email shows the time, browser and IP address.',
							'happyaccess'
						) }
					</p>
				</div>

				<RecoveryNote />

				{ saveError && (
					<Notice status="error" isDismissible={ false }>
						{ saveError.message }
					</Notice>
				) }

				<div className="ha-settings__actions">
					<Button
						type="submit"
						variant="primary"
						isBusy={ saving }
						accessibleWhenDisabled
						disabled={ ! dirty || saving }
					>
						{ __( 'Save changes', 'happyaccess' ) }
					</Button>
					{ dirty && (
						<span className="ha-settings__dirty">
							{ __( 'Unsaved changes', 'happyaccess' ) }
						</span>
					) }
					{ ! dirty && justSaved && (
						<span className="ha-settings__saved">
							{ __( 'Saved', 'happyaccess' ) }
						</span>
					) }
				</div>
			</form>
		</section>
	);
}
