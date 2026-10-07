import { expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AnnounceProvider, useAnnounce } from './Announcer';

function Speaker() {
	const announce = useAnnounce();
	return (
		<button type="button" onClick={ () => announce( 'Copied' ) }>
			Say it
		</button>
	);
}

it( 'writes announcements into the live region', async () => {
	const user = userEvent.setup();
	const { container } = render(
		<AnnounceProvider>
			<Speaker />
		</AnnounceProvider>
	);

	await user.click( screen.getByRole( 'button', { name: 'Say it' } ) );
	expect(
		container.querySelector( '[aria-live="polite"]' ).textContent
	).toBe( 'Copied ' );

	await user.click( screen.getByRole( 'button', { name: 'Say it' } ) );
	expect(
		container.querySelector( '[aria-live="polite"]' ).textContent
	).toBe( 'Copied' );
} );
