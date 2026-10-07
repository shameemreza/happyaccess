import { forwardRef } from '@wordpress/element';

/**
 * An on/off switch. It has no text of its own, so give it `aria-labelledby`
 * or `aria-label`.
 *
 * @param {Object}                  props          Props.
 * @param {boolean}                 props.checked  Whether it is on.
 * @param {(next: boolean) => void} props.onChange Called with the new value.
 * @param {Object}                  props.rest     Other attributes, like aria-labelledby.
 * @param {Object}                  ref            Ref for the button.
 * @return {Element} The switch.
 */
const Switch = forwardRef( function SwitchButton(
	{ checked, onChange, ...rest },
	ref
) {
	return (
		<button
			{ ...rest }
			ref={ ref }
			type="button"
			role="switch"
			className="ha-switch"
			data-state={ checked ? 'on' : 'off' }
			aria-checked={ checked }
			onClick={ () => onChange( ! checked ) }
		>
			<span
				className="ha-switch__knob"
				data-state={ checked ? 'on' : 'off' }
			/>
		</button>
	);
} );

export default Switch;
