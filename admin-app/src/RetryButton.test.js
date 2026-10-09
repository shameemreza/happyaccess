import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { reloadPage } from './api';
import RetryButton from './RetryButton';

vi.mock( './api', () => ( { reloadPage: vi.fn() } ) );

describe( 'RetryButton', () => {
	it( 'tries again for an ordinary error', async () => {
		const user = userEvent.setup();
		const onRetry = vi.fn();
		render(
			<RetryButton
				error={ { code: 'network', message: 'Offline' } }
				onRetry={ onRetry }
			/>
		);

		await user.click( screen.getByRole( 'button', { name: 'Try again' } ) );

		expect( onRetry ).toHaveBeenCalledTimes( 1 );
		expect( reloadPage ).not.toHaveBeenCalled();
	} );

	it( 'tries again when the error is not known', async () => {
		const user = userEvent.setup();
		const onRetry = vi.fn();
		render( <RetryButton onRetry={ onRetry } /> );

		await user.click( screen.getByRole( 'button', { name: 'Try again' } ) );

		expect( onRetry ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'reloads the page when HappyAccess is not answering', async () => {
		const user = userEvent.setup();
		const onRetry = vi.fn();
		render(
			<RetryButton
				variant="link"
				error={ { code: 'unavailable', message: 'Gone' } }
				onRetry={ onRetry }
			/>
		);

		expect(
			screen.queryByRole( 'button', { name: 'Try again' } )
		).not.toBeInTheDocument();
		await user.click(
			screen.getByRole( 'button', { name: 'Reload the page' } )
		);

		expect( reloadPage ).toHaveBeenCalledTimes( 1 );
		expect( onRetry ).not.toHaveBeenCalled();
	} );
} );
