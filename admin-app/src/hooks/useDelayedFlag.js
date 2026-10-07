import { useEffect, useState } from '@wordpress/element';

/**
 * True once `flag` has stayed true for `delay` milliseconds. A flag that ends
 * sooner never shows, so a quick load leaves no loading text behind.
 *
 * @param {boolean} flag  The thing being waited on.
 * @param {number}  delay Milliseconds to wait before showing.
 * @return {boolean} Whether to show the waiting state.
 */
export function useDelayedFlag( flag, delay = 300 ) {
	const [ shown, setShown ] = useState( false );

	useEffect( () => {
		if ( ! flag ) {
			setShown( false );
			return undefined;
		}
		const timer = setTimeout( () => setShown( true ), delay );
		return () => clearTimeout( timer );
	}, [ flag, delay ] );

	return flag && shown;
}
