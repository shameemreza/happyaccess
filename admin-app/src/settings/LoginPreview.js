import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const BUBBLE_ID = 'ha-loginprev-bubble';

/**
 * A small copy of the WordPress login screen. When Support access is on, a
 * hand-drawn marker circles the link HappyAccess adds, and a bubble explains
 * it. Clicking the circled link opens or closes the bubble.
 *
 * @param {Object}  props               Props.
 * @param {boolean} props.supportAccess Whether Support access is on.
 * @return {Element} The aside.
 */
export default function LoginPreview( { supportAccess } ) {
	const [ open, setOpen ] = useState( true );

	const onKeyDown = ( event ) => {
		if ( 'Escape' === event.key ) {
			setOpen( false );
		}
	};

	return (
		<aside className="ha-loginprev" aria-labelledby="ha-loginprev-title">
			<h2 id="ha-loginprev-title">
				{ __( 'Your login screen', 'happyaccess' ) }
			</h2>
			<p className="ha-loginprev__lead">
				{ __( 'Updates as you change features.', 'happyaccess' ) }
			</p>
			<div className="ha-loginprev__screen">
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
				{ supportAccess && (
					<div className="ha-loginprev__code">
						<span className="ha-loginprev__spot">
							<button
								type="button"
								className="ha-loginprev__link"
								aria-expanded={ open }
								aria-controls={ BUBBLE_ID }
								onClick={ () =>
									setOpen( ( value ) => ! value )
								}
								onKeyDown={ onKeyDown }
							>
								{ __(
									'Have a support access code?',
									'happyaccess'
								) }
							</button>
							<svg
								className="ha-loginprev__loop"
								viewBox="0 0 240 64"
								preserveAspectRatio="none"
								aria-hidden="true"
								focusable="false"
							>
								<path
									pathLength="1"
									d="M30 50 C 8 44, 6 22, 34 14 C 80 2, 176 4, 214 14 C 238 22, 236 46, 206 54 C 160 64, 70 62, 28 48 C 16 44, 18 30, 44 22"
								/>
							</svg>
						</span>
						{ open && (
							<div
								id={ BUBBLE_ID }
								className="ha-loginprev__bubble"
								role="note"
							>
								<svg
									className="ha-loginprev__arrow"
									viewBox="0 0 64 44"
									aria-hidden="true"
									focusable="false"
								>
									<path
										pathLength="1"
										d="M62 8 C 44 0, 30 18, 40 24 C 50 30, 50 12, 34 14 C 20 16, 12 30, 6 38"
									/>
									<path
										className="ha-loginprev__arrowhead"
										pathLength="1"
										d="M15 37 L 6 38 L 8 29"
									/>
								</svg>
								<strong>
									{ __(
										'Added by HappyAccess',
										'happyaccess'
									) }
								</strong>
								<span>
									{ __(
										'Support people click here and enter their 8-digit code.',
										'happyaccess'
									) }
								</span>
							</div>
						) }
					</div>
				) }
				<div aria-hidden="true" className="ha-loginprev__lost">
					{ __( 'Lost your password?', 'happyaccess' ) }
				</div>
			</div>
		</aside>
	);
}
