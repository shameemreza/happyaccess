import { useState } from '@wordpress/element';
import { describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import PermissionEditor from './PermissionEditor';
import { catalog } from './fixtures';

// Holds the caps the way GrantForm does.
function Harness( {
	onCaps = () => {},
	start = 'administrator',
	data = catalog,
} ) {
	const [ base, setBase ] = useState( start );
	const [ caps, setCaps ] = useState( data.presets[ start ] );
	return (
		<PermissionEditor
			catalog={ data }
			caps={ caps }
			base={ base }
			onBaseChange={ ( key ) => {
				setBase( key );
				setCaps( data.presets[ key ] );
			} }
			onCapsChange={ ( next ) => {
				setCaps( next );
				onCaps( next );
			} }
		/>
	);
}

const group = ( name ) =>
	screen.getByRole( 'switch', { name } ).closest( '.ha-group' );

describe( 'PermissionEditor', () => {
	it( 'offers the presets that exist and fills the counts from the one chosen', async () => {
		const user = userEvent.setup();
		render( <Harness /> );

		const select = screen.getByLabelText( 'Start from' );
		expect(
			within( select )
				.getAllByRole( 'option' )
				.map( ( option ) => option.textContent )
		).toEqual( [ 'Administrator', 'Shop manager', 'Editor' ] );
		expect( screen.getByText( '7 of 7 permissions' ) ).toBeInTheDocument();

		await user.selectOptions( select, 'editor' );

		expect( screen.getByText( '3 of 7 permissions' ) ).toBeInTheDocument();
		expect(
			within( group( 'Content' ) ).getByText( '3 of 3' )
		).toBeInTheDocument();
		expect(
			within( group( 'Plugins and updates' ) ).getByText( '0 of 2' )
		).toBeInTheDocument();
	} );

	it( 'isolates the counts, so RTL pages keep their numbers in order', () => {
		render( <Harness start="editor" /> );
		expect( screen.getByText( '3 of 7 permissions' ).tagName ).toBe(
			'BDI'
		);
		expect(
			within( group( 'Content' ) ).getByText( '3 of 3' ).tagName
		).toBe( 'BDI' );
	} );

	it( 'leaves a preset out when the site has no such role', () => {
		render(
			<Harness
				start="editor"
				data={ {
					...catalog,
					presets: { editor: catalog.presets.editor },
				} }
			/>
		);

		expect(
			within( screen.getByLabelText( 'Start from' ) ).getAllByRole(
				'option'
			)
		).toHaveLength( 1 );
	} );

	it( 'shows a switch as on, half or off, and a click sets every cap in the group', async () => {
		const user = userEvent.setup();
		const onCaps = vi.fn();
		render( <Harness start="shop_manager" onCaps={ onCaps } /> );

		const content = screen.getByRole( 'switch', { name: 'Content' } );
		const store = screen.getByRole( 'switch', { name: 'Store' } );
		const plugins = screen.getByRole( 'switch', {
			name: 'Plugins and updates',
		} );
		expect( content ).toHaveAttribute( 'aria-checked', 'mixed' );
		expect( store ).toHaveAttribute( 'aria-checked', 'true' );
		expect( plugins ).toHaveAttribute( 'aria-checked', 'false' );

		await user.click( plugins );
		expect( plugins ).toHaveAttribute( 'aria-checked', 'true' );
		expect( onCaps ).toHaveBeenLastCalledWith( [
			'edit_posts',
			'edit_shop_orders',
			'view_woocommerce_reports',
			'activate_plugins',
			'install_plugins',
		] );

		await user.click( plugins );
		expect( plugins ).toHaveAttribute( 'aria-checked', 'false' );

		// A half-ticked group turns fully on first.
		await user.click( content );
		expect( content ).toHaveAttribute( 'aria-checked', 'true' );
		expect(
			within( group( 'Content' ) ).getByText( '3 of 3' )
		).toBeInTheDocument();
	} );

	it( 'starts with every group collapsed', () => {
		render( <Harness /> );

		screen
			.getAllByRole( 'button', { expanded: false } )
			.forEach( ( button ) =>
				expect( button ).toHaveAttribute( 'aria-expanded', 'false' )
			);
		expect(
			screen.queryAllByRole( 'button', { expanded: true } )
		).toHaveLength( 0 );
		expect( screen.queryAllByRole( 'checkbox' ) ).toHaveLength( 0 );
	} );

	it( 'puts the knob at the end and white when all are on, in the middle when some, at the start and dark when none', async () => {
		const user = userEvent.setup();
		render( <Harness start="shop_manager" /> );
		const knob = ( name ) =>
			screen
				.getByRole( 'switch', { name } )
				.querySelector( '.ha-switch__knob' );

		expect( knob( 'Store' ).dataset.state ).toBe( 'on' );
		expect( knob( 'Content' ).dataset.state ).toBe( 'mixed' );
		expect( knob( 'Plugins and updates' ).dataset.state ).toBe( 'off' );

		await user.click( screen.getByRole( 'switch', { name: 'Store' } ) );
		expect( knob( 'Store' ).dataset.state ).toBe( 'off' );
	} );

	it( 'expands a group to its caps, each with the raw cap name', async () => {
		const user = userEvent.setup();
		render( <Harness start="editor" /> );

		const toggle = within( group( 'Content' ) ).getByRole( 'button', {
			name: /Content/,
		} );
		expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
		await user.click( toggle );

		expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
		expect( screen.getByText( 'upload_files' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'checkbox', { name: /Upload media/ } )
		).toBeChecked();
	} );

	it( 'ticks and unticks a single cap', async () => {
		const user = userEvent.setup();
		const onCaps = vi.fn();
		render( <Harness start="editor" onCaps={ onCaps } /> );
		await user.click(
			within( group( 'Content' ) ).getByRole( 'button', {
				name: /Content/,
			} )
		);

		await user.click(
			screen.getByRole( 'checkbox', { name: /Upload media/ } )
		);

		expect( onCaps ).toHaveBeenLastCalledWith( [
			'edit_posts',
			'edit_others_posts',
		] );
		expect( screen.getByText( '2 of 7 permissions' ) ).toBeInTheDocument();
	} );

	it( 'marks admin-level caps with a badge', async () => {
		const user = userEvent.setup();
		render( <Harness start="editor" /> );
		await user.click(
			within( group( 'Plugins and updates' ) ).getByRole( 'button', {
				name: /Plugins and updates/,
			} )
		);

		const trusted = screen.getByRole( 'checkbox', {
			name: /Install plugins/,
		} );
		expect(
			within( trusted.closest( 'label' ) ).getByText( 'Admin-level' )
		).toBeInTheDocument();
		expect( screen.getAllByText( 'Admin-level' ) ).toHaveLength( 2 );
	} );

	it( 'filters by label or cap and opens the groups that match', async () => {
		const user = userEvent.setup();
		render( <Harness /> );

		await user.type(
			screen.getByRole( 'searchbox', { name: 'Find a permission' } ),
			'plugins'
		);

		expect(
			screen.getByRole( 'switch', { name: 'Plugins and updates' } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'switch', { name: 'Content' } )
		).not.toBeInTheDocument();
		expect(
			screen.getByRole( 'checkbox', { name: /Turn plugins on and off/ } )
		).toBeInTheDocument();
		// The total still counts everything.
		expect( screen.getByText( '7 of 7 permissions' ) ).toBeInTheDocument();

		await user.clear( screen.getByRole( 'searchbox' ) );
		await user.type( screen.getByRole( 'searchbox' ), 'upload_files' );
		expect(
			screen.getByRole( 'checkbox', { name: /Upload media/ } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'checkbox', { name: /Edit posts by others/ } )
		).not.toBeInTheDocument();

		await user.clear( screen.getByRole( 'searchbox' ) );
		await user.type( screen.getByRole( 'searchbox' ), 'zzz' );
		expect(
			screen.getByText( 'No permissions match.' )
		).toBeInTheDocument();
	} );

	it( 'says what is always locked', () => {
		render( <Harness /> );
		expect(
			screen.getByText(
				'Always locked: HappyAccess settings and log, and your own account.'
			)
		).toBeInTheDocument();
	} );

	it( 'shows a retry when the catalog fails to load', async () => {
		const user = userEvent.setup();
		const onRetry = vi.fn();
		render(
			<PermissionEditor
				catalog={ null }
				error={ { code: 'network' } }
				onRetry={ onRetry }
				caps={ [] }
				base="administrator"
				onBaseChange={ () => {} }
				onCapsChange={ () => {} }
			/>
		);

		await user.click( screen.getByRole( 'button', { name: 'Try again' } ) );
		expect( onRetry ).toHaveBeenCalled();
	} );

	it( 'has no accessibility violations', async () => {
		const user = userEvent.setup();
		const { container } = render( <Harness start="shop_manager" /> );
		await user.click(
			within( group( 'Store' ) ).getByRole( 'button', { name: /Store/ } )
		);
		expect( await axe( container ) ).toHaveNoViolations();
	} );
} );
