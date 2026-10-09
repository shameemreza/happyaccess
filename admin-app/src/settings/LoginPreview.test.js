import { afterEach, expect, it, vi } from 'vitest';
import { act, render, screen, fireEvent } from '@testing-library/react';
import { axe } from 'jest-axe';
import LoginPreview from './LoginPreview';

afterEach( () => {
	vi.unstubAllGlobals();
} );

it( 'waits to draw the marker until the preview is on screen', () => {
	let notify;
	const observe = vi.fn();
	const disconnect = vi.fn();
	vi.stubGlobal(
		'IntersectionObserver',
		vi.fn( function ( callback ) {
			notify = callback;
			this.observe = observe;
			this.disconnect = disconnect;
		} )
	);

	const { container } = render( <LoginPreview supportAccess /> );
	const preview = container.querySelector( '.ha-loginprev' );
	expect( observe ).toHaveBeenCalled();
	expect( preview ).not.toHaveClass( 'is-visible' );

	act( () => notify( [ { isIntersecting: true } ] ) );
	expect( preview ).toHaveClass( 'is-visible' );
	expect( disconnect ).toHaveBeenCalled();
} );

it( 'draws the bubble as a hand-drawn shape, not a box', () => {
	const { container } = render( <LoginPreview supportAccess /> );
	expect(
		container.querySelector( '.ha-loginprev__bubble .ha-loginprev__shape' )
	).not.toBeNull();
} );

it( 'shows the support code link when Support access is on', () => {
	render( <LoginPreview supportAccess /> );

	expect(
		screen.getByRole( 'button', { name: 'Log in with an access code' } )
	).toBeInTheDocument();
	expect( screen.getByText( 'Lost your password?' ) ).toBeInTheDocument();
	expect(
		screen.getByRole( 'complementary', { name: 'Your login screen' } )
	).toBeInTheDocument();
} );

it( 'circles the added link and explains it in a bubble', () => {
	const { container } = render( <LoginPreview supportAccess /> );

	expect( container.querySelector( '.ha-loginprev__loop' ) ).not.toBeNull();
	expect( screen.getByText( 'Temporary access' ) ).toBeInTheDocument();
	expect(
		screen.queryByText(
			'Support people click here and enter their 8-digit code.'
		)
	).not.toBeInTheDocument();
	expect(
		screen.getByRole( 'button', { name: 'Log in with an access code' } )
	).toHaveAttribute( 'aria-expanded', 'true' );
} );

it( 'opens and closes the bubble from the circled link', () => {
	render( <LoginPreview supportAccess /> );
	const link = screen.getByRole( 'button', {
		name: 'Log in with an access code',
	} );

	fireEvent.click( link );
	expect( link ).toHaveAttribute( 'aria-expanded', 'false' );
	expect( screen.queryByText( 'Temporary access' ) ).not.toBeInTheDocument();

	fireEvent.click( link );
	expect( screen.getByText( 'Temporary access' ) ).toBeInTheDocument();

	fireEvent.keyDown( link, { key: 'Escape' } );
	expect( link ).toHaveAttribute( 'aria-expanded', 'false' );
} );

it( 'leaves the link and the marker out when Support access is off', () => {
	const { container } = render( <LoginPreview supportAccess={ false } /> );

	expect(
		screen.queryByText( 'Log in with an access code' )
	).not.toBeInTheDocument();
	expect( container.querySelector( '.ha-loginprev__loop' ) ).toBeNull();
	expect( screen.queryByText( 'Temporary access' ) ).not.toBeInTheDocument();
} );

it( 'has no accessibility violations', async () => {
	const { container } = render( <LoginPreview supportAccess /> );
	expect( await axe( container ) ).toHaveNoViolations();
} );
