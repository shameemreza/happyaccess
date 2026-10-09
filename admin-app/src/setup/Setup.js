import { useEffect, useId, useRef, useState } from '@wordpress/element';
import { Button, CheckboxControl, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { runSetup } from '../api';

const noop = () => {};

/**
 * The first-run steps: features, consent, done. Consent only shows when
 * Temporary access is picked, since it is the only feature that lets
 * someone else in.
 *
 * Nothing is saved until "Finish setup". The screen stays up on the last
 * step, and the parent switches to the tabs when one of its buttons is
 * pressed.
 *
 * @param {Object}                                  props                 Props.
 * @param {(features: Object, tab: string) => void} props.onFinish        Called with the saved feature switches and the tab to open.
 * @param {string}                                  props.twoStepNetwork  'main' when the main site decides two-step login for this site.
 * @param {string}                                  props.twoStepSetupUrl The two-step section of the user's own profile.
 * @return {Element} The setup screen.
 */
export default function Setup( {
	onFinish = noop,
	twoStepNetwork = '',
	twoStepSetupUrl = '',
} ) {
	const ids = useId();
	const twoStepShown = 'main' !== twoStepNetwork;
	const [ step, setStep ] = useState( 'features' );
	const [ chosen, setChosen ] = useState( {
		support_access: true,
		passwordless: false,
		two_step: false,
	} );
	const [ agreed, setAgreed ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ features, setFeatures ] = useState( null );
	const headingRef = useRef( null );
	const moved = useRef( false );

	// A new step puts focus on its heading, so a screen reader starts there.
	useEffect( () => {
		if ( moved.current ) {
			headingRef.current?.focus();
		}
		moved.current = true;
	}, [ step ] );

	const supportOn = chosen.support_access;
	const passwordlessOn = chosen.passwordless;
	const twoStepOn = twoStepShown && chosen.two_step;
	const anyOn = supportOn || passwordlessOn || twoStepOn;

	const steps = [
		{ key: 'features', label: __( 'Features', 'happyaccess' ) },
		...( supportOn
			? [ { key: 'consent', label: __( 'Consent', 'happyaccess' ) } ]
			: [] ),
		{ key: 'done', label: __( 'Done', 'happyaccess' ) },
	];
	const stepIndex = steps.findIndex( ( item ) => item.key === step );

	const pick = ( key ) => ( checked ) =>
		setChosen( ( previous ) => ( { ...previous, [ key ]: checked } ) );

	const finish = async () => {
		// The main site owns two-step login here, and the server refuses it.
		const sent = {
			support_access: supportOn,
			passwordless: passwordlessOn,
			...( twoStepShown ? { two_step: twoStepOn } : {} ),
		};
		setBusy( true );
		setError( null );
		try {
			const result = await runSetup( {
				features: sent,
				consent: supportOn,
			} );
			setFeatures( result?.features || sent );
			setStep( 'done' );
		} catch ( e ) {
			setError( e );
		} finally {
			setBusy( false );
		}
	};

	const choices = [
		{
			key: 'support_access',
			label: __( 'Temporary access', 'happyaccess' ),
			help: __(
				'Give support a login link or code that ends by itself. No shared passwords.',
				'happyaccess'
			),
		},
		{
			key: 'passwordless',
			label: __( 'Passwordless login', 'happyaccess' ),
			help: __(
				'Let people log in with a code or link sent to their email.',
				'happyaccess'
			),
		},
		...( twoStepShown
			? [
					{
						key: 'two_step',
						label: __( 'Two-step login', 'happyaccess' ),
						help: __(
							'Ask for a second step after the password, like a code from an authenticator app.',
							'happyaccess'
						),
					},
				]
			: [] ),
	];

	// The Done step's buttons, one per feature turned on. The first is primary.
	const actions = [];
	if ( supportOn ) {
		actions.push( {
			key: 'support',
			label: __( 'Give temporary access', 'happyaccess' ),
			onClick: () => onFinish( features, 'support' ),
		} );
	}
	if ( passwordlessOn ) {
		actions.push( {
			key: 'login',
			label: __( 'Choose how each role logs in', 'happyaccess' ),
			onClick: () => onFinish( features, 'login' ),
		} );
	}
	if ( twoStepOn && twoStepSetupUrl ) {
		actions.push( {
			key: 'two-step',
			label: __(
				'Set up two-step login for your account',
				'happyaccess'
			),
			href: twoStepSetupUrl,
		} );
	}

	const errorNotice = error && (
		<Notice status="error" isDismissible={ false }>
			{ error.message }
		</Notice>
	);

	return (
		<section className="ha-setup" aria-labelledby={ `${ ids }-title` }>
			<div className="ha-setup__intro">
				<h2 id={ `${ ids }-title` }>
					{ __( 'Set up HappyAccess', 'happyaccess' ) }
				</h2>
				<p>
					{ __(
						'A few quick choices. You can change them later in Settings.',
						'happyaccess'
					) }
				</p>
			</div>

			<ol
				className="ha-steps"
				aria-label={ __( 'Setup steps', 'happyaccess' ) }
			>
				{ steps.map( ( item, index ) => (
					<li
						key={ item.key }
						className={ index <= stepIndex ? 'is-reached' : '' }
						aria-current={ item.key === step ? 'step' : undefined }
					>
						<span className="ha-steps__bar" />
						<span className="ha-steps__label">
							{ `${ index + 1 }. ${ item.label }` }
						</span>
					</li>
				) ) }
			</ol>

			<div className="ha-setup__card">
				{ 'features' === step && (
					<>
						<h3
							id={ `${ ids }-features` }
							ref={ headingRef }
							tabIndex={ -1 }
						>
							{ __( 'What do you want to use?', 'happyaccess' ) }
						</h3>
						<p className="ha-setup__lead">
							{ __(
								'Pick any. Features you leave off add nothing to your site.',
								'happyaccess'
							) }
						</p>
						<div
							className="ha-setup__choices"
							role="group"
							aria-labelledby={ `${ ids }-features` }
						>
							{ choices.map( ( choice ) => (
								<div
									key={ choice.key }
									className={
										chosen[ choice.key ]
											? 'ha-setup__choice is-on'
											: 'ha-setup__choice'
									}
								>
									<CheckboxControl
										label={ choice.label }
										help={ choice.help }
										checked={ chosen[ choice.key ] }
										onChange={ pick( choice.key ) }
										__nextHasNoMarginBottom
									/>
								</div>
							) ) }
						</div>
						{ errorNotice }
					</>
				) }

				{ 'consent' === step && (
					<>
						<h3 ref={ headingRef } tabIndex={ -1 }>
							{ __(
								'Before you give anyone access',
								'happyaccess'
							) }
						</h3>
						<p className="ha-setup__lead">
							{ __(
								'Read this once. It applies to everyone you give temporary access.',
								'happyaccess'
							) }
						</p>
						<ul className="ha-setup__terms">
							<li>
								{ __(
									"Temporary access lets someone outside your team into your site's admin, for as long as you choose.",
									'happyaccess'
								) }
							</li>
							<li>
								{ __(
									'Protected admin blocks the riskiest actions and logs what they do, but it is not a sandbox. Only give access to people you trust.',
									'happyaccess'
								) }
							</li>
							<li>
								{ __(
									'Their login, IP address and actions are recorded here so you can review them. You are responsible for telling your visitors if your privacy policy needs it.',
									'happyaccess'
								) }
							</li>
						</ul>
						<div className="ha-setup__choice">
							<CheckboxControl
								label={ __(
									"I understand, and I'll only give access to people I trust.",
									'happyaccess'
								) }
								checked={ agreed }
								onChange={ setAgreed }
								__nextHasNoMarginBottom
							/>
						</div>
						{ errorNotice }
					</>
				) }

				{ 'done' === step && (
					<div className="ha-setup__done">
						<span className="ha-setup__tick" aria-hidden="true">
							<svg
								width="26"
								height="26"
								viewBox="0 0 24 24"
								fill="none"
								stroke="currentColor"
								strokeWidth="2.4"
								strokeLinecap="round"
								strokeLinejoin="round"
								focusable="false"
							>
								<path d="M5 12.5l4.5 4.5L19 7.5" />
							</svg>
						</span>
						<h3 ref={ headingRef } tabIndex={ -1 }>
							{ __( "You're all set", 'happyaccess' ) }
						</h3>
						<p className="ha-setup__lead">
							{ supportOn
								? __(
										'Next time someone needs into your admin, send them a link or code instead of a password.',
										'happyaccess'
									)
								: __(
										"Your login options are on. Here's where to set them up.",
										'happyaccess'
									) }
						</p>
						{ actions.length > 0 && (
							<div className="ha-setup__actions">
								{ actions.map( ( action, index ) => (
									<Button
										key={ action.key }
										variant={
											0 === index
												? 'primary'
												: 'secondary'
										}
										className="ha-setup__go"
										href={ action.href }
										onClick={ action.onClick }
									>
										{ action.label }
									</Button>
								) ) }
							</div>
						) }
					</div>
				) }

				{ 'done' !== step && (
					<div className="ha-setup__nav">
						{ 'consent' === step && (
							<Button
								variant="secondary"
								accessibleWhenDisabled
								disabled={ busy }
								onClick={ () => setStep( 'features' ) }
							>
								{ __( 'Back', 'happyaccess' ) }
							</Button>
						) }
						<span className="ha-setup__spacer" />
						{ /* With nothing picked there is nothing to finish, so the button says Continue. */ }
						{ 'features' === step && ( supportOn || ! anyOn ) && (
							<Button
								variant="primary"
								accessibleWhenDisabled
								disabled={ ! anyOn }
								onClick={ () => setStep( 'consent' ) }
							>
								{ __( 'Continue', 'happyaccess' ) }
							</Button>
						) }
						{ ( 'consent' === step ||
							( 'features' === step &&
								! supportOn &&
								anyOn ) ) && (
							<Button
								variant="primary"
								isBusy={ busy }
								accessibleWhenDisabled
								disabled={
									busy || ( 'consent' === step && ! agreed )
								}
								onClick={ finish }
							>
								{ __( 'Finish setup', 'happyaccess' ) }
							</Button>
						) }
					</div>
				) }
			</div>
		</section>
	);
}
