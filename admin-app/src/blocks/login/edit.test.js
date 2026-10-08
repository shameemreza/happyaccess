import { expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';

// The block editor packages are WordPress externals, so the tests stand in for them.
vi.mock( '@wordpress/block-editor', () => ( {
	InspectorControls: ( { children } ) => (
		<div data-testid="inspector">{ children }</div>
	),
	useBlockProps: () => ( { className: 'wp-block-happyaccess-login' } ),
} ) );
vi.mock( '@wordpress/server-side-render', () => ( {
	default: ( { block, attributes } ) => (
		<p data-testid="ssr">
			{ block } { JSON.stringify( attributes ) }
		</p>
	),
} ) );

import Edit from './edit';

function setup( attributes = { redirectTo: '', toggleStyle: '' } ) {
	const setAttributes = vi.fn();
	const view = render(
		<Edit attributes={ attributes } setAttributes={ setAttributes } />
	);
	return { setAttributes, ...view };
}

it( 'previews the block through the server renderer', () => {
	setup( { redirectTo: '/welcome/', toggleStyle: 'button' } );
	expect( screen.getByTestId( 'ssr' ) ).toHaveTextContent(
		'happyaccess/login'
	);
	expect( screen.getByTestId( 'ssr' ) ).toHaveTextContent( '/welcome/' );
} );

it( 'offers the redirect field and saves what is typed', async () => {
	const user = userEvent.setup();
	const { setAttributes } = setup();

	const field = screen.getByRole( 'textbox', {
		name: 'Redirect after login',
	} );
	await user.type( field, 'x' );
	expect( setAttributes ).toHaveBeenCalledWith( { redirectTo: 'x' } );
} );

it( 'offers the toggle style with the three agreed options', async () => {
	const user = userEvent.setup();
	const { setAttributes } = setup();

	const select = screen.getByRole( 'combobox', { name: 'Toggle style' } );
	expect(
		Array.from( select.options ).map( ( o ) => [ o.value, o.text ] )
	).toEqual( [
		[ '', 'Site default' ],
		[ 'link', 'Text link' ],
		[ 'button', 'Full button' ],
	] );

	await user.selectOptions( select, 'button' );
	expect( setAttributes ).toHaveBeenCalledWith( { toggleStyle: 'button' } );
} );

it( 'has no accessibility violations', async () => {
	const { container } = setup();
	expect( await axe( container ) ).toHaveNoViolations();
} );
