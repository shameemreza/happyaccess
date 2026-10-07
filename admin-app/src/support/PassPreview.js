import { __, sprintf } from '@wordpress/i18n';

/**
 * The pass as it will look, updated while the form is filled in.
 *
 * @param {Object} props            Props.
 * @param {string} props.label      Who the pass is for.
 * @param {string} props.levelName  Name of the access level.
 * @param {string} props.validUntil Formatted end time.
 * @return {Element} The preview.
 */
export default function PassPreview( { label, levelName, validUntil } ) {
	const shown =
		( label || '' ).trim() || __( 'Who is it for?', 'happyaccess' );

	return (
		<div className="ha-preview" aria-hidden="true">
			<div className="ha-preview__top">
				<div className="ha-preview__meta">
					<span className="ha-preview__tag">
						{ __( 'Preview', 'happyaccess' ) }
					</span>
					<span className="ha-preview__until">
						{ sprintf(
							/* translators: %s: date and time the pass ends. */
							__( 'Valid until %s', 'happyaccess' ),
							validUntil
						) }
					</span>
				</div>
				<div className="ha-preview__label">{ shown }</div>
				<div className="ha-preview__level">{ levelName }</div>
			</div>
			<div className="ha-preview__cut" />
			<div className="ha-preview__bottom">
				<span className="ha-preview__dots">••••&nbsp;••••</span>
				<span className="ha-preview__note">
					{ __(
						'Link and code appear when you create the pass.',
						'happyaccess'
					) }
				</span>
			</div>
		</div>
	);
}
