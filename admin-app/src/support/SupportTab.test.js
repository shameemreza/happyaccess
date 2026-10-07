import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import apiFetch from '@wordpress/api-fetch';
import { AnnounceProvider } from '../Announcer';
import SupportTab from './SupportTab';
import { grantFixture, NOW } from './fixtures';

vi.mock( '@wordpress/api-fetch' );

// The date picker measures itself, and jsdom has no ResizeObserver.
vi.stubGlobal(
	'ResizeObserver',
	class {
		observe() {}
		unobserve() {}
		disconnect() {}
	}
);

const boot = {
	timezone: 'UTC',
	maxDays: 30,
	loginUrl: 'https://yourstore.test/wp-login.php',
	menus: [],
	roles: [ { slug: 'administrator', name: 'Administrator' } ],
};

const SECRETS = {
	code: '4829 1375',
	link_url:
		'https://yourstore.test/wp-login.php?action=happyaccess&step=link&k=Qm3x',
	code_url:
		'https://yourstore.test/wp-login.php?action=happyaccess&step=code',
	message: 'Here is temporary access.',
	emailed: false,
};

let db;
let calls;

function mockServer( items ) {
	db = { grants: items, serial: 100 };
	calls = [];
	apiFetch.mockImplementation( async ( { path, method = 'GET', data } ) => {
		const route = path.replace( '/happyaccess/v1', '' );
		calls.push( { route, method, data } );
		const find = ( id ) => db.grants.find( ( g ) => g.id === Number( id ) );
		const update = ( id, patch ) => {
			Object.assign( find( id ), patch );
			return { ...find( id ) };
		};
		let match;
		if ( 'GET' === method && '/grants' === route ) {
			return { items: db.grants.map( ( g ) => ( { ...g } ) ) };
		}
		if ( route.startsWith( '/activity' ) ) {
			return { items: [], total: 0, page: 1, per_page: 1 };
		}
		if ( 'POST' === method && '/grants' === route ) {
			const grant = grantFixture( {
				id: ++db.serial,
				label: data.label,
				email: data.email || '',
				last_login_at: 0,
				login_count: 0,
				expires_at: NOW + data.duration,
				duration: data.duration,
			} );
			db.grants.unshift( grant );
			return { ...grant, ...SECRETS };
		}
		if ( 'POST' === method && '/grants/revoke-all' === route ) {
			const count = db.grants.length;
			db.grants = [];
			return { count };
		}
		if ( 'POST' === method && '/lock' === route ) {
			const revoked = db.grants.length;
			db.grants = [];
			return { revoked };
		}
		match = /^\/grants\/(\d+)\/(extend|suspend|resume|regenerate)$/.exec(
			route
		);
		if ( match ) {
			const [ , id, action ] = match;
			if ( 'extend' === action ) {
				return update( id, {
					expires_at: find( id ).expires_at + data.seconds,
				} );
			}
			if ( 'suspend' === action ) {
				return update( id, { status: 'suspended' } );
			}
			if ( 'resume' === action ) {
				return update( id, { status: 'active' } );
			}
			return {
				...find( id ),
				...SECRETS,
				code: '1111 2222',
				emailed: Boolean( data.send_email ),
			};
		}
		match = /^\/grants\/(\d+)$/.exec( route );
		if ( match && 'DELETE' === method ) {
			db.grants = db.grants.filter(
				( g ) => g.id !== Number( match[ 1 ] )
			);
			return { revoked: true };
		}
		throw new Error( 'Unexpected request ' + method + ' ' + path );
	} );
}

function setup( props = {} ) {
	const user = userEvent.setup();
	const onViewActivity = vi.fn();
	const view = render(
		<AnnounceProvider>
			<SupportTab
				boot={ boot }
				onViewActivity={ onViewActivity }
				{ ...props }
			/>
		</AnnounceProvider>
	);
	const live = () =>
		view.container.querySelector( '[aria-live]' ).textContent;
	return { user, onViewActivity, live, ...view };
}

const listItem = ( name ) =>
	screen
		.getAllByRole( 'listitem' )
		.find( ( li ) => within( li ).queryByText( name ) );

async function createPass( user, label = 'Acme Plugin Support' ) {
	await user.type( await screen.findByLabelText( 'Who is it for' ), label );
	await user.click(
		screen.getByRole( 'button', { name: 'Create support pass' } )
	);
	return screen.findByRole( 'heading', {
		name: 'Access is ready for ' + label,
	} );
}

beforeEach( () => {
	apiFetch.mockReset();
	vi.useFakeTimers( { toFake: [ 'Date' ] } );
	vi.setSystemTime( NOW * 1000 );
} );

afterEach( () => {
	vi.useRealTimers();
} );

describe( 'status line', () => {
	it( 'counts people and names the latest login', async () => {
		mockServer( [
			grantFixture(),
			grantFixture( {
				id: 6,
				label: 'Jordan',
				last_login_at: NOW - 3 * 3600,
			} ),
			grantFixture( { id: 7, label: 'Hosting', status: 'suspended' } ),
		] );
		setup();

		expect(
			await screen.findByText( '2 people have access.' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Acme Plugin Support logged in 14 minutes ago.' )
		).toBeInTheDocument();
	} );

	it( 'uses the singular for one person', async () => {
		mockServer( [ grantFixture( { last_login_at: 0 } ) ] );
		setup();

		expect(
			await screen.findByText( '1 person has access.' )
		).toBeInTheDocument();
		expect(
			screen.queryByText( /^Acme Plugin Support logged in/ )
		).not.toBeInTheDocument();
	} );
} );

describe( 'create and result card', () => {
	it( 'replaces the form with the card, focused, showing the code and link', async () => {
		mockServer( [] );
		const { user } = setup();

		const heading = await createPass( user );

		expect( heading ).toHaveFocus();
		expect( screen.getByText( '4829 1375' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Login link' ).value ).toBe(
			SECRETS.link_url
		);
		expect(
			screen.queryByRole( 'heading', { name: 'Give support access' } )
		).not.toBeInTheDocument();
		// The new pass is in the list, without its secrets.
		expect(
			await screen.findByText( '1 person has access.' )
		).toBeInTheDocument();
		expect( listItem( 'Acme Plugin Support' ) ).toBeTruthy();
	} );

	it( 'copies, and removes the code from the page after Done', async () => {
		mockServer( [] );
		const { user, container, live } = setup();
		await createPass( user );

		await user.click(
			screen.getByRole( 'button', { name: 'Copy access code' } )
		);
		expect(
			screen.getByRole( 'button', { name: 'Access code copied' } )
		).toHaveTextContent( 'Copied' );
		await waitFor( () =>
			expect( live() ).toContain( 'Access code copied' )
		);

		await user.click(
			screen.getByRole( 'button', {
				name: 'Done, give access to someone else',
			} )
		);

		expect( screen.queryByText( '4829 1375' ) ).not.toBeInTheDocument();
		expect( container.textContent ).not.toContain( '4829 1375' );
		expect( container.innerHTML ).not.toContain( 'Qm3x' );
		expect(
			screen.queryByLabelText( 'Login link' )
		).not.toBeInTheDocument();
		// The form is back, with the cursor in the label field.
		expect( screen.getByLabelText( 'Who is it for' ) ).toHaveFocus();
		expect( screen.getByLabelText( 'Who is it for' ) ).toHaveValue( '' );
	} );

	it( 'keeps the code out of browser storage', async () => {
		mockServer( [] );
		const { user } = setup();
		await createPass( user );

		const stored = JSON.stringify( [
			{ ...window.localStorage },
			{ ...window.sessionStorage },
		] );
		expect( stored ).not.toContain( '4829' );
		expect( stored ).not.toContain( 'Qm3x' );
	} );

	it( 'shows new secrets after New link and code, and sends by email', async () => {
		mockServer( [ grantFixture( { email: 'agent@acme.test' } ) ] );
		const { user } = setup();
		await screen.findByText( '1 person has access.' );

		await user.click(
			screen.getByRole( 'button', { name: 'New link and code' } )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Make new ones' } )
		);

		const heading = await screen.findByRole( 'heading', {
			name: 'Access is ready for Acme Plugin Support',
		} );
		expect( heading ).toHaveFocus();
		expect( screen.getByText( '1111 2222' ) ).toBeInTheDocument();
		expect(
			calls.find( ( c ) => c.route.endsWith( '/regenerate' ) ).data
		).toEqual( { send_email: false } );

		await user.click(
			screen.getByRole( 'button', { name: 'Send by email' } )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Make new ones and send' } )
		);

		expect(
			await screen.findByText( /^Emailed to agent@acme.test/ )
		).toBeInTheDocument();
		const regenerations = calls.filter( ( c ) =>
			c.route.endsWith( '/regenerate' )
		);
		expect( regenerations.at( -1 ).data ).toEqual( { send_email: true } );
	} );

	it( 'starts the card fresh when the same pass is regenerated from the list', async () => {
		mockServer( [ grantFixture( { email: 'agent@acme.test' } ) ] );
		const { user } = setup();
		await screen.findByText( '1 person has access.' );
		const regenerate = async () => {
			await user.click(
				screen.getByRole( 'button', { name: 'New link and code' } )
			);
			await user.click(
				screen.getByRole( 'button', { name: 'Make new ones' } )
			);
		};

		await regenerate();
		await screen.findByRole( 'heading', {
			name: 'Access is ready for Acme Plugin Support',
		} );
		await user.click(
			screen.getByRole( 'button', { name: 'Send by email' } )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Make new ones and send' } )
		);
		expect( await screen.findByText( /^Emailed to/ ) ).toBeInTheDocument();

		await regenerate();

		await waitFor( () =>
			expect(
				screen.getByRole( 'heading', {
					name: 'Access is ready for Acme Plugin Support',
				} )
			).toHaveFocus()
		);
		expect( screen.queryByText( /^Emailed to/ ) ).not.toBeInTheDocument();
	} );

	it( 'has no accessibility violations on the result card', async () => {
		mockServer( [] );
		const { user, container } = setup();
		await createPass( user );

		expect( await axe( container ) ).toHaveNoViolations();
	} );
} );

describe( 'pass actions', () => {
	it( 'extends by a day in seconds', async () => {
		mockServer( [ grantFixture() ] );
		const { user } = setup();
		await screen.findByText( '1 person has access.' );

		await user.click( screen.getByRole( 'button', { name: 'Extend' } ) );
		await user.click( screen.getByRole( 'button', { name: '+1 day' } ) );

		await waitFor( () =>
			expect( screen.getByText( 'Ends in 3 days' ) ).toBeInTheDocument()
		);
		const call = calls.find( ( c ) => c.route === '/grants/5/extend' );
		expect( call.data ).toEqual( { seconds: 86400 } );
	} );

	it( 'suspends, which changes the pill and the count, then resumes', async () => {
		mockServer( [ grantFixture() ] );
		const { user, live } = setup();
		await screen.findByText( '1 person has access.' );

		await user.click( screen.getByRole( 'button', { name: 'Suspend' } ) );

		expect( await screen.findByText( 'Suspended' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Active' ) ).not.toBeInTheDocument();
		expect(
			screen.getByText( 'Nobody has access right now.' )
		).toBeInTheDocument();
		await waitFor( () =>
			expect( live() ).toContain(
				'Access suspended for Acme Plugin Support'
			)
		);

		await user.click( screen.getByRole( 'button', { name: 'Resume' } ) );
		expect( await screen.findByText( 'Active' ) ).toBeInTheDocument();
	} );

	it( 'revokes through the confirm and removes the row', async () => {
		mockServer( [
			grantFixture(),
			grantFixture( { id: 6, label: 'Jordan' } ),
		] );
		const { user, live } = setup();
		await screen.findByText( '2 people have access.' );

		await user.click(
			within( listItem( 'Jordan' ) ).getByRole( 'button', {
				name: 'Revoke',
			} )
		);
		expect(
			apiFetch.mock.calls.some( ( [ o ] ) => 'DELETE' === o.method )
		).toBe( false );
		await user.click(
			screen.getByRole( 'button', { name: 'Revoke now' } )
		);

		await waitFor( () =>
			expect( screen.queryByText( 'Jordan' ) ).not.toBeInTheDocument()
		);
		expect( calls.find( ( c ) => 'DELETE' === c.method ).route ).toBe(
			'/grants/6'
		);
		expect(
			screen.getByText( '1 person has access.' )
		).toBeInTheDocument();
		expect( live() ).toContain( 'Access revoked for Jordan' );
	} );

	it( 'drops the result card when its pass is revoked', async () => {
		mockServer( [] );
		const { user } = setup();
		await createPass( user );

		await user.click( screen.getByRole( 'button', { name: 'Revoke' } ) );
		await user.click(
			screen.getByRole( 'button', { name: 'Revoke now' } )
		);

		expect(
			await screen.findByRole( 'heading', {
				name: 'Give support access',
			} )
		).toBeInTheDocument();
		expect( screen.queryByText( '4829 1375' ) ).not.toBeInTheDocument();
	} );

	it( 'revokes all through its own confirm and loads the list again', async () => {
		mockServer( [
			grantFixture(),
			grantFixture( { id: 6, label: 'Jordan' } ),
		] );
		const { user } = setup();
		await screen.findByText( '2 people have access.' );
		const before = calls.filter(
			( c ) => '/grants' === c.route && 'GET' === c.method
		).length;

		await user.click(
			screen.getByRole( 'button', { name: 'Revoke all' } )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Revoke all now' } )
		);

		expect(
			await screen.findByText( 'Nobody has access.' )
		).toBeInTheDocument();
		expect( calls.some( ( c ) => '/grants/revoke-all' === c.route ) ).toBe(
			true
		);
		const after = calls.filter(
			( c ) => '/grants' === c.route && 'GET' === c.method
		).length;
		expect( after ).toBe( before + 1 );
	} );

	it( 'asks the app to open the activity tab for a pass', async () => {
		mockServer( [ grantFixture() ] );
		const { user, onViewActivity } = setup();
		await screen.findByText( '1 person has access.' );

		await user.click(
			screen.getByRole( 'link', { name: 'View activity' } )
		);

		expect( onViewActivity ).toHaveBeenCalledWith( 5 );
	} );

	it( 'has no accessibility violations on the list', async () => {
		mockServer( [
			grantFixture(),
			grantFixture( { id: 6, label: 'Jordan', status: 'suspended' } ),
		] );
		const { container } = setup();
		await screen.findByText( '1 person has access.' );

		expect( await axe( container ) ).toHaveNoViolations();
	} );
} );

describe( 'refresh key', () => {
	it( 'loads the list again and drops an open result card', async () => {
		mockServer( [] );
		const user = userEvent.setup();
		const view = render(
			<AnnounceProvider>
				<SupportTab boot={ boot } refreshKey={ 0 } />
			</AnnounceProvider>
		);
		await createPass( user );
		db.grants = [];

		view.rerender(
			<AnnounceProvider>
				<SupportTab boot={ boot } refreshKey={ 1 } />
			</AnnounceProvider>
		);

		expect(
			await screen.findByRole( 'heading', {
				name: 'Give support access',
			} )
		).toBeInTheDocument();
		expect( screen.queryByText( '4829 1375' ) ).not.toBeInTheDocument();
		await waitFor( () =>
			expect(
				screen.getByText( 'Nobody has access.' )
			).toBeInTheDocument()
		);
	} );
} );

describe( 'live times', () => {
	it( 'updates the time left every 30 seconds', async () => {
		vi.useFakeTimers();
		vi.setSystemTime( NOW * 1000 );
		mockServer( [
			grantFixture( { expires_at: NOW + 2 * 3600, duration: 3 * 3600 } ),
		] );
		setup();
		await act( async () => {
			await vi.advanceTimersByTimeAsync( 0 );
		} );
		expect( screen.getByText( 'Ends in 2 hours' ) ).toBeInTheDocument();

		await act( async () => {
			await vi.advanceTimersByTimeAsync( 31 * 60 * 1000 );
		} );

		expect(
			screen.getByText( 'Ends in 1 hour 29 minutes' )
		).toBeInTheDocument();
	} );
} );
