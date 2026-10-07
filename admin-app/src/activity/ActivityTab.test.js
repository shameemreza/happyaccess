import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import apiFetch from '@wordpress/api-fetch';
import { getSettings, setSettings } from '@wordpress/date';
import { AnnounceProvider } from '../Announcer';
import DataProvider from '../data/DataProvider';
import { grantFixture } from '../support/fixtures';
import ActivityTab from './ActivityTab';
import { item, page } from './fixtures';

vi.mock( '@wordpress/api-fetch' );

const original = getSettings();
const at = ( iso ) => Date.parse( iso ) / 1000;
const boot = {
	currentUser: { id: 1, name: 'Sam' },
	features: { support_access: true },
};

// jsdom's Blob has no text().
const readBlob = ( blob ) =>
	new Promise( ( resolve ) => {
		const reader = new window.FileReader();
		reader.onload = () => resolve( reader.result );
		reader.readAsText( blob );
	} );

let rows;
let gate = null;
let exportGate = null;

// Makes the next activity list request wait until the returned function is called.
function holdNext() {
	let release;
	gate = new Promise( ( resolve ) => ( release = resolve ) );
	return release;
}

const liveText = () =>
	document
		.querySelector( '.screen-reader-text[aria-live="polite"]' )
		.textContent.trim();
let lastPage;
let routes;

function mockServer( { grants = [ grantFixture() ], total } = {} ) {
	routes = {};
	apiFetch.mockImplementation( async ( { path } ) => {
		const url = new URL( path, 'https://example.test' );
		const route = url.pathname.replace( '/happyaccess/v1', '' );
		routes[ route ] = ( routes[ route ] || [] ).concat( [
			url.searchParams,
		] );
		if ( '/grants' === route ) {
			return { items: grants };
		}
		if ( '/settings' === route ) {
			return { privacy: { retention_days: 90 } };
		}
		if ( '/activity/summary' === route ) {
			return {
				logins: 3,
				changes: 14,
				minutes: null,
				ips: [ '203.0.113.24' ],
			};
		}
		if ( '/activity/export' === route ) {
			if ( exportGate ) {
				await exportGate;
			}
			return {
				filename: 'happyaccess-activity-2026-10-06.csv',
				csv: 'time,summary\n2026-10-06,"One"\n2026-10-06,"Two"\n',
			};
		}
		if ( gate ) {
			const waiting = gate;
			gate = null;
			await waiting;
		}
		lastPage = Number( url.searchParams.get( 'page' ) || 1 );
		return page( rows, { total: total ?? rows.length, page: lastPage } );
	} );
}

const activityCalls = () => routes[ '/activity' ] || [];
const lastActivity = () => activityCalls().at( -1 );
// The app also loads the default first page when it starts, whatever the tab asks for.
const withToken = () => activityCalls().filter( ( q ) => q.has( 'token_id' ) );

function setup( props = {} ) {
	const user = userEvent.setup();
	const view = render(
		<AnnounceProvider>
			<DataProvider>
				<ActivityTab boot={ boot } { ...props } />
			</DataProvider>
		</AnnounceProvider>
	);
	return { user, ...view };
}

beforeEach( () => {
	gate = null;
	exportGate = null;
	apiFetch.mockReset();
	rows = [
		item( { id: 1, time: at( '2026-10-05T19:00:00Z' ) } ),
		item( {
			id: 2,
			time: at( '2026-10-05T17:00:00Z' ),
			event: 'plugin_deactivated',
			event_label: 'Plugin deactivated',
			summary: 'Deactivated plugin: Fancy Product Slider',
		} ),
	];
	window.history.replaceState(
		{},
		'',
		'/wp-admin/users.php?page=happyaccess&tab=activity'
	);
	mockServer();
} );

afterEach( () => {
	vi.useRealTimers();
	setSettings( original );
} );

describe( 'ActivityTab', () => {
	it( 'groups rows by day in the site timezone, at a frozen time', async () => {
		setSettings( {
			...original,
			timezone: {
				offset: 6,
				offsetFormatted: '6',
				string: 'Asia/Dhaka',
				abbr: '+06',
			},
		} );
		vi.useFakeTimers( { toFake: [ 'Date' ] } );
		vi.setSystemTime( new Date( '2026-10-06T04:00:00Z' ) );
		setup();

		const today = await screen.findByRole( 'heading', { name: 'Today' } );
		const yesterday = screen.getByRole( 'heading', { name: 'Yesterday' } );
		expect(
			within( today.parentElement ).getByText(
				'Saved WooCommerce shipping settings'
			)
		).toBeVisible();
		expect(
			within( yesterday.parentElement ).getByText(
				'Deactivated plugin: Fancy Product Slider'
			)
		).toBeVisible();

		// Last 7 days is today and the six days before it, in site time.
		await waitFor( () => expect( activityCalls() ).toHaveLength( 1 ) );
		expect( lastActivity().get( 'since' ) ).toBe( '2026-09-30' );
		expect( lastActivity().get( 'until' ) ).toBe( '2026-10-06' );
	} );

	it( 'sends the feature when a chip is pressed', async () => {
		const { user } = setup();
		await screen.findByText( 'Saved WooCommerce shipping settings' );
		expect( lastActivity().has( 'feature' ) ).toBe( false );

		await user.click( screen.getByRole( 'button', { name: 'Admin' } ) );

		await waitFor( () =>
			expect( lastActivity().get( 'feature' ) ).toBe( 'admin' )
		);
		expect(
			screen.getByRole( 'button', { name: 'Admin' } )
		).toHaveAttribute( 'aria-pressed', 'true' );
		expect( screen.getByRole( 'button', { name: 'All' } ) ).toHaveAttribute(
			'aria-pressed',
			'false'
		);
	} );

	it( 'shows the Passwordless and Two-step chips only once they exist', async () => {
		const { unmount } = setup();
		await screen.findByText( 'Saved WooCommerce shipping settings' );
		expect(
			screen.queryByRole( 'button', { name: 'Passwordless' } )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Two-step' } )
		).not.toBeInTheDocument();
		unmount();

		setup( {
			loginReady: true,
			boot: {
				...boot,
				features: { passwordless: true, two_step: true },
			},
		} );
		expect(
			await screen.findByRole( 'button', { name: 'Passwordless' } )
		).toBeVisible();
		expect(
			screen.getByRole( 'button', { name: 'Two-step' } )
		).toBeVisible();
	} );

	it( 'sends one request for a typed search', async () => {
		const { user } = setup();
		await screen.findByText( 'Saved WooCommerce shipping settings' );
		const before = activityCalls().length;

		await user.type(
			screen.getByRole( 'searchbox', { name: 'Search activity' } ),
			'slider'
		);

		await waitFor( () =>
			expect( lastActivity().get( 'search' ) ).toBe( 'slider' )
		);
		await new Promise( ( resolve ) => setTimeout( resolve, 400 ) );
		expect( activityCalls() ).toHaveLength( before + 1 );
	} );

	it( 'sends a chosen pass as token_id and shows its summary', async () => {
		const { user } = setup();
		await screen.findByText( 'Saved WooCommerce shipping settings' );
		expect(
			screen.queryByRole( 'region', { name: /Session summary/ } )
		).not.toBeInTheDocument();

		await user.selectOptions(
			screen.getByLabelText( 'Who' ),
			'Acme Plugin Support'
		);

		await waitFor( () =>
			expect( lastActivity().get( 'token_id' ) ).toBe( '5' )
		);
		const summary = await screen.findByRole( 'region', {
			name: 'Session summary for Acme Plugin Support',
		} );
		expect(
			await within( summary ).findByText(
				'3 logins, 14 changes, all from 203.0.113.24'
			)
		).toBeVisible();
		expect( routes[ '/activity/summary' ][ 0 ].get( 'token_id' ) ).toBe(
			'5'
		);
	} );

	it( 'sends the current user as user_id', async () => {
		const { user } = setup();
		await screen.findByText( 'Saved WooCommerce shipping settings' );

		await user.selectOptions( screen.getByLabelText( 'Who' ), 'Sam (you)' );

		await waitFor( () =>
			expect( lastActivity().get( 'user_id' ) ).toBe( '1' )
		);
	} );

	it( 'preselects the pass from the URL, shows a revoked one as Pass #id and clears the URL', async () => {
		window.history.replaceState(
			{},
			'',
			'/wp-admin/users.php?page=happyaccess&tab=activity&token=9'
		);
		setup();

		await waitFor( () =>
			expect( withToken().at( -1 ).get( 'token_id' ) ).toBe( '9' )
		);
		await waitFor( () =>
			expect( screen.getByLabelText( 'Who' ) ).toHaveDisplayValue(
				'Pass #9'
			)
		);
		expect(
			await screen.findByRole( 'region', {
				name: 'Session summary for Pass #9',
			} )
		).toBeVisible();
		expect( window.location.search ).toBe(
			'?page=happyaccess&tab=activity'
		);
		// A pass can run for weeks, so the window is wider than a week.
		expect( screen.getByLabelText( 'When' ) ).toHaveDisplayValue(
			'Last 30 days'
		);
		expect( withToken() ).toHaveLength( 1 );
	} );

	it( 'says the pass is loading, not Pass #id, until the list arrives', async () => {
		let release;
		const held = new Promise( ( resolve ) => ( release = resolve ) );
		const serve = apiFetch.getMockImplementation();
		apiFetch.mockImplementation( async ( options ) => {
			if ( options.path.endsWith( '/grants' ) ) {
				await held;
			}
			return serve( options );
		} );
		window.history.replaceState(
			{},
			'',
			'/wp-admin/users.php?page=happyaccess&tab=activity&token=5'
		);
		setup();

		expect( screen.getByLabelText( 'Who' ) ).toHaveDisplayValue(
			'Loading pass'
		);
		expect( screen.queryByText( 'Pass #5' ) ).not.toBeInTheDocument();

		release();
		await waitFor( () =>
			expect( screen.getByLabelText( 'Who' ) ).toHaveDisplayValue(
				'Acme Plugin Support'
			)
		);
	} );

	it( 'uses the pass name once the list has loaded', async () => {
		window.history.replaceState(
			{},
			'',
			'/wp-admin/users.php?page=happyaccess&tab=activity&token=5'
		);
		setup();

		await waitFor( () =>
			expect( screen.getByLabelText( 'Who' ) ).toHaveDisplayValue(
				'Acme Plugin Support'
			)
		);
	} );

	it( 'asks for a custom range as two dates', async () => {
		const { user } = setup();
		await screen.findByText( 'Saved WooCommerce shipping settings' );

		await user.selectOptions(
			screen.getByLabelText( 'When' ),
			'Custom range'
		);
		await user.type( screen.getByLabelText( 'From' ), '2026-10-01' );
		await user.type( screen.getByLabelText( 'To' ), '2026-10-03' );

		await waitFor( () => {
			expect( lastActivity().get( 'since' ) ).toBe( '2026-10-01' );
			expect( lastActivity().get( 'until' ) ).toBe( '2026-10-03' );
		} );
	} );

	it( 'expands a row to show its IP address', async () => {
		const { user } = setup();
		const row = await screen.findByRole( 'button', {
			name: /Saved WooCommerce shipping settings/,
		} );

		await user.click( row );

		expect( row ).toHaveAttribute( 'aria-expanded', 'true' );
		expect( screen.getByText( '203.0.113.24' ) ).toBeVisible();
	} );

	it( 'pages through the events and reports the total and retention', async () => {
		mockServer( { total: 60 } );
		const { user } = setup();
		await screen.findByText( 'Saved WooCommerce shipping settings' );

		const showing = await screen.findByText( /Showing 2 of 60 events\./ );
		// The count sits in <bdi>, so RTL pages keep its numbers in order.
		expect( showing.tagName ).toBe( 'BDI' );
		expect( showing.closest( '.ha-log__count' ) ).toHaveTextContent(
			'Kept for 90 days, change it in Settings.'
		);
		expect(
			screen.getByRole( 'button', { name: 'Previous page, page 1 of 3' } )
		).toHaveAttribute( 'aria-disabled', 'true' );

		await user.click(
			screen.getByRole( 'button', { name: 'Next page, page 1 of 3' } )
		);
		await waitFor( () =>
			expect( lastActivity().get( 'page' ) ).toBe( '2' )
		);
		await waitFor( () =>
			expect(
				screen.getByRole( 'button', {
					name: 'Previous page, page 2 of 3',
				} )
			).not.toHaveAttribute( 'aria-disabled', 'true' )
		);

		await user.click(
			screen.getByRole( 'button', { name: 'Previous page, page 2 of 3' } )
		);
		await waitFor( () =>
			expect( lastActivity().has( 'page' ) ).toBe( true )
		);
		expect( lastActivity().get( 'page' ) ).toBe( '1' );
	} );

	it( 'opens Settings from the footer link', async () => {
		const onOpenSettings = vi.fn();
		const { user } = setup( { onOpenSettings } );

		await user.click(
			await screen.findByRole( 'link', { name: 'Settings' } )
		);

		expect( onOpenSettings ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'downloads the CSV as a Blob and announces the count', async () => {
		const created = [];
		URL.createObjectURL = vi.fn( ( blob ) => {
			created.push( blob );
			return 'blob:csv';
		} );
		URL.revokeObjectURL = vi.fn();
		let download = '';
		vi.spyOn(
			window.HTMLAnchorElement.prototype,
			'click'
		).mockImplementation( function () {
			download = this.download;
		} );
		const { user } = setup();
		await screen.findByText( 'Saved WooCommerce shipping settings' );

		await user.click(
			screen.getByRole( 'button', { name: 'Export CSV' } )
		);

		expect( created ).toHaveLength( 1 );
		// The link needs a moment to start the download before the URL goes.
		expect( URL.revokeObjectURL ).not.toHaveBeenCalled();
		await waitFor(
			() =>
				expect( URL.revokeObjectURL ).toHaveBeenCalledWith(
					'blob:csv'
				),
			{ timeout: 2000 }
		);
		expect( created[ 0 ].type ).toBe( 'text/csv;charset=utf-8' );
		expect( await readBlob( created[ 0 ] ) ).toBe(
			'time,summary\n2026-10-06,"One"\n2026-10-06,"Two"\n'
		);
		expect( download ).toBe( 'happyaccess-activity-2026-10-06.csv' );
		expect( screen.getByText( 'Exported 2 events' ) ).toBeInTheDocument();
		// The export sends the filters, not a page.
		expect( routes[ '/activity/export' ][ 0 ].has( 'page' ) ).toBe( false );
	} );

	it( 'keeps focus on Export CSV while the export runs', async () => {
		URL.createObjectURL = vi.fn( () => 'blob:csv' );
		URL.revokeObjectURL = vi.fn();
		vi.spyOn(
			window.HTMLAnchorElement.prototype,
			'click'
		).mockImplementation( () => {} );
		let release;
		exportGate = new Promise( ( resolve ) => ( release = resolve ) );
		const { user } = setup();
		await screen.findByText( 'Saved WooCommerce shipping settings' );
		const button = screen.getByRole( 'button', { name: 'Export CSV' } );

		await user.click( button );

		await waitFor( () =>
			expect( button ).toHaveAttribute( 'aria-disabled', 'true' )
		);
		expect( button ).not.toBeDisabled();
		expect( button ).toHaveFocus();
		release();
		await screen.findByText( 'Exported 2 events' );
		expect( button ).toHaveFocus();
	} );

	it( 'announces a new count only after the new filters have loaded', async () => {
		const { user } = setup();
		await screen.findByText( 'Saved WooCommerce shipping settings' );
		expect( liveText() ).toBe( '' );

		const release = holdNext();
		await user.click( screen.getByRole( 'button', { name: 'Admin' } ) );
		await waitFor( () =>
			expect( lastActivity().get( 'feature' ) ).toBe( 'admin' )
		);
		// The old list is still on screen, and its two events are not news.
		expect( liveText() ).toBe( '' );

		rows = [ rows[ 0 ] ];
		release();

		await waitFor( () => expect( liveText() ).toBe( '1 event' ) );
	} );

	it( 'says nothing when a filter is picked that was already picked', async () => {
		const { user } = setup();
		await screen.findByText( 'Saved WooCommerce shipping settings' );
		const before = activityCalls().length;

		await user.click( screen.getByRole( 'button', { name: 'All' } ) );
		await user.selectOptions(
			screen.getByLabelText( 'When' ),
			'Last 7 days'
		);

		expect( activityCalls() ).toHaveLength( before );
		expect( liveText() ).toBe( '' );
	} );

	it( 'counts one event in the singular', async () => {
		rows = [ rows[ 0 ] ];
		setup();

		expect(
			await screen.findByText( /Showing 1 of 1 event\./ )
		).toBeVisible();
	} );

	it( 'drops the old rows when a request fails', async () => {
		const { user } = setup();
		await screen.findByText( 'Saved WooCommerce shipping settings' );

		apiFetch.mockImplementationOnce( async () => {
			throw {
				code: 'rest_forbidden',
				message: 'Nope.',
				data: { status: 403 },
			};
		} );
		await user.click( screen.getByRole( 'button', { name: 'Admin' } ) );

		await screen.findByRole( 'button', { name: 'Try again' } );
		expect(
			screen.queryByText( 'Saved WooCommerce shipping settings' )
		).not.toBeInTheDocument();
	} );

	it( 'shows an empty state and an error with a way to retry', async () => {
		rows = [];
		const { user } = setup();
		expect(
			await screen.findByText( 'No activity matches these filters.' )
		).toBeVisible();

		apiFetch.mockImplementationOnce( async () => {
			throw {
				code: 'rest_forbidden',
				message: 'Nope.',
				data: { status: 403 },
			};
		} );
		await user.click( screen.getByRole( 'button', { name: 'Admin' } ) );
		expect(
			await screen.findByRole( 'button', { name: 'Try again' } )
		).toBeVisible();
		expect( screen.getAllByText( 'Nope.' ).length ).toBeGreaterThan( 0 );
	} );

	it( 'has no accessibility violations', async () => {
		const { user, container } = setup();
		await user.click(
			await screen.findByRole( 'button', {
				name: /Saved WooCommerce shipping settings/,
			} )
		);
		await user.selectOptions(
			screen.getByLabelText( 'Who' ),
			'Acme Plugin Support'
		);
		await screen.findByText(
			'3 logins, 14 changes, all from 203.0.113.24'
		);

		expect( await axe( container ) ).toHaveNoViolations();
	} );
} );
