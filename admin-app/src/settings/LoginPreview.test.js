import { expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { axe } from 'jest-axe';
import LoginPreview from './LoginPreview';

it( 'shows the support code link when Support access is on', () => {
	render( <LoginPreview supportAccess /> );

	expect(
		screen.getByText( 'Have a support access code?' )
	).toBeInTheDocument();
	expect( screen.getByText( 'Added by HappyAccess' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Lost your password?' ) ).toBeInTheDocument();
	expect(
		screen.getByRole( 'complementary', { name: 'Your login screen' } )
	).toBeInTheDocument();
} );

it( 'leaves the link out when Support access is off', () => {
	render( <LoginPreview supportAccess={ false } /> );

	expect(
		screen.queryByText( 'Have a support access code?' )
	).not.toBeInTheDocument();
	expect(
		screen.queryByText( 'Added by HappyAccess' )
	).not.toBeInTheDocument();
	expect(
		screen.getByText( 'Visitors see the usual login form only.' )
	).toBeInTheDocument();
} );

it( 'has no accessibility violations', async () => {
	const { container } = render( <LoginPreview supportAccess /> );
	expect( await axe( container ) ).toHaveNoViolations();
} );
