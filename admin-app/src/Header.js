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
					width="36"
					height="36"
					viewBox="0 0 64 64"
					fill="none"
					stroke="currentColor"
					strokeWidth="4.2"
					strokeLinecap="round"
					strokeLinejoin="round"
					focusable="false"
				>
					<path d="M32 8 L52 16 V30 C52 44 43 53 32 57 C21 53 12 44 12 30 V16 Z" />
					<path d="M22 34 Q32 44 42 34" />
					<path d="M25 26 V26.2 M39 26 V26.2" />
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
