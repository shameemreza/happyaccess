import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, render, screen } from '@testing-library/react';
import { useEffect, useState } from '@wordpress/element';
import LoadingLine from './LoadingLine';

// Something that loads for `ms` milliseconds, then shows its content.
function Loads( { ms } ) {
	const [ loading, setLoading ] = useState( true );
	useEffect( () => {
		const timer = setTimeout( () => setLoading( false ), ms );
		return () => clearTimeout( timer );
	}, [ ms ] );
	return (
		<>
			<LoadingLine loading={ loading } className="line">
				Loading things
			</LoadingLine>
			{ ! loading && <p>Things</p> }
		</>
	);
}

beforeEach( () => {
	vi.useFakeTimers();
} );

afterEach( () => {
	vi.useRealTimers();
} );

const advance = ( ms ) =>
	act( () => {
		vi.advanceTimersByTime( ms );
	} );

describe( 'LoadingLine', () => {
	it( 'never shows the text for a 100 ms load', () => {
		render( <Loads ms={ 100 } /> );

		advance( 99 );
		expect(
			screen.queryByText( 'Loading things' )
		).not.toBeInTheDocument();
		advance( 1 );
		expect(
			screen.queryByText( 'Loading things' )
		).not.toBeInTheDocument();
		expect( screen.getByText( 'Things' ) ).toBeInTheDocument();
		// And it does not appear afterwards, when its delay would have ended.
		advance( 1000 );
		expect(
			screen.queryByText( 'Loading things' )
		).not.toBeInTheDocument();
	} );

	it( 'shows the text after 300 ms of a 500 ms load, then removes it', () => {
		render( <Loads ms={ 500 } /> );

		advance( 299 );
		expect(
			screen.queryByText( 'Loading things' )
		).not.toBeInTheDocument();
		advance( 1 );
		expect( screen.getByText( 'Loading things' ) ).toBeInTheDocument();
		advance( 200 );
		expect(
			screen.queryByText( 'Loading things' )
		).not.toBeInTheDocument();
		expect( screen.getByText( 'Things' ) ).toBeInTheDocument();
	} );

	it( 'holds the place with a quiet block that speaks to no one until the text shows', () => {
		const { container } = render( <Loads ms={ 500 } /> );

		const line = container.querySelector( '.line' );
		const block = line.querySelector( '.ha-skeleton' );
		expect( block ).toHaveAttribute( 'aria-hidden', 'true' );
		expect( line ).toHaveTextContent( '' );

		advance( 300 );
		expect( container.querySelector( '.line' ) ).toBe( line );
		expect( line.querySelector( '.ha-skeleton' ) ).toBeNull();
	} );
} );
