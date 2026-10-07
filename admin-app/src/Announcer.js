import {
	createContext,
	useCallback,
	useContext,
	useMemo,
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

	// A trailing non-breaking space on every other call lets the same text be read twice.
	const text = state.count % 2 ? state.text + ' ' : state.text;
	const value = useMemo( () => announce, [ announce ] );

	return (
		<AnnounceContext.Provider value={ value }>
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
