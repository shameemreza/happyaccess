import { listActivity } from '../api';

// How long a fetched page counts as current. Inside this window, coming back
// to the tab shows the page without asking the server again.
export const ACTIVITY_FRESH_MS = 10000;

const MAX_ENTRIES = 20;

/**
 * Remembers the last result for each set of activity filters, so a tab
 * switch shows the page it had at once and refreshes it quietly.
 *
 * @param {() => number} clock Time in milliseconds. Tests pass their own.
 * @return {Object} { get, load, isFresh, markStale }
 */
export function createActivityCache( clock = Date.now ) {
	const entries = new Map();
	const pending = new Map();
	// Bumped by markStale, so a request that began earlier can't pass for current.
	let generation = 0;

	const store = ( key, result, born ) => {
		const kept = entries.get( key );
		// An answer to a request from before a change must not replace a newer one.
		if ( kept && kept.born > born ) {
			return;
		}
		entries.delete( key );
		entries.set( key, {
			result,
			born,
			at: clock(),
			stale: born !== generation,
		} );
		if ( entries.size > MAX_ENTRIES ) {
			entries.delete( entries.keys().next().value );
		}
	};

	return {
		get: ( key ) => entries.get( key ),

		/**
		 * Fetches a page and keeps it. A second call for the same key while
		 * the first runs shares its request.
		 *
		 * @param {string} key     The key for the filters.
		 * @param {Object} filters The filters to send.
		 * @return {Promise<Object>} The REST result.
		 */
		load( key, filters ) {
			const running = pending.get( key );
			if ( running ) {
				return running;
			}
			const born = generation;
			const request = listActivity( filters ).then( ( result ) => {
				store( key, result, born );
				return result;
			} );
			const done = () => {
				if ( pending.get( key ) === request ) {
					pending.delete( key );
				}
			};
			request.then( done, done );
			pending.set( key, request );
			return request;
		},

		isFresh( key ) {
			const entry = entries.get( key );
			return (
				!! entry &&
				! entry.stale &&
				clock() - entry.at < ACTIVITY_FRESH_MS
			);
		},

		// After a change that logs events, so the next visit asks again.
		markStale() {
			generation++;
			pending.clear();
			entries.forEach( ( entry ) => {
				entry.stale = true;
			} );
		},
	};
}
