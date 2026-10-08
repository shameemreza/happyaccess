import { expect, it, vi } from 'vitest';

const { registerBlockType } = vi.hoisted( () => ( {
	registerBlockType: vi.fn(),
} ) );

// The blocks package is a WordPress external, so the test stands in for it.
vi.mock( '@wordpress/blocks', () => ( { registerBlockType } ) );

it( 'registers only the editor parts, so the server keeps the translated title and description', async () => {
	await import( './index' );

	expect( registerBlockType ).toHaveBeenCalledTimes( 1 );
	const [ name, settings ] = registerBlockType.mock.calls[ 0 ];
	expect( name ).toBe( 'happyaccess/login' );
	expect( Object.keys( settings ).sort() ).toEqual( [ 'edit', 'save' ] );
	expect( settings.save() ).toBeNull();
} );
