import { __ } from '@wordpress/i18n';

/**
 * Stands in for the first-run setup screen until it is built.
 *
 * @return {Element} The placeholder.
 */
export default function SetupPlaceholder() {
	return (
		<section className="ha-panel" aria-labelledby="ha-setup-title">
			<h2 id="ha-setup-title">
				{ __( 'Set up HappyAccess', 'happyaccess' ) }
			</h2>
		</section>
	);
}
