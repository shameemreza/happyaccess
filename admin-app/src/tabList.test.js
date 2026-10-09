import { describe, expect, it } from 'vitest';
import { getTabs, getVisibleTabs, resolveCurrentTab } from './tabList';

const ON = { features: { support_access: true, passwordless: true } };
const OFF = { features: { support_access: true, passwordless: false } };

describe( 'getTabs', () => {
	it( 'names the tabs Temporary access and Login and security, with the slugs unchanged', () => {
		expect(
			getTabs().map( ( { slug, label } ) => [ slug, label ] )
		).toEqual( [
			[ 'support', 'Temporary access' ],
			[ 'activity', 'Activity' ],
			[ 'login', 'Login and security' ],
			[ 'settings', 'Settings' ],
		] );
	} );
} );

describe( 'resolveCurrentTab', () => {
	it( 'keeps the current tab while it is shown', () => {
		const tabs = getVisibleTabs( { ...ON, loginReady: true } );

		expect( resolveCurrentTab( tabs, 'login' ) ).toBe( 'login' );
		expect( resolveCurrentTab( tabs, 'activity' ) ).toBe( 'activity' );
	} );

	it( 'moves from a Login tab that just went away to Settings', () => {
		const tabs = getVisibleTabs( { ...OFF, loginReady: true } );

		expect( resolveCurrentTab( tabs, 'login' ) ).toBe( 'settings' );
	} );

	it( 'shows the Login tab while either login feature is on, and hides it when both are off', () => {
		const slugs = ( features ) =>
			getVisibleTabs( { features, loginReady: true } ).map(
				( tab ) => tab.slug
			);

		expect(
			slugs( {
				support_access: true,
				passwordless: false,
				two_step: true,
			} )
		).toContain( 'login' );
		expect(
			slugs( {
				support_access: true,
				passwordless: false,
				two_step: false,
			} )
		).not.toContain( 'login' );
	} );

	it( 'falls back to the first tab for any other tab that is gone', () => {
		const tabs = getVisibleTabs( {
			features: { support_access: false },
			loginReady: true,
		} );

		expect( resolveCurrentTab( tabs, 'support' ) ).toBe( 'activity' );
	} );
} );
