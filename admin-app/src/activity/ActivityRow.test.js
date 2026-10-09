import { describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import ActivityRow from './ActivityRow';
import { item } from './fixtures';

function setup( row, expanded = false ) {
	const onToggle = vi.fn();
	const view = render(
		<ol>
			<ActivityRow
				item={ row }
				expanded={ expanded }
				onToggle={ onToggle }
			/>
		</ol>
	);
	return { onToggle, ...view };
}

describe( 'ActivityRow', () => {
	it( 'shows the summary, who did it and the feature tag', () => {
		setup( item() );

		const button = screen.getByRole( 'button', { expanded: false } );
		expect( button ).toHaveTextContent(
			'Saved WooCommerce shipping settings'
		);
		expect( button ).toHaveTextContent( 'Acme Plugin Support' );
		expect( button ).toHaveTextContent( 'Temporary access' );
	} );

	it( 'falls back to the event label when the summary is empty', () => {
		setup( item( { summary: '' } ) );

		expect( screen.getByRole( 'button' ) ).toHaveTextContent(
			'Settings saved'
		);
	} );

	it( 'names the admin, not the pass, for what an admin did', () => {
		setup(
			item( {
				event: 'grant_created',
				kind: 'admin',
				summary: 'Gave support access to Acme',
				actor: { id: 1, name: 'Sam' },
			} )
		);

		const button = screen.getByRole( 'button' );
		expect( button ).toHaveTextContent( 'Sam' );
		expect( button ).toHaveTextContent( 'Admin' );
	} );

	it( 'opens its details from the button', async () => {
		const { onToggle } = setup( item() );

		await userEvent.click( screen.getByRole( 'button' ) );

		expect( onToggle ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'shows the IP address, the pass and the event when expanded', () => {
		setup( item(), true );

		const button = screen.getByRole( 'button', { expanded: true } );
		const details = document.getElementById(
			button.getAttribute( 'aria-controls' )
		);
		expect( within( details ).getByText( '203.0.113.24' ) ).toBeVisible();
		expect(
			within( details ).getByText( 'Acme Plugin Support' )
		).toBeVisible();
		expect( within( details ).getByText( 'Settings saved' ) ).toBeVisible();
	} );

	it( 'labels a revoked pass by its id and a missing IP as not recorded', () => {
		setup( item( { pass: '', ip: '', token_id: 9 } ), true );

		expect( screen.getByText( 'Pass #9' ) ).toBeVisible();
		expect( screen.getByText( 'Not recorded' ) ).toBeVisible();
	} );

	it( 'has no accessibility violations', async () => {
		const { container } = setup( item(), true );

		expect( await axe( container ) ).toHaveNoViolations();
	} );
} );
