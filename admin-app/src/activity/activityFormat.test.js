import { afterEach, describe, expect, it } from 'vitest';
import { getSettings, setSettings } from '@wordpress/date';
import {
	countCsvRows,
	groupByDay,
	rangeFilters,
	shiftDay,
	siteDay,
} from './activityFormat';
import { item } from './fixtures';

const original = getSettings();

function useTimezone( string ) {
	setSettings( {
		...original,
		timezone: { offset: 6, offsetFormatted: '6', string, abbr: '+06' },
	} );
}

afterEach( () => setSettings( original ) );

const at = ( iso ) => Date.parse( iso ) / 1000;

describe( 'days in the site timezone', () => {
	it( 'puts 19:00 UTC on October 5 into October 6 for Asia/Dhaka', () => {
		useTimezone( 'Asia/Dhaka' );
		expect( siteDay( at( '2026-10-05T19:00:00Z' ) ) ).toBe( '2026-10-06' );
		expect( siteDay( at( '2026-10-05T17:59:00Z' ) ) ).toBe( '2026-10-05' );
	} );

	it( 'moves a day across month and year edges', () => {
		expect( shiftDay( '2026-10-01', -1 ) ).toBe( '2026-09-30' );
		expect( shiftDay( '2027-01-01', -1 ) ).toBe( '2026-12-31' );
		expect( shiftDay( '2026-10-06', -6 ) ).toBe( '2026-09-30' );
	} );

	it( 'groups into Today, Yesterday and a dated day, keeping the order', () => {
		useTimezone( 'Asia/Dhaka' );
		const now = at( '2026-10-06T04:00:00Z' );
		const groups = groupByDay(
			[
				item( { id: 1, time: at( '2026-10-05T19:00:00Z' ) } ),
				item( { id: 2, time: at( '2026-10-05T18:30:00Z' ) } ),
				item( { id: 3, time: at( '2026-10-05T17:00:00Z' ) } ),
				item( { id: 4, time: at( '2026-10-03T12:00:00Z' ) } ),
			],
			now
		);
		expect( groups.map( ( group ) => group.title ) ).toEqual( [
			'Today',
			'Yesterday',
			'October 3, 2026',
		] );
		expect( groups[ 0 ].items.map( ( row ) => row.id ) ).toEqual( [
			1, 2,
		] );
	} );
} );

describe( 'rangeFilters', () => {
	it( 'makes last 7 days today and the six days before it', () => {
		expect( rangeFilters( '7', '', '', '2026-10-06' ) ).toEqual( {
			since: '2026-09-30',
			until: '2026-10-06',
		} );
		expect( rangeFilters( '30', '', '', '2026-10-06' ) ).toEqual( {
			since: '2026-09-07',
			until: '2026-10-06',
		} );
	} );

	it( 'passes a custom range through and skips an empty end', () => {
		expect( rangeFilters( 'custom', '2026-10-01', '', 'x' ) ).toEqual( {
			since: '2026-10-01',
		} );
	} );
} );

describe( 'countCsvRows', () => {
	it( 'does not count the header or a line break inside quotes', () => {
		const csv =
			'time,summary\n2026-10-06,"one\ntwo"\n2026-10-05,"say ""hi"""\n';
		expect( countCsvRows( csv ) ).toBe( 2 );
		expect( countCsvRows( 'time,summary\n' ) ).toBe( 0 );
		expect( countCsvRows( '' ) ).toBe( 0 );
	} );
} );
