import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import apiFetch from '@wordpress/api-fetch';
import { AnnounceProvider } from '../Announcer';
import DataProvider from '../data/DataProvider';
import { settingsFixture } from '../settings/fixtures';
import TwoStepSection from './TwoStepSection';

vi.mock( '@wordpress/api-fetch' );

let server;
let posts;

const twoStep = ( overrides = {} ) => ( {
	role_policy: {},
	grace_type: 'logins',
	grace_logins: 3,
	grace_days: 7,
	block_xmlrpc: true,
	...overrides,
} );

// Merges like array_replace_recursive, which the route uses.
function deepMerge( base, patch ) {
	const out = { ...base };
	Object.entries( patch ).forEach( ( [ key, value ] ) => {
		out[ key ] =
			value && 'object' === typeof value && ! Array.isArray( value )
				? deepMerge( base?.[ key ] || {}, value )
				: value;
	} );
	return out;
}

function mockServer( initial ) {
	server = initial;
	posts = [];
	apiFetch.mockReset();
	apiFetch.mockImplementation( async ( { path, method, data } ) => {
		if ( '/happyaccess/v1/settings' !== path ) {
			return { items: [], groups: [], presets: {} };
		}
		if ( 'POST' !== method ) {
			return server;
		}
		posts.push( data );
		server = deepMerge( server, data );
		return server;
	} );
}

const ROLES = [
	{ slug: 'administrator', name: 'Administrator', isAdmin: true },
	{ slug: 'editor', name: 'Editor', isAdmin: false },
	{ slug: 'customer', name: 'Customer', isAdmin: false },
];

const boot = ( overrides = {} ) => ( {
	features: { support_access: true, passwordless: false, two_step: true },
	loginRoles: ROLES,
	otherTwoStep: [],
	...overrides,
} );

const liveText = () =>
	document
		.querySelector( '.screen-reader-text[aria-live="polite"]' )
		.textContent.trim();

const RECOVERY =
	'If someone is locked out, turn it off on their profile, run wp happyaccess twostep reset <user>, or add this line to wp-config.php:';
const DISABLE_LINE = "define( 'HAPPYACCESS_DISABLE_TWOSTEP', true );";
const REQUIRED_NOTE =
	'People in these roles set up two-step login the next time they log in.';

async function renderSection( bootData = boot() ) {
	const view = render(
		<AnnounceProvider>
			<DataProvider>
				<TwoStepSection boot={ bootData } />
			</DataProvider>
		</AnnounceProvider>
	);
	await screen.findByRole( 'heading', { name: 'Two-step login' } );
	return view;
}

const saveButton = () => screen.getByRole( 'button', { name: 'Save changes' } );
// The alert checkboxes are named by role too, so the selects are found in their own group.
const roleSelect = ( name ) =>
	within(
		screen.getByRole( 'group', {
			name: 'How each role uses two-step login',
		} )
	).getByLabelText( name );
const alertGroup = () =>
	screen.getByRole( 'group', { name: 'New device alerts' } );
const alertBox = ( name ) =>
	within( alertGroup() ).getByRole( 'checkbox', { name } );
const grace = () => screen.getByLabelText( 'Grace period for required roles' );

beforeEach( () => {
	mockServer( settingsFixture( { two_step: twoStep() } ) );
} );

describe( 'Two-step section', () => {
	it( 'shows a row per role with off, optional and required, and a missing role is optional', async () => {
		mockServer(
			settingsFixture( {
				two_step: twoStep( {
					role_policy: { administrator: 'required', editor: 'off' },
				} ),
			} )
		);
		await renderSection();

		const roles = screen.getByRole( 'group', {
			name: 'How each role uses two-step login',
		} );
		expect( within( roles ).getAllByRole( 'listitem' ) ).toHaveLength( 3 );
		expect(
			within( within( roles ).getByLabelText( 'Customer' ) )
				.getAllByRole( 'option' )
				.map( ( option ) => option.textContent )
		).toEqual( [ 'Off', 'Optional', 'Required' ] );
		expect(
			within( roles ).getByLabelText( 'Administrator' )
		).toHaveDisplayValue( 'Required' );
		expect( within( roles ).getByLabelText( 'Editor' ) ).toHaveDisplayValue(
			'Off'
		);
		expect(
			within( roles ).getByLabelText( 'Customer' )
		).toHaveDisplayValue( 'Optional' );
	} );

	it( 'saves the roles as a flat map of the changed ones', async () => {
		const user = userEvent.setup();
		await renderSection();

		await user.selectOptions( roleSelect( 'Editor' ), 'Required' );
		await user.selectOptions( roleSelect( 'Administrator' ), 'Off' );
		await user.click( saveButton() );

		await waitFor( () => expect( liveText() ).toBe( 'Settings saved' ) );
		expect( posts ).toEqual( [
			{
				two_step: {
					role_policy: { editor: 'required', administrator: 'off' },
				},
			},
		] );
		expect( screen.getByText( 'Saved' ) ).toBeInTheDocument();
		expect( roleSelect( 'Editor' ) ).toHaveDisplayValue( 'Required' );
	} );

	it( 'ticks only Administrator for new device alerts until the list is saved', async () => {
		await renderSection();

		expect(
			within( alertGroup() ).getByText(
				'Email a user when their account logs in from a new device'
			)
		).toBeInTheDocument();
		expect( alertBox( 'Administrator' ) ).toBeChecked();
		expect( alertBox( 'Editor' ) ).not.toBeChecked();
		expect( alertBox( 'Customer' ) ).not.toBeChecked();
		expect(
			screen.getByText(
				'The email shows the time, browser and IP address.'
			)
		).toBeInTheDocument();
	} );

	it( 'shows the saved alert roles, and an empty list ticks nothing', async () => {
		mockServer(
			settingsFixture( {
				two_step: twoStep( { device_alert_roles: [ 'editor' ] } ),
			} )
		);
		const shown = await renderSection();
		expect( alertBox( 'Administrator' ) ).not.toBeChecked();
		expect( alertBox( 'Editor' ) ).toBeChecked();
		shown.unmount();

		mockServer(
			settingsFixture( {
				two_step: twoStep( { device_alert_roles: [] } ),
			} )
		);
		await renderSection();
		expect(
			within( alertGroup() ).queryAllByRole( 'checkbox', {
				checked: true,
			} )
		).toHaveLength( 0 );
	} );

	it( 'saves the alert roles as the whole list', async () => {
		const user = userEvent.setup();
		await renderSection();

		await user.click( alertBox( 'Editor' ) );
		expect( screen.getByText( 'Unsaved changes' ) ).toBeInTheDocument();
		await user.click( saveButton() );

		await waitFor( () => expect( liveText() ).toBe( 'Settings saved' ) );
		expect( posts ).toEqual( [
			{ two_step: { device_alert_roles: [ 'administrator', 'editor' ] } },
		] );
		expect( alertBox( 'Editor' ) ).toBeChecked();
		expect( screen.getByText( 'Saved' ) ).toBeInTheDocument();
	} );

	it( 'saves an empty list when every role is unticked', async () => {
		const user = userEvent.setup();
		await renderSection();

		await user.click( alertBox( 'Administrator' ) );
		await user.click( saveButton() );

		await waitFor( () => expect( posts ).toHaveLength( 1 ) );
		expect( posts[ 0 ] ).toEqual( {
			two_step: { device_alert_roles: [] },
		} );
		expect( alertBox( 'Administrator' ) ).not.toBeChecked();
	} );

	it( 'has nothing to save after a role is ticked and unticked again', async () => {
		const user = userEvent.setup();
		await renderSection();

		await user.click( alertBox( 'Customer' ) );
		expect( saveButton() ).not.toHaveAttribute( 'aria-disabled', 'true' );
		await user.click( alertBox( 'Customer' ) );

		expect( saveButton() ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( screen.queryByText( 'Unsaved changes' ) ).toBeNull();
	} );

	it( 'offers four grace periods, 3 logins by default', async () => {
		await renderSection();

		expect(
			within( grace() )
				.getAllByRole( 'option' )
				.map( ( option ) => option.textContent )
		).toEqual( [ '3 logins', '5 logins', '7 days', '14 days' ] );
		expect( grace() ).toHaveDisplayValue( '3 logins' );
	} );

	it.each( [
		[ '5 logins', twoStep(), { grace_type: 'logins', grace_logins: 5 } ],
		[ '7 days', twoStep(), { grace_type: 'days', grace_days: 7 } ],
		[ '14 days', twoStep(), { grace_type: 'days', grace_days: 14 } ],
		[
			'3 logins',
			twoStep( { grace_type: 'days', grace_days: 14 } ),
			{ grace_type: 'logins', grace_logins: 3 },
		],
	] )( 'maps "%s" to its settings', async ( label, saved, expected ) => {
		mockServer( settingsFixture( { two_step: saved } ) );
		const user = userEvent.setup();
		await renderSection();

		await user.selectOptions( grace(), label );
		await user.click( saveButton() );

		await waitFor( () => expect( posts ).toHaveLength( 1 ) );
		expect( posts[ 0 ] ).toEqual( { two_step: expected } );
		expect( grace() ).toHaveDisplayValue( label );
	} );

	it( 'keeps a saved grace period that is none of the four as Custom', async () => {
		mockServer(
			settingsFixture( {
				two_step: twoStep( { grace_type: 'logins', grace_logins: 4 } ),
			} )
		);
		const first = await renderSection();
		expect( grace() ).toHaveDisplayValue( 'Custom (4 logins)' );
		first.unmount();

		mockServer(
			settingsFixture( {
				two_step: twoStep( { grace_type: 'days', grace_days: 10 } ),
			} )
		);
		await renderSection();
		expect( grace() ).toHaveDisplayValue( 'Custom (10 days)' );
	} );

	it( 'drops a grace period put back to the saved one', async () => {
		const user = userEvent.setup();
		await renderSection();

		await user.selectOptions( grace(), '14 days' );
		expect( saveButton() ).not.toHaveAttribute( 'aria-disabled', 'true' );
		await user.selectOptions( grace(), '3 logins' );
		expect( saveButton() ).toHaveAttribute( 'aria-disabled', 'true' );
	} );

	it( 'blocks XML-RPC by default and saves the switch', async () => {
		const user = userEvent.setup();
		await renderSection();
		const toggle = screen.getByRole( 'switch', {
			name: 'Block XML-RPC login for accounts with two-step login',
		} );
		expect( toggle ).toBeChecked();

		await user.click( toggle );
		await user.click( saveButton() );

		await waitFor( () => expect( posts ).toHaveLength( 1 ) );
		expect( posts[ 0 ] ).toEqual( { two_step: { block_xmlrpc: false } } );
	} );

	it( 'says required roles set up at their next login, only while a role is required', async () => {
		const user = userEvent.setup();
		await renderSection();
		expect( screen.queryByText( REQUIRED_NOTE ) ).toBeNull();

		await user.selectOptions( roleSelect( 'Editor' ), 'Required' );
		expect( screen.getByText( REQUIRED_NOTE ) ).toBeInTheDocument();

		await user.selectOptions( roleSelect( 'Editor' ), 'Optional' );
		expect( screen.queryByText( REQUIRED_NOTE ) ).toBeNull();
	} );

	it( 'always shows how to get a locked-out person back in', async () => {
		await renderSection();

		const note = screen.getByText( /If someone is locked out/ );
		expect( note.textContent ).toBe( RECOVERY );
		expect(
			within( note )
				.getAllByRole( 'code' )
				.map( ( code ) => code.textContent )
		).toEqual( [ 'wp happyaccess twostep reset <user>' ] );
	} );

	it( 'puts the wp-config line on its own line, where it can scroll', async () => {
		await renderSection();

		const note = screen.getByText( /If someone is locked out/ );
		const line = note.nextElementSibling;
		expect( line.tagName ).toBe( 'CODE' );
		expect( line ).toHaveClass( 'ha-login__line' );
		expect( line.textContent ).toBe( DISABLE_LINE );
		// It scrolls sideways on a narrow screen, so the keyboard can reach it.
		expect( line ).toHaveAttribute( 'tabindex', '0' );
	} );

	it( 'names another two-step plugin when one is active', async () => {
		const shown = await renderSection(
			boot( { otherTwoStep: [ 'WP 2FA' ] } )
		);
		expect(
			within( shown.container ).getByText(
				'WP 2FA already adds two-step login. HappyAccess skips accounts that use it, so nobody is asked twice.'
			)
		).toBeInTheDocument();
		shown.unmount();

		const both = await renderSection(
			boot( { otherTwoStep: [ 'WP 2FA', 'Kadence Security' ] } )
		);
		expect(
			within( both.container ).getByText(
				'WP 2FA and Kadence Security already add two-step login. HappyAccess skips accounts that use them, so nobody is asked twice.'
			)
		).toBeInTheDocument();
		both.unmount();

		const none = await renderSection();
		expect(
			within( none.container ).queryByText( /already add/ )
		).not.toBeInTheDocument();
	} );

	it( 'has no accessibility violations, with the notes showing', async () => {
		mockServer(
			settingsFixture( {
				two_step: twoStep( { role_policy: { editor: 'required' } } ),
			} )
		);
		const { container } = await renderSection(
			boot( { otherTwoStep: [ 'WP 2FA' ] } )
		);
		expect( screen.getByText( REQUIRED_NOTE ) ).toBeInTheDocument();

		expect( await axe( container ) ).toHaveNoViolations();
	} );
} );
