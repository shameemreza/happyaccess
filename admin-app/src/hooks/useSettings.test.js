import { beforeEach, expect, it, vi } from 'vitest';
import { act, renderHook, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { useSettings } from './useSettings';

vi.mock( '@wordpress/api-fetch' );

beforeEach( () => {
	apiFetch.mockReset();
} );

it( 'loads, saves and sets up, replacing the settings with each response', async () => {
	apiFetch.mockResolvedValueOnce( {
		needs_setup: true,
		security: { max_attempts: 5 },
	} );
	const { result } = renderHook( () => useSettings() );
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
	const { result } = renderHook( () => useSettings() );
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
	const { result } = renderHook( () => useSettings() );
	await waitFor( () => expect( result.current.loading ).toBe( false ) );
	expect( result.current.error.code ).toBe( 'network' );
	expect( result.current.settings ).toBeNull();
} );
