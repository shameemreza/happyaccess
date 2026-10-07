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

		await user.click(
			screen.getByRole( 'button', { name: 'Emergency lock' } )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'End all passes' } )
		);

		expect(
			await screen.findAllByText( 'Nobody has access.' )
		).toHaveLength( 2 );
		expect(
			screen.queryByText( 'Acme Plugin Support' )
		).not.toBeInTheDocument();
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

	it( 'has no accessibility violations on the setup gate', async () => {
		const { container } = render(
			<App boot={ boot( { needsSetup: true } ) } />
		);
		expect( await axe( container ) ).toHaveNoViolations();
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
