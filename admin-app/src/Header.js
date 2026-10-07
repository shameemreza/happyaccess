import { __ } from '@wordpress/i18n';
import EmergencyLock from './support/EmergencyLock';

/**
 * Logo tile, title, help link and the Emergency lock button.
 *
 * @param {Object}                  props          Props.
 * @param {(count: number) => void} props.onLocked Called after Emergency lock ended the passes.
 * @return {Element} The header.
 */
export default function Header( { onLocked } ) {
	return (
		<header className="ha-header">
			<div className="ha-logo" aria-hidden="true">
				<svg
					width="26"
					height="26"
					viewBox="0 0 24 24"
					fill="none"
					stroke="currentColor"
					strokeWidth="2"
					strokeLinecap="round"
					strokeLinejoin="round"
					focusable="false"
				>
					<circle cx="8" cy="12" r="4" />
					<path d="M12 12h9M18 12v3M21 12v2" />
					<path d="M6.5 13.2c.8.7 2.2.7 3 0" />
				</svg>
			</div>
			<div className="ha-heading">
				<h1 className="ha-title">
					{ __( 'HappyAccess', 'happyaccess' ) }
				</h1>
				<p className="ha-subtitle">
					{ __(
						'Safe support logins, passwordless login and two-step login, in one place.',
						'happyaccess'
					) }
				</p>
			</div>
			<a
				className="ha-header__help"
				href="https://wordpress.org/plugins/happyaccess/"
			>
				{ __( 'Help and docs', 'happyaccess' ) }
			</a>
			<EmergencyLock onLocked={ onLocked } />
		</header>
	);
}
