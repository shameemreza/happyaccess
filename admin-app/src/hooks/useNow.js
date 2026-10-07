import { useEffect, useState } from '@wordpress/element';

const nowInSeconds = () => Math.floor( Date.now() / 1000 );

/**
 * The current time in seconds. Ticks on the interval and pauses while the tab is hidden.
 *
 * @param {number} intervalMs Tick length in milliseconds.
 * @return {number} Unix time in seconds.
 */
export function useNow( intervalMs = 30000 ) {
	const [ now, setNow ] = useState( nowInSeconds );

	useEffect( () => {
		let timer = null;
		const tick = () => setNow( nowInSeconds() );
		const stop = () => {
			if ( null !== timer ) {
				clearInterval( timer );
				timer = null;
			}
		};
		const start = () => {
			stop();
			timer = setInterval( tick, intervalMs );
		};
		const onVisibility = () => {
			if ( 'visible' === document.visibilityState ) {
				tick();
				start();
			} else {
				stop();
			}
		};

		if ( 'visible' === document.visibilityState ) {
			start();
		}
		document.addEventListener( 'visibilitychange', onVisibility );
		return () => {
			stop();
			document.removeEventListener( 'visibilitychange', onVisibility );
		};
	}, [ intervalMs ] );

	return now;
}
