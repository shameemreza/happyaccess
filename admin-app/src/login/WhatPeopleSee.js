import { useId } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useCoverage } from '../data/DataProvider';
import LoginMock from '../settings/LoginMock';

const DIGITS = [ 1, 2, 3, 4, 5, 6 ];

/**
 * One sentence that says what the drawing shows, for screen readers.
 *
 * @param {Object}  props         What the drawing shows.
 * @param {boolean} props.link    Whether the email code link shows.
 * @param {boolean} props.twoStep Whether the code step shows.
 * @param {boolean} props.email   Whether the code step asks for an email code.
 * @return {string} The sentence.
 */
export function previewSentence( { link, twoStep, email = false } ) {
	if ( twoStep && email ) {
		if ( link ) {
			return __(
				'The WordPress login form, with an "Email me a login code" link between the password and the Log in button, then a second step that asks for a code sent by email, with a link to use a backup code.',
				'happyaccess'
			);
		}
		return __(
			'The WordPress login form, then a second step that asks for a code sent by email, with a link to use a backup code.',
			'happyaccess'
		);
	}
	if ( twoStep ) {
		if ( link ) {
			return __(
				'The WordPress login form, with an "Email me a login code" link between the password and the Log in button, then a second step that asks for an authenticator app code, with a link to use a backup code.',
				'happyaccess'
			);
		}
		return __(
			'The WordPress login form, then a second step that asks for an authenticator app code, with a link to use a backup code.',
			'happyaccess'
		);
	}
	if ( link ) {
		return __(
			'The WordPress login form, with an "Email me a login code" link between the password and the Log in button.',
			'happyaccess'
		);
	}
	return __( 'The WordPress login form.', 'happyaccess' );
}

/**
 * Whether the code step should read "Email code": the counts are in, and
 * people here use email codes but nobody uses the app. Until the counts
 * load, and whenever anyone uses the app, it keeps the app label.
 *
 * @param {Object|null} coverage The coverage answer.
 * @return {boolean} Whether to draw the email code step.
 */
export function emailStep( coverage ) {
	const methods = coverage?.methods;
	if ( ! methods ) {
		return false;
	}
	return 0 === Number( methods.app ) && Number( methods.email ) > 0;
}

/**
 * A live drawing of the login screen with the Login and security settings
 * as they are in the form, saved or not: the email code link, which
 * wp-login.php prints between the password and the Log in button, and
 * the code step while two-step login is on, labeled for the method people
 * here use. The WordPress login page always prints a plain link, so the
 * button style never shows here.
 *
 * @param {Object}  props         Props.
 * @param {boolean} props.link    Whether the email code link shows.
 * @param {boolean} props.twoStep Whether two-step login is on.
 * @return {Element} The card.
 */
export default function WhatPeopleSee( { link, twoStep } ) {
	const id = useId();
	const { coverage } = useCoverage();
	const email = twoStep && emailStep( coverage );

	return (
		<section
			className="ha-loginprev ha-seeprev"
			aria-labelledby={ `${ id }-title` }
		>
			<h2 id={ `${ id }-title` }>
				{ __( 'What people see', 'happyaccess' ) }
			</h2>
			<p className="ha-loginprev__lead">
				{ __( 'Updates as you change these settings.', 'happyaccess' ) }
			</p>
			<p className="screen-reader-text">
				{ previewSentence( { link, twoStep, email } ) }
			</p>
			<div className="ha-loginprev__screen" aria-hidden="true">
				<LoginMock
					afterPassword={
						link ? (
							<div className="ha-seeprev__alt">
								{ __( 'Email me a login code', 'happyaccess' ) }
							</div>
						) : null
					}
				/>
				<div className="ha-loginprev__lost">
					{ __( 'Lost your password?', 'happyaccess' ) }
				</div>
				{ twoStep && (
					<div className="ha-loginprev__form ha-seeprev__step">
						<div className="ha-loginprev__label">
							{ email
								? __( 'Email code', 'happyaccess' )
								: __(
										'Authenticator app code',
										'happyaccess'
									) }
						</div>
						<div className="ha-seeprev__digits">
							{ DIGITS.map( ( digit ) => (
								<span key={ digit } />
							) ) }
						</div>
						<div className="ha-loginprev__submit">
							<span>{ __( 'Log in', 'happyaccess' ) }</span>
						</div>
						<div className="ha-seeprev__backup">
							{ __( 'Use a backup code', 'happyaccess' ) }
						</div>
					</div>
				) }
			</div>
		</section>
	);
}
