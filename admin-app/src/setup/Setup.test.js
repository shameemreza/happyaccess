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

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockResolvedValue( {
		features: {
			support_access: true,
			passwordless: false,
			two_step: false,
		},
		needs_setup: false,
	} );
} );

describe( 'First-run setup', () => {
	it( 'starts on the features step, with only Support access, ticked', () => {
		render( <Setup /> );

		expect(
			screen.getByRole( 'heading', {
				level: 2,
				name: 'Set up HappyAccess',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'checkbox', { name: 'Temporary access' } )
		).toBeChecked();
		expect( screen.getAllByRole( 'checkbox' ) ).toHaveLength( 1 );
		expect(
			screen.queryByText( 'Passwordless login' )
		).not.toBeInTheDocument();
		expect(
			screen.queryByText( 'Two-step login' )
		).not.toBeInTheDocument();
		expect( steps().map( ( item ) => item.textContent ) ).toEqual( [
			'1. Features',
			'2. Consent',
			'3. Done',
		] );
		expect( currentStep() ).toHaveLength( 1 );
		expect( currentStep()[ 0 ] ).toHaveTextContent( '1. Features' );
		expect( currentStep()[ 0 ] ).toHaveAttribute( 'aria-current', 'step' );
	} );

	it( 'will not go on with nothing picked', async () => {
		const user = userEvent.setup();
		render( <Setup /> );

		await user.click(
			screen.getByRole( 'checkbox', { name: 'Temporary access' } )
		);

		expect(
			screen.getByRole( 'button', { name: 'Continue' } )
		).toBeDisabled();
	} );

	it( 'shows the consent text and keeps Finish setup off until it is ticked', async () => {
		const user = userEvent.setup();
		render( <Setup /> );
		await toConsent( user );

		expect( currentStep()[ 0 ] ).toHaveTextContent( '2. Consent' );
		expect(
			screen.getByText(
				"A support pass lets someone outside your team into your site's admin, for as long as you choose."
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

		const finish = screen.getByRole( 'button', { name: 'Finish setup' } );
		const consent = screen.getByRole( 'checkbox', {
			name: "I understand, and I'll only give access to people I trust.",
		} );
		expect( consent ).not.toBeChecked();
		expect( finish ).toHaveAttribute( 'aria-disabled', 'true' );

		await user.click( consent );
		expect( finish ).not.toHaveAttribute( 'aria-disabled', 'true' );
		await user.click( consent );
		expect( finish ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	it( 'goes back to the features step', async () => {
		const user = userEvent.setup();
		render( <Setup /> );
		await toConsent( user );

		await user.click( screen.getByRole( 'button', { name: 'Back' } ) );

		expect(
			screen.getByRole( 'heading', { name: 'What do you want to use?' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'checkbox', { name: 'Temporary access' } )
		).toBeChecked();
	} );

	it( 'saves with consent, shows Done, and hands the features to the parent on the last button', async () => {
		const onFinish = vi.fn();
		const user = userEvent.setup();
		render( <Setup onFinish={ onFinish } /> );
		await toConsent( user );

		await user.click(
			screen.getByRole( 'checkbox', {
				name: "I understand, and I'll only give access to people I trust.",
			} )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Finish setup' } )
		);

		const heading = await screen.findByRole( 'heading', {
			name: "You're all set",
		} );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ] ).toMatchObject( {
			path: '/happyaccess/v1/setup',
			method: 'POST',
			data: { features: { support_access: true }, consent: true },
		} );
		expect( heading ).toHaveFocus();
		expect( currentStep()[ 0 ] ).toHaveTextContent( '3. Done' );
		expect( onFinish ).not.toHaveBeenCalled();

		await user.click(
			screen.getByRole( 'button', {
				name: 'Create your first support pass',
			} )
		);

		expect( onFinish ).toHaveBeenCalledWith( {
			support_access: true,
			passwordless: false,
			two_step: false,
		} );
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
		await user.click(
			screen.getByRole( 'checkbox', {
				name: "I understand, and I'll only give access to people I trust.",
			} )
		);
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
		await user.click(
			screen.getByRole( 'checkbox', {
				name: "I understand, and I'll only give access to people I trust.",
			} )
		);
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

		await user.click(
			screen.getByRole( 'checkbox', {
				name: "I understand, and I'll only give access to people I trust.",
			} )
		);
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

	it( 'has no accessibility violations on any step', async () => {
		const user = userEvent.setup();
		const { container } = render( <Setup /> );
		expect( await axe( container ) ).toHaveNoViolations();

		await toConsent( user );
		expect( await axe( container ) ).toHaveNoViolations();

		await user.click(
			screen.getByRole( 'checkbox', {
				name: "I understand, and I'll only give access to people I trust.",
			} )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Finish setup' } )
		);
		await screen.findByRole( 'heading', { name: "You're all set" } );
		expect( await axe( container ) ).toHaveNoViolations();
	} );
} );
