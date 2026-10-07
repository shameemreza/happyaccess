import { beforeEach, expect, it, vi } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { useActivity } from './useActivity';

vi.mock( '@wordpress/api-fetch' );

const page = ( ids ) => ( {
	items: ids.map( ( id ) => ( { id } ) ),
	total: 40,
	page: 1,
	per_page: 25,
} );

beforeEach( () => {
	apiFetch.mockReset();
} );

it( 'loads a page with the filters', async () => {
	apiFetch.mockResolvedValue( page( [ 1, 2 ] ) );
	const { result } = renderHook( () =>
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
	const { result, rerender } = renderHook(
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

	const { result, rerender } = renderHook(
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
	const { result } = renderHook( () => useActivity( {} ) );
	await waitFor( () => expect( result.current.loading ).toBe( false ) );
	expect( result.current.error.code ).toBe( 'network' );
} );

it( 'clears the old items when a request fails', async () => {
	apiFetch.mockResolvedValueOnce( page( [ 1, 2 ] ) );
	const { result, rerender } = renderHook(
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
	const { result, rerender } = renderHook(
		( { filters } ) => useActivity( filters ),
		{ initialProps: { filters: { search: 'a' } } }
	);
	await waitFor( () =>
		expect( result.current.loadedKey ).toBe( '{"search":"a"}' )
	);

	rerender( { filters: { search: 'ab' } } );
	await waitFor( () => expect( result.current.loading ).toBe( true ) );
	expect( result.current.loadedKey ).toBe( '{"search":"a"}' );

	resolveSecond( page( [ 2 ] ) );
	await waitFor( () =>
		expect( result.current.loadedKey ).toBe( '{"search":"ab"}' )
	);
} );
