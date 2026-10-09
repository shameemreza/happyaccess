import { beforeEach, expect, it, vi } from 'vitest';
import { act, renderHook, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import {
	activityKey,
	defaultActivityRequest,
} from '../activity/activityFormat';
import DataProvider, {
	useActivityCache,
	useCatalog,
	useCoverage,
	useGrants,
	useSettings,
} from './DataProvider';

vi.mock( '@wordpress/api-fetch' );

const answers = {
	'/happyaccess/v1/settings': { needs_setup: false, features: {} },
	'/happyaccess/v1/grants': { items: [ { id: 1 } ] },
	'/happyaccess/v1/catalog': { groups: [], presets: {} },
	'/happyaccess/v1/twostep/coverage': { roles: [], large: false },
};

const gets = () =>
	apiFetch.mock.calls
		.map( ( [ options ] ) => options )
		.filter( ( options ) => ! options.method || 'GET' === options.method )
		.map( ( options ) => options.path );

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockImplementation( async ( { path, method } ) => {
		if ( path.startsWith( '/happyaccess/v1/activity' ) ) {
			return { items: [], total: 0, page: 1, per_page: 25 };
		}
		if ( 'POST' === method && '/happyaccess/v1/grants' === path ) {
			return { id: 2, label: 'New' };
		}
		return answers[ path ];
	} );
} );

function useAll() {
	return {
		grants: useGrants(),
		settings: useSettings(),
		catalog: useCatalog(),
		activity: useActivityCache(),
		coverage: useCoverage(),
	};
}

const wrapper = ( { children } ) => <DataProvider>{ children }</DataProvider>;

async function loaded( result ) {
	await waitFor( () =>
		expect( result.current.grants.loading ).toBe( false )
	);
	await waitFor( () =>
		expect( result.current.settings.loading ).toBe( false )
	);
	await waitFor( () =>
		expect( result.current.catalog.loading ).toBe( false )
	);
}

it( 'loads nothing while the setup screen shows, then each thing once', async () => {
	let enabled = false;
	const gate = ( { children } ) => (
		<DataProvider enabled={ enabled }>{ children }</DataProvider>
	);
	const { result, rerender } = renderHook( useAll, { wrapper: gate } );
	expect( apiFetch ).not.toHaveBeenCalled();

	enabled = true;
	rerender();
	await loaded( result );

	const paths = gets();
	expect( paths ).toHaveLength( 4 );
	expect( paths ).toEqual(
		expect.arrayContaining( [
			'/happyaccess/v1/catalog',
			'/happyaccess/v1/grants',
			'/happyaccess/v1/settings',
		] )
	);
	const activity = paths.filter( ( path ) =>
		path.startsWith( '/happyaccess/v1/activity?' )
	);
	expect( activity ).toHaveLength( 1 );
	expect( activity[ 0 ] ).toContain( 'page=1&per_page=25' );
	expect( result.current.grants.grants ).toEqual( [ { id: 1 } ] );
} );

it( 'fails loudly when a data hook has no provider', () => {
	const error = vi.spyOn( console, 'error' ).mockImplementation( () => {} );
	expect( () => renderHook( () => useGrants() ) ).toThrow( /DataProvider/ );
	error.mockRestore();
} );

it( 'reloads the passes and the catalog quietly after a settings save', async () => {
	const { result } = renderHook( useAll, { wrapper } );
	await loaded( result );
	const before = gets().length;

	await act( async () => {
		await result.current.settings.save( { privacy: { logging: false } } );
	} );

	await waitFor( () => expect( gets() ).toHaveLength( before + 2 ) );
	expect( gets().slice( before ).sort() ).toEqual( [
		'/happyaccess/v1/catalog',
		'/happyaccess/v1/grants',
	] );
	// The catalog stays on screen while it reloads.
	expect( result.current.catalog.catalog ).not.toBeNull();
	expect( result.current.catalog.loading ).toBe( false );
} );

it( 'keeps the first Activity page, and marks it stale after a pass is made', async () => {
	const { result } = renderHook( useAll, { wrapper } );
	await loaded( result );
	const key = activityKey(
		defaultActivityRequest( Math.floor( Date.now() / 1000 ) )
	);
	await waitFor( () =>
		expect( result.current.activity.isFresh( key ) ).toBe( true )
	);

	await act( async () => {
		await result.current.grants.create( { label: 'New' } );
	} );

	expect( result.current.activity.isFresh( key ) ).toBe( false );
	// The page itself stays, to show while the next visit loads a new one.
	expect( result.current.activity.get( key ) ).toBeDefined();
} );

it( 'loads the two-step coverage only when asked, once, and again after a settings save', async () => {
	const { result } = renderHook( useAll, { wrapper } );
	await loaded( result );
	const coverageGets = () =>
		gets().filter(
			( path ) => '/happyaccess/v1/twostep/coverage' === path
		);
	expect( coverageGets() ).toHaveLength( 0 );

	act( () => result.current.coverage.loadOnce() );
	await waitFor( () =>
		expect( result.current.coverage.coverage ).toEqual( {
			roles: [],
			large: false,
		} )
	);
	act( () => result.current.coverage.loadOnce() );
	expect( coverageGets() ).toHaveLength( 1 );

	await act( async () => {
		await result.current.settings.save( {
			two_step: { role_policy: { editor: 'required' } },
		} );
	} );
	await waitFor( () => expect( coverageGets() ).toHaveLength( 2 ) );
} );
