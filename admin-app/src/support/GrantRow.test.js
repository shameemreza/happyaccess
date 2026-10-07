import { describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AnnounceProvider } from '../Announcer';
import GrantRow from './GrantRow';
import { grantFixture, NOW } from './fixtures';

const boot = {
	maxDays: 30,
	roles: [ { slug: 'shop_manager', name: 'Shop manager' } ],
};

function setup( grant = grantFixture(), props = {} ) {
	const user = userEvent.setup();
	const onAct = props.onAct || vi.fn( () => Promise.resolve( {} ) );
	const onViewActivity = vi.fn();
	const view = render(
		<AnnounceProvider>
			<ul>
				<GrantRow
					grant={ grant }
					now={ NOW }
					boot={ boot }
					onAct={ onAct }
					onViewActivity={ onViewActivity }
				/>
			</ul>
		</AnnounceProvider>
	);
	const live = () =>
		view.container.querySelector( '[aria-live]' ).textContent;
	return { user, onAct, onViewActivity, live, ...view };
}

describe( 'GrantRow', () => {
	it( 'shows the label, status, level, last login and time left', () => {
		setup();

		expect( screen.getByText( 'Acme Plugin Support' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Active' ) ).toBeInTheDocument();
		expect(
			screen.getByText(
				'Administrator (protected) · Last login 14 minutes ago, 3 logins'
			)
		).toBeInTheDocument();
		expect( screen.getByText( 'Ends in 2 days' ) ).toBeInTheDocument();
		expect( screen.getByText( '3 day pass' ) ).toBeInTheDocument();
		expect( screen.getByText( '2d' ) ).toBeInTheDocument();
	} );

	it( 'puts the exact end in the tooltip', () => {
		setup();

		expect( screen.getByText( 'Ends in 2 days' ) ).toHaveAttribute(
			'title',
			expect.stringMatching(
				/^Access ends \w{3}, \w{3} \d+, \d+:\d\d [ap]m$/
			)
		);
	} );

	it( 'says Never logged in, and names a chosen role', () => {
		setup(
			grantFixture( {
				last_login_at: 0,
				login_count: 0,
				role: 'shop_manager',
			} )
		);

		expect(
			screen.getByText( 'Shop manager · Never logged in' )
		).toBeInTheDocument();
	} );

	it( 'describes a used one-time pass', () => {
		setup(
			grantFixture( {
				status: 'used',
				one_time: true,
				last_login_at: NOW - 5 * 3600,
			} )
		);

		expect( screen.getByText( 'Used once' ) ).toBeInTheDocument();
		expect(
			screen.getByText(
				'Administrator (protected) · One-time pass, logged in 5 hours ago'
			)
		).toBeInTheDocument();
	} );

	it( 'offers +1, +3 and +7 days and extends by seconds', async () => {
		const { user, onAct, live } = setup();

		await user.click( screen.getByRole( 'button', { name: 'Extend' } ) );
		expect(
			screen.getByRole( 'button', { name: '+1 day' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: '+3 days' } )
		).toBeInTheDocument();
		expect(
			screen.getByText( /Never more than 30 days from now/ )
		).toBeInTheDocument();

		await user.click( screen.getByRole( 'button', { name: '+3 days' } ) );

		expect( onAct ).toHaveBeenCalledWith( 5, 'extend', 3 * 86400 );
		await waitFor( () =>
			expect( live() ).toContain(
				'Access for Acme Plugin Support extended by 3 days'
			)
		);
		expect(
			screen.queryByRole( 'button', { name: '+3 days' } )
		).not.toBeInTheDocument();
	} );

	it( 'hides extensions that would pass the cap, and says when none are left', async () => {
		// 29 days and 20 hours left: +1 day crosses 30 days, nothing fits.
		const near = grantFixture( {
			expires_at: NOW + 29 * 86400 + 20 * 3600,
			duration: 29 * 86400,
		} );
		const { user } = setup( near );

		await user.click( screen.getByRole( 'button', { name: 'Extend' } ) );

		expect(
			screen.queryByRole( 'button', { name: '+1 day' } )
		).not.toBeInTheDocument();
		expect(
			screen.getByText( 'This pass already ends at the 30-day limit.' )
		).toBeInTheDocument();
	} );

	it( 'keeps only the options that fit', async () => {
		// 26 days left: +1 and +3 fit, +7 does not.
		const { user } = setup(
			grantFixture( { expires_at: NOW + 26 * 86400 } )
		);

		await user.click( screen.getByRole( 'button', { name: 'Extend' } ) );

		expect(
			screen.getByRole( 'button', { name: '+1 day' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: '+3 days' } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: '+7 days' } )
		).not.toBeInTheDocument();
	} );

	it( 'points a used one-time pass to a new link and code', async () => {
		const { user } = setup(
			grantFixture( { status: 'used', one_time: true } )
		);

		await user.click( screen.getByRole( 'button', { name: 'Extend' } ) );

		expect(
			screen.getByText( /use New link and code/ )
		).toBeInTheDocument();
	} );

	it( 'suspends, and shows Resume for a suspended pass', async () => {
		const { user, onAct, live } = setup();
		await user.click( screen.getByRole( 'button', { name: 'Suspend' } ) );
		expect( onAct ).toHaveBeenCalledWith( 5, 'suspend', undefined );
		await waitFor( () =>
			expect( live() ).toContain(
				'Access suspended for Acme Plugin Support'
			)
		);
	} );

	it( 'resumes a suspended pass', async () => {
		const { user, onAct } = setup(
			grantFixture( { status: 'suspended' } )
		);

		expect( screen.getByText( 'Suspended' ) ).toBeInTheDocument();
		await user.click( screen.getByRole( 'button', { name: 'Resume' } ) );

		expect( onAct ).toHaveBeenCalledWith( 5, 'resume', undefined );
	} );

	it( 'asks before making a new link and code', async () => {
		const { user, onAct } = setup();

		await user.click(
			screen.getByRole( 'button', { name: 'New link and code' } )
		);
		const confirm = screen.getByRole( 'alert' );
		expect( confirm ).toHaveTextContent(
			'This makes a new link and code. The old ones stop working and anyone using this pass is logged out. A suspended pass stays suspended.'
		);
		expect( onAct ).not.toHaveBeenCalled();

		await user.click(
			within( confirm ).getByRole( 'button', { name: 'Make new ones' } )
		);
		expect( onAct ).toHaveBeenCalledWith( 5, 'regenerate', false );
	} );

	it( 'asks before revoking, and keeping access cancels', async () => {
		const { user, onAct } = setup();

		await user.click( screen.getByRole( 'button', { name: 'Revoke' } ) );
		const confirm = screen.getByRole( 'alert' );
		expect( confirm ).toHaveTextContent(
			"Revoke access for Acme Plugin Support? They're logged out right away and the account is deleted."
		);
		expect(
			within( confirm ).getByRole( 'button', { name: 'Keep access' } )
		).toHaveFocus();

		await user.click(
			within( confirm ).getByRole( 'button', { name: 'Keep access' } )
		);
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
		expect( onAct ).not.toHaveBeenCalled();
		expect(
			screen.getByRole( 'button', { name: 'Revoke' } )
		).toHaveFocus();

		await user.click( screen.getByRole( 'button', { name: 'Revoke' } ) );
		await user.click(
			screen.getByRole( 'button', { name: 'Revoke now' } )
		);
		expect( onAct ).toHaveBeenCalledWith( 5, 'revoke', undefined );
	} );

	it( 'blocks every action while a request runs', async () => {
		let finish;
		const onAct = vi.fn(
			() =>
				new Promise( ( resolve ) => {
					finish = resolve;
				} )
		);
		const { user } = setup( grantFixture(), { onAct } );

		await user.click( screen.getByRole( 'button', { name: 'Suspend' } ) );

		const group = screen.getByRole( 'group', {
			name: 'Actions for Acme Plugin Support',
		} );
		within( group )
			.getAllByRole( 'button' )
			.forEach( ( button ) =>
				expect( button ).toHaveAttribute( 'aria-disabled', 'true' )
			);

		// A second click does nothing.
		await user.click( screen.getByRole( 'button', { name: 'Suspend' } ) );
		expect( onAct ).toHaveBeenCalledTimes( 1 );

		finish( {} );
		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: 'Suspend' } )
			).not.toHaveAttribute( 'aria-disabled', 'true' )
		);
	} );

	it( 'shows the server message when an action fails', async () => {
		const onAct = vi.fn( () =>
			Promise.reject( { message: 'That pass already ended.' } )
		);
		const { user } = setup( grantFixture(), { onAct } );

		await user.click( screen.getByRole( 'button', { name: 'Suspend' } ) );

		await waitFor( () =>
			expect(
				document.querySelector( '.components-notice__content' )
			).toHaveTextContent( 'That pass already ended.' )
		);
	} );

	it( 'opens the activity tab for this pass', async () => {
		const { user, onViewActivity } = setup();

		await user.click(
			screen.getByRole( 'link', { name: 'View activity' } )
		);

		expect( onViewActivity ).toHaveBeenCalledWith( 5 );
	} );

	it( 'links View activity with the pass id as token', () => {
		setup();

		const href = screen
			.getByRole( 'link', { name: 'View activity' } )
			.getAttribute( 'href' );
		expect(
			new URLSearchParams( href.split( '?' )[ 1 ] ).get( 'token' )
		).toBe( '5' );
	} );
} );
