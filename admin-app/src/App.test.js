import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import App from './App';

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

	it( 'has no accessibility violations on the setup gate', async () => {
		const { container } = render(
			<App boot={ boot( { needsSetup: true } ) } />
		);
		expect( await axe( container ) ).toHaveNoViolations();
	} );
} );
