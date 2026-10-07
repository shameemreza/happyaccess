import { useDelayedFlag } from './hooks/useDelayedFlag';

export const LOADING_DELAY = 300;

/**
 * A single line of loading text that only appears when loading runs long.
 * Until then a quiet block of the same height holds the place, so nothing
 * jumps when the content arrives.
 *
 * @param {Object}  props           Props.
 * @param {boolean} props.loading   Whether the content is still loading.
 * @param {string}  props.className Classes for the line, so it keeps its place in the layout.
 * @param {Object}  props.rest      Other attributes for the line, like role.
 * @param {string}  props.children  The loading text.
 * @return {Element|null} The line, or nothing once loaded.
 */
export default function LoadingLine( {
	loading,
	className = '',
	children,
	...rest
} ) {
	const late = useDelayedFlag( loading, LOADING_DELAY );

	if ( ! loading ) {
		return null;
	}
	return (
		<p className={ className } { ...rest }>
			{ late ? (
				children
			) : (
				<span className="ha-skeleton" aria-hidden="true" />
			) }
		</p>
	);
}
