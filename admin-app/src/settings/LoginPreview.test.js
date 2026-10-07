import { expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { axe } from 'jest-axe';
import LoginPreview from './LoginPreview';

it( 'shows the support code link when Support access is on', () => {
	render( <LoginPreview supportAccess /> );

	expect(
		screen.getByText( 'Have a support access code?' )
	).toBeInTheDocument();
	expect( screen.getByText( 'Lost your password?' ) ).toBeInTheDocument();
	expect(
		screen.getByRole( 'complementary', { name: 'Your login screen' } )
	).toBeInTheDocument();
} );

it( 'keeps the mock screen clean and explains the change below it', () => {
	const { container } = render( <LoginPreview supportAccess /> );

	const screenMock = container.querySelector( '.ha-loginprev__screen' );
	expect( screenMock.textContent ).not.toContain( 'HappyAccess' );
	expect(
		screen.getByText(
			'HappyAccess adds the "Have a support access code?" link below the login form.'
		)
	).toBeInTheDocument();
} );

it( 'leaves the link out when Support access is off', () => {
	render( <LoginPreview supportAccess={ false } /> );

	expect(
		screen.queryByText( 'Have a support access code?' )
	).not.toBeInTheDocument();
	expect(
		screen.getByText(
			'With Support access off, your login screen stays as it is.'
		)
	).toBeInTheDocument();
} );

it( 'has no accessibility violations', async () => {
	const { container } = render( <LoginPreview supportAccess /> );
	expect( await axe( container ) ).toHaveNoViolations();
} );
