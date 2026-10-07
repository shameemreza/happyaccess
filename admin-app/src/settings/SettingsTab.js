import { useId, useRef, useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useAnnounce } from '../hooks/useAnnounce';
import { useSettings } from '../hooks/useSettings';
import { InlineConfirm } from '../support/GrantRow';
import LoginPreview from './LoginPreview';
import {
	buildPatch,
	durationOptions,
	getPath,
	getProxyOptions,
	lockoutOptions,
	pairValue,
	retentionOptions,
} from './settingsModel';
import Switch from './Switch';

const noop = () => {};

const FALLBACKS = {
	'features.support_access': true,
	'security.max_attempts': 5,
	'security.lockout_duration': 1800,
	'security.proxy_header': '',
	'security.recaptcha_enabled': false,
	'security.recaptcha_site_key': '',
	'privacy.logging': true,
	'privacy.retention_days': 30,
	'privacy.anonymize_ip': false,
	'privacy.delete_on_uninstall': false,
	'support.default_duration': 259200,
};

function endedText( count ) {
	return sprintf(
		/* translators: %d: number of passes that were ended. */
		_n(
			'Support access turned off. %d pass ended.',
			'Support access turned off. %d passes ended.',
			count,
			'happyaccess'
		),
		count
	);
}

/**
 * The Settings tab: the feature switch, safety and privacy, and a live
 * login screen preview.
 *
 * Support access saves the moment it is switched, since turning it off ends
 * every pass. Everything else waits for "Save changes", which sends only
 * the keys that changed.
 *
 * @param {Object}                     props                  Props.
 * @param {(features: Object) => void} props.onFeaturesChange Called with the saved feature switches.
 * @return {Element} The tab.
 */
export default function SettingsTab( { onFeaturesChange = noop } ) {
	const announce = useAnnounce();
	const { settings, loading, saving, error, refresh, save } = useSettings();
	const ids = useId();

	// Changed fields by dotted path. A field set back to its saved value drops out.
	const [ edits, setEdits ] = useState( {} );
	const [ secretInput, setSecretInput ] = useState( '' );
	const [ confirmOff, setConfirmOff ] = useState( false );
	const [ featureError, setFeatureError ] = useState( null );
	const [ saveError, setSaveError ] = useState( null );
	const [ justSaved, setJustSaved ] = useState( false );
	const switchRef = useRef( null );
	const secretRef = useRef( null );

	if ( loading && ! settings ) {
		return (
			<section className="ha-settings" aria-busy="true">
				<p className="ha-settings__loading">
					{ __( 'Loading settings', 'happyaccess' ) }
				</p>
			</section>
		);
	}
	if ( ! settings ) {
		return (
			<section className="ha-settings">
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

	const saved = ( path ) => {
		const value = getPath( settings, path );
		return undefined === value ? FALLBACKS[ path ] : value;
	};
	const val = ( path ) => ( path in edits ? edits[ path ] : saved( path ) );

	const setValues = ( changes ) => {
		setJustSaved( false );
		setEdits( ( previous ) => {
			const next = { ...previous };
			Object.entries( changes ).forEach( ( [ path, value ] ) => {
				if ( value === saved( path ) ) {
					delete next[ path ];
				} else {
					next[ path ] = value;
				}
			} );
			return next;
		} );
	};
	const setValue = ( path, value ) => setValues( { [ path ]: value } );

	const supportOn = !! saved( 'features.support_access' );
	const patch = buildPatch( edits );
	const recaptchaOn = !! val( 'security.recaptcha_enabled' );
	// A typed secret only counts while reCAPTCHA is on.
	const secretToSend = recaptchaOn ? secretInput : '';
	const dirty = Object.keys( edits ).length > 0 || '' !== secretToSend;
	const secretSet = !! settings.recaptcha_secret_set;

	const tries = Number( val( 'security.max_attempts' ) );
	const pause = Number( val( 'security.lockout_duration' ) );

	const finish = ( result ) => {
		if ( result?.features ) {
			onFeaturesChange( result.features );
		}
	};

	const switchSupport = async ( next ) => {
		setFeatureError( null );
		setConfirmOff( false );
		try {
			const result = await save( {
				features: { support_access: next },
			} );
			finish( result );
			if ( next ) {
				announce( __( 'Support access turned on', 'happyaccess' ) );
			} else {
				const count = Number( result?.revoked ) || 0;
				announce(
					count > 0
						? endedText( count )
						: __( 'Support access turned off', 'happyaccess' )
				);
			}
		} catch ( e ) {
			setFeatureError( e );
		}
		switchRef.current?.focus();
	};

	const onSwitch = ( next ) => {
		if ( saving ) {
			return;
		}
		if ( next ) {
			switchSupport( true );
		} else {
			setFeatureError( null );
			setConfirmOff( true );
		}
	};

	const removeSecret = async () => {
		setSaveError( null );
		try {
			const result = await save( { recaptcha_secret_key: '' } );
			finish( result );
			setSecretInput( '' );
			announce( __( 'reCAPTCHA secret key removed', 'happyaccess' ) );
		} catch ( e ) {
			setSaveError( e );
			return;
		}
		secretRef.current?.focus();
	};

	const submit = async ( event ) => {
		event.preventDefault();
		if ( ! dirty || saving ) {
			return;
		}
		setSaveError( null );
		const body = { ...patch };
		if ( '' !== secretToSend ) {
			body.recaptcha_secret_key = secretToSend;
		}
		try {
			const result = await save( body );
			finish( result );
			setEdits( {} );
			setSecretInput( '' );
			setJustSaved( true );
			announce( __( 'Settings saved', 'happyaccess' ) );
		} catch ( e ) {
			setSaveError( e );
		}
	};

	const featureName = __( 'Support access', 'happyaccess' );

	return (
		<div className="ha-settings">
			<div className="ha-settings__main">
				<section
					className="ha-card"
					aria-labelledby={ `${ ids }-features` }
				>
					<div className="ha-card__head">
						<h2 id={ `${ ids }-features` }>
							{ __(
								'What HappyAccess does on this site',
								'happyaccess'
							) }
						</h2>
						<p>
							{ __(
								'Turn on only what you need. Anything off adds no code to your pages.',
								'happyaccess'
							) }
						</p>
					</div>
					<ul className="ha-features">
						<li className="ha-feature">
							<span
								className="ha-feature__icon"
								aria-hidden="true"
							>
								<svg
									width="18"
									height="18"
									viewBox="0 0 24 24"
									fill="none"
									stroke="currentColor"
									strokeWidth="2"
									strokeLinecap="round"
									strokeLinejoin="round"
									focusable="false"
								>
									<path d="M8 12a4 4 0 1 0 0 .01M12 12h9M18 12v3" />
								</svg>
							</span>
							<div className="ha-feature__text">
								<div
									className="ha-feature__name"
									id={ `${ ids }-support-name` }
								>
									{ featureName }
								</div>
								<div id={ `${ ids }-support-desc` }>
									{ __(
										'Give a support person a login link or code that ends by itself. No shared passwords, no accounts left behind.',
										'happyaccess'
									) }
								</div>
							</div>
							<Switch
								ref={ switchRef }
								checked={ supportOn }
								onChange={ onSwitch }
								aria-labelledby={ `${ ids }-support-name` }
								aria-describedby={ `${ ids }-support-desc` }
							/>
						</li>
					</ul>
					{ confirmOff && (
						<div className="ha-card__confirm">
							<InlineConfirm
								keepLabel={ __( 'Keep access', 'happyaccess' ) }
								doLabel={ __( 'Turn off', 'happyaccess' ) }
								busy={ saving }
								onKeep={ () => {
									setConfirmOff( false );
									switchRef.current?.focus();
								} }
								onConfirm={ () => switchSupport( false ) }
							>
								{ __(
									'Turn off support access? Every current pass ends now.',
									'happyaccess'
								) }
							</InlineConfirm>
						</div>
					) }
					{ featureError && (
						<div className="ha-card__confirm">
							<Notice status="error" isDismissible={ false }>
								{ featureError.message }
							</Notice>
						</div>
					) }
				</section>

				<section
					className="ha-card"
					aria-labelledby={ `${ ids }-safety` }
				>
					<form
						className="ha-card__body ha-settings__form"
						onSubmit={ submit }
						noValidate
					>
						<h2 id={ `${ ids }-safety` }>
							{ __( 'Safety and privacy', 'happyaccess' ) }
						</h2>
						<div className="ha-settings__grid">
							<SelectControl
								className="ha-field"
								label={ __(
									'Wrong codes before a pause',
									'happyaccess'
								) }
								value={ pairValue( tries, pause ) }
								options={ lockoutOptions(
									Number( saved( 'security.max_attempts' ) ),
									Number(
										saved( 'security.lockout_duration' )
									)
								) }
								onChange={ ( value ) => {
									const [ nextTries, nextPause ] = value
										.split( ':' )
										.map( Number );
									setValues( {
										'security.max_attempts': nextTries,
										'security.lockout_duration': nextPause,
									} );
								} }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
							<SelectControl
								className="ha-field"
								label={ __(
									'Keep activity for',
									'happyaccess'
								) }
								value={ String(
									val( 'privacy.retention_days' )
								) }
								options={ retentionOptions(
									Number( saved( 'privacy.retention_days' ) )
								) }
								onChange={ ( value ) =>
									setValue(
										'privacy.retention_days',
										Number( value )
									)
								}
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
							<SelectControl
								className="ha-field"
								label={ __(
									'Visitor IP comes from',
									'happyaccess'
								) }
								help={ __(
									'Pick Cloudflare only if your site is behind Cloudflare. Your server must accept traffic only from Cloudflare.',
									'happyaccess'
								) }
								value={ val( 'security.proxy_header' ) }
								options={ getProxyOptions() }
								onChange={ ( value ) =>
									setValue( 'security.proxy_header', value )
								}
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
							<SelectControl
								className="ha-field"
								label={ __(
									'Default pass length',
									'happyaccess'
								) }
								value={ String(
									val( 'support.default_duration' )
								) }
								options={ durationOptions(
									Number(
										saved( 'support.default_duration' )
									)
								) }
								onChange={ ( value ) =>
									setValue(
										'support.default_duration',
										Number( value )
									)
								}
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						</div>

						<div className="ha-settings__checks">
							<CheckboxControl
								label={ __(
									'Shorten IP addresses in the log',
									'happyaccess'
								) }
								checked={ !! val( 'privacy.anonymize_ip' ) }
								onChange={ ( value ) =>
									setValue( 'privacy.anonymize_ip', value )
								}
								__nextHasNoMarginBottom
							/>
							<CheckboxControl
								label={ __( 'Keep a log', 'happyaccess' ) }
								help={ __(
									'Turning this off also stops the support activity record.',
									'happyaccess'
								) }
								checked={ !! val( 'privacy.logging' ) }
								onChange={ ( value ) =>
									setValue( 'privacy.logging', value )
								}
								__nextHasNoMarginBottom
							/>
							<CheckboxControl
								label={ __(
									'Delete all HappyAccess data when the plugin is deleted',
									'happyaccess'
								) }
								checked={
									!! val( 'privacy.delete_on_uninstall' )
								}
								onChange={ ( value ) =>
									setValue(
										'privacy.delete_on_uninstall',
										value
									)
								}
								__nextHasNoMarginBottom
							/>
						</div>

						<div className="ha-recaptcha">
							<div className="ha-recaptcha__row">
								<span
									className="ha-recaptcha__name"
									id={ `${ ids }-recaptcha` }
								>
									{ __( 'reCAPTCHA', 'happyaccess' ) }
								</span>
								<Switch
									checked={ recaptchaOn }
									onChange={ ( value ) =>
										setValue(
											'security.recaptcha_enabled',
											value
										)
									}
									aria-labelledby={ `${ ids }-recaptcha` }
								/>
							</div>
							<p className="ha-help">
								{ __(
									'Adds a Google reCAPTCHA check to the support code screen.',
									'happyaccess'
								) }
							</p>
							{ recaptchaOn && (
								<div className="ha-settings__grid">
									<TextControl
										className="ha-field"
										label={ __(
											'Site key',
											'happyaccess'
										) }
										value={ String(
											val( 'security.recaptcha_site_key' )
										) }
										onChange={ ( value ) =>
											setValue(
												'security.recaptcha_site_key',
												value
											)
										}
										autoComplete="off"
										__nextHasNoMarginBottom
										__next40pxDefaultSize
									/>
									<div className="ha-recaptcha__secret">
										<TextControl
											ref={ secretRef }
											className="ha-field"
											type="password"
											label={ __(
												'Secret key',
												'happyaccess'
											) }
											help={
												secretSet
													? __(
															'Type a new key to replace the saved one.',
															'happyaccess'
														)
													: undefined
											}
											value={ secretInput }
											onChange={ ( value ) => {
												setJustSaved( false );
												setSecretInput( value );
											} }
											autoComplete="new-password"
											__nextHasNoMarginBottom
											__next40pxDefaultSize
										/>
										{ secretSet && (
											<div className="ha-recaptcha__saved">
												<span>
													{ __(
														'A secret key is saved',
														'happyaccess'
													) }
												</span>
												<Button
													variant="tertiary"
													isDestructive
													size="compact"
													accessibleWhenDisabled
													disabled={ saving }
													onClick={ removeSecret }
												>
													{ __(
														'Remove',
														'happyaccess'
													) }
												</Button>
											</div>
										) }
									</div>
								</div>
							) }
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
			</div>
			<LoginPreview supportAccess={ supportOn } />
		</div>
	);
}
