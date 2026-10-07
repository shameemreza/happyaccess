import { useEffect, useId, useRef, useState } from '@wordpress/element';
import { Button, CheckboxControl, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { runSetup } from '../api';

const noop = () => {};

/**
 * The three first-run steps: features, consent, done.
 *
 * Nothing is saved until "Finish setup". The screen stays up on the last
 * step, and the parent switches to the tabs when "Create your first support
 * pass" is pressed.
 *
 * @param {Object}                     props          Props.
 * @param {(features: Object) => void} props.onFinish Called with the saved feature switches when the last button is pressed.
 * @return {Element} The setup screen.
 */
export default function Setup( { onFinish = noop } ) {
	const ids = useId();
	const [ step, setStep ] = useState( 1 );
	const [ supportOn, setSupportOn ] = useState( true );
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

	const steps = [
		__( 'Features', 'happyaccess' ),
		__( 'Consent', 'happyaccess' ),
		__( 'Done', 'happyaccess' ),
	];

	const finish = async () => {
		setBusy( true );
		setError( null );
		try {
			const result = await runSetup( {
				features: { support_access: supportOn },
				consent: true,
			} );
			setFeatures( result?.features || { support_access: supportOn } );
			setStep( 3 );
		} catch ( e ) {
			setError( e );
		} finally {
			setBusy( false );
		}
	};

	return (
		<section className="ha-setup" aria-labelledby={ `${ ids }-title` }>
			<div className="ha-setup__intro">
				<h2 id={ `${ ids }-title` }>
					{ __( 'Set up HappyAccess', 'happyaccess' ) }
				</h2>
				<p>
					{ __(
						'Two quick choices. You can change both later in Settings.',
						'happyaccess'
					) }
				</p>
			</div>

			<ol
				className="ha-steps"
				aria-label={ __( 'Setup steps', 'happyaccess' ) }
			>
				{ steps.map( ( label, index ) => {
					const number = index + 1;
					return (
						<li
							key={ number }
							className={ number <= step ? 'is-reached' : '' }
							aria-current={
								number === step ? 'step' : undefined
							}
						>
							<span className="ha-steps__bar" />
							<span className="ha-steps__label">
								{ `${ number }. ${ label }` }
							</span>
						</li>
					);
				} ) }
			</ol>

			<div className="ha-setup__card">
				{ 1 === step && (
					<>
						<h3 ref={ headingRef } tabIndex={ -1 }>
							{ __( 'What do you want to use?', 'happyaccess' ) }
						</h3>
						<p className="ha-setup__lead">
							{ __(
								'Pick any. Features you leave off add nothing to your site.',
								'happyaccess'
							) }
						</p>
						<div
							className={
								supportOn
									? 'ha-setup__choice is-on'
									: 'ha-setup__choice'
							}
						>
							<CheckboxControl
								label={ __( 'Support access', 'happyaccess' ) }
								help={ __(
									'Login links and codes for support people, that end by themselves.',
									'happyaccess'
								) }
								checked={ supportOn }
								onChange={ setSupportOn }
								__nextHasNoMarginBottom
							/>
						</div>
					</>
				) }

				{ 2 === step && (
					<>
						<h3 ref={ headingRef } tabIndex={ -1 }>
							{ __(
								'Before you give anyone access',
								'happyaccess'
							) }
						</h3>
						<p className="ha-setup__lead">
							{ __(
								'Read this once. It applies to every support pass you create.',
								'happyaccess'
							) }
						</p>
						<ul className="ha-setup__terms">
							<li>
								{ __(
									"A support pass lets someone outside your team into your site's admin, for as long as you choose.",
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
						{ error && (
							<Notice status="error" isDismissible={ false }>
								{ error.message }
							</Notice>
						) }
					</>
				) }

				{ 3 === step && (
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
							{ __(
								'Next time support asks for access, create a pass here instead of a new user and a shared password.',
								'happyaccess'
							) }
						</p>
						<Button
							variant="primary"
							className="ha-setup__go"
							onClick={ () => onFinish( features ) }
						>
							{ __(
								'Create your first support pass',
								'happyaccess'
							) }
						</Button>
					</div>
				) }

				{ step < 3 && (
					<div className="ha-setup__nav">
						{ 2 === step && (
							<Button
								variant="secondary"
								accessibleWhenDisabled
								disabled={ busy }
								onClick={ () => setStep( 1 ) }
							>
								{ __( 'Back', 'happyaccess' ) }
							</Button>
						) }
						<span className="ha-setup__spacer" />
						{ 1 === step ? (
							<Button
								variant="primary"
								disabled={ ! supportOn }
								onClick={ () => setStep( 2 ) }
							>
								{ __( 'Continue', 'happyaccess' ) }
							</Button>
						) : (
							<Button
								variant="primary"
								isBusy={ busy }
								accessibleWhenDisabled
								disabled={ ! agreed || busy }
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
