import { __ } from '@wordpress/i18n';

/**
 * The drawing of a WordPress login form: the logo, the two fields and the
 * button. It is decorative, so it is hidden from screen readers.
 *
 * @return {Element} The drawing.
 */
export default function LoginMock() {
	return (
		<div aria-hidden="true" className="ha-loginprev__mock">
			<svg
				width="56"
				height="56"
				viewBox="0 0 24 24"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.2"
				focusable="false"
			>
				<circle cx="12" cy="12" r="9" />
				<path d="M5 9l3.5 9L12 9l3.5 9L19 9" />
			</svg>
			<div className="ha-loginprev__form">
				<div className="ha-loginprev__label">
					{ __( 'Username or email address', 'happyaccess' ) }
				</div>
				<div className="ha-loginprev__input" />
				<div className="ha-loginprev__label">
					{ __( 'Password', 'happyaccess' ) }
				</div>
				<div className="ha-loginprev__input" />
				<div className="ha-loginprev__submit">
					<span>{ __( 'Log in', 'happyaccess' ) }</span>
				</div>
			</div>
		</div>
	);
}
