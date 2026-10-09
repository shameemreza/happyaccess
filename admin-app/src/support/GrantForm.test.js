import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import apiFetch from '@wordpress/api-fetch';
import { AnnounceProvider } from '../Announcer';
import DataProvider from '../data/DataProvider';
import GrantForm from './GrantForm';
import { catalog } from './fixtures';

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
	menus: [
		{
			slug: 'woocommerce',
			title: 'WooCommerce',
			children: [
				{ slug: 'woocommerce::wc-settings', title: 'Settings' },
				{ slug: 'woocommerce::wc-status', title: 'Status' },
			],
		},
		{ slug: 'plugins.php', title: 'Plugins', children: [] },
	],
	roles: [
		{ slug: 'administrator', name: 'Administrator' },
		{ slug: 'shop_manager', name: 'Shop manager' },
		{ slug: 'editor', name: 'Editor' },
	],
};

const created = {
	id: 7,
	label: 'Acme',
	code: '48291375',
	link_url: 'https://x.test/?k=1',
};

function mockApi( {
	createResult = created,
	createError = null,
	settings = null,
} = {} ) {
	apiFetch.mockImplementation( ( { path, method } ) => {
		if ( '/happyaccess/v1/catalog' === path ) {
			return Promise.resolve( catalog );
		}
		if ( settings && '/happyaccess/v1/settings' === path ) {
			return Promise.resolve( settings );
		}
		if ( '/happyaccess/v1/grants' === path && 'POST' === method ) {
			return createError
				? Promise.reject( createError )
				: Promise.resolve( createResult );
		}
		return Promise.reject( new Error( 'Unexpected request ' + path ) );
	} );
}

// The browser's timezone, as the form sees it.
const browserZone = ( zone ) =>
	vi
		.spyOn( Intl.DateTimeFormat.prototype, 'resolvedOptions' )
		.mockReturnValue( { timeZone: zone } );

const grantCalls = () =>
	apiFetch.mock.calls.filter( ( [ options ] ) => 'POST' === options.method );
const lastBody = () => grantCalls().at( -1 )[ 0 ].data;
const createButton = () =>
	screen.getByRole( 'button', { name: 'Create support pass' } );
// Create stays focusable while blocked, so it says so with aria-disabled.
const expectBlocked = () =>
	expect( createButton() ).toHaveAttribute( 'aria-disabled', 'true' );
const expectReady = () =>
	expect( createButton() ).not.toHaveAttribute( 'aria-disabled' );
const labelBox = () => screen.getByRole( 'textbox', { name: 'Who is it for' } );
const emailBox = () =>
	screen.getByRole( 'textbox', { name: 'Their email (optional)' } );

function setup( props = {} ) {
	const user = userEvent.setup();
	const onCreated = vi.fn();
	const view = render(
		<AnnounceProvider>
			<DataProvider>
				<GrantForm boot={ boot } onCreated={ onCreated } { ...props } />
			</DataProvider>
		</AnnounceProvider>
	);
	return { user, onCreated, ...view };
}

const fillLabel = ( user, text = 'Acme Plugin Support' ) =>
	user.type( screen.getByRole( 'textbox', { name: 'Who is it for' } ), text );

const pickLevel = ( user, name ) =>
	user.click( screen.getByRole( 'radio', { name } ) );

async function openCustom( user, preset = 'editor' ) {
	await pickLevel( user, /Custom access/ );
	const select = await screen.findByLabelText( 'Start from' );
	await user.selectOptions( select, preset );
}

beforeEach( () => {
	apiFetch.mockReset();
	mockApi();
} );

afterEach( () => {
	vi.useRealTimers();
} );

describe( 'GrantForm fields', () => {
	it( 'keeps Create disabled until someone is named', async () => {
		const { user } = setup();
		expect( createButton() ).toHaveAttribute( 'aria-disabled', 'true' );

		await fillLabel( user );
		expectReady();

		await user.clear( labelBox() );
		await user.type( labelBox(), '   ' );
		expectBlocked();
	} );

	it( 'marks the label field as required', () => {
		setup();
		expect( labelBox() ).toBeRequired();
		expect( labelBox() ).toHaveAttribute( 'aria-required', 'true' );
	} );

	it( 'says under Create why it is blocked, starting with the first reason', async () => {
		const { user } = setup();
		expect( createButton() ).toHaveAccessibleDescription(
			"Add who it's for"
		);
		expect( screen.getByText( "Add who it's for" ) ).toBeInTheDocument();

		await fillLabel( user );
		await pickLevel( user, /Full admin/ );
		expect( createButton() ).toHaveAccessibleDescription(
			'Tick the trust box to continue'
		);

		await user.click(
			screen.getByRole( 'checkbox', { name: /I trust this person/ } )
		);
		expectReady();
		expect( screen.queryByText( 'Tick the trust box to continue' ) ).toBe(
			null
		);
	} );

	it( 'shows an error for an invalid email and blocks Create until it is fixed', async () => {
		const { user } = setup();
		await fillLabel( user );
		await user.type( emailBox(), 'help@' );
		await user.tab();

		expect( emailBox() ).toHaveAttribute( 'aria-invalid', 'true' );
		expect( emailBox() ).toHaveAccessibleDescription(
			'Enter a full email address, like name@example.com.'
		);
		expectBlocked();
		expect( createButton() ).toHaveAccessibleDescription(
			'Fix the email address'
		);

		await user.type( emailBox(), 'example.com' );
		expect( emailBox() ).not.toHaveAttribute( 'aria-invalid', 'true' );
		expectReady();
	} );

	it( 'does not send a blocked form when Create is clicked', async () => {
		const { user } = setup();
		await user.click( createButton() );
		expect( grantCalls() ).toHaveLength( 0 );
	} );

	it( 'names each level by its title and describes it with its text', () => {
		setup();
		const radio = screen.getByRole( 'radio', { name: 'Protected admin' } );
		expect( radio ).toHaveAccessibleDescription(
			'Can fix almost anything. The risky actions are blocked. Best for most support.'
		);
		expect(
			screen.getByRole( 'radio', { name: 'Full admin, no limits' } )
		).toBeInTheDocument();
	} );

	it( 'offers the three levels with the prototype text and protected by default', () => {
		setup();

		const radios = screen.getAllByRole( 'radio' );
		expect(
			radios.map( ( radio ) => radio.closest( 'label' ).textContent )
		).toEqual( [
			'Protected adminCan fix almost anything. The risky actions are blocked. Best for most support.',
			'Custom accessPick exactly what they can do, permission by permission.',
			'Full admin, no limitsFor teams you fully trust, like your host or developer.',
		] );
		expect( radios[ 0 ] ).toBeChecked();
		expect(
			screen.getByText( 'Always blocked on this pass' )
		).toBeInTheDocument();
	} );

	it( 'shows the optional email field with its help', () => {
		setup();
		expect(
			screen.getByRole( 'textbox', { name: 'Their email (optional)' } )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Only used if you send the access by email.' )
		).toBeInTheDocument();
	} );
} );

describe( 'GrantForm trust', () => {
	it( 'needs the trust box for Full and clears it when the level changes', async () => {
		const { user } = setup();
		await fillLabel( user );
		await pickLevel( user, /Full admin/ );

		expect( screen.getByText( /No limits\./ ) ).toBeInTheDocument();
		expectBlocked();

		const trust = screen.getByRole( 'checkbox', {
			name: 'I trust this person with full access to my site',
		} );
		await user.click( trust );
		expectReady();

		await pickLevel( user, /Protected admin/ );
		expectReady();
		await pickLevel( user, /Full admin/ );
		expect(
			screen.getByRole( 'checkbox', { name: /I trust this person/ } )
		).not.toBeChecked();
		expectBlocked();
	} );

	it( 'is a plain box tied to its checkbox, so it is not announced as an alert', async () => {
		const { user } = setup();
		await fillLabel( user );
		await pickLevel( user, /Full admin/ );

		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
		const trust = screen.getByRole( 'checkbox', {
			name: 'I trust this person with full access to my site',
		} );
		expect( trust ).toHaveAccessibleDescription(
			/^No limits\. They can do anything/
		);
	} );

	it( 'asks for trust in Custom only once an admin-level cap is ticked', async () => {
		const { user } = setup();
		await fillLabel( user );
		await openCustom( user, 'editor' );

		expectReady();
		expect(
			screen.queryByRole( 'checkbox', { name: /I trust this person/ } )
		).not.toBeInTheDocument();

		await user.click(
			screen.getByRole( 'checkbox', { name: 'Plugins and updates' } )
		);

		expect(
			screen.getByText( /Admin-level permissions are on\./ )
		).toBeInTheDocument();
		expectBlocked();

		await user.click(
			screen.getByRole( 'checkbox', { name: /I trust this person/ } )
		);
		expectReady();
	} );

	it( 'resets the trust tick when the last admin-level cap is unticked', async () => {
		const { user } = setup();
		await fillLabel( user );
		await openCustom( user, 'editor' );
		const plugins = screen.getByRole( 'checkbox', {
			name: 'Plugins and updates',
		} );
		await user.click( plugins );
		await user.click(
			screen.getByRole( 'checkbox', { name: /I trust this person/ } )
		);

		await user.click( plugins );
		expect(
			screen.queryByRole( 'checkbox', { name: /I trust this person/ } )
		).not.toBeInTheDocument();
		expectReady();

		await user.click( plugins );
		expect(
			screen.getByRole( 'checkbox', { name: /I trust this person/ } )
		).not.toBeChecked();
		expectBlocked();
	} );

	it( 'starts Custom from the administrator preset, which needs trust', async () => {
		const { user } = setup();
		await fillLabel( user );
		await pickLevel( user, /Custom access/ );

		await screen.findByLabelText( 'Start from' );
		expect( screen.getByLabelText( 'Start from' ) ).toHaveValue(
			'administrator'
		);
		expectBlocked();
	} );

	it( 'keeps Create disabled in Custom with no permissions ticked', async () => {
		const { user } = setup();
		await fillLabel( user );
		await openCustom( user, 'editor' );
		await user.click( screen.getByRole( 'checkbox', { name: 'Content' } ) );

		expect( screen.getByText( '0 of 7 permissions' ) ).toBeInTheDocument();
		expectBlocked();
	} );

	it( 'loads the catalog once, however often the level changes', async () => {
		const { user } = setup();
		await pickLevel( user, /Custom access/ );
		await screen.findByLabelText( 'Start from' );
		await pickLevel( user, /Protected admin/ );
		await pickLevel( user, /Custom access/ );

		const loads = apiFetch.mock.calls.filter(
			( [ options ] ) => '/happyaccess/v1/catalog' === options.path
		);
		expect( loads ).toHaveLength( 1 );
	} );
} );

describe( 'GrantForm time and preview', () => {
	it( 'shows the typed label and the end time for 3 days, in the site timezone', async () => {
		vi.useFakeTimers( { toFake: [ 'Date' ] } );
		vi.setSystemTime( new Date( '2026-10-06T16:40:00Z' ) );
		browserZone( 'Asia/Dhaka' );
		const { user } = setup();

		expect( screen.getByText( 'Who is it for?' ) ).toBeInTheDocument();
		await fillLabel( user, 'Jordan' );

		expect(
			screen.getByText( 'Jordan', { selector: '.ha-preview__label' } )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Administrator (protected)' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Valid until Fri, Oct 9, 4:40 pm (UTC)' )
		).toBeInTheDocument();
		expect( screen.getByTestId( 'ha-ends' ) ).toHaveTextContent(
			'Ends Fri, Oct 9, 4:40 pm (UTC)'
		);
		expect(
			screen.getByRole( 'button', { name: '3 days' } )
		).toHaveAttribute( 'aria-pressed', 'true' );
	} );

	it( 'updates the preview for the duration and the level', async () => {
		vi.useFakeTimers( { toFake: [ 'Date' ] } );
		vi.setSystemTime( new Date( '2026-10-06T16:40:00Z' ) );
		browserZone( 'UTC' );
		const { user } = setup();

		await user.click( screen.getByRole( 'button', { name: '1 day' } ) );
		expect( screen.getByTestId( 'ha-ends' ) ).toHaveTextContent(
			'Ends tomorrow, Wed, Oct 7, 4:40 pm'
		);
		await user.click( screen.getByRole( 'button', { name: '7 days' } ) );
		expect(
			screen.getByText( 'Valid until Tue, Oct 13, 4:40 pm' )
		).toBeInTheDocument();

		await pickLevel( user, /Full admin/ );
		expect(
			screen.getByText( 'Administrator (full access)' )
		).toBeInTheDocument();
		await pickLevel( user, /Custom access/ );
		expect(
			screen.getByText( 'Custom access', {
				selector: '.ha-preview__level',
			} )
		).toBeInTheDocument();
	} );

	it( 'opens a date picker for Custom, capped at the longest pass', async () => {
		vi.useFakeTimers( { toFake: [ 'Date' ] } );
		vi.setSystemTime( new Date( '2026-10-06T16:40:00Z' ) );
		const { user } = setup( { boot: { ...boot, maxDays: 10 } } );

		await user.click( screen.getByRole( 'button', { name: 'Custom' } ) );

		expect(
			screen.getByText( 'Pick a date up to 10 days ahead.' )
		).toBeInTheDocument();
		expect(
			document.querySelector( '.components-datetime' )
		).not.toBeNull();
		expect( screen.getByTestId( 'ha-ends' ) ).toHaveTextContent(
			'Ends Fri, Oct 16, 4:40 pm'
		);

		await fillLabel( user );
		await user.click( createButton() );
		await waitFor( () => expect( grantCalls() ).toHaveLength( 1 ) );
		expect( lastBody().duration ).toBe( 10 * 86400 );
	} );
} );

describe( 'GrantForm default length', () => {
	const withDefault = ( seconds ) =>
		mockApi( { settings: { support: { default_duration: seconds } } } );
	const pressed = ( name ) =>
		expect( screen.getByRole( 'button', { name } ) ).toHaveAttribute(
			'aria-pressed',
			'true'
		);

	it( 'starts at the preset that matches the Default pass length setting', async () => {
		withDefault( 7 * 86400 );
		const { user } = setup();

		await waitFor( () => pressed( '7 days' ) );
		await fillLabel( user );
		await user.click( createButton() );
		await waitFor( () => expect( grantCalls() ).toHaveLength( 1 ) );
		expect( lastBody().duration ).toBe( 7 * 86400 );
	} );

	it( 'starts at 1 day when the setting is 1 day', async () => {
		withDefault( 86400 );
		setup();

		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: '1 day' } )
			).toHaveAttribute( 'aria-pressed', 'true' )
		);
	} );

	it( 'starts at Custom with the setting as its end when no preset matches', async () => {
		vi.useFakeTimers( { toFake: [ 'Date' ] } );
		vi.setSystemTime( new Date( '2026-10-06T16:40:00Z' ) );
		browserZone( 'UTC' );
		withDefault( 10 * 86400 );
		setup();

		await waitFor( () => pressed( 'Custom' ) );
		expect( screen.getByTestId( 'ha-ends' ) ).toHaveTextContent(
			'Ends Fri, Oct 16, 4:40 pm'
		);
	} );

	it( 'keeps the length someone already picked when the setting loads late', async () => {
		let answer;
		apiFetch.mockImplementation( ( { path } ) => {
			if ( '/happyaccess/v1/settings' === path ) {
				return new Promise( ( resolve ) => {
					answer = resolve;
				} );
			}
			return Promise.reject( new Error( 'Unexpected request ' + path ) );
		} );
		const { user } = setup();

		await user.click( screen.getByRole( 'button', { name: '1 day' } ) );
		await waitFor( () => expect( answer ).toBeDefined() );
		await act( async () => {
			answer( { support: { default_duration: 7 * 86400 } } );
		} );

		pressed( '1 day' );
		expect(
			screen.getByRole( 'button', { name: '7 days' } )
		).toHaveAttribute( 'aria-pressed', 'false' );
	} );

	it( 'stays at 3 days when the settings could not load', () => {
		setup();
		expect(
			screen.getByRole( 'button', { name: '3 days' } )
		).toHaveAttribute( 'aria-pressed', 'true' );
	} );
} );

describe( 'GrantForm login alerts on a full pass', () => {
	it( 'fixes the alerts to every login for Full admin and sends every', async () => {
		const { user } = setup();
		await fillLabel( user );
		await user.click(
			screen.getByRole( 'button', { name: 'More options' } )
		);
		await user.selectOptions(
			screen.getByLabelText( 'Login alerts' ),
			'off'
		);
		await pickLevel( user, /Full admin/ );

		const select = screen.getByLabelText( 'Login alerts' );
		expect( select ).toBeDisabled();
		expect( select ).toHaveValue( 'every' );
		expect(
			screen.getByText(
				'A full admin pass always alerts you on every login.'
			)
		).toBeInTheDocument();

		await user.click(
			screen.getByRole( 'checkbox', { name: /I trust this person/ } )
		);
		await user.click( createButton() );
		await waitFor( () => expect( grantCalls() ).toHaveLength( 1 ) );
		expect( lastBody().notify ).toBe( 'every' );
	} );

	it( 'gives the alert choice back when the level changes from Full admin', async () => {
		const { user } = setup();
		await user.click(
			screen.getByRole( 'button', { name: 'More options' } )
		);
		await user.selectOptions(
			screen.getByLabelText( 'Login alerts' ),
			'off'
		);
		await pickLevel( user, /Full admin/ );
		await pickLevel( user, /Protected admin/ );

		const select = screen.getByLabelText( 'Login alerts' );
		expect( select ).toBeEnabled();
		expect( select ).toHaveValue( 'off' );
	} );
} );

describe( 'GrantForm timezone note', () => {
	const ends = () => screen.getByTestId( 'ha-ends' ).textContent;
	const until = () =>
		document.querySelector( '.ha-preview__until' ).textContent;

	it( 'names the site timezone only when it differs from the browser', () => {
		browserZone( 'Asia/Dhaka' );
		setup( { boot: { ...boot, timezone: 'Asia/Dhaka' } } );
		expect( ends() ).not.toContain( '(' );
		expect( until() ).not.toContain( '(' );
	} );

	it( 'adds the name to both the ends line and the preview when it differs', () => {
		browserZone( 'America/New_York' );
		setup( { boot: { ...boot, timezone: 'Asia/Dhaka' } } );
		expect( ends() ).toMatch( /^Ends .+ \(Asia\/Dhaka\)$/ );
		expect( until() ).toMatch( /^Valid until .+ \(Asia\/Dhaka\)$/ );
	} );

	it( 'leaves out an offset that matches the browser, like +00:00 on a UTC machine', () => {
		browserZone( 'UTC' );
		vi.spyOn( Date.prototype, 'getTimezoneOffset' ).mockReturnValue( 0 );
		setup( { boot: { ...boot, timezone: '+00:00' } } );
		expect( ends() ).not.toContain( '(' );
		expect( until() ).not.toContain( '(' );
	} );

	it( 'names an offset timezone that differs from the browser as UTC plus hours', () => {
		browserZone( 'UTC' );
		vi.spyOn( Date.prototype, 'getTimezoneOffset' ).mockReturnValue( 0 );
		setup( { boot: { ...boot, timezone: '+06:00' } } );
		expect( ends() ).toMatch( / \(UTC\+6\)$/ );
		expect( until() ).toMatch( / \(UTC\+6\)$/ );
	} );

	it.each( [
		[ '+00:00', 'UTC' ],
		[ '+05:30', 'UTC+5:30' ],
		[ '-03:00', 'UTC-3' ],
		[ '-09:30', 'UTC-9:30' ],
	] )( 'shows the offset %s as %s', ( timezone, label ) => {
		browserZone( 'Asia/Tokyo' );
		vi.spyOn( Date.prototype, 'getTimezoneOffset' ).mockReturnValue( -540 );
		setup( { boot: { ...boot, timezone } } );
		expect( ends() ).toContain( ` (${ label })` );
	} );
} );

describe( 'GrantForm request', () => {
	it( 'sends a protected pass with defaults and no email', async () => {
		const { user, onCreated } = setup();
		await fillLabel( user );
		await user.click( createButton() );

		await waitFor( () =>
			expect( onCreated ).toHaveBeenCalledWith( created )
		);
		expect( grantCalls()[ 0 ][ 0 ].path ).toBe( '/happyaccess/v1/grants' );
		expect( lastBody() ).toEqual( {
			label: 'Acme Plugin Support',
			level: 'protected',
			duration: 3 * 86400,
			one_time: false,
			notify: 'first',
			send_email: false,
		} );
	} );

	it( 'sends the email, the chosen role and every more option', async () => {
		const { user } = setup();
		await fillLabel( user );
		await user.type(
			screen.getByRole( 'textbox', { name: 'Their email (optional)' } ),
			'help@example.com'
		);
		await user.click( screen.getByRole( 'button', { name: '7 days' } ) );
		await user.click(
			screen.getByRole( 'button', { name: 'More options' } )
		);

		await user.click(
			screen.getByRole( 'checkbox', { name: /One-time use/ } )
		);
		await user.selectOptions(
			screen.getByLabelText( 'Or give a different role' ),
			'shop_manager'
		);
		await user.selectOptions(
			screen.getByLabelText( 'Login alerts' ),
			'every'
		);
		await user.type(
			screen.getByRole( 'textbox', {
				name: /Only allow these IP addresses/,
			} ),
			'10.0.0.1, 10.0.0.2'
		);
		await user.type(
			screen.getByRole( 'textbox', { name: 'After login, open' } ),
			'/wp-admin/edit.php'
		);
		await user.click(
			screen.getByRole( 'checkbox', { name: 'Email it to them now' } )
		);
		await user.click( createButton() );

		await waitFor( () => expect( grantCalls() ).toHaveLength( 1 ) );
		expect( lastBody() ).toEqual( {
			label: 'Acme Plugin Support',
			email: 'help@example.com',
			level: 'protected',
			role: 'shop_manager',
			duration: 7 * 86400,
			one_time: true,
			notify: 'every',
			ips: [ '10.0.0.1', '10.0.0.2' ],
			redirect_to: '/wp-admin/edit.php',
			send_email: true,
		} );
	} );

	it( 'shows the email toggle only once an email is typed', async () => {
		const { user } = setup();
		await user.click(
			screen.getByRole( 'button', { name: 'More options' } )
		);
		expect(
			screen.queryByRole( 'checkbox', { name: 'Email it to them now' } )
		).not.toBeInTheDocument();

		await user.type(
			screen.getByRole( 'textbox', { name: 'Their email (optional)' } ),
			'a@b.co'
		);
		expect(
			screen.getByRole( 'checkbox', { name: 'Email it to them now' } )
		).toBeInTheDocument();
	} );

	it( 'hides the role select unless the level is protected', async () => {
		const { user } = setup();
		await user.click(
			screen.getByRole( 'button', { name: 'More options' } )
		);
		expect(
			screen.getByLabelText( 'Or give a different role' )
		).toBeInTheDocument();

		await pickLevel( user, /Full admin/ );
		expect(
			screen.queryByLabelText( 'Or give a different role' )
		).not.toBeInTheDocument();
	} );

	it( 'saves hidden screens as slugs, shown as Parent › Child', async () => {
		const { user } = setup();
		await fillLabel( user );
		await user.click(
			screen.getByRole( 'button', { name: 'More options' } )
		);

		const field = screen.getByRole( 'combobox', {
			name: 'Hide admin screens',
		} );
		await user.click( field );
		await user.type( field, 'WooCommerce ›' );
		await user.click(
			await screen.findByRole( 'option', {
				name: /WooCommerce › Settings/,
			} )
		);
		await user.type( field, 'Plug' );
		await user.click(
			await screen.findByRole( 'option', { name: /Plugins/ } )
		);

		expect(
			screen.getByText( 'WooCommerce › Settings' )
		).toBeInTheDocument();
		await user.click( createButton() );

		await waitFor( () => expect( grantCalls() ).toHaveLength( 1 ) );
		expect( lastBody().menus ).toEqual( [
			'woocommerce::wc-settings',
			'plugins.php',
		] );
	} );

	it( 'sends a custom pass with its caps and no trust flag when none is admin-level', async () => {
		const { user } = setup();
		await fillLabel( user );
		await openCustom( user, 'shop_manager' );
		await user.click( createButton() );

		await waitFor( () => expect( grantCalls() ).toHaveLength( 1 ) );
		expect( lastBody() ).toMatchObject( {
			level: 'custom',
			caps: [
				'edit_posts',
				'edit_shop_orders',
				'view_woocommerce_reports',
			],
		} );
		expect( lastBody() ).not.toHaveProperty( 'confirm_full' );
		expect( lastBody() ).not.toHaveProperty( 'role' );
	} );

	it( 'sends confirm_full for a custom pass with an admin-level cap', async () => {
		const { user } = setup();
		await fillLabel( user );
		await pickLevel( user, /Custom access/ );
		await screen.findByLabelText( 'Start from' );
		await user.click(
			screen.getByRole( 'checkbox', { name: /I trust this person/ } )
		);
		await user.click( createButton() );

		await waitFor( () => expect( grantCalls() ).toHaveLength( 1 ) );
		expect( lastBody() ).toMatchObject( {
			level: 'custom',
			confirm_full: true,
		} );
		expect( lastBody().caps ).toContain( 'install_plugins' );
	} );

	it( 'sends confirm_full for a full pass, with no caps and no role', async () => {
		const { user } = setup();
		await fillLabel( user );
		await pickLevel( user, /Full admin/ );
		await user.click(
			screen.getByRole( 'checkbox', { name: /I trust this person/ } )
		);
		await user.click( createButton() );

		await waitFor( () => expect( grantCalls() ).toHaveLength( 1 ) );
		expect( lastBody().level ).toBe( 'full' );
		expect( lastBody().confirm_full ).toBe( true );
		expect( lastBody() ).not.toHaveProperty( 'caps' );
		expect( lastBody() ).not.toHaveProperty( 'role' );
	} );

	it( 'announces the new pass and hands the result to onCreated', async () => {
		const { user, onCreated, container } = setup();
		await fillLabel( user );
		await user.click( createButton() );

		await waitFor( () => expect( onCreated ).toHaveBeenCalledTimes( 1 ) );
		expect(
			container.querySelector( '[aria-live="polite"]' )
		).toHaveTextContent( 'Support pass created for Acme Plugin Support' );
	} );

	it( 'uses the create function it is given', async () => {
		const create = vi.fn().mockResolvedValue( created );
		const { user, onCreated } = setup( { create } );
		await fillLabel( user );
		await user.click( createButton() );

		await waitFor( () =>
			expect( onCreated ).toHaveBeenCalledWith( created )
		);
		expect( create.mock.calls[ 0 ][ 0 ] ).toMatchObject( {
			label: 'Acme Plugin Support',
			level: 'protected',
		} );
		expect( grantCalls() ).toHaveLength( 0 );
	} );

	it( 'shows the server error and keeps the form when create fails', async () => {
		mockApi( {
			createError: {
				code: 'happyaccess_invalid',
				message: 'The role does not exist.',
				data: { status: 400 },
			},
		} );
		const { user, onCreated } = setup();
		await fillLabel( user );
		await user.click( createButton() );

		// The notice is also spoken, so the text can appear twice.
		expect(
			( await screen.findAllByText( 'The role does not exist.' ) ).length
		).toBeGreaterThan( 0 );
		expect(
			document.querySelector( '.components-notice__content' )
		).toHaveTextContent( 'The role does not exist.' );
		expect( onCreated ).not.toHaveBeenCalled();
		expect(
			screen.getByRole( 'textbox', { name: 'Who is it for' } )
		).toHaveValue( 'Acme Plugin Support' );
		expectReady();
	} );

	it( 'does not hold on to the code after create', async () => {
		const { user, onCreated, container } = setup();
		await fillLabel( user );
		await user.click( createButton() );
		await waitFor( () => expect( onCreated ).toHaveBeenCalled() );

		expect( container.textContent ).not.toContain( '48291375' );
		expect( window.localStorage.length ).toBe( 0 );
		expect( window.sessionStorage.length ).toBe( 0 );
	} );
} );

describe( 'GrantForm accessibility', () => {
	it( 'has no violations at each level', async () => {
		const { user, container } = setup();
		await user.click(
			screen.getByRole( 'button', { name: 'More options' } )
		);
		expect( await axe( container ) ).toHaveNoViolations();

		await pickLevel( user, /Custom access/ );
		await screen.findByLabelText( 'Start from' );
		await user.click(
			within(
				screen
					.getByRole( 'checkbox', { name: 'Store' } )
					.closest( '.ha-group' )
			).getByRole( 'button', { name: /Store/ } )
		);
		expect( await axe( container ) ).toHaveNoViolations();

		await pickLevel( user, /Full admin/ );
		expect( await axe( container ) ).toHaveNoViolations();
	} );

	it( 'has no violations with the custom date picker open', async () => {
		const { user, container } = setup();
		await user.click( screen.getByRole( 'button', { name: 'Custom' } ) );
		expect( await axe( container ) ).toHaveNoViolations();
	} );
} );
