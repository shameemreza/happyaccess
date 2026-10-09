import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import apiFetch from '@wordpress/api-fetch';
import { AnnounceProvider } from '../Announcer';
import DataProvider from '../data/DataProvider';
import ActiveList from './ActiveList';
import { grantFixture, NOW } from './fixtures';

vi.mock( '@wordpress/api-fetch' );

const latest = {
	items: [
		{
			summary: 'Updated WooCommerce settings',
			event_label: 'Settings changed',
			time: NOW - 9 * 60,
		},
	],
};

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockResolvedValue( latest );
} );

function setup( props = {} ) {
	const user = userEvent.setup();
	const handlers = {
		onAct: vi.fn( () => Promise.resolve( {} ) ),
		onRevokeAll: vi.fn( () => Promise.resolve() ),
		onViewActivity: vi.fn(),
		onRetry: vi.fn(),
	};
	const view = render(
		<AnnounceProvider>
			<DataProvider enabled={ false }>
				<ActiveList
					grants={ [
						grantFixture(),
						grantFixture( {
							id: 6,
							label: 'Jordan',
							status: 'suspended',
						} ),
					] }
					loading={ false }
					error={ null }
					now={ NOW }
					boot={ { maxDays: 30, roles: [] } }
					{ ...handlers }
					{ ...props }
				/>
			</DataProvider>
		</AnnounceProvider>
	);
	return { user, ...handlers, ...view };
}

describe( 'ActiveList', () => {
	it( 'shows the heading, the count and a row for each pass', () => {
		setup();

		expect(
			screen.getByRole( 'heading', { name: 'Who has access' } )
		).toBeInTheDocument();
		// Real text, so screen readers read it, instead of a label on a span.
		const spoken = screen.getByText( '2 in the list' );
		expect( spoken ).toHaveClass( 'screen-reader-text' );
		expect( document.querySelector( '.ha-active__count' ) ).toHaveAttribute(
			'aria-hidden',
			'true'
		);
		expect( screen.getAllByRole( 'listitem' ) ).toHaveLength( 2 );
	} );

	it( 'shows the empty state', () => {
		setup( { grants: [] } );

		expect( screen.getByText( 'Nobody has access.' ) ).toBeInTheDocument();
		expect(
			screen.getByText(
				'Passes you create show here, with the time each one has left.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Revoke all' } )
		).not.toBeInTheDocument();
	} );

	it( 'confirms Revoke all inline, then calls it', async () => {
		const { user, onRevokeAll } = setup();

		await user.click(
			screen.getByRole( 'button', { name: 'Revoke all' } )
		);
		const confirm = screen.getByText( /^Revoke every pass below\?/ );
		expect( confirm ).toBeInTheDocument();
		expect( onRevokeAll ).not.toHaveBeenCalled();

		await user.click(
			screen.getByRole( 'button', { name: 'Revoke all now' } )
		);

		expect( onRevokeAll ).toHaveBeenCalledTimes( 1 );
		await waitFor( () =>
			expect(
				screen.queryByRole( 'button', { name: 'Revoke all now' } )
			).not.toBeInTheDocument()
		);
	} );

	it( 'keeps the passes when Keep access is chosen', async () => {
		const { user, onRevokeAll } = setup();

		await user.click(
			screen.getByRole( 'button', { name: 'Revoke all' } )
		);
		await user.click(
			within(
				screen.getByRole( 'group', { name: /^Revoke every pass/ } )
			).getByRole( 'button', {
				name: 'Keep access',
			} )
		);

		expect( onRevokeAll ).not.toHaveBeenCalled();
		expect(
			screen.getByRole( 'button', { name: 'Revoke all' } )
		).toHaveFocus();
	} );

	it( 'shows the server message when Revoke all fails', async () => {
		const { user } = setup( {
			onRevokeAll: vi.fn( () => Promise.reject( { message: 'Nope.' } ) ),
		} );

		await user.click(
			screen.getByRole( 'button', { name: 'Revoke all' } )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Revoke all now' } )
		);

		await waitFor( () =>
			expect(
				document.querySelector( '.components-notice__content' )
			).toHaveTextContent( 'Nope.' )
		);
	} );

	it( 'shows the latest support activity and a link to all of it', async () => {
		const { user, onViewActivity } = setup();

		expect(
			await screen.findByText(
				'Latest: Updated WooCommerce settings, 9 minutes ago'
			)
		).toBeInTheDocument();
		expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toBe(
			'/happyaccess/v1/activity?per_page=1&feature=support'
		);

		await user.click(
			screen.getByRole( 'link', { name: 'See all activity' } )
		);
		expect( onViewActivity ).toHaveBeenCalledWith();
	} );

	it( 'passes the revoke through and moves focus to the heading', async () => {
		const { user, onAct } = setup();
		const row = screen.getAllByRole( 'listitem' )[ 0 ];

		await user.click(
			within( row ).getByRole( 'button', { name: 'Revoke' } )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Revoke now' } )
		);

		expect( onAct ).toHaveBeenCalledWith( 5, 'revoke', undefined );
		await waitFor( () =>
			expect(
				screen.getByRole( 'heading', { name: 'Who has access' } )
			).toHaveFocus()
		);
	} );

	it( 'blocks Revoke all and the row actions while it runs', async () => {
		let finish;
		const { user } = setup( {
			onRevokeAll: vi.fn(
				() =>
					new Promise( ( resolve ) => {
						finish = resolve;
					} )
			),
		} );

		await user.click(
			screen.getByRole( 'button', { name: 'Revoke all' } )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Revoke all now' } )
		);

		expect(
			screen.getByRole( 'button', { name: 'Revoke all' } )
		).toHaveAttribute( 'aria-disabled', 'true' );
		screen
			.getAllByRole( 'button', { name: 'Extend' } )
			.forEach( ( button ) =>
				expect( button ).toHaveAttribute( 'aria-disabled', 'true' )
			);

		finish();
		await waitFor( () =>
			expect(
				screen.getAllByRole( 'button', { name: 'Extend' } )[ 0 ]
			).not.toHaveAttribute( 'aria-disabled', 'true' )
		);
	} );

	it( 'loads the latest activity again after an action', async () => {
		const { user } = setup();
		await screen.findByText( /^Latest:/ );
		const activityCalls = () =>
			apiFetch.mock.calls.filter( ( [ options ] ) =>
				options.path.startsWith( '/happyaccess/v1/activity' )
			).length;
		expect( activityCalls() ).toBe( 1 );

		await user.click(
			screen.getAllByRole( 'button', { name: 'Suspend' } )[ 0 ]
		);

		await waitFor( () => expect( activityCalls() ).toBe( 2 ) );
	} );

	it( 'shows a load error with a way to retry', async () => {
		const { user, onRetry } = setup( {
			grants: [],
			error: { message: 'Could not reach the server.' },
		} );

		expect(
			document.querySelector( '.components-notice__content' )
		).toHaveTextContent( 'Could not reach the server.' );
		await user.click( screen.getByRole( 'button', { name: 'Try again' } ) );
		expect( onRetry ).toHaveBeenCalled();
	} );

	it( 'offers a reload instead of a retry when HappyAccess is not answering', () => {
		setup( {
			grants: [],
			error: {
				code: 'unavailable',
				message:
					"HappyAccess isn't answering. It may have been turned off or updated. Reload the page to continue.",
			},
		} );

		expect(
			document.querySelector( '.components-notice__content' )
		).toHaveTextContent( "HappyAccess isn't answering." );
		expect(
			screen.getByRole( 'button', { name: 'Reload the page' } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Try again' } )
		).not.toBeInTheDocument();
	} );

	it( 'has no accessibility violations', async () => {
		const { container } = setup();
		await screen.findByText( /^Latest:/ );

		expect( await axe( container ) ).toHaveNoViolations();
	} );
} );
