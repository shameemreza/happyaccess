import { useId, useState } from '@wordpress/element';
import { Button, Notice, SelectControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useSettings } from '../data/DataProvider';
import { useAnnounce } from '../hooks/useAnnounce';
import LoadingLine from '../LoadingLine';
import Switch from '../settings/Switch';
import RolePolicyTable, { EITHER } from './RolePolicyTable';
import TwoStepCoverage from './TwoStepCoverage';
import TwoStepSection from './TwoStepSection';
import WhatPeopleSee from './WhatPeopleSee';

// The server defaults, for settings saved before the passwordless group existed.
const DEFAULTS = {
	code_lifetime: 600,
	toggle_style: 'link',
	show_on: { wp_login: true, woo_account: true, woo_checkout: true },
	role_policy: {},
};

const LIFETIMES = [ 300, 600, 900, 1800 ];

/**
 * @param {number} seconds Saved `passwordless.code_lifetime`.
 * @return {Array<{value: string, label: string}>} 5, 10, 15 and 30 minutes, plus the saved value when it is another.
 */
export function lifetimeOptions( seconds ) {
	const values = [ ...LIFETIMES ];
	if ( seconds > 0 && ! values.includes( seconds ) ) {
		values.push( seconds );
	}
	return values.map( ( value ) => {
		const minutes = Math.round( value / 60 );
		return {
			value: String( value ),
			label: sprintf(
				/* translators: %d: number of minutes. */
				_n( '%d minute', '%d minutes', minutes, 'happyaccess' ),
				minutes
			),
		};
	} );
}

/**
 * @return {Array<{value: string, label: string}>} The two `passwordless.toggle_style` values.
 */
function styleOptions() {
	return [
		{ value: 'link', label: __( 'Text link', 'happyaccess' ) },
		{ value: 'button', label: __( 'Full button', 'happyaccess' ) },
	];
}

/**
 * @param {boolean} woocommerce Whether WooCommerce is active.
 * @return {Array<{key: string, label: string}>} The places the email code option can show.
 */
function places( woocommerce ) {
	const list = [
		{ key: 'wp_login', label: __( 'WordPress login page', 'happyaccess' ) },
	];
	if ( woocommerce ) {
		list.push(
			{
				key: 'woo_account',
				label: __( 'WooCommerce My Account', 'happyaccess' ),
			},
			{
				key: 'woo_checkout',
				label: __( 'WooCommerce checkout', 'happyaccess' ),
			}
		);
	}
	return list;
}

/**
 * The Login and security tab: the passwordless login settings and the
 * two-step login settings, each while its feature is on, and beside them
 * a live drawing of what people see at login and, with two-step login on,
 * who has it. The tab itself shows only while one of the features is on.
 *
 * Nothing saves until "Save changes", which sends only the keys that
 * changed, inside the `passwordless` group. The two-step section has its
 * own form and saves the `two_step` group.
 *
 * @param {Object} props      Props.
 * @param {Object} props.boot Boot data: features, woocommerce, loginRoles, otherTwoFactor and otherTwoStep.
 * @return {Element} The tab.
 */
export default function LoginTab( { boot } ) {
	const announce = useAnnounce();
	const { settings, loading, saving, error, refresh, save } = useSettings();
	const ids = useId();

	// Changed fields: `code_lifetime`, `toggle_style` and `show_on.<place>`.
	const [ edits, setEdits ] = useState( {} );
	// Changed roles, by slug.
	const [ roleEdits, setRoleEdits ] = useState( {} );
	const [ saveError, setSaveError ] = useState( null );
	const [ justSaved, setJustSaved ] = useState( false );

	if ( loading && ! settings ) {
		return (
			<section className="ha-login" aria-busy="true">
				<LoadingLine loading className="ha-settings__loading">
					{ __( 'Loading settings', 'happyaccess' ) }
				</LoadingLine>
			</section>
		);
	}
	if ( ! settings ) {
		return (
			<section className="ha-login">
				<Notice status="error" isDismissible={ false }>
					{ error?.message ||
						__( 'Could not load the settings.', 'happyaccess' ) }
				</Notice>
				<Button variant="secondary" onClick={ refresh }>
					{ __( 'Try again', 'happyaccess' ) }
				</Button>
			</section>
		);
	}

	const stored = settings.passwordless || {};
	const savedPolicy = stored.role_policy || DEFAULTS.role_policy;
	const saved = ( key ) => {
		if ( key.startsWith( 'show_on.' ) ) {
			const place = key.slice( 8 );
			const value = stored.show_on?.[ place ];
			return undefined === value ? DEFAULTS.show_on[ place ] : value;
		}
		return undefined === stored[ key ] ? DEFAULTS[ key ] : stored[ key ];
	};
	const val = ( key ) => ( key in edits ? edits[ key ] : saved( key ) );
	const savedRole = ( slug ) => savedPolicy[ slug ] || EITHER;
	const policy = { ...savedPolicy, ...roleEdits };

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

	const dirty =
		Object.keys( edits ).length > 0 || Object.keys( roleEdits ).length > 0;

	const buildPatch = () => {
		const group = {};
		Object.entries( edits ).forEach( ( [ key, value ] ) => {
			if ( key.startsWith( 'show_on.' ) ) {
				group.show_on = { ...group.show_on, [ key.slice( 8 ) ]: value };
			} else {
				group[ key ] = value;
			}
		} );
		if ( Object.keys( roleEdits ).length > 0 ) {
			group.role_policy = { ...roleEdits };
		}
		return { passwordless: group };
	};

	const submit = async ( event ) => {
		event.preventDefault();
		if ( ! dirty || saving ) {
			return;
		}
		setSaveError( null );
		try {
			await save( buildPatch() );
			setEdits( {} );
			setRoleEdits( {} );
			setJustSaved( true );
			announce( __( 'Settings saved', 'happyaccess' ) );
		} catch ( e ) {
			setSaveError( e );
		}
	};

	const passwordlessOn = !! boot?.features?.passwordless;
	const twoStepOn = !! boot?.features?.two_step;
	const lifetime = Number( val( 'code_lifetime' ) );
	// The drawing follows the form, saved or not.
	const previewLink = passwordlessOn && !! val( 'show_on.wp_login' );

	const forms = (
		<div className="ha-columns__main ha-login">
			{ passwordlessOn && (
				<section
					className="ha-card"
					aria-labelledby={ `${ ids }-title` }
				>
					<form
						className="ha-card__body ha-settings__form"
						onSubmit={ submit }
						noValidate
					>
						<h2 id={ `${ ids }-title` }>
							{ __( 'Passwordless login', 'happyaccess' ) }
						</h2>
						<div className="ha-settings__grid">
							<SelectControl
								className="ha-field"
								label={ __( 'Code lifetime', 'happyaccess' ) }
								value={ String( lifetime ) }
								options={ lifetimeOptions(
									Number( saved( 'code_lifetime' ) )
								) }
								onChange={ ( value ) =>
									setValue( 'code_lifetime', Number( value ) )
								}
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
							<SelectControl
								className="ha-field"
								label={ __( 'Button style', 'happyaccess' ) }
								help={ __(
									"How the 'Email me a login code instead' option looks on WooCommerce forms, the shortcode and the block. The WordPress login page always shows a link.",
									'happyaccess'
								) }
								value={ val( 'toggle_style' ) }
								options={ styleOptions() }
								onChange={ ( value ) =>
									setValue( 'toggle_style', value )
								}
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						</div>

						<div
							className="ha-login__group"
							role="group"
							aria-labelledby={ `${ ids }-places` }
						>
							<h3
								id={ `${ ids }-places` }
								className="ha-login__heading"
							>
								{ __(
									'Show the email code option on',
									'happyaccess'
								) }
							</h3>
							<ul className="ha-login__places">
								{ places( !! boot?.woocommerce ).map(
									( place ) => {
										const key = `show_on.${ place.key }`;
										const labelId = `${ ids }-${ place.key }`;
										return (
											<li
												key={ place.key }
												className="ha-login__place"
											>
												<span id={ labelId }>
													{ place.label }
												</span>
												<Switch
													checked={ !! val( key ) }
													onChange={ ( next ) =>
														setValue( key, next )
													}
													aria-labelledby={ labelId }
												/>
											</li>
										);
									}
								) }
							</ul>
						</div>

						<div
							className="ha-login__group"
							role="group"
							aria-labelledby={ `${ ids }-roles` }
						>
							<h3
								id={ `${ ids }-roles` }
								className="ha-login__heading"
							>
								{ __( 'How each role logs in', 'happyaccess' ) }
							</h3>
							{ boot?.otherTwoFactor && (
								<Notice status="info" isDismissible={ false }>
									{ __(
										"Accounts that use two-step login from another plugin can't use email codes. They log in with their password and that plugin's check.",
										'happyaccess'
									) }
								</Notice>
							) }
							<RolePolicyTable
								roles={ boot?.loginRoles || [] }
								policy={ policy }
								onChange={ setRole }
							/>
						</div>

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
			) }
			{ twoStepOn && <TwoStepSection boot={ boot } /> }
		</div>
	);

	return (
		<div className="ha-columns">
			{ forms }
			<div className="ha-side">
				<WhatPeopleSee link={ previewLink } twoStep={ twoStepOn } />
				{ twoStepOn && (
					<TwoStepCoverage
						others={
							Array.isArray( boot?.otherTwoStep )
								? boot.otherTwoStep
								: []
						}
					/>
				) }
			</div>
		</div>
	);
}
