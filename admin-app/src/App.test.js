import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import apiFetch from '@wordpress/api-fetch';
import App, { readBoot } from './App';
import { settingsFixture } from './settings/fixtures';
import { grantFixture } from './support/fixtures';

vi.mock( '@wordpress/api-fetch' );

let grantsOnServer;
let settingsOnServer;

const boot = ( overrides = {} ) => ( {
	features: { support_access: true, passwordless: false, two_step: false },
	needsSetup: false,
	...overrides,
} );

const tabNames = () =>
	within( screen.getByRole( 'navigation', { name: 'HappyAccess sections' } ) )
		.getAllByRole( 'link' )
		.map( ( link ) => link.textContent );

beforeEach( () => {
	grantsOnServer = [];
	settingsOnServer = settingsFixture();
	apiFetch.mockReset();
	apiFetch.mockImplementation( async ( { path, method, data } ) => {
		if ( '/happyaccess/v1/lock' === path && 'POST' === method ) {
			const revoked = grantsOnServer.length;
			grantsOnServer = [];
			return { revoked };
		}
		if ( '/happyaccess/v1/setup' === path ) {
			settingsOnServer = { ...settingsOnServer, needs_setup: false };
			return settingsOnServer;
		}
		if ( '/happyaccess/v1/settings' === path ) {
			if ( 'POST' === method ) {
				const patch = data.features || {};
				settingsOnServer = {
					...settingsOnServer,
					features: { ...settingsOnServer.features, ...patch },
				};
				return { ...settingsOnServer, revoked: grantsOnServer.length };
			}
			return settingsOnServer;
		}
		if ( '/happyaccess/v1/catalog' === path ) {
			return { groups: [], presets: {} };
		}
		if ( path.startsWith( '/happyaccess/v1/activity/summary' ) ) {
			return { logins: 2, changes: 4, minutes: null, ips: [] };
		}
		if ( path.startsWith( '/happyaccess/v1/activity' ) ) {
			return { items: [], total: 0, page: 1, per_page: 1 };
		}
		return { items: grantsOnServer };
	} );
	window.localStorage.clear();
	window.history.replaceState(
		{},
		'',
		'/wp-admin/users.php?page=happyaccess'
	);
} );

describe( 'App shell', () => {
	it( 'shows the header with the help link and the lock button', () => {
		render( <App boot={ boot() } /> );

		expect(
			screen.getByRole( 'heading', { level: 1, name: 'HappyAccess' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'Help and docs' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Emergency lock' } )
		).toBeInTheDocument();
	} );

	it( 'renders four tabs when Login is available', () => {
		render(
			<App
				boot={ boot( {
					features: { support_access: true, passwordless: true },
				} ) }
				loginReady
			/>
		);

		expect( tabNames() ).toEqual( [
			'Support access',
			'Activity',
			'Login',
			'Settings',
		] );
	} );

	it( 'hides Login when it is not available or no login feature is on', () => {
		const { unmount } = render(
			<App
				boot={ boot( {
					features: { support_access: true, passwordless: true },
				} ) }
			/>
		);
		expect( tabNames() ).toEqual( [
			'Support access',
			'Activity',
			'Settings',
		] );
		unmount();

		render( <App boot={ boot() } loginReady /> );
		expect( tabNames() ).toEqual( [
			'Support access',
			'Activity',
			'Settings',
		] );
	} );

	it( 'reads loginReady from the boot data', () => {
		render(
			<App
				boot={ boot( {
					loginReady: true,
					features: { support_access: true, passwordless: true },
				} ) }
			/>
		);

		expect( tabNames() ).toContain( 'Login' );
	} );

	it( 'hides Login when both login features are off, and shows the Login tab when Passwordless is on', async () => {
		const { unmount } = render(
			<App
				boot={ boot( {
					loginReady: true,
					features: {
						support_access: true,
						passwordless: false,
						two_step: false,
					},
				} ) }
			/>
		);
		expect( tabNames() ).not.toContain( 'Login' );
		unmount();

		const user = userEvent.setup();
		render(
			<App
				boot={ boot( {
					loginReady: true,
					woocommerce: false,
					loginRoles: [
						{
							slug: 'administrator',
							name: 'Administrator',
							isAdmin: true,
						},
					],
					features: { support_access: true, passwordless: true },
				} ) }
			/>
		);
		await user.click( screen.getByRole( 'link', { name: 'Login' } ) );

		expect(
			await screen.findByRole( 'heading', { name: 'Passwordless login' } )
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Administrator' ) ).toBeInTheDocument();
	} );

	it( 'shows the Login tab when Passwordless is switched on in Settings, and hides it when it is switched off', async () => {
		window.localStorage.setItem( 'happyaccess.tab', 'settings' );
		const user = userEvent.setup();
		render( <App boot={ boot( { loginReady: true } ) } /> );
		await screen.findByRole( 'heading', { name: 'Safety and privacy' } );
		expect( tabNames() ).not.toContain( 'Login' );

		await user.click(
			screen.getByRole( 'switch', { name: 'Passwordless login' } )
		);

		await waitFor( () => expect( tabNames() ).toContain( 'Login' ) );
		expect( tabNames() ).toEqual( [
			'Support access',
			'Activity',
			'Login',
			'Settings',
		] );
		expect(
			screen.getByRole( 'heading', { name: 'Safety and privacy' } )
		).toBeInTheDocument();

		await user.click(
			screen.getByRole( 'switch', { name: 'Passwordless login' } )
		);

		await waitFor( () => expect( tabNames() ).not.toContain( 'Login' ) );
		expect(
			screen.getByRole( 'heading', { name: 'Safety and privacy' } )
		).toBeInTheDocument();
	} );

	it( 'hides Support access when that feature is off', () => {
		render(
			<App boot={ boot( { features: { support_access: false } } ) } />
		);

		expect( tabNames() ).toEqual( [ 'Activity', 'Settings' ] );
		expect(
			screen.getByRole( 'link', { name: 'Activity' } )
		).toHaveAttribute( 'aria-current', 'page' );
	} );

	it( 'sets aria-current, the query arg and the remembered tab when a tab is clicked', async () => {
		const user = userEvent.setup();
		render( <App boot={ boot() } /> );
		expect(
			screen.getByRole( 'link', { name: 'Support access' } )
		).toHaveAttribute( 'aria-current', 'page' );

		await user.click( screen.getByRole( 'link', { name: 'Activity' } ) );

		expect(
			screen.getByRole( 'link', { name: 'Activity' } )
		).toHaveAttribute( 'aria-current', 'page' );
		expect(
			screen.getByRole( 'link', { name: 'Support access' } )
		).not.toHaveAttribute( 'aria-current' );
		expect(
			new URLSearchParams( window.location.search ).get( 'tab' )
		).toBe( 'activity' );
		expect(
			new URLSearchParams( window.location.search ).get( 'page' )
		).toBe( 'happyaccess' );
		expect( window.localStorage.getItem( 'happyaccess.tab' ) ).toBe(
			'activity'
		);
		expect(
			screen.getByRole( 'heading', { level: 2, name: 'Activity' } )
		).toBeInTheDocument();
	} );

	it( 'starts on the tab in the URL, then the remembered tab', () => {
		window.localStorage.setItem( 'happyaccess.tab', 'settings' );
		const { unmount } = render( <App boot={ boot() } /> );
		expect(
			screen.getByRole( 'link', { name: 'Settings' } )
		).toHaveAttribute( 'aria-current', 'page' );
		unmount();

		window.history.replaceState(
			{},
			'',
			'/wp-admin/users.php?page=happyaccess&tab=activity'
		);
		render( <App boot={ boot() } /> );
		expect(
			screen.getByRole( 'link', { name: 'Activity' } )
		).toHaveAttribute( 'aria-current', 'page' );
	} );

	it( 'ignores a tab that is not shown', () => {
		window.history.replaceState(
			{},
			'',
			'/wp-admin/users.php?page=happyaccess&tab=login'
		);
		render( <App boot={ boot() } /> );

		expect(
			screen.getByRole( 'link', { name: 'Support access' } )
		).toHaveAttribute( 'aria-current', 'page' );
	} );

	it( 'still works when storage is blocked', async () => {
		const user = userEvent.setup();
		const blocked = vi
			.spyOn( Storage.prototype, 'setItem' )
			.mockImplementation( () => {
				throw new Error( 'blocked' );
			} );
		render( <App boot={ boot() } /> );

		await user.click( screen.getByRole( 'link', { name: 'Settings' } ) );

		expect(
			screen.getByRole( 'link', { name: 'Settings' } )
		).toHaveAttribute( 'aria-current', 'page' );
		blocked.mockRestore();
	} );

	it( 'shows setup instead of the tabs when setup is needed', () => {
		render( <App boot={ boot( { needsSetup: true } ) } /> );

		expect(
			screen.getByRole( 'heading', {
				level: 2,
				name: 'Set up HappyAccess',
			} )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'navigation', { name: 'HappyAccess sections' } )
		).not.toBeInTheDocument();
	} );

	it( 'opens the Activity tab for one pass, with its id as the token arg', async () => {
		grantsOnServer = [ grantFixture( { id: 9 } ) ];
		const user = userEvent.setup();
		render( <App boot={ boot() } /> );

		await user.click(
			await screen.findByRole( 'link', { name: 'View activity' } )
		);

		expect(
			screen.getByRole( 'link', { name: 'Activity' } )
		).toHaveAttribute( 'aria-current', 'page' );
		// The Activity tab picks that pass, then takes the token out of the URL.
		expect( await screen.findByLabelText( 'Who' ) ).toHaveDisplayValue(
			'Acme Plugin Support'
		);
		expect(
			apiFetch.mock.calls.some(
				( [ options ] ) =>
					options.path.startsWith( '/happyaccess/v1/activity?' ) &&
					options.path.includes( 'token_id=9' )
			)
		).toBe( true );
		const params = new URLSearchParams( window.location.search );
		expect( params.get( 'tab' ) ).toBe( 'activity' );
		expect( params.has( 'token' ) ).toBe( false );
		expect( params.get( 'page' ) ).toBe( 'happyaccess' );
	} );

	it( 'ends every pass from the header and refreshes the list', async () => {
		grantsOnServer = [ grantFixture( { id: 9 } ) ];
		const user = userEvent.setup();
		render( <App boot={ boot() } /> );
		expect(
			await screen.findByRole( 'heading', { name: 'Who has access' } )
		).toBeInTheDocument();
		await screen.findByText( 'Acme Plugin Support' );
		const grantGets = () =>
			apiFetch.mock.calls.filter(
				( [ options ] ) =>
					'/happyaccess/v1/grants' === options.path &&
					( ! options.method || 'GET' === options.method )
			).length;
		const before = grantGets();

		await user.click(
			screen.getByRole( 'button', { name: 'Emergency lock' } )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'End all passes' } )
		);

		expect(
			await screen.findAllByText( 'Nobody has access.' )
		).toHaveLength( 1 );
		expect(
			screen.queryByText( 'Acme Plugin Support' )
		).not.toBeInTheDocument();
		expect( grantGets() ).toBe( before + 1 );
	} );

	it( 'drops ended passes from the Activity Who list after Emergency lock', async () => {
		grantsOnServer = [ grantFixture( { id: 9 } ) ];
		window.history.replaceState(
			{},
			'',
			'/wp-admin/users.php?page=happyaccess&tab=activity'
		);
		const user = userEvent.setup();
		render( <App boot={ boot() } /> );
		const who = await screen.findByLabelText( 'Who' );
		await waitFor( () =>
			expect(
				within( who ).getByRole( 'option', {
					name: 'Acme Plugin Support',
				} )
			).toBeInTheDocument()
		);

		await user.click(
			screen.getByRole( 'button', { name: 'Emergency lock' } )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'End all passes' } )
		);

		await waitFor( () =>
			expect(
				within( who ).queryByRole( 'option', {
					name: 'Acme Plugin Support',
				} )
			).not.toBeInTheDocument()
		);
	} );

	it( 'walks through setup, then shows the tabs on Support access without a reload', async () => {
		const user = userEvent.setup();
		render(
			<App
				boot={ boot( {
					needsSetup: true,
					features: { support_access: true },
				} ) }
			/>
		);
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		await user.click(
			screen.getByRole( 'checkbox', {
				name: "I understand, and I'll only give access to people I trust.",
			} )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Finish setup' } )
		);
		await screen.findByRole( 'heading', { name: "You're all set" } );
		expect(
			screen.queryByRole( 'navigation', { name: 'HappyAccess sections' } )
		).not.toBeInTheDocument();

		await user.click(
			screen.getByRole( 'button', {
				name: 'Create your first support pass',
			} )
		);

		expect( tabNames() ).toEqual( [
			'Support access',
			'Activity',
			'Settings',
		] );
		expect(
			screen.getByRole( 'link', { name: 'Support access' } )
		).toHaveAttribute( 'aria-current', 'page' );
		expect(
			await screen.findByRole( 'heading', {
				name: 'Give support access',
			} )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'heading', { name: 'Set up HappyAccess' } )
		).not.toBeInTheDocument();
		// Setup is gone, so focus goes to the first field of the form.
		await waitFor( () =>
			expect(
				screen.getByRole( 'textbox', { name: 'Who is it for' } )
			).toHaveFocus()
		);

		// Leaving the tab and coming back does not pull focus into the form again.
		await user.click( screen.getByRole( 'link', { name: 'Settings' } ) );
		await user.click(
			screen.getByRole( 'link', { name: 'Support access' } )
		);
		expect(
			screen.getByRole( 'textbox', { name: 'Who is it for' } )
		).not.toHaveFocus();
	} );

	it( 'drops the Support access tab and updates the boot data when the feature is switched off', async () => {
		window.happyaccessBoot = { features: { support_access: true } };
		window.localStorage.setItem( 'happyaccess.tab', 'settings' );
		const user = userEvent.setup();
		render(
			<App
				boot={ boot( { features: window.happyaccessBoot.features } ) }
			/>
		);
		await user.click(
			await screen.findByRole( 'switch', { name: 'Support access' } )
		);
		await user.click( screen.getByRole( 'button', { name: 'Turn off' } ) );

		await waitFor( () =>
			expect( tabNames() ).toEqual( [ 'Activity', 'Settings' ] )
		);
		expect( window.happyaccessBoot.features.support_access ).toBe( false );
		expect(
			screen.getByRole( 'link', { name: 'Settings' } )
		).toHaveAttribute( 'aria-current', 'page' );
		expect(
			screen.queryByText( 'Have a support access code?' )
		).not.toBeInTheDocument();
		delete window.happyaccessBoot;
	} );

	it( 'creates the printed boot features when the page had none', async () => {
		delete window.happyaccessBoot;
		window.localStorage.setItem( 'happyaccess.tab', 'settings' );
		const user = userEvent.setup();
		render( <App boot={ boot() } /> );
		await user.click(
			await screen.findByRole( 'switch', { name: 'Support access' } )
		);
		await user.click( screen.getByRole( 'button', { name: 'Turn off' } ) );

		await waitFor( () =>
			expect( window.happyaccessBoot.features ).toMatchObject( {
				support_access: false,
			} )
		);
		delete window.happyaccessBoot;
	} );

	it( 'has one polite live region', () => {
		const { container } = render( <App boot={ boot() } /> );

		const regions = container.querySelectorAll( '[aria-live]' );
		expect( regions ).toHaveLength( 1 );
		expect( regions[ 0 ] ).toHaveAttribute( 'aria-live', 'polite' );
	} );

	it( 'has no accessibility violations', async () => {
		const { container } = render( <App boot={ boot() } /> );
		expect( await axe( container ) ).toHaveNoViolations();
	} );

	it( 'has no accessibility violations on the Settings tab', async () => {
		window.localStorage.setItem( 'happyaccess.tab', 'settings' );
		const { container } = render( <App boot={ boot() } /> );
		await screen.findByRole( 'heading', { name: 'Safety and privacy' } );
		expect( await axe( container ) ).toHaveNoViolations();
	} );

	it( 'has no accessibility violations on the Login tab', async () => {
		window.localStorage.setItem( 'happyaccess.tab', 'login' );
		const { container } = render(
			<App
				boot={ boot( {
					loginReady: true,
					woocommerce: true,
					loginRoles: [
						{
							slug: 'administrator',
							name: 'Administrator',
							isAdmin: true,
						},
						{ slug: 'editor', name: 'Editor', isAdmin: false },
					],
					features: { support_access: true, passwordless: true },
				} ) }
			/>
		);
		await screen.findByRole( 'heading', { name: 'Passwordless login' } );
		expect( await axe( container ) ).toHaveNoViolations();
	} );

	it( 'has no accessibility violations on the setup gate', async () => {
		const { container } = render(
			<App boot={ boot( { needsSetup: true } ) } />
		);
		expect( await axe( container ) ).toHaveNoViolations();
	} );
} );

describe( 'data kept between tabs', () => {
	// GET requests so far, by path without the namespace.
	const gets = ( route ) =>
		apiFetch.mock.calls.filter(
			( [ options ] ) =>
				( ! options.method || 'GET' === options.method ) &&
				options.path.replace( '/happyaccess/v1', '' ) === route
		).length;
	const goTo = ( user, name ) =>
		user.click( screen.getByRole( 'link', { name } ) );

	it( 'does not fetch the passes, settings or catalog again when tabs change', async () => {
		grantsOnServer = [ grantFixture( { id: 9 } ) ];
		const user = userEvent.setup();
		render( <App boot={ boot() } /> );
		await screen.findByText( 'Acme Plugin Support' );

		await goTo( user, 'Settings' );
		await screen.findByRole( 'heading', { name: 'Safety and privacy' } );
		await goTo( user, 'Support access' );
		await screen.findByText( 'Acme Plugin Support' );
		await goTo( user, 'Activity' );
		await screen.findByText( /^Showing 0 of 0 events/ );
		await goTo( user, 'Support access' );
		await goTo( user, 'Settings' );
		await screen.findByRole( 'heading', { name: 'Safety and privacy' } );
		await goTo( user, 'Support access' );
		await screen.findByText( 'Acme Plugin Support' );

		expect( gets( '/grants' ) ).toBe( 1 );
		expect( gets( '/settings' ) ).toBe( 1 );
		expect( gets( '/catalog' ) ).toBe( 1 );
	} );

	it( 'opens the Login tab from the settings already loaded, with no second fetch', async () => {
		const user = userEvent.setup();
		render(
			<App
				boot={ boot( {
					loginReady: true,
					woocommerce: true,
					loginRoles: [],
					features: { support_access: true, passwordless: true },
				} ) }
			/>
		);
		await goTo( user, 'Settings' );
		await screen.findByRole( 'heading', { name: 'Safety and privacy' } );

		await goTo( user, 'Login' );

		// Shown at once, with no loading line in between.
		expect(
			screen.getByRole( 'heading', { name: 'Passwordless login' } )
		).toBeInTheDocument();
		expect( screen.queryByText( 'Loading settings' ) ).toBeNull();
		await goTo( user, 'Support access' );
		await goTo( user, 'Login' );
		expect( gets( '/settings' ) ).toBe( 1 );
	} );

	it( 'does not fetch the first Activity page or the latest line again within seconds', async () => {
		const user = userEvent.setup();
		render( <App boot={ boot() } /> );
		await screen.findByRole( 'heading', { name: 'Who has access' } );

		await goTo( user, 'Activity' );
		await screen.findByText( /^Showing 0 of 0 events/ );
		await goTo( user, 'Support access' );
		await goTo( user, 'Activity' );
		await goTo( user, 'Support access' );

		const activityGets = apiFetch.mock.calls
			.map( ( [ options ] ) => options.path )
			.filter( ( path ) =>
				path.startsWith( '/happyaccess/v1/activity?' )
			);
		// One for the first page, shared by the app and the tab, and one for the Support footer.
		expect( activityGets ).toHaveLength( 2 );
	} );

	it( 'keeps secrets out of the shared data: a pass made earlier shows no code after a tab switch', async () => {
		const user = userEvent.setup();
		apiFetch.mockImplementation( async ( options ) => {
			if (
				'POST' === options.method &&
				'/happyaccess/v1/grants' === options.path
			) {
				grantsOnServer = [
					grantFixture( { id: 12, label: 'Vendor' } ),
				];
				return {
					...grantsOnServer[ 0 ],
					code: '4829 1375',
					link_url: 'https://yourstore.test/wp-login.php?k=Qm3x',
					code_url: 'https://yourstore.test/wp-login.php?step=code',
					message: 'Here is temporary access.',
					emailed: false,
				};
			}
			return { items: grantsOnServer, groups: [], presets: {} };
		} );
		render( <App boot={ boot() } /> );
		await user.type(
			await screen.findByLabelText( 'Who is it for' ),
			'Vendor'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Create support pass' } )
		);
		await screen.findByRole( 'heading', {
			name: 'Access is ready for Vendor',
		} );
		expect( screen.getByText( '4829 1375' ) ).toBeInTheDocument();

		await goTo( user, 'Settings' );
		await goTo( user, 'Support access' );

		expect( await screen.findAllByText( 'Vendor' ) ).not.toHaveLength( 0 );
		expect( screen.queryByText( '4829 1375' ) ).not.toBeInTheDocument();
		expect( document.body.textContent ).not.toContain( 'Qm3x' );
	} );
} );

describe( 'readBoot', () => {
	it( 'warns in the console when the page printed no boot data', () => {
		const warn = vi.spyOn( console, 'warn' ).mockImplementation( () => {} );
		expect( readBoot( {} ) ).toBeUndefined();
		expect( warn ).toHaveBeenCalledWith(
			expect.stringContaining( 'happyaccessBoot' )
		);
		warn.mockRestore();
	} );

	it( 'returns the boot data without a warning when it is there', () => {
		const warn = vi.spyOn( console, 'warn' ).mockImplementation( () => {} );
		const printed = { features: {} };
		expect( readBoot( { happyaccessBoot: printed } ) ).toBe( printed );
		expect( warn ).not.toHaveBeenCalled();
		warn.mockRestore();
	} );
} );
