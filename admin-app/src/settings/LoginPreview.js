import { __ } from '@wordpress/i18n';

/**
 * A small copy of the WordPress login screen. It shows the support code link
 * when Support access is on.
 *
 * @param {Object}  props               Props.
 * @param {boolean} props.supportAccess Whether Support access is on.
 * @return {Element} The aside.
 */
export default function LoginPreview( { supportAccess } ) {
	return (
		<aside className="ha-loginprev" aria-labelledby="ha-loginprev-title">
			<h2 id="ha-loginprev-title">
				{ __( 'Your login screen', 'happyaccess' ) }
			</h2>
			<p className="ha-loginprev__lead">
				{ __( 'Updates as you change features.', 'happyaccess' ) }
			</p>
			<p className="screen-reader-text">
				{ supportAccess
					? __(
							'Visitors see a link to enter a support access code.',
							'happyaccess'
						)
					: __(
							'Visitors see the usual login form only.',
							'happyaccess'
						) }
			</p>
			<div className="ha-loginprev__screen" aria-hidden="true">
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
				{ supportAccess && (
					<div className="ha-loginprev__code">
						{ __( 'Have a support access code?', 'happyaccess' ) }
					</div>
				) }
				<div className="ha-loginprev__lost">
					{ __( 'Lost your password?', 'happyaccess' ) }
				</div>
			</div>
		</aside>
	);
}
