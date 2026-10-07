import { expect, it } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { axe } from 'jest-axe';
import LoginPreview from './LoginPreview';

it( 'shows the support code link when Support access is on', () => {
	render( <LoginPreview supportAccess /> );

	expect(
		screen.getByRole( 'button', { name: 'Have a support access code?' } )
	).toBeInTheDocument();
	expect( screen.getByText( 'Lost your password?' ) ).toBeInTheDocument();
	expect(
		screen.getByRole( 'complementary', { name: 'Your login screen' } )
	).toBeInTheDocument();
} );

it( 'circles the added link and explains it in a bubble', () => {
	const { container } = render( <LoginPreview supportAccess /> );

	expect( container.querySelector( '.ha-loginprev__loop' ) ).not.toBeNull();
	expect( screen.getByText( 'Added by HappyAccess' ) ).toBeInTheDocument();
	expect(
		screen.getByText(
			'Support people click here and enter their 8-digit code.'
		)
	).toBeInTheDocument();
	expect(
		screen.getByRole( 'button', { name: 'Have a support access code?' } )
	).toHaveAttribute( 'aria-expanded', 'true' );
} );

it( 'opens and closes the bubble from the circled link', () => {
	render( <LoginPreview supportAccess /> );
	const link = screen.getByRole( 'button', {
		name: 'Have a support access code?',
	} );

	fireEvent.click( link );
	expect( link ).toHaveAttribute( 'aria-expanded', 'false' );
	expect(
		screen.queryByText( 'Added by HappyAccess' )
	).not.toBeInTheDocument();

	fireEvent.click( link );
	expect( screen.getByText( 'Added by HappyAccess' ) ).toBeInTheDocument();

	fireEvent.keyDown( link, { key: 'Escape' } );
	expect( link ).toHaveAttribute( 'aria-expanded', 'false' );
} );

it( 'leaves the link and the marker out when Support access is off', () => {
	const { container } = render( <LoginPreview supportAccess={ false } /> );

	expect(
		screen.queryByText( 'Have a support access code?' )
	).not.toBeInTheDocument();
	expect( container.querySelector( '.ha-loginprev__loop' ) ).toBeNull();
	expect(
		screen.queryByText( 'Added by HappyAccess' )
	).not.toBeInTheDocument();
} );

it( 'has no accessibility violations', async () => {
	const { container } = render( <LoginPreview supportAccess /> );
	expect( await axe( container ) ).toHaveNoViolations();
} );
