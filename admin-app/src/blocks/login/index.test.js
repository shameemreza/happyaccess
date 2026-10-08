import { expect, it, vi } from 'vitest';

// A plain list, because restoreMocks clears vi.fn() calls before each test and the call happens on import.
const { calls } = vi.hoisted( () => ( { calls: [] } ) );

// The blocks package is a WordPress external, so the test stands in for it.
vi.mock( '@wordpress/blocks', () => ( {
	registerBlockType: ( ...args ) => calls.push( args ),
} ) );

// Imported here, not inside the test, so loading the editor packages doesn't count toward the test's time limit.
import './index';

it( 'registers only the editor parts, so the server keeps the translated title and description', () => {
	expect( calls ).toHaveLength( 1 );
	const [ name, settings ] = calls[ 0 ];
	expect( name ).toBe( 'happyaccess/login' );
	expect( Object.keys( settings ).sort() ).toEqual( [ 'edit', 'save' ] );
	expect( settings.save() ).toBeNull();
} );
