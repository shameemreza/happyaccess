import {
	createContext,
	useCallback,
	useContext,
	useState,
} from '@wordpress/element';

const AnnounceContext = createContext( () => {} );

/**
 * Owns the one polite live region and hands `announce()` to the tree.
 *
 * @param {Object}  props          Props.
 * @param {Element} props.children Children.
 * @return {Element} Provider plus the live region.
 */
export function AnnounceProvider( { children } ) {
	const [ state, setState ] = useState( { text: '', count: 0 } );

	const announce = useCallback( ( text ) => {
		setState( ( previous ) => ( { text, count: previous.count + 1 } ) );
	}, [] );

	// Screen readers skip a live region whose text did not change. Every other
	// call adds a non-breaking space, so saying "Copied" twice is read twice.
	const text = state.count % 2 ? state.text + '\u00A0' : state.text;

	return (
		<AnnounceContext.Provider value={ announce }>
			{ children }
			<div
				className="screen-reader-text"
				aria-live="polite"
				aria-atomic="true"
			>
				{ text }
			</div>
		</AnnounceContext.Provider>
	);
}

/**
 * @return {(text: string) => void} announce( text ), which speaks text in the live region.
 */
export function useAnnounce() {
	return useContext( AnnounceContext );
}
