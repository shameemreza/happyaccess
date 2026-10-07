import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { act, renderHook, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { useGrants } from './useGrants';

vi.mock( '@wordpress/api-fetch' );

const grant = ( id, extra = {} ) => ( {
	id,
	label: `Pass ${ id }`,
	status: 'active',
	...extra,
} );

function setVisibility( state ) {
	Object.defineProperty( document, 'visibilityState', {
		configurable: true,
		get: () => state,
	} );
}

beforeEach( () => {
	apiFetch.mockReset();
	setVisibility( 'visible' );
} );

afterEach( () => {
	vi.useRealTimers();
	setVisibility( 'visible' );
} );

it( 'loads the list on mount', async () => {
	apiFetch.mockResolvedValue( { items: [ grant( 5 ), grant( 6 ) ] } );
	const { result } = renderHook( () => useGrants() );

	expect( result.current.loading ).toBe( true );
	await waitFor( () => expect( result.current.loading ).toBe( false ) );
	expect( result.current.grants.map( ( g ) => g.id ) ).toEqual( [ 5, 6 ] );
	expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toBe(
		'/happyaccess/v1/grants'
	);
} );

it( 'act extend posts the seconds and replaces the item without a refetch', async () => {
	apiFetch.mockResolvedValueOnce( { items: [ grant( 5 ), grant( 6 ) ] } );
	const { result } = renderHook( () => useGrants() );
	await waitFor( () => expect( result.current.loading ).toBe( false ) );

	apiFetch.mockResolvedValueOnce( grant( 5, { seconds_left: 99 } ) );
	await act( async () => {
		await result.current.act( 5, 'extend', 86400 );
	} );

	const call = apiFetch.mock.calls[ 1 ][ 0 ];
	expect( call.path ).toBe( '/happyaccess/v1/grants/5/extend' );
	expect( call.method ).toBe( 'POST' );
	expect( call.data ).toEqual( { seconds: 86400 } );
	expect( apiFetch ).toHaveBeenCalledTimes( 2 );
	expect( result.current.grants[ 0 ].seconds_left ).toBe( 99 );
	expect( result.current.grants[ 1 ].id ).toBe( 6 );
} );

it( 'act revoke removes the item', async () => {
	apiFetch.mockResolvedValueOnce( { items: [ grant( 5 ), grant( 6 ) ] } );
	const { result } = renderHook( () => useGrants() );
	await waitFor( () => expect( result.current.loading ).toBe( false ) );

	apiFetch.mockResolvedValueOnce( { revoked: true } );
	await act( async () => {
		await result.current.act( 5, 'revoke' );
	} );

	expect( apiFetch.mock.calls[ 1 ][ 0 ].method ).toBe( 'DELETE' );
	expect( result.current.grants.map( ( g ) => g.id ) ).toEqual( [ 6 ] );
} );

it( 'create returns the secrets but keeps them out of the list', async () => {
	apiFetch.mockResolvedValueOnce( { items: [] } );
	const { result } = renderHook( () => useGrants() );
	await waitFor( () => expect( result.current.loading ).toBe( false ) );

	apiFetch.mockResolvedValueOnce(
		grant( 9, {
			code: 'ABCD-1234',
			link_url: 'https://x/?k=1',
			message: 'm',
		} )
	);
	let made;
	await act( async () => {
		made = await result.current.create( {
			label: 'Pass 9',
			level: 'protected',
		} );
	} );

	expect( made.code ).toBe( 'ABCD-1234' );
	expect( result.current.grants ).toEqual( [ grant( 9 ) ] );
} );

it( 'rejects with the normalized error when an action fails', async () => {
	apiFetch.mockResolvedValueOnce( { items: [ grant( 5 ) ] } );
	const { result } = renderHook( () => useGrants() );
	await waitFor( () => expect( result.current.loading ).toBe( false ) );

	apiFetch.mockRejectedValueOnce( {
		code: 'happyaccess_conflict',
		message: 'No.',
		data: { status: 409 },
	} );
	await act( async () => {
		await expect(
			result.current.act( 5, 'suspend' )
		).rejects.toMatchObject( {
			code: 'happyaccess_conflict',
			status: 409,
		} );
	} );
	expect( result.current.grants ).toHaveLength( 1 );
} );

it( 'sets error when the list fails to load', async () => {
	apiFetch.mockRejectedValue( { code: 'fetch_error', message: 'offline' } );
	const { result } = renderHook( () => useGrants() );
	await waitFor( () => expect( result.current.loading ).toBe( false ) );
	expect( result.current.error.code ).toBe( 'network' );
} );

it( 'refreshes every 60 seconds while visible, pauses while hidden and stops on unmount', async () => {
	vi.useFakeTimers();
	apiFetch.mockResolvedValue( { items: [] } );
	const { unmount } = renderHook( () => useGrants() );
	await act( async () => {} );
	expect( apiFetch ).toHaveBeenCalledTimes( 1 );

	await act( async () => {
		await vi.advanceTimersByTimeAsync( 60000 );
	} );
	expect( apiFetch ).toHaveBeenCalledTimes( 2 );

	setVisibility( 'hidden' );
	await act( async () => {
		await vi.advanceTimersByTimeAsync( 120000 );
	} );
	expect( apiFetch ).toHaveBeenCalledTimes( 2 );

	setVisibility( 'visible' );
	await act( async () => {
		await vi.advanceTimersByTimeAsync( 60000 );
	} );
	expect( apiFetch ).toHaveBeenCalledTimes( 3 );

	unmount();
	await vi.advanceTimersByTimeAsync( 180000 );
	expect( apiFetch ).toHaveBeenCalledTimes( 3 );
	expect( vi.getTimerCount() ).toBe( 0 );
} );
