import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import apiFetch from '@wordpress/api-fetch';
import { AnnounceProvider } from '../Announcer';
import DataProvider from '../data/DataProvider';
import { settingsFixture } from '../settings/fixtures';
import LoginTab from './LoginTab';

vi.mock( '@wordpress/api-fetch' );

let server;
let posts;

const passwordless = ( overrides = {} ) => ( {
	code_lifetime: 600,
	show_on: { wp_login: true, woo_account: true, woo_checkout: true },
	role_policy: {},
	toggle_style: 'link',
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
	{ slug: 'shop_manager', name: 'Shop manager', isAdmin: false },
];

const boot = ( overrides = {} ) => ( {
	features: { support_access: true, passwordless: true, two_step: false },
	woocommerce: true,
	roles: ROLES,
	...overrides,
} );

const liveText = () =>
	document
		.querySelector( '.screen-reader-text[aria-live="polite"]' )
		.textContent.trim();

const saveButton = () => screen.getByRole( 'button', { name: 'Save changes' } );

const WARNING =
	'Anyone who can read this email inbox can log in as this role. If email stops working, add HAPPYACCESS_ALLOW_PASSWORD_LOGIN to wp-config.php to get back in.';

async function renderTab( bootData = boot() ) {
	const view = render(
		<AnnounceProvider>
			<DataProvider>
				<LoginTab boot={ bootData } />
			</DataProvider>
		</AnnounceProvider>
	);
	await screen.findByRole( 'heading', { name: 'Passwordless login' } );
	return view;
}

beforeEach( () => {
	mockServer( settingsFixture( { passwordless: passwordless() } ) );
} );

describe( 'Login tab', () => {
	it( 'shows the saved values', async () => {
		mockServer(
			settingsFixture( {
				passwordless: passwordless( {
					code_lifetime: 900,
					toggle_style: 'button',
					show_on: {
						wp_login: true,
						woo_account: false,
						woo_checkout: true,
					},
					role_policy: { editor: 'email_only' },
				} ),
			} )
		);
		await renderTab();

		expect( screen.getByLabelText( 'Code lifetime' ) ).toHaveDisplayValue(
			'15 minutes'
		);
		expect( screen.getByLabelText( 'Button style' ) ).toHaveDisplayValue(
			'Full button'
		);
		expect(
			screen.getByText(
				"How the 'Email me a login code instead' option looks on WooCommerce forms, the shortcode and the block."
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'switch', { name: 'WordPress login page' } )
		).toBeChecked();
		expect(
			screen.getByRole( 'switch', { name: 'WooCommerce My Account' } )
		).not.toBeChecked();
		expect(
			screen.getByRole( 'switch', { name: 'WooCommerce checkout' } )
		).toBeChecked();
		expect( screen.getByLabelText( 'Editor' ) ).toHaveDisplayValue(
			'Email code only'
		);
		expect( screen.getByLabelText( 'Administrator' ) ).toHaveDisplayValue(
			'Password or email code'
		);
	} );

	it( 'offers 5, 10, 15 and 30 minutes, and keeps a saved value that is none of them', async () => {
		mockServer(
			settingsFixture( {
				passwordless: passwordless( { code_lifetime: 1200 } ),
			} )
		);
		await renderTab();

		const options = within(
			screen.getByLabelText( 'Code lifetime' )
		).getAllByRole( 'option' );
		expect( options.map( ( option ) => option.textContent ) ).toEqual( [
			'5 minutes',
			'10 minutes',
			'15 minutes',
			'30 minutes',
			'20 minutes',
		] );
		expect( screen.getByLabelText( 'Code lifetime' ) ).toHaveDisplayValue(
			'20 minutes'
		);
	} );

	it( 'hides the WooCommerce rows without WooCommerce', async () => {
		await renderTab( boot( { woocommerce: false } ) );

		expect(
			screen.getByRole( 'switch', { name: 'WordPress login page' } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'switch', { name: 'WooCommerce My Account' } )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'switch', { name: 'WooCommerce checkout' } )
		).not.toBeInTheDocument();
	} );

	it( 'shows the admin warning only for a manage_options role set to email code only', async () => {
		const user = userEvent.setup();
		await renderTab();
		expect( screen.queryByText( /Anyone who can read/ ) ).toBeNull();

		await user.selectOptions(
			screen.getByLabelText( 'Editor' ),
			'email_only'
		);
		expect( screen.queryByText( /Anyone who can read/ ) ).toBeNull();

		await user.selectOptions(
			screen.getByLabelText( 'Administrator' ),
			'email_only'
		);
		const warning = screen.getByText( /Anyone who can read/ );
		expect( warning.textContent ).toBe( WARNING );
		expect(
			within( warning ).getByText( 'HAPPYACCESS_ALLOW_PASSWORD_LOGIN' )
				.tagName
		).toBe( 'CODE' );
		expect(
			screen.getByLabelText( 'Administrator' )
		).toHaveAccessibleDescription( WARNING );

		await user.selectOptions(
			screen.getByLabelText( 'Administrator' ),
			'either'
		);
		expect( screen.queryByText( /Anyone who can read/ ) ).toBeNull();
	} );

	it( 'saves the passwordless group with only the changed keys', async () => {
		const user = userEvent.setup();
		await renderTab();
		expect( saveButton() ).toHaveAttribute( 'aria-disabled', 'true' );

		await user.selectOptions(
			screen.getByLabelText( 'Code lifetime' ),
			'30 minutes'
		);
		await user.selectOptions(
			screen.getByLabelText( 'Button style' ),
			'Full button'
		);
		await user.click(
			screen.getByRole( 'switch', { name: 'WooCommerce checkout' } )
		);
		await user.selectOptions(
			screen.getByLabelText( 'Shop manager' ),
			'email_only'
		);
		expect( screen.getByText( 'Unsaved changes' ) ).toBeInTheDocument();

		await user.click( saveButton() );

		await waitFor( () => expect( liveText() ).toBe( 'Settings saved' ) );
		expect( posts ).toEqual( [
			{
				passwordless: {
					code_lifetime: 1800,
					toggle_style: 'button',
					show_on: { woo_checkout: false },
					role_policy: { shop_manager: 'email_only' },
				},
			},
		] );
		expect( screen.getByText( 'Saved' ) ).toBeInTheDocument();
		expect( saveButton() ).toHaveAttribute( 'aria-disabled', 'true' );
		expect(
			screen.getByRole( 'switch', { name: 'WooCommerce checkout' } )
		).not.toBeChecked();
	} );

	it( 'saves either when a role goes back to password or email code', async () => {
		mockServer(
			settingsFixture( {
				passwordless: passwordless( {
					role_policy: { editor: 'email_only' },
				} ),
			} )
		);
		const user = userEvent.setup();
		await renderTab();

		await user.selectOptions( screen.getByLabelText( 'Editor' ), 'either' );
		await user.click( saveButton() );

		await waitFor( () => expect( liveText() ).toBe( 'Settings saved' ) );
		expect( posts ).toEqual( [
			{ passwordless: { role_policy: { editor: 'either' } } },
		] );
	} );

	it( 'drops a field that is put back to its saved value', async () => {
		const user = userEvent.setup();
		await renderTab();
		const lifetime = screen.getByLabelText( 'Code lifetime' );

		await user.selectOptions( lifetime, '300' );
		expect( saveButton() ).not.toHaveAttribute( 'aria-disabled', 'true' );
		await user.selectOptions( lifetime, '600' );

		expect( saveButton() ).toHaveAttribute( 'aria-disabled', 'true' );
	} );

	it( 'shows the error from a failed save and keeps the edits', async () => {
		const user = userEvent.setup();
		await renderTab();
		const answer = apiFetch.getMockImplementation();
		apiFetch.mockImplementation( ( request ) =>
			'POST' === request.method
				? Promise.reject( {
						code: 'happyaccess_settings_not_saved',
						message:
							'Settings could not be saved. Please try again.',
						data: { status: 500 },
					} )
				: answer( request )
		);

		await user.selectOptions(
			screen.getByLabelText( 'Code lifetime' ),
			'300'
		);
		await user.click( saveButton() );

		expect(
			(
				await screen.findAllByText(
					'Settings could not be saved. Please try again.'
				)
			).length
		).toBeGreaterThan( 0 );
		expect( screen.getByLabelText( 'Code lifetime' ) ).toHaveDisplayValue(
			'5 minutes'
		);
		expect( screen.getByText( 'Unsaved changes' ) ).toBeInTheDocument();
	} );

	it( 'uses the defaults when the settings have no passwordless group', async () => {
		mockServer( settingsFixture() );
		await renderTab();

		expect( screen.getByLabelText( 'Code lifetime' ) ).toHaveDisplayValue(
			'10 minutes'
		);
		expect( screen.getByLabelText( 'Button style' ) ).toHaveDisplayValue(
			'Text link'
		);
		expect(
			screen.getByRole( 'switch', { name: 'WooCommerce checkout' } )
		).toBeChecked();
	} );

	it( 'has no accessibility violations, with the warning showing', async () => {
		mockServer(
			settingsFixture( {
				passwordless: passwordless( {
					role_policy: { administrator: 'email_only' },
				} ),
			} )
		);
		const { container } = await renderTab();
		expect( screen.getByText( /Anyone who can read/ ) ).toBeInTheDocument();

		expect( await axe( container ) ).toHaveNoViolations();
	} );
} );
