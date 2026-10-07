import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import apiFetch from '@wordpress/api-fetch';
import { AnnounceProvider } from '../Announcer';
import EmergencyLock from './EmergencyLock';

vi.mock( '@wordpress/api-fetch' );

beforeEach( () => {
	apiFetch.mockReset();
} );

function setup() {
	const user = userEvent.setup();
	const onLocked = vi.fn();
	const view = render(
		<AnnounceProvider>
			<EmergencyLock onLocked={ onLocked } />
		</AnnounceProvider>
	);
	const live = () =>
		view.container.querySelector( '[aria-live]' ).textContent;
	return { user, onLocked, live, ...view };
}

const lockButton = () =>
	screen.getByRole( 'button', { name: 'Emergency lock' } );

describe( 'EmergencyLock', () => {
	it( 'opens a modal that asks first and does nothing yet', async () => {
		const { user } = setup();

		await user.click( lockButton() );

		const modal = screen.getByRole( 'dialog', {
			name: 'End every support pass now?',
		} );
		expect( modal ).toHaveTextContent(
			'Everyone using a pass is logged out and their accounts are deleted.'
		);
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	it( 'closes on Keep access without calling the API', async () => {
		const { user } = setup();

		await user.click( lockButton() );
		await user.click(
			screen.getByRole( 'button', { name: 'Keep access' } )
		);

		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
		expect( apiFetch ).not.toHaveBeenCalled();
		await waitFor( () => expect( lockButton() ).toHaveFocus() );
	} );

	it( 'ends every pass, announces the count and tells the parent', async () => {
		apiFetch.mockResolvedValue( { revoked: 3 } );
		const { user, onLocked, live } = setup();

		await user.click( lockButton() );
		await user.click(
			screen.getByRole( 'button', { name: 'End all passes' } )
		);

		await waitFor( () => expect( onLocked ).toHaveBeenCalledWith( 3 ) );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/happyaccess/v1/lock',
			method: 'POST',
		} );
		expect( live() ).toContain( '3 passes ended' );
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
	} );

	it( 'announces one pass in the singular', async () => {
		apiFetch.mockResolvedValue( { revoked: 1 } );
		const { user, live } = setup();

		await user.click( lockButton() );
		await user.click(
			screen.getByRole( 'button', { name: 'End all passes' } )
		);

		await waitFor( () => expect( live() ).toContain( '1 pass ended' ) );
	} );

	it( 'keeps the modal open and shows the message when it fails', async () => {
		apiFetch.mockRejectedValue( {
			code: 'rest_forbidden',
			message: 'Sorry, you are not allowed to do that.',
			data: { status: 403 },
		} );
		const { user, onLocked } = setup();

		await user.click( lockButton() );
		await user.click(
			screen.getByRole( 'button', { name: 'End all passes' } )
		);

		expect(
			await screen.findByText( 'Sorry, you are not allowed to do that.', {
				selector: '.components-notice__content',
			} )
		).toBeInTheDocument();
		expect( screen.getByRole( 'dialog' ) ).toBeInTheDocument();
		expect( onLocked ).not.toHaveBeenCalled();
	} );

	it( 'has no accessibility violations with the modal open', async () => {
		const { user } = setup();
		await user.click( lockButton() );

		expect( await axe( document.body ) ).toHaveNoViolations();
	} );
} );
