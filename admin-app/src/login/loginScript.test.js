/**
 * The front-end login script (assets/login.js), run in jsdom.
 */
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const source = readFileSync(
	path.resolve( __dirname, '../../../assets/login.js' ),
	'utf8'
);

const markup = `
<div data-happyaccess-pl>
	<p class="happyaccess-pl__toggle-row" hidden><button type="button" class="happyaccess-pl__toggle" aria-expanded="false">Email me a login code</button></p>
	<div class="happyaccess-pl__panel" hidden>
		<div data-step="request">
			<input data-field="login" />
			<button type="button" data-action="request">Send code</button>
		</div>
		<div data-step="verify" hidden>
			<input data-field="code" />
			<button type="button" data-action="verify">Log in</button>
		</div>
		<p class="happyaccess-pl__message" tabindex="-1"></p>
	</div>
</div>`;

function flush() {
	return vi.advanceTimersByTimeAsync( 0 );
}

describe( 'assets/login.js with reCAPTCHA on', () => {
	let fetch;

	beforeEach( () => {
		vi.useFakeTimers();
		document.body.innerHTML = markup;
		fetch = vi.fn( () =>
			Promise.resolve( {
				ok: false,
				json: () =>
					Promise.resolve( {
						message:
							"The security check didn't pass. Reload the page and try again.",
					} ),
			} )
		);
		window.fetch = fetch;
		window.happyaccessLogin = {
			url: '/wp-json/happyaccess/v1/passwordless/',
			header: 'X-HappyAccess-Login',
			i18n: { empty: 'Empty', noCode: 'No code', error: 'Error' },
		};
		window.happyaccessRecaptcha = {
			key: 'test-site-key',
			field: 'happyaccess_recaptcha',
		};
	} );

	afterEach( () => {
		vi.useRealTimers();
		delete window.grecaptcha;
		delete window.happyaccessRecaptcha;
		delete window.happyaccessLogin;
		delete window.fetch;
	} );

	function start() {
		new Function( source )();
		document.querySelector( '[data-field="login"]' ).value =
			'someone@example.org';
		document.querySelector( '[data-action="request"]' ).click();
	}

	it( 'sends the request without a token after 8 seconds when grecaptcha stalls', async () => {
		window.grecaptcha = { ready: vi.fn(), execute: vi.fn() };
		start();

		await vi.advanceTimersByTimeAsync( 7999 );
		expect( fetch ).not.toHaveBeenCalled();

		await vi.advanceTimersByTimeAsync( 1 );
		await flush();
		expect( fetch ).toHaveBeenCalledTimes( 1 );
		const body = JSON.parse( fetch.mock.calls[ 0 ][ 1 ].body );
		expect( body ).toEqual( { login: 'someone@example.org' } );
		expect(
			document.querySelector( '.happyaccess-pl__message' ).textContent
		).toBe(
			"The security check didn't pass. Reload the page and try again."
		);
		expect(
			document.querySelector( '[data-action="request"]' ).disabled
		).toBe( false );
	} );

	it( 'sends the token once when grecaptcha answers, and the timer does nothing later', async () => {
		window.grecaptcha = {
			ready: ( callback ) => callback(),
			execute: vi.fn( () => Promise.resolve( 'token-value' ) ),
		};
		start();
		await flush();

		expect( fetch ).toHaveBeenCalledTimes( 1 );
		expect( JSON.parse( fetch.mock.calls[ 0 ][ 1 ].body ) ).toEqual( {
			login: 'someone@example.org',
			happyaccess_recaptcha: 'token-value',
		} );
		expect( window.grecaptcha.execute ).toHaveBeenCalledWith(
			'test-site-key',
			{ action: 'pl_request' }
		);

		await vi.advanceTimersByTimeAsync( 9000 );
		expect( fetch ).toHaveBeenCalledTimes( 1 );
	} );
} );
