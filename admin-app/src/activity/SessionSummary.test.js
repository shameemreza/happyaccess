import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { axe } from 'jest-axe';
import apiFetch from '@wordpress/api-fetch';
import SessionSummary from './SessionSummary';

vi.mock( '@wordpress/api-fetch' );

beforeEach( () => {
	apiFetch.mockReset();
} );

describe( 'SessionSummary', () => {
	it( 'asks for the pass and shows logins, changes and IPs', async () => {
		apiFetch.mockResolvedValue( {
			logins: 3,
			changes: 14,
			minutes: null,
			ips: [ '203.0.113.24' ],
		} );

		render( <SessionSummary tokenId={ 5 } label="Acme Plugin Support" /> );

		expect(
			await screen.findByText(
				'3 logins, 14 changes, all from 203.0.113.24'
			)
		).toBeVisible();
		expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toBe(
			'/happyaccess/v1/activity/summary?token_id=5'
		);
		expect( screen.getByText( 'Acme Plugin Support' ) ).toBeVisible();
		expect( screen.queryByText( 'Time in total' ) ).not.toBeInTheDocument();
	} );

	it( 'shows the total time when there is one', async () => {
		apiFetch.mockResolvedValue( {
			logins: 1,
			changes: 0,
			minutes: 112,
			ips: [ '203.0.113.24', '198.51.100.7' ],
		} );

		render( <SessionSummary tokenId={ 5 } label="Acme" /> );

		expect(
			await screen.findByText(
				'1 login, 1 hr 52 min in total, 0 changes, from 203.0.113.24, 198.51.100.7'
			)
		).toBeVisible();
		expect( screen.getByText( 'Time in total' ) ).toBeVisible();
	} );

	it( 'says so when the summary cannot load', async () => {
		apiFetch.mockRejectedValue( { code: 'rest_forbidden' } );

		render( <SessionSummary tokenId={ 5 } label="Acme" /> );

		expect(
			await screen.findByText( 'Could not load the summary.' )
		).toBeVisible();
	} );

	it( 'has no accessibility violations', async () => {
		apiFetch.mockResolvedValue( {
			logins: 3,
			changes: 14,
			minutes: null,
			ips: [ '203.0.113.24' ],
		} );

		const { container } = render(
			<SessionSummary tokenId={ 5 } label="Acme" />
		);
		await waitFor( () =>
			expect( screen.getByText( 'Changes' ) ).toBeVisible()
		);

		expect( await axe( container ) ).toHaveNoViolations();
	} );
} );
