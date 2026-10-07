import { beforeEach, expect, it, vi } from 'vitest';
import apiFetch from '@wordpress/api-fetch';
import { ACTIVITY_FRESH_MS, createActivityCache } from './activityCache';

vi.mock( '@wordpress/api-fetch' );

const page = ( id ) => ( {
	items: [ { id } ],
	total: 1,
	page: 1,
	per_page: 25,
} );

let now;
const make = () => createActivityCache( () => now );

beforeEach( () => {
	now = 1000;
	apiFetch.mockReset();
} );

it( 'shares one request between callers asking for the same key at once', async () => {
	apiFetch.mockResolvedValue( page( 1 ) );
	const cache = make();

	const [ a, b ] = await Promise.all( [
		cache.load( 'k', { feature: 'support' } ),
		cache.load( 'k', { feature: 'support' } ),
	] );

	expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	expect( a ).toBe( b );
	expect( cache.get( 'k' ).result ).toBe( a );
} );

it( 'asks again once the first request is done', async () => {
	apiFetch.mockResolvedValue( page( 1 ) );
	const cache = make();

	await cache.load( 'k', {} );
	await cache.load( 'k', {} );

	expect( apiFetch ).toHaveBeenCalledTimes( 2 );
} );

it( 'counts a page as current for ten seconds', async () => {
	apiFetch.mockResolvedValue( page( 1 ) );
	const cache = make();
	await cache.load( 'k', {} );

	now += ACTIVITY_FRESH_MS - 1;
	expect( cache.isFresh( 'k' ) ).toBe( true );
	now += 1;
	expect( cache.isFresh( 'k' ) ).toBe( false );
	expect( cache.isFresh( 'other' ) ).toBe( false );
} );

it( 'does not let a request that began before a change pass for current', async () => {
	let release;
	apiFetch.mockImplementationOnce(
		() => new Promise( ( resolve ) => ( release = resolve ) )
	);
	apiFetch.mockResolvedValueOnce( page( 2 ) );
	const cache = make();

	const early = cache.load( 'k', {} );
	cache.markStale();
	// A request after the change does not join the one from before it.
	const late = await cache.load( 'k', {} );
	expect( apiFetch ).toHaveBeenCalledTimes( 2 );
	expect( cache.isFresh( 'k' ) ).toBe( true );

	release( page( 1 ) );
	await early;
	// The older answer arrived last, and it does not replace the newer one.
	expect( cache.get( 'k' ).result ).toBe( late );
	expect( cache.isFresh( 'k' ) ).toBe( true );
} );

it( 'keeps the page after markStale, so it can show while a new one loads', async () => {
	apiFetch.mockResolvedValue( page( 1 ) );
	const cache = make();
	await cache.load( 'k', {} );

	cache.markStale();

	expect( cache.isFresh( 'k' ) ).toBe( false );
	expect( cache.get( 'k' ).result.items ).toHaveLength( 1 );
} );

it( 'keeps at most twenty pages, dropping the oldest', async () => {
	apiFetch.mockResolvedValue( page( 1 ) );
	const cache = make();

	for ( let i = 0; i < 21; i++ ) {
		await cache.load( `k${ i }`, {} );
	}

	expect( cache.get( 'k0' ) ).toBeUndefined();
	expect( cache.get( 'k1' ) ).toBeDefined();
	expect( cache.get( 'k20' ) ).toBeDefined();
} );
