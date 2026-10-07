import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { act, render, renderHook, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import DataProvider from '../data/DataProvider';
import { ACTIVITY_FRESH_MS } from '../data/activityCache';
import { useActivity } from './useActivity';

vi.mock( '@wordpress/api-fetch' );

const page = ( ids ) => ( {
	items: ids.map( ( id ) => ( { id } ) ),
	total: 40,
	page: 1,
	per_page: 25,
} );

// A provider that loads nothing by itself, so only the hook's requests count.
const wrapper = ( { children } ) => (
	<DataProvider enabled={ false }>{ children }</DataProvider>
);

const renderActivity = ( callback, options ) =>
	renderHook( callback, { wrapper, ...options } );

beforeEach( () => {
	apiFetch.mockReset();
} );

afterEach( () => {
	vi.useRealTimers();
} );

it( 'loads a page with the filters', async () => {
	apiFetch.mockResolvedValue( page( [ 1, 2 ] ) );
	const { result } = renderActivity( () =>
		useActivity( { feature: 'support' } )
	);
	await waitFor( () => expect( result.current.loading ).toBe( false ) );

	expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toBe(
		'/happyaccess/v1/activity?feature=support'
	);
	expect( result.current.items ).toHaveLength( 2 );
	expect( result.current.total ).toBe( 40 );
	expect( result.current.perPage ).toBe( 25 );
} );

it( 'fetches again when the filters change, not when they are equal', async () => {
	apiFetch.mockResolvedValue( page( [ 1 ] ) );
	const { result, rerender } = renderActivity(
		( { filters } ) => useActivity( filters ),
		{ initialProps: { filters: { page: 1 } } }
	);
	await waitFor( () => expect( result.current.loading ).toBe( false ) );

	rerender( { filters: { page: 1 } } );
	expect( apiFetch ).toHaveBeenCalledTimes( 1 );

	rerender( { filters: { page: 2 } } );
	await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 2 ) );
	expect( apiFetch.mock.calls[ 1 ][ 0 ].path ).toContain( 'page=2' );
} );

it( 'ignores a slow response that was replaced by a newer request', async () => {
	let resolveSlow;
	apiFetch.mockImplementationOnce(
		() => new Promise( ( resolve ) => ( resolveSlow = resolve ) )
	);
	apiFetch.mockResolvedValueOnce( page( [ 9 ] ) );

	const { result, rerender } = renderActivity(
		( { filters } ) => useActivity( filters ),
		{ initialProps: { filters: { search: 'a' } } }
	);
	rerender( { filters: { search: 'ab' } } );
	await waitFor( () => expect( result.current.items ).toHaveLength( 1 ) );

	resolveSlow( page( [ 1, 2, 3 ] ) );
	await Promise.resolve();
	expect( result.current.items ).toHaveLength( 1 );
	expect( result.current.items[ 0 ].id ).toBe( 9 );
} );

it( 'sets error on failure', async () => {
	apiFetch.mockRejectedValue( { code: 'fetch_error', message: 'offline' } );
	const { result } = renderActivity( () => useActivity( {} ) );
	await waitFor( () => expect( result.current.loading ).toBe( false ) );
	expect( result.current.error.code ).toBe( 'network' );
} );

it( 'clears the old items when a request fails', async () => {
	apiFetch.mockResolvedValueOnce( page( [ 1, 2 ] ) );
	const { result, rerender } = renderActivity(
		( { filters } ) => useActivity( filters ),
		{ initialProps: { filters: { search: 'a' } } }
	);
	await waitFor( () => expect( result.current.items ).toHaveLength( 2 ) );

	apiFetch.mockRejectedValueOnce( {
		code: 'rest_forbidden',
		message: 'No.',
	} );
	rerender( { filters: { search: 'ab' } } );
	await waitFor( () => expect( result.current.error ).not.toBeNull() );

	expect( result.current.items ).toEqual( [] );
	expect( result.current.total ).toBe( 0 );
} );

it( 'reports which filters the shown items were loaded for', async () => {
	let resolveSecond;
	apiFetch.mockResolvedValueOnce( page( [ 1 ] ) );
	apiFetch.mockImplementationOnce(
		() => new Promise( ( resolve ) => ( resolveSecond = resolve ) )
	);
	const { result, rerender } = renderActivity(
		( { filters } ) => useActivity( filters ),
		{ initialProps: { filters: { search: 'a' } } }
	);
	await waitFor( () =>
		expect( result.current.loadedKey ).toBe( '[["search","a"]]' )
	);

	rerender( { filters: { search: 'ab' } } );
	await waitFor( () => expect( result.current.loading ).toBe( true ) );
	expect( result.current.loadedKey ).toBe( '[["search","a"]]' );

	resolveSecond( page( [ 2 ] ) );
	await waitFor( () =>
		expect( result.current.loadedKey ).toBe( '[["search","ab"]]' )
	);
} );

// Shows the hook's output for a tab that can be hidden and shown again.
function Probe( { filters, seen } ) {
	seen.current = useActivity( filters );
	return null;
}

function mountTab( filters ) {
	const seen = { current: null };
	const tree = ( show ) => (
		<DataProvider enabled={ false }>
			{ show && <Probe filters={ filters } seen={ seen } /> }
		</DataProvider>
	);
	const view = render( tree( true ) );
	return {
		seen,
		hide: () => view.rerender( tree( false ) ),
		show: () => view.rerender( tree( true ) ),
	};
}

it( 'shows the kept page at once on coming back and refreshes it quietly', async () => {
	vi.useFakeTimers( { toFake: [ 'Date' ] } );
	vi.setSystemTime( 1000000 );
	apiFetch.mockResolvedValueOnce( page( [ 1, 2 ] ) );
	const tab = mountTab( { feature: 'support' } );
	await waitFor( () => expect( tab.seen.current.loading ).toBe( false ) );
	expect( apiFetch ).toHaveBeenCalledTimes( 1 );

	tab.hide();
	vi.setSystemTime( 1000000 + ACTIVITY_FRESH_MS + 1 );
	let release;
	apiFetch.mockImplementationOnce(
		() => new Promise( ( resolve ) => ( release = resolve ) )
	);
	tab.show();

	// The kept rows are there on the first render, with no loading state.
	expect( tab.seen.current.items ).toHaveLength( 2 );
	expect( tab.seen.current.loading ).toBe( false );
	await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 2 ) );
	expect( tab.seen.current.refreshing ).toBe( true );

	await act( async () => release( page( [ 1, 2, 3 ] ) ) );
	await waitFor( () => expect( tab.seen.current.items ).toHaveLength( 3 ) );
	expect( tab.seen.current.refreshing ).toBe( false );
} );

it( 'does not ask again when the kept page is only seconds old', async () => {
	vi.useFakeTimers( { toFake: [ 'Date' ] } );
	vi.setSystemTime( 1000000 );
	apiFetch.mockResolvedValue( page( [ 1 ] ) );
	const tab = mountTab( {} );
	await waitFor( () => expect( tab.seen.current.loading ).toBe( false ) );

	tab.hide();
	vi.setSystemTime( 1000000 + ACTIVITY_FRESH_MS - 1 );
	tab.show();

	expect( tab.seen.current.items ).toHaveLength( 1 );
	expect( apiFetch ).toHaveBeenCalledTimes( 1 );
} );

it( 'keeps the kept page when a quiet refresh fails', async () => {
	vi.useFakeTimers( { toFake: [ 'Date' ] } );
	vi.setSystemTime( 1000000 );
	apiFetch.mockResolvedValueOnce( page( [ 1 ] ) );
	const tab = mountTab( {} );
	await waitFor( () => expect( tab.seen.current.loading ).toBe( false ) );

	tab.hide();
	vi.setSystemTime( 1000000 + ACTIVITY_FRESH_MS + 1 );
	apiFetch.mockRejectedValueOnce( { code: 'fetch_error', message: 'x' } );
	tab.show();
	await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 2 ) );
	await waitFor( () => expect( tab.seen.current.refreshing ).toBe( false ) );

	expect( tab.seen.current.items ).toHaveLength( 1 );
	expect( tab.seen.current.error ).toBeNull();
} );

it( 'keeps one page for each set of filters', async () => {
	apiFetch.mockResolvedValueOnce( page( [ 1 ] ) );
	apiFetch.mockResolvedValueOnce( page( [ 7, 8 ] ) );
	const { result, rerender } = renderActivity(
		( { filters } ) => useActivity( filters ),
		{ initialProps: { filters: { search: 'a' } } }
	);
	await waitFor( () => expect( result.current.items ).toHaveLength( 1 ) );
	rerender( { filters: { search: 'b' } } );
	await waitFor( () => expect( result.current.items ).toHaveLength( 2 ) );

	// Back to the first filters: its page shows at once, with no loading state.
	apiFetch.mockResolvedValueOnce( page( [ 1 ] ) );
	rerender( { filters: { search: 'a' } } );
	expect( result.current.items ).toHaveLength( 1 );
	expect( result.current.loading ).toBe( false );
} );
