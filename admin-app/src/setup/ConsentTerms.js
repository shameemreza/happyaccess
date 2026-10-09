import { __ } from '@wordpress/i18n';

/**
 * The heading of the consent terms, shared by setup and Settings.
 *
 * @return {string} The heading text.
 */
export const consentHeading = () =>
	__( 'Before you give anyone access', 'happyaccess' );

/**
 * The label of the consent checkbox, shared by setup and Settings.
 *
 * @return {string} The checkbox label.
 */
export const consentLabel = () =>
	__(
		"I understand, and I'll only give access to people I trust.",
		'happyaccess'
	);

/**
 * The three points a person agrees to before Temporary access goes on,
 * the same in first-run setup and in Settings.
 *
 * @return {Element} The list.
 */
export default function ConsentTerms() {
	return (
		<ul className="ha-terms">
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
	);
}
