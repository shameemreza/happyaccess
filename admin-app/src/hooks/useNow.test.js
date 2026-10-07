import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { useNow } from './useNow';

function setVisibility( state ) {
	Object.defineProperty( document, 'visibilityState', {
		configurable: true,
		get: () => state,
	} );
	document.dispatchEvent( new Event( 'visibilitychange' ) );
}

beforeEach( () => {
	vi.useFakeTimers();
	vi.setSystemTime( new Date( '2026-01-01T00:00:00Z' ) );
	setVisibility( 'visible' );
} );

afterEach( () => {
	vi.useRealTimers();
	setVisibility( 'visible' );
} );

it( 'returns seconds and ticks on the interval', () => {
	const { result } = renderHook( () => useNow( 1000 ) );
	const first = result.current;
	expect( first ).toBe( Math.floor( Date.now() / 1000 ) );

	act( () => {
		vi.advanceTimersByTime( 3000 );
	} );
	expect( result.current ).toBe( first + 3 );
} );

it( 'defaults to a 30 second interval', () => {
	const { result } = renderHook( () => useNow() );
	const first = result.current;
	act( () => {
		vi.advanceTimersByTime( 29000 );
	} );
	expect( result.current ).toBe( first );
	act( () => {
		vi.advanceTimersByTime( 1000 );
	} );
	expect( result.current ).toBe( first + 30 );
} );

it( 'pauses while hidden and catches up when visible again', () => {
	const { result } = renderHook( () => useNow( 1000 ) );
	const first = result.current;

	act( () => setVisibility( 'hidden' ) );
	act( () => {
		vi.advanceTimersByTime( 10000 );
	} );
	expect( result.current ).toBe( first );

	act( () => {
		vi.setSystemTime( Date.now() );
		setVisibility( 'visible' );
	} );
	expect( result.current ).toBe( first + 10 );
} );

it( 'clears its interval and listener on unmount', () => {
	const remove = vi.spyOn( document, 'removeEventListener' );
	const { unmount } = renderHook( () => useNow( 1000 ) );
	expect( vi.getTimerCount() ).toBe( 1 );
	unmount();
	expect( vi.getTimerCount() ).toBe( 0 );
	expect( remove ).toHaveBeenCalledWith(
		'visibilitychange',
		expect.any( Function )
	);
} );
