import { afterEach, describe, expect, it, vi } from 'vitest';
import {
	act,
	cleanup,
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { AnnounceProvider } from '../Announcer';
import ResultCard from './ResultCard';
import { grantFixture, NOW } from './fixtures';

const boot = {
	loginUrl: 'https://yourstore.test/wp-login.php',
	roles: [],
};

const result = ( extra = {} ) => ( {
	...grantFixture( { expires_at: NOW + 3 * 86400 } ),
	code: '4829 1375',
	link_url:
		'https://yourstore.test/wp-login.php?action=happyaccess&step=link&k=Qm3x',
	code_url:
		'https://yourstore.test/wp-login.php?action=happyaccess&step=code',
	message: "Here's temporary access to Your Store:\nLogin link: ...",
	emailed: false,
	...extra,
} );

function setup( props = {}, userOptions = {} ) {
	const user = userEvent.setup( userOptions );
	const onDone = vi.fn();
	const onSendEmail = props.onSendEmail || vi.fn();
	const view = render(
		<AnnounceProvider>
			<ResultCard
				result={ props.result || result() }
				boot={ boot }
				onDone={ onDone }
				onSendEmail={ onSendEmail }
			/>
		</AnnounceProvider>
	);
	const live = () =>
		view.container.querySelector( '[aria-live]' ).textContent;
	return { user, onDone, onSendEmail, live, ...view };
}

afterEach( () => {
	vi.useRealTimers();
} );

describe( 'ResultCard', () => {
	it( 'moves focus to the heading', () => {
		setup();

		expect(
			screen.getByRole( 'heading', {
				name: 'Access is ready for Acme Plugin Support',
			} )
		).toHaveFocus();
	} );

	it( 'shows the pass, the link, the code and the hint', () => {
		setup();

		const pass = screen.getByRole( 'group', { name: 'Support pass' } );
		expect(
			within( pass ).getByText( 'Support pass' )
		).toBeInTheDocument();
		expect(
			within( pass ).getByText( /^Valid until / )
		).toBeInTheDocument();
		expect(
			within( pass ).getByText( 'Administrator (protected)' )
		).toBeInTheDocument();
		const link = screen.getByLabelText( 'Login link' );
		expect( link ).toHaveAttribute( 'readonly' );
		expect( link.value ).toContain( 'step=link&k=Qm3x' );
		expect( screen.getByText( '4829 1375' ) ).toBeInTheDocument();
		expect(
			screen.getByText(
				'Entered at yourstore.test, "Log in with an access code"'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText( /^Anyone with the link or the code can log in\./ )
		).toBeInTheDocument();
	} );

	it( 'toggles the message the person gets', async () => {
		const { user } = setup();
		const toggle = screen.getByRole( 'button', {
			name: 'See the message they get',
		} );
		expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( document.querySelector( 'pre' ) ).toBeNull();

		await user.click( toggle );

		expect( document.querySelector( 'pre' ).textContent ).toContain(
			"Here's temporary access to Your Store:"
		);
		expect(
			screen.getByRole( 'button', { name: 'Hide the message' } )
		).toHaveAttribute( 'aria-expanded', 'true' );
	} );

	it( 'copies the link, announces it and says Copied', async () => {
		const { user, live } = setup();

		await user.click(
			screen.getByRole( 'button', { name: 'Copy login link' } )
		);

		expect( await navigator.clipboard.readText() ).toBe(
			result().link_url
		);
		expect(
			screen.getByRole( 'button', { name: 'Login link copied' } )
		).toHaveTextContent( 'Copied' );
		await waitFor( () =>
			expect( live() ).toContain( 'Login link copied' )
		);
	} );

	it( 'goes back to Copy after 2.2 seconds', async () => {
		vi.useFakeTimers( { shouldAdvanceTime: true } );
		const { user } = setup( {}, { advanceTimers: vi.advanceTimersByTime } );

		await user.click(
			screen.getByRole( 'button', { name: 'Copy access code' } )
		);
		expect( await navigator.clipboard.readText() ).toBe( '4829 1375' );
		expect(
			screen.getByRole( 'button', { name: 'Access code copied' } )
		).toBeInTheDocument();

		act( () => {
			vi.advanceTimersByTime( 2100 );
		} );
		expect(
			screen.getByRole( 'button', { name: 'Access code copied' } )
		).toBeInTheDocument();
		act( () => {
			vi.advanceTimersByTime( 200 );
		} );
		expect(
			screen.getByRole( 'button', { name: 'Copy access code' } )
		).toHaveTextContent( 'Copy' );
	} );

	it( 'copies the message with its instructions', async () => {
		const { user, live } = setup();

		await user.click(
			screen.getByRole( 'button', {
				name: 'Copy message with instructions',
			} )
		);

		expect( await navigator.clipboard.readText() ).toBe( result().message );
		expect(
			screen.getByRole( 'button', { name: 'Copied, ready to paste' } )
		).toBeInTheDocument();
		await waitFor( () =>
			expect( live() ).toContain( 'Message copied, ready to paste' )
		);
	} );

	describe( 'copy hint shortcut', () => {
		const originalAgent = Object.getOwnPropertyDescriptor(
			window.navigator,
			'userAgent'
		);
		const setBrowser = ( { agent = '', platform } ) => {
			Object.defineProperty( navigator, 'userAgent', {
				configurable: true,
				value: agent,
			} );
			Object.defineProperty( navigator, 'userAgentData', {
				configurable: true,
				value: undefined === platform ? undefined : { platform },
			} );
		};
		afterEach( () => {
			delete navigator.userAgentData;
			if ( originalAgent ) {
				Object.defineProperty( navigator, 'userAgent', originalAgent );
			}
		} );

		const hintFor = async () => {
			const { user } = setup();
			Object.defineProperty( navigator, 'clipboard', {
				configurable: true,
				value: undefined,
			} );
			await user.click(
				screen.getByRole( 'button', { name: 'Copy login link' } )
			);
		};

		it( 'says Cmd+C on a Mac, by the client hint', async () => {
			setBrowser( { platform: 'macOS' } );
			await hintFor();
			expect(
				screen.getByText( 'Press Cmd+C to copy' )
			).toBeInTheDocument();
		} );

		it( 'says Cmd+C on a Mac, by the user agent when there is no client hint', async () => {
			setBrowser( {
				agent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
			} );
			await hintFor();
			expect(
				screen.getByText( 'Press Cmd+C to copy' )
			).toBeInTheDocument();
		} );

		it( 'says Cmd+C on an iPad', async () => {
			setBrowser( {
				agent: 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)',
			} );
			await hintFor();
			expect(
				screen.getByText( 'Press Cmd+C to copy' )
			).toBeInTheDocument();
		} );

		it( 'says Ctrl+C on Windows, even when the user agent mentions Mac', async () => {
			setBrowser( {
				agent: 'Mozilla/5.0 (Macintosh)',
				platform: 'Windows',
			} );
			await hintFor();
			expect(
				screen.getByText( 'Press Ctrl+C to copy' )
			).toBeInTheDocument();
		} );

		it( 'says Ctrl+C on Linux', async () => {
			setBrowser( { agent: 'Mozilla/5.0 (X11; Linux x86_64)' } );
			await hintFor();
			expect(
				screen.getByText( 'Press Ctrl+C to copy' )
			).toBeInTheDocument();
		} );
	} );

	it( 'selects the text and shows a hint when the clipboard is missing', async () => {
		const { user } = setup();
		Object.defineProperty( navigator, 'clipboard', {
			configurable: true,
			value: undefined,
		} );

		await user.click(
			screen.getByRole( 'button', { name: 'Copy login link' } )
		);

		expect(
			screen.getByText( /^Press (Ctrl|Cmd)\+C to copy$/ )
		).toBeInTheDocument();
		const link = screen.getByLabelText( 'Login link' );
		expect( link.selectionStart ).toBe( 0 );
		expect( link.selectionEnd ).toBe( link.value.length );
		expect(
			screen.getByRole( 'button', { name: 'Copy login link' } )
		).toHaveTextContent( 'Copy' );

		await user.click(
			screen.getByRole( 'button', { name: 'Copy access code' } )
		);
		expect( window.getSelection().toString() ).toBe( '4829 1375' );
	} );

	it( 'hides Send by email when no email was given', () => {
		setup();

		expect(
			screen.queryByRole( 'button', { name: 'Send by email' } )
		).not.toBeInTheDocument();
	} );

	it( 'confirms before sending, then shows the new secrets', async () => {
		const onSendEmail = vi.fn( () => Promise.resolve( { emailed: true } ) );
		const { user, live } = setup( {
			result: result( { email: 'agent@acme.test' } ),
			onSendEmail,
		} );

		await user.click(
			screen.getByRole( 'button', { name: 'Send by email' } )
		);
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			'This makes a new link and code and emails them. The ones shown here stop working.'
		);
		expect( onSendEmail ).not.toHaveBeenCalled();

		await user.click(
			screen.getByRole( 'button', { name: 'Make new ones and send' } )
		);

		expect( onSendEmail ).toHaveBeenCalledTimes( 1 );
		expect(
			await screen.findByText( /^Emailed to agent@acme.test/ )
		).toBeInTheDocument();
		await waitFor( () =>
			expect( live() ).toContain( 'emailed to agent@acme.test' )
		);
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
	} );

	it( 'says so when the email could not be sent', async () => {
		const onSendEmail = vi.fn( () =>
			Promise.resolve( { emailed: false } )
		);
		const { user } = setup( {
			result: result( { email: 'agent@acme.test' } ),
			onSendEmail,
		} );

		await user.click(
			screen.getByRole( 'button', { name: 'Send by email' } )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Make new ones and send' } )
		);

		await waitFor( () =>
			expect(
				document.querySelector( '.components-notice__content' )
			).toHaveTextContent( 'The email could not be sent.' )
		);
		expect( screen.queryByText( /^Emailed to/ ) ).not.toBeInTheDocument();
	} );

	it( 'groups the code with its name', () => {
		setup();

		expect(
			screen.getByRole( 'group', { name: 'Or the access code' } )
		).toHaveTextContent( '4829 1375' );
	} );

	it( 'blocks Copy and Done while the email is being sent', async () => {
		let finish;
		const onSendEmail = vi.fn(
			() =>
				new Promise( ( resolve ) => {
					finish = resolve;
				} )
		);
		const { user, onDone } = setup( {
			result: result( { email: 'agent@acme.test' } ),
			onSendEmail,
		} );

		await user.click(
			screen.getByRole( 'button', { name: 'Send by email' } )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Make new ones and send' } )
		);

		[
			'Copy login link',
			'Copy access code',
			'Copy message with instructions',
			'Done, give access to someone else',
		].forEach( ( name ) =>
			expect( screen.getByRole( 'button', { name } ) ).toHaveAttribute(
				'aria-disabled',
				'true'
			)
		);
		await user.click(
			screen.getByRole( 'button', {
				name: 'Done, give access to someone else',
			} )
		);
		expect( onDone ).not.toHaveBeenCalled();

		finish( { emailed: true } );
		await screen.findByText( /^Emailed to/ );
		expect(
			screen.getByRole( 'button', { name: 'Copy login link' } )
		).not.toHaveAttribute( 'aria-disabled', 'true' );
	} );

	it( 'cancels its own timers when it unmounts', async () => {
		vi.useFakeTimers();
		const realSet = globalThis.setTimeout;
		const realClear = globalThis.clearTimeout;
		const started = [];
		const cancelled = [];
		globalThis.setTimeout = ( callback, delay, ...rest ) => {
			const id = realSet( callback, delay, ...rest );
			started.push( { id, callback, delay } );
			return id;
		};
		globalThis.clearTimeout = ( id ) => {
			cancelled.push( id );
			return realClear( id );
		};
		try {
			setup();
			Object.defineProperty( navigator, 'clipboard', {
				configurable: true,
				value: undefined,
			} );

			// With no clipboard the card selects the message on the next tick.
			fireEvent.click(
				screen.getByRole( 'button', {
					name: 'Copy message with instructions',
				} )
			);
			await act( async () => {} );
			// jsdom starts its own zero-delay timer when text gets selected.
			const ours = started.filter(
				( { callback, delay } ) =>
					0 === delay &&
					! String( callback ).includes( 'selectionchange' )
			);
			expect( ours.length ).toBeGreaterThan( 0 );
			cleanup();

			ours.forEach( ( { id } ) => expect( cancelled ).toContain( id ) );
		} finally {
			globalThis.setTimeout = realSet;
			globalThis.clearTimeout = realClear;
		}
	} );

	it( 'calls onDone', async () => {
		const { user, onDone } = setup();

		await user.click(
			screen.getByRole( 'button', {
				name: 'Done, give access to someone else',
			} )
		);

		expect( onDone ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'has no accessibility violations', async () => {
		const { container } = setup( {
			result: result( { email: 'agent@acme.test' } ),
		} );

		expect( await axe( container ) ).toHaveNoViolations();
	} );
} );
