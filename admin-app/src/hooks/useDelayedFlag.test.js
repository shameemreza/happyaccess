import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { useDelayedFlag } from './useDelayedFlag';

beforeEach( () => {
	vi.useFakeTimers();
} );

afterEach( () => {
	vi.useRealTimers();
} );

it( 'turns on only after the flag has stayed on for the delay', () => {
	const { result } = renderHook( () => useDelayedFlag( true, 300 ) );
	expect( result.current ).toBe( false );

	act( () => {
		vi.advanceTimersByTime( 299 );
	} );
	expect( result.current ).toBe( false );
	act( () => {
		vi.advanceTimersByTime( 1 );
	} );
	expect( result.current ).toBe( true );
} );

it( 'never turns on for a flag that ends sooner, and starts over next time', () => {
	const { result, rerender } = renderHook(
		( { flag } ) => useDelayedFlag( flag, 300 ),
		{ initialProps: { flag: true } }
	);
	act( () => {
		vi.advanceTimersByTime( 200 );
	} );
	rerender( { flag: false } );
	act( () => {
		vi.advanceTimersByTime( 1000 );
	} );
	expect( result.current ).toBe( false );

	rerender( { flag: true } );
	act( () => {
		vi.advanceTimersByTime( 200 );
	} );
	expect( result.current ).toBe( false );
} );

it( 'turns off as soon as the flag does', () => {
	const { result, rerender } = renderHook(
		( { flag } ) => useDelayedFlag( flag, 300 ),
		{ initialProps: { flag: true } }
	);
	act( () => {
		vi.advanceTimersByTime( 300 );
	} );
	expect( result.current ).toBe( true );

	rerender( { flag: false } );
	expect( result.current ).toBe( false );
} );
