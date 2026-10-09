import { useId } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	featureTag,
	formatClock,
	iconPath,
	isWarning,
	whoLabel,
} from './activityFormat';

/**
 * One event in the timeline. The row is a button that opens its details.
 *
 * @param {Object}     props          Props.
 * @param {Object}     props.item     Item from the REST list.
 * @param {boolean}    props.expanded Whether the details are open.
 * @param {() => void} props.onToggle Opens or closes the details.
 * @return {Element} The list item.
 */
export default function ActivityRow( { item, expanded, onToggle } ) {
	const detailsId = useId();
	const tag = featureTag( item );
	const tone = isWarning( item ) ? 'warn' : tag.key;

	let pass = __( 'Not a support pass', 'happyaccess' );
	if ( item.pass ) {
		pass = item.pass;
	} else if ( item.token_id > 0 ) {
		pass = sprintf(
			/* translators: %d: the id of a support pass. */
			__( 'Pass #%d', 'happyaccess' ),
			item.token_id
		);
	}

	return (
		<li className="ha-event">
			<button
				type="button"
				className="ha-event__button"
				aria-expanded={ expanded }
				aria-controls={ expanded ? detailsId : undefined }
				onClick={ onToggle }
			>
				<span className="ha-event__time">
					{ formatClock( item.time ) }
				</span>
				<span
					className={ `ha-event__icon ha-tone--${ tone }` }
					aria-hidden="true"
				>
					<svg
						width="16"
						height="16"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						strokeWidth="2"
						strokeLinecap="round"
						strokeLinejoin="round"
						focusable="false"
					>
						<path d={ iconPath( item ) } />
					</svg>
				</span>
				<span className="ha-event__text">
					<span className="ha-event__summary">
						{ item.summary || item.event_label }
					</span>
					<span className="ha-event__who">{ whoLabel( item ) }</span>
				</span>
				<span className={ `ha-event__tag ha-tone--${ tag.key }` }>
					{ tag.label }
				</span>
			</button>
			{ expanded && (
				<dl id={ detailsId } className="ha-event__details">
					<dt>{ __( 'IP address', 'happyaccess' ) }</dt>
					<dd>{ item.ip || __( 'Not recorded', 'happyaccess' ) }</dd>
					<dt>{ __( 'Pass', 'happyaccess' ) }</dt>
					<dd>{ pass }</dd>
					<dt>{ __( 'Event', 'happyaccess' ) }</dt>
					<dd>{ item.event_label }</dd>
				</dl>
			) }
		</li>
	);
}
