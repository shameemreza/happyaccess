import { expect, it } from 'vitest';
import { act, render, renderHook } from '@testing-library/react';
import { AnnounceProvider } from '../Announcer';
import { useAnnounce } from './useAnnounce';

it( 'writes to the one live region and re-announces the same message', () => {
	let announce;
	function Grab() {
		announce = useAnnounce();
		return null;
	}
	const { container } = render(
		<AnnounceProvider>
			<Grab />
		</AnnounceProvider>
	);
	const region = () => container.querySelector( '[aria-live="polite"]' );

	act( () => announce( 'Pass revoked' ) );
	const first = region().textContent;
	act( () => announce( 'Pass revoked' ) );
	const second = region().textContent;

	expect( first.trim() ).toBe( 'Pass revoked' );
	expect( second.trim() ).toBe( 'Pass revoked' );
	expect( second ).not.toBe( first );
	expect( container.querySelectorAll( '[aria-live]' ) ).toHaveLength( 1 );
} );

it( 'is a no-op outside a provider', () => {
	const { result } = renderHook( () => useAnnounce() );
	expect( () => result.current( 'x' ) ).not.toThrow();
} );
