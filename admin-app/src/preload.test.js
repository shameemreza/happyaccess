import { beforeAll, describe, expect, it, vi } from 'vitest';
import apiFetch from '@wordpress/api-fetch';
import { PER_PAGE, rangeFilters } from './activity/activityFormat';

// The real apiFetch, with the preloading middleware added the way the page
// does it, before the app's bundle loads and adds its own middleware.
const settingsBody = { features: { support_access: true }, needs_setup: false };
const activityPath =
	'/happyaccess/v1/activity?since=2026-09-15&until=2026-09-21&page=1&per_page=25';
const preloaded = {
	'/happyaccess/v1/settings': { body: settingsBody, headers: {} },
	'/happyaccess/v1/grants': { body: { items: [] }, headers: {} },
	'/happyaccess/v1/catalog': { body: { groups: [] }, headers: {} },
	'/happyaccess/v1/twostep/coverage': {
		body: { roles: [], large: false },
		headers: {},
	},
	// The PHP side writes the query unsorted. The middleware sorts both sides.
	[ activityPath ]: {
		body: { items: [], total: 0, page: 1, per_page: 25 },
		headers: {},
	},
};

let api;
const network = vi.fn();

beforeAll( async () => {
	apiFetch.use( apiFetch.createPreloadingMiddleware( preloaded ) );
	apiFetch.setFetchHandler( network );
	api = await import( './api' );
} );

describe( 'preloaded first data', () => {
	it( 'answers the first getSettings call without a network request', async () => {
		const result = await api.getSettings();

		expect( result ).toEqual( settingsBody );
		expect( network ).not.toHaveBeenCalled();
	} );

	it( 'asks the network the second time, since a preload is used once', async () => {
		network.mockResolvedValue( {
			status: 200,
			ok: true,
			text: async () => JSON.stringify( { fresh: true } ),
		} );

		const result = await api.getSettings();

		expect( network ).toHaveBeenCalledTimes( 1 );
		expect( result ).toEqual( { fresh: true } );
	} );

	it( 'answers the grants and catalog calls from the page', async () => {
		network.mockClear();

		expect( await api.listGrants() ).toEqual( { items: [] } );
		expect( await api.getCatalog() ).toEqual( { groups: [] } );
		expect( network ).not.toHaveBeenCalled();
	} );

	it( 'answers the two-step coverage call from the page', async () => {
		network.mockClear();

		expect( await api.getCoverage() ).toEqual( {
			roles: [],
			large: false,
		} );
		expect( network ).not.toHaveBeenCalled();
	} );

	it( 'answers the default Activity request, which api.js builds with the same path', async () => {
		network.mockClear();
		const request = {
			feature: '',
			...rangeFilters( '7', '', '', '2026-09-21' ),
			page: 1,
			per_page: PER_PAGE,
		};

		const result = await api.listActivity( request );

		expect( result.per_page ).toBe( 25 );
		expect( network ).not.toHaveBeenCalled();
	} );
} );
