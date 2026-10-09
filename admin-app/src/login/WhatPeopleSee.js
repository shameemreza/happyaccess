import { useId } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import LoginMock from '../settings/LoginMock';

const DIGITS = [ 1, 2, 3, 4, 5, 6 ];

/**
 * One sentence that says what the drawing shows, for screen readers.
 *
 * @param {Object}  props         What the drawing shows.
 * @param {boolean} props.link    Whether the email code link shows.
 * @param {boolean} props.twoStep Whether the code step shows.
 * @return {string} The sentence.
 */
export function previewSentence( { link, twoStep } ) {
	if ( twoStep ) {
		if ( link ) {
			return __(
				'The WordPress login form, with an "Email me a login code" link under it, then a second step that asks for an authenticator app code, with a link to use a backup code.',
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
			'The WordPress login form, with an "Email me a login code" link under it.',
			'happyaccess'
		);
	}
	return __( 'The WordPress login form.', 'happyaccess' );
}

/**
 * A live drawing of the login screen with the Login and security settings
 * as they are in the form, saved or not: the email code link under the
 * form, and the code step while two-step login is on. The WordPress login
 * page always prints a plain link, so the button style never shows here.
 *
 * @param {Object}  props         Props.
 * @param {boolean} props.link    Whether the email code link shows.
 * @param {boolean} props.twoStep Whether two-step login is on.
 * @return {Element} The card.
 */
export default function WhatPeopleSee( { link, twoStep } ) {
	const id = useId();

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
				{ previewSentence( { link, twoStep } ) }
			</p>
			<div className="ha-loginprev__screen" aria-hidden="true">
				<LoginMock />
				{ link && (
					<div className="ha-seeprev__alt">
						{ __( 'Email me a login code', 'happyaccess' ) }
					</div>
				) }
				<div className="ha-loginprev__lost">
					{ __( 'Lost your password?', 'happyaccess' ) }
				</div>
				{ twoStep && (
					<div className="ha-loginprev__form ha-seeprev__step">
						<div className="ha-loginprev__label">
							{ __( 'Authenticator app code', 'happyaccess' ) }
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
