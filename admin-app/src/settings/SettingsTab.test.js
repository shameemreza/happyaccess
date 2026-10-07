import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import apiFetch from '@wordpress/api-fetch';
import { AnnounceProvider } from '../Announcer';
import { settingsFixture } from './fixtures';
import SettingsTab from './SettingsTab';

vi.mock( '@wordpress/api-fetch' );

let server;
let posts;

// Answers GET and POST like the route: a POST merges its nested patch.
function mockServer( initial = settingsFixture(), { revoked } = {} ) {
	server = initial;
	posts = [];
	apiFetch.mockReset();
	apiFetch.mockImplementation( async ( { path, method, data } ) => {
		if ( '/happyaccess/v1/settings' !== path ) {
			throw new Error( `Unexpected request ${ path }` );
		}
		if ( 'POST' !== method ) {
			return server;
		}
		posts.push( data );
		const next = { ...server };
		Object.entries( data ).forEach( ( [ key, value ] ) => {
			if ( 'recaptcha_secret_key' === key ) {
				next.recaptcha_secret_set = '' !== value;
			} else {
				next[ key ] = { ...next[ key ], ...value };
			}
		} );
		server = next;
		return undefined === revoked ? server : { ...server, revoked };
	} );
}

const liveText = () =>
	document
		.querySelector( '.screen-reader-text[aria-live="polite"]' )
		.textContent.trim();

async function renderTab( props = {} ) {
	const view = render(
		<AnnounceProvider>
			<SettingsTab { ...props } />
		</AnnounceProvider>
	);
	await screen.findByRole( 'heading', { name: 'Safety and privacy' } );
	return view;
}

// The error notice also speaks its text, which puts a second copy in the page.
const page = () => document.querySelector( '.ha-settings' );

const saveButton = () => screen.getByRole( 'button', { name: 'Save changes' } );

beforeEach( () => {
	mockServer();
} );

describe( 'Settings tab', () => {
	it( 'shows the saved values', async () => {
		mockServer(
			settingsFixture( {
				privacy: { retention_days: 90 },
				security: { proxy_header: 'HTTP_CF_CONNECTING_IP' },
			} )
		);
		await renderTab();

		expect(
			screen.getByLabelText( 'Wrong codes before a pause' )
		).toHaveDisplayValue( '5 tries, then 30 minutes' );
		expect(
			screen.getByLabelText( 'Keep activity for' )
		).toHaveDisplayValue( '90 days' );
		expect(
			screen.getByLabelText( 'Visitor IP comes from' )
		).toHaveDisplayValue( 'Cloudflare' );
		expect(
			screen.getByLabelText( 'Default pass length' )
		).toHaveDisplayValue( '3 days' );
		expect(
			screen.getByRole( 'switch', { name: 'Support access' } )
		).toBeChecked();
		expect(
			screen.getByText(
				'Pick Cloudflare only if your site is behind Cloudflare. Your server must accept traffic only from Cloudflare.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'Turning this off also stops the support activity record.'
			)
		).toBeInTheDocument();
	} );

	it( 'keeps Save changes off until something changes, then sends only that key', async () => {
		const user = userEvent.setup();
		await renderTab();
		expect( saveButton() ).toBeDisabled();
		expect(
			screen.queryByText( 'Unsaved changes' )
		).not.toBeInTheDocument();

		await user.selectOptions(
			screen.getByLabelText( 'Keep activity for' ),
			'365'
		);

		expect( screen.getByText( 'Unsaved changes' ) ).toBeInTheDocument();
		expect( saveButton() ).toBeEnabled();
		await user.click( saveButton() );

		await waitFor( () => expect( liveText() ).toBe( 'Settings saved' ) );
		expect( posts ).toEqual( [ { privacy: { retention_days: 365 } } ] );
		expect( saveButton() ).toBeDisabled();
		expect(
			screen.queryByText( 'Unsaved changes' )
		).not.toBeInTheDocument();
	} );

	it( 'drops a field that is put back to its saved value', async () => {
		const user = userEvent.setup();
		await renderTab();
		const keep = screen.getByLabelText( 'Keep activity for' );

		await user.selectOptions( keep, '90' );
		expect( saveButton() ).toBeEnabled();
		await user.selectOptions( keep, '30' );

		expect( saveButton() ).toBeDisabled();
	} );

	it( 'sends the lockout pair together and the other fields as they are', async () => {
		const user = userEvent.setup();
		await renderTab();

		await user.selectOptions(
			screen.getByLabelText( 'Wrong codes before a pause' ),
			'10 tries, then 15 minutes'
		);
		await user.selectOptions(
			screen.getByLabelText( 'Default pass length' ),
			'7 days'
		);
		await user.selectOptions(
			screen.getByLabelText( 'Visitor IP comes from' ),
			'Cloudflare'
		);
		await user.click(
			screen.getByRole( 'checkbox', {
				name: 'Shorten IP addresses in the log',
			} )
		);
		await user.click(
			screen.getByRole( 'checkbox', { name: 'Keep a log' } )
		);
		await user.click( saveButton() );

		await waitFor( () => expect( posts ).toHaveLength( 1 ) );
		expect( posts[ 0 ] ).toEqual( {
			security: {
				max_attempts: 10,
				lockout_duration: 900,
				proxy_header: 'HTTP_CF_CONNECTING_IP',
			},
			support: { default_duration: 604800 },
			privacy: { anonymize_ip: true, logging: false },
		} );
	} );

	it( 'offers the server default first, so a fresh install does not show Custom', async () => {
		await renderTab();

		const options = within(
			screen.getByLabelText( 'Wrong codes before a pause' )
		)
			.getAllByRole( 'option' )
			.map( ( option ) => option.textContent );
		expect( options ).toEqual( [
			'5 tries, then 30 minutes',
			'3 tries, then 30 minutes',
			'10 tries, then 15 minutes',
		] );
	} );

	it( 'keeps a stored custom pair selectable after a preset is picked', async () => {
		mockServer(
			settingsFixture( {
				security: { max_attempts: 7, lockout_duration: 1200 },
			} )
		);
		const user = userEvent.setup();
		await renderTab();
		const select = screen.getByLabelText( 'Wrong codes before a pause' );

		await user.selectOptions( select, '3 tries, then 30 minutes' );
		expect( select ).toHaveDisplayValue( '3 tries, then 30 minutes' );
		await user.selectOptions( select, 'Custom (7 tries, 20 minutes)' );

		expect( select ).toHaveDisplayValue( 'Custom (7 tries, 20 minutes)' );
		expect( saveButton() ).toBeDisabled();
	} );

	it( 'shows a stored pair and a stored day count that are not presets, and leaves them alone', async () => {
		mockServer(
			settingsFixture( {
				security: { max_attempts: 7, lockout_duration: 1200 },
				privacy: { retention_days: 45 },
			} )
		);
		const user = userEvent.setup();
		await renderTab();

		expect(
			screen.getByLabelText( 'Wrong codes before a pause' )
		).toHaveDisplayValue( 'Custom (7 tries, 20 minutes)' );
		expect(
			screen.getByLabelText( 'Keep activity for' )
		).toHaveDisplayValue( '45 days' );

		await user.click(
			screen.getByRole( 'checkbox', { name: 'Keep a log' } )
		);
		await user.click( saveButton() );
		await waitFor( () => expect( posts ).toHaveLength( 1 ) );
		expect( posts[ 0 ] ).toEqual( { privacy: { logging: false } } );
	} );

	it( 'shows the error from a failed save and keeps the edit', async () => {
		const user = userEvent.setup();
		await renderTab();
		apiFetch.mockRejectedValueOnce( {
			code: 'rest_invalid_param',
			message: 'That value is not allowed.',
			data: { status: 400 },
		} );

		await user.selectOptions(
			screen.getByLabelText( 'Keep activity for' ),
			'90'
		);
		await user.click( saveButton() );

		expect(
			await within( page() ).findByText( 'That value is not allowed.' )
		).toBeInTheDocument();
		expect( screen.getByText( 'Unsaved changes' ) ).toBeInTheDocument();
		expect( saveButton() ).toBeEnabled();
	} );

	describe( 'Support access', () => {
		it( 'asks first, then saves only that key and announces how many passes ended', async () => {
			mockServer( settingsFixture(), { revoked: 2 } );
			const onFeaturesChange = vi.fn();
			const user = userEvent.setup();
			await renderTab( { onFeaturesChange } );
			// An unsaved edit elsewhere must survive the switch.
			await user.selectOptions(
				screen.getByLabelText( 'Keep activity for' ),
				'90'
			);

			await user.click(
				screen.getByRole( 'switch', { name: 'Support access' } )
			);

			const group = screen.getByRole( 'group', {
				name: 'Turn off support access? Every current pass ends now.',
			} );
			expect( posts ).toHaveLength( 0 );
			expect(
				screen.getByRole( 'button', { name: 'Keep access' } )
			).toHaveFocus();

			await user.click(
				within( group ).getByRole( 'button', { name: 'Turn off' } )
			);

			await waitFor( () =>
				expect( liveText() ).toBe(
					'Support access turned off. 2 passes ended.'
				)
			);
			expect( posts ).toEqual( [
				{ features: { support_access: false } },
			] );
			expect( onFeaturesChange ).toHaveBeenCalledWith(
				expect.objectContaining( { support_access: false } )
			);
			expect(
				screen.getByRole( 'switch', { name: 'Support access' } )
			).not.toBeChecked();
			expect(
				screen.getByRole( 'switch', { name: 'Support access' } )
			).toHaveFocus();
			expect( screen.queryByRole( 'group' ) ).not.toBeInTheDocument();
			expect(
				screen.queryByText( 'Have a support access code?' )
			).not.toBeInTheDocument();
			expect( screen.getByText( 'Unsaved changes' ) ).toBeInTheDocument();
		} );

		it( 'uses the singular for one pass', async () => {
			mockServer( settingsFixture(), { revoked: 1 } );
			const user = userEvent.setup();
			await renderTab();

			await user.click(
				screen.getByRole( 'switch', { name: 'Support access' } )
			);
			await user.click(
				screen.getByRole( 'button', { name: 'Turn off' } )
			);

			await waitFor( () =>
				expect( liveText() ).toBe(
					'Support access turned off. 1 pass ended.'
				)
			);
		} );

		it( 'changes nothing when you keep access', async () => {
			const user = userEvent.setup();
			await renderTab();

			await user.click(
				screen.getByRole( 'switch', { name: 'Support access' } )
			);
			await user.click(
				screen.getByRole( 'button', { name: 'Keep access' } )
			);

			expect( posts ).toHaveLength( 0 );
			expect(
				screen.getByRole( 'switch', { name: 'Support access' } )
			).toBeChecked();
			expect( screen.queryByRole( 'group' ) ).not.toBeInTheDocument();
		} );

		it( 'turns on right away, without a question', async () => {
			mockServer(
				settingsFixture( {
					features: { support_access: false },
				} )
			);
			const user = userEvent.setup();
			await renderTab();
			expect(
				screen.queryByText( 'Have a support access code?' )
			).not.toBeInTheDocument();

			await user.click(
				screen.getByRole( 'switch', { name: 'Support access' } )
			);

			await waitFor( () => expect( posts ).toHaveLength( 1 ) );
			expect( posts[ 0 ] ).toEqual( {
				features: { support_access: true },
			} );
			expect(
				screen.getByText( 'Have a support access code?' )
			).toBeInTheDocument();
			expect( liveText() ).toBe( 'Support access turned on' );
		} );

		it( 'shows an inline error and leaves the switch on when the save fails', async () => {
			const user = userEvent.setup();
			await renderTab();
			apiFetch.mockRejectedValueOnce( {
				code: 'happyaccess_failed',
				message: 'Could not turn it off.',
				data: { status: 500 },
			} );

			await user.click(
				screen.getByRole( 'switch', { name: 'Support access' } )
			);
			await user.click(
				screen.getByRole( 'button', { name: 'Turn off' } )
			);

			expect(
				await within( page() ).findByText( 'Could not turn it off.' )
			).toBeInTheDocument();
			expect(
				screen.getByRole( 'switch', { name: 'Support access' } )
			).toBeChecked();
		} );
	} );

	describe( 'reCAPTCHA', () => {
		it( 'hides the keys until it is switched on', async () => {
			const user = userEvent.setup();
			await renderTab();
			expect(
				screen.queryByLabelText( 'Secret key' )
			).not.toBeInTheDocument();

			await user.click(
				screen.getByRole( 'switch', { name: 'reCAPTCHA' } )
			);
			await user.type( screen.getByLabelText( 'Site key' ), 'site-123' );
			await user.click( saveButton() );

			await waitFor( () => expect( posts ).toHaveLength( 1 ) );
			expect( posts[ 0 ] ).toEqual( {
				security: {
					recaptcha_enabled: true,
					recaptcha_site_key: 'site-123',
				},
			} );
		} );

		it( 'never fills the secret field, and Remove sends an empty string', async () => {
			mockServer(
				settingsFixture( {
					security: {
						recaptcha_enabled: true,
						recaptcha_site_key: 'site-123',
					},
					recaptcha_secret_set: true,
				} )
			);
			const user = userEvent.setup();
			await renderTab();

			const secret = screen.getByLabelText( 'Secret key' );
			expect( secret ).toHaveValue( '' );
			expect( secret ).toHaveAttribute( 'type', 'password' );
			expect(
				screen.getByText( 'A secret key is saved' )
			).toBeInTheDocument();
			expect( saveButton() ).toBeDisabled();

			await user.click(
				screen.getByRole( 'button', { name: 'Remove' } )
			);

			await waitFor( () => expect( posts ).toHaveLength( 1 ) );
			expect( posts[ 0 ] ).toEqual( { recaptcha_secret_key: '' } );
			await waitFor( () =>
				expect(
					screen.queryByText( 'A secret key is saved' )
				).not.toBeInTheDocument()
			);
			expect( screen.getByLabelText( 'Secret key' ) ).toHaveValue( '' );
		} );

		it( 'Remove drops a secret that was typed but not saved', async () => {
			mockServer(
				settingsFixture( {
					security: { recaptcha_enabled: true },
					recaptcha_secret_set: true,
				} )
			);
			const user = userEvent.setup();
			await renderTab();

			await user.type( screen.getByLabelText( 'Secret key' ), 'typed' );
			await user.click(
				screen.getByRole( 'button', { name: 'Remove' } )
			);

			await waitFor( () =>
				expect( screen.getByLabelText( 'Secret key' ) ).toHaveValue(
					''
				)
			);
			expect( saveButton() ).toBeDisabled();
			expect( posts ).toEqual( [ { recaptcha_secret_key: '' } ] );
		} );

		it( 'does not send a typed secret when reCAPTCHA is switched off before saving', async () => {
			mockServer(
				settingsFixture( {
					security: { recaptcha_enabled: true },
				} )
			);
			const user = userEvent.setup();
			await renderTab();

			await user.type( screen.getByLabelText( 'Secret key' ), 'typed' );
			await user.click(
				screen.getByRole( 'switch', { name: 'reCAPTCHA' } )
			);
			await user.click( saveButton() );

			await waitFor( () => expect( posts ).toHaveLength( 1 ) );
			expect( posts[ 0 ] ).toEqual( {
				security: { recaptcha_enabled: false },
			} );
		} );

		it( 'sends a typed secret with Save changes, then clears the field', async () => {
			mockServer(
				settingsFixture( {
					security: { recaptcha_enabled: true },
				} )
			);
			const user = userEvent.setup();
			await renderTab();

			await user.type( screen.getByLabelText( 'Secret key' ), 's3cret' );
			expect( saveButton() ).toBeEnabled();
			await user.click( saveButton() );

			await waitFor( () => expect( posts ).toHaveLength( 1 ) );
			expect( posts[ 0 ] ).toEqual( { recaptcha_secret_key: 's3cret' } );
			await waitFor( () =>
				expect( screen.getByLabelText( 'Secret key' ) ).toHaveValue(
					''
				)
			);
			expect(
				screen.getByText( 'A secret key is saved' )
			).toBeInTheDocument();
		} );
	} );

	it( 'shows the support code link in the login preview only while Support access is on', async () => {
		await renderTab();
		const preview = screen.getByRole( 'complementary', {
			name: 'Your login screen',
		} );

		expect(
			within( preview ).getByText( 'Have a support access code?' )
		).toBeInTheDocument();
	} );

	it( 'shows a retry when the settings fail to load', async () => {
		apiFetch.mockReset();
		apiFetch.mockRejectedValueOnce( {
			code: 'rest_forbidden',
			message: 'Sorry, you are not allowed to do that.',
			data: { status: 403 },
		} );
		render(
			<AnnounceProvider>
				<SettingsTab />
			</AnnounceProvider>
		);

		expect(
			await screen.findByText( 'Sorry, you are not allowed to do that.' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Try again' } )
		).toBeInTheDocument();
	} );

	it( 'has no accessibility violations', async () => {
		mockServer(
			settingsFixture( {
				security: { recaptcha_enabled: true },
				recaptcha_secret_set: true,
			} )
		);
		const { container } = await renderTab();
		expect( await axe( container ) ).toHaveNoViolations();
	} );
} );
