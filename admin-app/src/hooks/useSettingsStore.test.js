import { beforeEach, expect, it, vi } from 'vitest';
import { act, renderHook, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { useSettingsStore } from './useSettingsStore';

vi.mock( '@wordpress/api-fetch' );

beforeEach( () => {
	apiFetch.mockReset();
} );

it( 'loads, saves and sets up, replacing the settings with each response', async () => {
	apiFetch.mockResolvedValueOnce( {
		needs_setup: true,
		security: { max_attempts: 5 },
	} );
	const { result } = renderHook( () => useSettingsStore() );
	await waitFor( () => expect( result.current.loading ).toBe( false ) );
	expect( result.current.settings.needs_setup ).toBe( true );

	apiFetch.mockResolvedValueOnce( {
		needs_setup: false,
		security: { max_attempts: 3 },
	} );
	await act( async () => {
		await result.current.save( { security: { max_attempts: 3 } } );
	} );
	let call = apiFetch.mock.calls[ 1 ][ 0 ];
	expect( call.path ).toBe( '/happyaccess/v1/settings' );
	expect( call.method ).toBe( 'POST' );
	expect( call.data ).toEqual( { security: { max_attempts: 3 } } );
	expect( result.current.settings.security.max_attempts ).toBe( 3 );

	apiFetch.mockResolvedValueOnce( { needs_setup: false } );
	await act( async () => {
		await result.current.setup( {
			features: { support_access: true },
			consent: true,
		} );
	} );
	call = apiFetch.mock.calls[ 2 ][ 0 ];
	expect( call.path ).toBe( '/happyaccess/v1/setup' );
	expect( call.data ).toEqual( {
		features: { support_access: true },
		consent: true,
	} );
	expect( result.current.saving ).toBe( false );
} );

it( 'rejects a failed save and keeps the settings', async () => {
	apiFetch.mockResolvedValueOnce( { needs_setup: false } );
	const { result } = renderHook( () => useSettingsStore() );
	await waitFor( () => expect( result.current.loading ).toBe( false ) );

	apiFetch.mockRejectedValueOnce( {
		code: 'happyaccess_consent_required',
		message: 'Confirm first.',
		data: { status: 400 },
	} );
	await act( async () => {
		await expect( result.current.save( {} ) ).rejects.toMatchObject( {
			status: 400,
		} );
	} );
	expect( result.current.settings.needs_setup ).toBe( false );
	expect( result.current.saving ).toBe( false );
} );

it( 'sets error when loading fails', async () => {
	apiFetch.mockRejectedValue( { code: 'fetch_error', message: 'offline' } );
	const { result } = renderHook( () => useSettingsStore() );
	await waitFor( () => expect( result.current.loading ).toBe( false ) );
	expect( result.current.error.code ).toBe( 'network' );
	expect( result.current.settings ).toBeNull();
} );

it( 'stays saving until every save in flight is done', async () => {
	apiFetch.mockResolvedValueOnce( { needs_setup: false } );
	const { result } = renderHook( () => useSettingsStore() );
	await waitFor( () => expect( result.current.loading ).toBe( false ) );

	const finish = [];
	apiFetch.mockImplementation(
		() => new Promise( ( resolve ) => finish.push( resolve ) )
	);
	let first;
	let second;
	act( () => {
		first = result.current.save( { a: 1 } );
		second = result.current.save( { b: 2 } );
	} );
	expect( result.current.saving ).toBe( true );

	await act( async () => {
		finish[ 0 ]( { needs_setup: false } );
		await first;
	} );
	expect( result.current.saving ).toBe( true );

	await act( async () => {
		finish[ 1 ]( { needs_setup: false } );
		await second;
	} );
	expect( result.current.saving ).toBe( false );
} );

it( 'shows loading again on a retry and clears the error when it works', async () => {
	apiFetch.mockRejectedValueOnce( { code: 'fetch_error', message: 'x' } );
	const { result } = renderHook( () => useSettingsStore() );
	await waitFor( () => expect( result.current.error ).not.toBeNull() );

	let finish;
	apiFetch.mockImplementationOnce(
		() => new Promise( ( resolve ) => ( finish = resolve ) )
	);
	let retry;
	act( () => {
		retry = result.current.refresh();
	} );
	expect( result.current.loading ).toBe( true );

	await act( async () => {
		finish( { needs_setup: false } );
		await retry;
	} );
	expect( result.current.loading ).toBe( false );
	expect( result.current.error ).toBeNull();
	expect( result.current.settings ).toEqual( { needs_setup: false } );
} );

it( 'clears a load error once a save returns the settings', async () => {
	apiFetch.mockRejectedValueOnce( { code: 'fetch_error', message: 'x' } );
	const { result } = renderHook( () => useSettingsStore() );
	await waitFor( () => expect( result.current.error ).not.toBeNull() );

	apiFetch.mockResolvedValueOnce( { needs_setup: false } );
	await act( async () => {
		await result.current.setup( { features: {}, consent: true } );
	} );
	expect( result.current.error ).toBeNull();
} );
