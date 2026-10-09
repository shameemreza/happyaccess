import { readFileSync } from 'node:fs';
import path from 'node:path';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import apiFetch from '@wordpress/api-fetch';
import Setup from './Setup';

vi.mock( '@wordpress/api-fetch' );

const steps = () =>
	within( screen.getByRole( 'list', { name: 'Setup steps' } ) ).getAllByRole(
		'listitem'
	);
const currentStep = () =>
	steps().filter( ( item ) => item.hasAttribute( 'aria-current' ) );

async function toConsent( user ) {
	await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
	await screen.findByRole( 'heading', {
		name: 'Before you give anyone access',
	} );
}

/**
 * One block of the setup styles, by selector, as written in the source.
 * The tests run without CSS, so the layout rules are checked here.
 *
 * @param {string} selector Selector that opens the block.
 * @return {string} The block's text up to its closing brace.
 */
function styleBlock( selector ) {
	const scss = readFileSync(
		path.resolve( 'admin-app/src/setup/_setup.scss' ),
		'utf8'
	);
	const start = scss.indexOf( selector + ' {' );
	return -1 === start ? '' : scss.slice( start, scss.indexOf( '}', start ) );
}

const CONSENT = "I understand, and I'll only give access to people I trust.";
const TWO_STEP_URL =
	'https://example.test/wp-admin/profile.php#happyaccess-twostep';
const NAMES = {
	support_access: 'Temporary access',
	passwordless: 'Passwordless login',
	two_step: 'Two-step login',
};

const box = ( name ) => screen.getByRole( 'checkbox', { name } );
const sent = () => apiFetch.mock.calls[ 0 ][ 0 ].data;
const doneButtons = () =>
	Array.from(
		document.querySelectorAll( '.ha-setup__actions .components-button' )
	).map( ( button ) => [
		button.textContent,
		button.classList.contains( 'is-primary' ) ? 'primary' : 'secondary',
	] );

/**
 * Sets the three choices on the features step, then finishes setup,
 * through the consent step when Temporary access is on.
 *
 * @param {Object} user  userEvent instance.
 * @param {Object} picks Which features to tick, by key.
 */
async function finishWith( user, picks ) {
	for ( const [ key, name ] of Object.entries( NAMES ) ) {
		const checkbox = screen.queryByRole( 'checkbox', { name } );
		if ( checkbox && !! picks[ key ] !== checkbox.checked ) {
			await user.click( checkbox );
		}
	}
	if ( picks.support_access ) {
		await toConsent( user );
		await user.click( box( CONSENT ) );
	}
	await user.click( screen.getByRole( 'button', { name: 'Finish setup' } ) );
	await screen.findByRole( 'heading', { name: "You're all set" } );
}

beforeEach( () => {
	apiFetch.mockReset();
	// The server answers with every switch, as the ones sent were saved.
	apiFetch.mockImplementation( async ( { data } ) => ( {
		features: {
			support_access: false,
			passwordless: false,
			two_step: false,
			...data.features,
		},
		needs_setup: false,
	} ) );
} );

describe( 'First-run setup', () => {
	it( 'starts on the features step with the three choices, only Temporary access ticked', () => {
		render( <Setup twoStepSetupUrl={ TWO_STEP_URL } /> );

		expect(
			screen.getByRole( 'heading', {
				level: 2,
				name: 'Set up HappyAccess',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'A few quick choices. You can change them later in Settings.'
			)
		).toBeInTheDocument();
		expect(
			screen
				.getAllByRole( 'checkbox' )
				.map( ( checkbox ) => checkbox.closest( '.ha-setup__choice' ) )
				.map(
					( choice ) => choice.querySelector( 'label' ).textContent
				)
		).toEqual( [
			'Temporary access',
			'Passwordless login',
			'Two-step login',
		] );
		expect( box( 'Temporary access' ) ).toBeChecked();
		expect( box( 'Passwordless login' ) ).not.toBeChecked();
		expect( box( 'Two-step login' ) ).not.toBeChecked();
		expect(
			screen.getByText(
				'Give support a login link or code that ends by itself. No shared passwords.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'Let people log in with a code or link sent to their email.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'Ask for a second step after the password, like a code from an authenticator app.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'group', { name: 'What do you want to use?' } )
		).toContainElement( box( 'Two-step login' ) );
		expect( steps().map( ( item ) => item.textContent ) ).toEqual( [
			'1. Features',
			'2. Consent',
			'3. Done',
		] );
		expect( currentStep() ).toHaveLength( 1 );
		expect( currentStep()[ 0 ] ).toHaveTextContent( '1. Features' );
		expect( currentStep()[ 0 ] ).toHaveAttribute( 'aria-current', 'step' );
		expect(
			screen.getByRole( 'button', { name: 'Continue' } )
		).not.toHaveAttribute( 'aria-disabled' );
	} );

	it( 'will not go on with nothing picked, and keeps Continue focusable', async () => {
		const user = userEvent.setup();
		render( <Setup /> );

		await user.click( box( 'Temporary access' ) );

		const next = screen.getByRole( 'button', { name: 'Continue' } );
		expect( next ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( next ).not.toBeDisabled();
		expect(
			screen.queryByRole( 'button', { name: 'Finish setup' } )
		).not.toBeInTheDocument();
		await user.click( next );
		expect(
			screen.getByRole( 'heading', { name: 'What do you want to use?' } )
		).toBeInTheDocument();
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	it( 'drops the consent step from the bar and finishes from step 1 without Temporary access', async () => {
		const user = userEvent.setup();
		render( <Setup /> );

		await user.click( box( 'Temporary access' ) );
		await user.click( box( 'Passwordless login' ) );

		expect( steps().map( ( item ) => item.textContent ) ).toEqual( [
			'1. Features',
			'2. Done',
		] );
		expect(
			screen.queryByRole( 'button', { name: 'Continue' } )
		).not.toBeInTheDocument();
		await user.click(
			screen.getByRole( 'button', { name: 'Finish setup' } )
		);

		await screen.findByRole( 'heading', { name: "You're all set" } );
		expect( sent() ).toEqual( {
			features: {
				support_access: false,
				passwordless: true,
				two_step: false,
			},
			consent: false,
		} );
		expect( currentStep()[ 0 ] ).toHaveTextContent( '2. Done' );
		expect(
			screen.queryByRole( 'heading', {
				name: 'Before you give anyone access',
			} )
		).not.toBeInTheDocument();
	} );

	// Every pick that can be finished: the bar, what is sent, and the Done buttons.
	it.each( [
		[
			{ support_access: true },
			[ 'Features', 'Consent', 'Done' ],
			[ [ 'Give temporary access', 'primary' ] ],
		],
		[
			{ passwordless: true },
			[ 'Features', 'Done' ],
			[ [ 'Choose how each role logs in', 'primary' ] ],
		],
		[
			{ two_step: true },
			[ 'Features', 'Done' ],
			[ [ 'Set up two-step login for your account', 'primary' ] ],
		],
		[
			{ support_access: true, passwordless: true },
			[ 'Features', 'Consent', 'Done' ],
			[
				[ 'Give temporary access', 'primary' ],
				[ 'Choose how each role logs in', 'secondary' ],
			],
		],
		[
			{ support_access: true, two_step: true },
			[ 'Features', 'Consent', 'Done' ],
			[
				[ 'Give temporary access', 'primary' ],
				[ 'Set up two-step login for your account', 'secondary' ],
			],
		],
		[
			{ passwordless: true, two_step: true },
			[ 'Features', 'Done' ],
			[
				[ 'Choose how each role logs in', 'primary' ],
				[ 'Set up two-step login for your account', 'secondary' ],
			],
		],
		[
			{ support_access: true, passwordless: true, two_step: true },
			[ 'Features', 'Consent', 'Done' ],
			[
				[ 'Give temporary access', 'primary' ],
				[ 'Choose how each role logs in', 'secondary' ],
				[ 'Set up two-step login for your account', 'secondary' ],
			],
		],
	] )( 'finishes with %o', async ( picks, bar, buttons ) => {
		const user = userEvent.setup();
		render( <Setup twoStepSetupUrl={ TWO_STEP_URL } /> );

		await finishWith( user, picks );

		expect( steps().map( ( item ) => item.textContent ) ).toEqual(
			bar.map( ( label, index ) => `${ index + 1 }. ${ label }` )
		);
		expect( currentStep()[ 0 ] ).toHaveTextContent(
			`${ bar.length }. Done`
		);
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ] ).toMatchObject( {
			path: '/happyaccess/v1/setup',
			method: 'POST',
		} );
		expect( sent() ).toEqual( {
			features: {
				support_access: !! picks.support_access,
				passwordless: !! picks.passwordless,
				two_step: !! picks.two_step,
			},
			consent: !! picks.support_access,
		} );
		expect( doneButtons() ).toEqual( buttons );
		expect(
			screen.getByText(
				picks.support_access
					? 'Next time someone needs to get into your admin, send them a link or code instead of a password.'
					: "Your login options are on. Here's where to set them up."
			)
		).toBeInTheDocument();
	} );

	it( 'opens the right tab from each Done button, and links two-step to the profile', async () => {
		const onFinish = vi.fn();
		const user = userEvent.setup();
		render(
			<Setup onFinish={ onFinish } twoStepSetupUrl={ TWO_STEP_URL } />
		);
		await finishWith( user, {
			support_access: true,
			passwordless: true,
			two_step: true,
		} );
		const saved = {
			support_access: true,
			passwordless: true,
			two_step: true,
		};
		expect( onFinish ).not.toHaveBeenCalled();

		expect(
			screen.getByRole( 'link', {
				name: 'Set up two-step login for your account',
			} )
		).toHaveAttribute( 'href', TWO_STEP_URL );

		await user.click(
			screen.getByRole( 'button', {
				name: 'Choose how each role logs in',
			} )
		);
		expect( onFinish ).toHaveBeenLastCalledWith( saved, 'login' );

		await user.click(
			screen.getByRole( 'button', { name: 'Give temporary access' } )
		);
		expect( onFinish ).toHaveBeenLastCalledWith( saved, 'support' );
	} );

	it( 'hides two-step login when the main site decides it, and never sends it', async () => {
		const user = userEvent.setup();
		render(
			<Setup twoStepNetwork="main" twoStepSetupUrl={ TWO_STEP_URL } />
		);

		expect(
			screen
				.getAllByRole( 'checkbox' )
				.map(
					( checkbox ) =>
						checkbox.closest( '.ha-setup__choice' ).textContent
				)
		).toEqual( [
			expect.stringContaining( 'Temporary access' ),
			expect.stringContaining( 'Passwordless login' ),
		] );
		expect(
			screen.queryByText( 'Two-step login' )
		).not.toBeInTheDocument();

		await finishWith( user, { passwordless: true } );

		expect( sent() ).toEqual( {
			features: { support_access: false, passwordless: true },
			consent: false,
		} );
		expect( 'two_step' in sent().features ).toBe( false );
		expect( doneButtons() ).toEqual( [
			[ 'Choose how each role logs in', 'primary' ],
		] );
	} );

	it( 'keeps two-step login on a subdirectory network, where each site decides', () => {
		render( <Setup twoStepNetwork="subdir" /> );

		expect( box( 'Two-step login' ) ).toBeInTheDocument();
	} );

	it( 'shows the consent text and keeps Finish setup off until it is ticked', async () => {
		const user = userEvent.setup();
		render( <Setup /> );
		await toConsent( user );

		expect( currentStep()[ 0 ] ).toHaveTextContent( '2. Consent' );
		expect(
			screen.getByText(
				'Read this once. It applies to everyone you give temporary access.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				"Temporary access lets someone outside your team into your site's admin, for as long as you choose."
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'Protected admin blocks the riskiest actions and logs what they do, but it is not a sandbox. Only give access to people you trust.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'Their login, IP address and actions are recorded here so you can review them. You are responsible for telling your visitors if your privacy policy needs it.'
			)
		).toBeInTheDocument();
		expect( document.body ).not.toHaveTextContent( /support pass/i );

		const finish = screen.getByRole( 'button', { name: 'Finish setup' } );
		const consent = box( CONSENT );
		expect( consent ).not.toBeChecked();
		expect( finish ).toHaveAttribute( 'aria-disabled', 'true' );

		await user.click( consent );
		expect( finish ).not.toHaveAttribute( 'aria-disabled', 'true' );
		await user.click( consent );
		expect( finish ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	it( 'goes back to the features step with the picks kept', async () => {
		const user = userEvent.setup();
		render( <Setup /> );
		await user.click( box( 'Passwordless login' ) );
		await toConsent( user );

		await user.click( screen.getByRole( 'button', { name: 'Back' } ) );

		expect(
			screen.getByRole( 'heading', { name: 'What do you want to use?' } )
		).toBeInTheDocument();
		expect( box( 'Temporary access' ) ).toBeChecked();
		expect( box( 'Passwordless login' ) ).toBeChecked();
	} );

	it( 'puts focus on the Done heading and waits for a button before handing over', async () => {
		const onFinish = vi.fn();
		const user = userEvent.setup();
		render( <Setup onFinish={ onFinish } /> );

		await finishWith( user, { support_access: true } );

		expect(
			screen.getByRole( 'heading', { name: "You're all set" } )
		).toHaveFocus();
		expect( onFinish ).not.toHaveBeenCalled();

		await user.click(
			screen.getByRole( 'button', { name: 'Give temporary access' } )
		);

		expect( onFinish ).toHaveBeenCalledWith(
			{
				support_access: true,
				passwordless: false,
				two_step: false,
			},
			'support'
		);
	} );

	it( 'keeps focus on Finish setup while it saves', async () => {
		let release;
		apiFetch.mockImplementation(
			() =>
				new Promise( ( resolve ) => {
					release = () =>
						resolve( { features: { support_access: true } } );
				} )
		);
		const user = userEvent.setup();
		render( <Setup /> );
		await toConsent( user );
		await user.click( box( CONSENT ) );
		const finish = screen.getByRole( 'button', { name: 'Finish setup' } );

		await user.click( finish );

		await waitFor( () => expect( release ).toBeDefined() );
		expect( finish ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( finish ).not.toBeDisabled();
		expect( finish ).toHaveFocus();
		release();
		await screen.findByRole( 'heading', { name: "You're all set" } );
	} );

	it( 'keeps focus on Back while it saves', async () => {
		let release;
		apiFetch.mockImplementation(
			() =>
				new Promise( ( resolve ) => {
					release = () =>
						resolve( { features: { support_access: true } } );
				} )
		);
		const user = userEvent.setup();
		render( <Setup /> );
		await toConsent( user );
		await user.click( box( CONSENT ) );
		await user.click(
			screen.getByRole( 'button', { name: 'Finish setup' } )
		);
		await waitFor( () => expect( release ).toBeDefined() );
		const back = screen.getByRole( 'button', { name: 'Back' } );

		act( () => back.focus() );

		expect( back ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( back ).not.toBeDisabled();
		expect( back ).toHaveFocus();
		release();
		await screen.findByRole( 'heading', { name: "You're all set" } );
	} );

	it( 'stays on the consent step and shows the error when saving fails', async () => {
		apiFetch.mockRejectedValue( {
			code: 'happyaccess_consent_required',
			message: 'Please confirm before giving anyone access.',
			data: { status: 400 },
		} );
		const user = userEvent.setup();
		render( <Setup /> );
		await toConsent( user );

		await user.click( box( CONSENT ) );
		await user.click(
			screen.getByRole( 'button', { name: 'Finish setup' } )
		);

		await waitFor( () =>
			expect( document.querySelector( '.ha-setup' ) ).toHaveTextContent(
				'Please confirm before giving anyone access.'
			)
		);
		expect(
			screen.getByRole( 'heading', {
				name: 'Before you give anyone access',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Finish setup' } )
		).not.toHaveAttribute( 'aria-disabled', 'true' );
	} );

	it( 'stays on the features step and shows the error when a finish from there fails', async () => {
		apiFetch.mockRejectedValue( {
			code: 'happyaccess_settings_not_saved',
			message: 'Settings could not be saved. Please try again.',
			data: { status: 500 },
		} );
		const user = userEvent.setup();
		render( <Setup /> );
		await user.click( box( 'Temporary access' ) );
		await user.click( box( 'Two-step login' ) );

		await user.click(
			screen.getByRole( 'button', { name: 'Finish setup' } )
		);

		await waitFor( () =>
			expect( document.querySelector( '.ha-setup' ) ).toHaveTextContent(
				'Settings could not be saved. Please try again.'
			)
		);
		expect(
			screen.getByRole( 'heading', { name: 'What do you want to use?' } )
		).toBeInTheDocument();
	} );

	it( 'has no accessibility violations on any step', async () => {
		const user = userEvent.setup();
		const { container } = render(
			<Setup twoStepSetupUrl={ TWO_STEP_URL } />
		);
		expect( await axe( container ) ).toHaveNoViolations();

		await user.click( box( 'Passwordless login' ) );
		await user.click( box( 'Two-step login' ) );
		await toConsent( user );
		expect( await axe( container ) ).toHaveNoViolations();

		await user.click( box( CONSENT ) );
		await user.click(
			screen.getByRole( 'button', { name: 'Finish setup' } )
		);
		await screen.findByRole( 'heading', { name: "You're all set" } );
		expect( doneButtons() ).toHaveLength( 3 );
		expect( await axe( container ) ).toHaveNoViolations();
	} );

	it( 'has no accessibility violations without Temporary access, or with two-step hidden', async () => {
		const user = userEvent.setup();
		const { container } = render( <Setup twoStepNetwork="main" /> );
		expect( await axe( container ) ).toHaveNoViolations();

		await user.click( box( 'Temporary access' ) );
		expect( await axe( container ) ).toHaveNoViolations();

		await finishWith( user, { passwordless: true } );
		expect( await axe( container ) ).toHaveNoViolations();
	} );

	it( 'stacks the Done buttons in one column of equal width, primary on top', async () => {
		const user = userEvent.setup();
		render( <Setup twoStepSetupUrl={ TWO_STEP_URL } /> );

		await finishWith( user, {
			support_access: true,
			passwordless: true,
			two_step: true,
		} );

		expect( doneButtons()[ 0 ] ).toEqual( [
			'Give temporary access',
			'primary',
		] );
		const actions = styleBlock( '.ha-setup__actions' );
		expect( actions ).toContain( 'display: grid;' );
		expect( actions ).toContain(
			'grid-template-columns: fit-content(100%);'
		);
		expect( actions ).toContain( 'justify-content: center;' );
		expect( actions ).not.toContain( 'flex-wrap' );
	} );

	it( 'shows no focus box on a step heading that code focuses', async () => {
		const user = userEvent.setup();
		render( <Setup /> );

		await finishWith( user, { passwordless: true } );

		const heading = screen.getByRole( 'heading', {
			name: "You're all set",
		} );
		expect( heading ).toHaveFocus();
		expect( heading ).toHaveAttribute( 'tabindex', '-1' );
		const focus = styleBlock( '&:focus,\n\t\t\t&:focus-visible' );
		expect( focus ).toContain( 'outline: none;' );
		expect( focus ).not.toContain( 'solid' );
	} );
} );
