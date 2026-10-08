import { describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import RolePolicyTable from './RolePolicyTable';

const ROLES = [
	{ slug: 'administrator', name: 'Administrator', isAdmin: true },
	{ slug: 'editor', name: 'Editor', isAdmin: false },
	{ slug: 'customer', name: 'Customer', isAdmin: false },
];

describe( 'RolePolicyTable', () => {
	it( 'shows one row per role, with the two choices', () => {
		render(
			<RolePolicyTable
				roles={ ROLES }
				policy={ { editor: 'email_only' } }
				onChange={ () => {} }
			/>
		);

		const rows = screen.getAllByRole( 'listitem' );
		expect( rows ).toHaveLength( 3 );
		expect(
			within( screen.getByLabelText( 'Customer' ) )
				.getAllByRole( 'option' )
				.map( ( option ) => option.textContent )
		).toEqual( [ 'Password or email code', 'Email code only' ] );
		expect( screen.getByLabelText( 'Editor' ) ).toHaveValue( 'email_only' );
		// A role the policy leaves out logs in either way.
		expect( screen.getByLabelText( 'Customer' ) ).toHaveValue( 'either' );
	} );

	it( 'calls onChange with the role slug and the choice', async () => {
		const user = userEvent.setup();
		const onChange = vi.fn();
		render(
			<RolePolicyTable
				roles={ ROLES }
				policy={ {} }
				onChange={ onChange }
			/>
		);

		await user.selectOptions(
			screen.getByLabelText( 'Customer' ),
			'email_only'
		);

		expect( onChange ).toHaveBeenCalledWith( 'customer', 'email_only' );
	} );

	it( 'warns only for a role that can manage the site, set to email code only', () => {
		const { rerender } = render(
			<RolePolicyTable
				roles={ ROLES }
				policy={ { editor: 'email_only' } }
				onChange={ () => {} }
			/>
		);
		expect( screen.queryByText( /Anyone who can read/ ) ).toBeNull();

		rerender(
			<RolePolicyTable
				roles={ ROLES }
				policy={ { administrator: 'email_only' } }
				onChange={ () => {} }
			/>
		);
		expect( screen.getByText( /Anyone who can read/ ) ).toBeInTheDocument();
	} );

	it( 'says so when there are no roles', () => {
		render(
			<RolePolicyTable roles={ [] } policy={ {} } onChange={ () => {} } />
		);

		expect( screen.getByText( 'No roles found.' ) ).toBeInTheDocument();
	} );

	it( 'has no accessibility violations', async () => {
		const { container } = render(
			<RolePolicyTable
				roles={ ROLES }
				policy={ { administrator: 'email_only' } }
				onChange={ () => {} }
			/>
		);

		expect( await axe( container ) ).toHaveNoViolations();
	} );
} );
