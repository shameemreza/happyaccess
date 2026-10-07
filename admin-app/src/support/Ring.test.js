import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { axe } from 'jest-axe';
import Ring, { longLeft, shortLeft } from './Ring';

const DAY = 86400;
const HOUR = 3600;

describe( 'time labels', () => {
	it( 'shortens to days, hours or minutes', () => {
		expect( shortLeft( 2 * DAY + 4 * HOUR ) ).toBe( '2d' );
		expect( shortLeft( 5 * HOUR + 59 ) ).toBe( '5h' );
		expect( shortLeft( 40 * 60 + 10 ) ).toBe( '40m' );
		expect( shortLeft( -5 ) ).toBe( '0m' );
	} );

	it( 'speaks days and hours, hours and minutes, or minutes', () => {
		expect( longLeft( 2 * DAY + 4 * HOUR ) ).toBe(
			'Ends in 2 days 4 hours'
		);
		expect( longLeft( DAY ) ).toBe( 'Ends in 1 day' );
		expect( longLeft( HOUR + 60 ) ).toBe( 'Ends in 1 hour 1 minute' );
		expect( longLeft( 40 * 60 ) ).toBe( 'Ends in 40 minutes' );
		expect( longLeft( 20 ) ).toBe( 'Ends in 1 minute' );
		expect( longLeft( 0 ) ).toBe( 'Ending now' );
	} );
} );

describe( 'Ring', () => {
	const arc = ( container ) => container.querySelector( '.ha-ring__arc' );

	it( 'is an image with the time left as its name and as its label', () => {
		render( <Ring secondsLeft={ 2 * DAY + 4 * HOUR } total={ 3 * DAY } /> );

		expect(
			screen.getByRole( 'img', { name: 'Ends in 2 days 4 hours' } )
		).toBeInTheDocument();
		expect( screen.getByText( '2d' ) ).toBeInTheDocument();
	} );

	it( 'keeps the short label in its own direction, so RTL pages do not reorder it', () => {
		render( <Ring secondsLeft={ 2 * DAY } total={ 3 * DAY } /> );
		expect( screen.getByText( '2d' ).tagName ).toBe( 'BDI' );
	} );

	it( 'fills the arc by the share of time left', () => {
		const { container } = render(
			<Ring secondsLeft={ 50 } total={ 100 } />
		);
		// Half of 2 * PI * 20.
		expect( arc( container ).style.strokeDasharray ).toBe( '62.8 125.7' );
	} );

	it( 'uses the accent, then amber under 25 percent, and gray when suspended', () => {
		const { container, rerender } = render(
			<Ring secondsLeft={ 60 } total={ 100 } />
		);
		expect( arc( container ).style.stroke ).toContain(
			'--wp-admin-theme-color'
		);

		rerender( <Ring secondsLeft={ 24 } total={ 100 } /> );
		expect( arc( container ).style.stroke ).toBe( '#dba617' );

		rerender( <Ring secondsLeft={ 24 } total={ 100 } state="suspended" /> );
		expect( arc( container ).style.stroke ).toBe( '#a7aaad' );
	} );

	it( 'stays in range when the pass is over or the total is missing', () => {
		const { container, rerender } = render(
			<Ring secondsLeft={ -10 } total={ 100 } />
		);
		expect( arc( container ).style.strokeDasharray ).toBe( '0.0 125.7' );

		rerender( <Ring secondsLeft={ 500 } total={ 100 } /> );
		expect( arc( container ).style.strokeDasharray ).toBe( '125.7 125.7' );

		rerender( <Ring secondsLeft={ 5 } total={ 0 } /> );
		expect( arc( container ).style.strokeDasharray ).toBe( '0.0 125.7' );
	} );

	it( 'has no accessibility violations', async () => {
		const { container } = render(
			<Ring secondsLeft={ 3 * HOUR } total={ DAY } />
		);
		expect( await axe( container ) ).toHaveNoViolations();
	} );
} );
