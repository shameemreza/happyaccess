import { describe, expect, it } from 'vitest';
import { getVisibleTabs, resolveCurrentTab } from './tabList';

const ON = { features: { support_access: true, passwordless: true } };
const OFF = { features: { support_access: true, passwordless: false } };

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

	it( 'falls back to the first tab for any other tab that is gone', () => {
		const tabs = getVisibleTabs( {
			features: { support_access: false },
			loginReady: true,
		} );

		expect( resolveCurrentTab( tabs, 'support' ) ).toBe( 'activity' );
	} );
} );
