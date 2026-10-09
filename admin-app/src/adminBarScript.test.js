/**
 * The admin bar countdown (assets/admin-bar.js), run in jsdom.
 */
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { sprintf } from '@wordpress/i18n';

const source = readFileSync(
	path.resolve( __dirname, '../../assets/admin-bar.js' ),
	'utf8'
);

const NOW = 1800000000;

function run( { ends, plural } ) {
	window.happyaccessBar = {
		ends,
		ended: 'Temporary access has ended',
		less: 'less than a minute',
		confirm: 'Sure?',
	};
	window.wp = {
		i18n: {
			_n: ( single, many, n ) => plural( 1 === n ? single : many ),
			sprintf,
		},
	};
	new Function( source )();
	return document.querySelector( '[data-expires]' ).textContent;
}

describe( 'assets/admin-bar.js', () => {
	beforeEach( () => {
		vi.useFakeTimers();
		vi.setSystemTime( NOW * 1000 );
		document.body.innerHTML = `<div id="wp-admin-bar-happyaccess-timer"><span data-expires="${
			NOW + 2 * 3600 + 5 * 60
		}"></span></div>`;
	} );

	afterEach( () => {
		vi.useRealTimers();
		delete window.happyaccessBar;
		delete window.wp;
	} );

	it( 'fills plain placeholders', () => {
		expect(
			run( {
				ends: 'Temporary access ends in %s',
				plural: ( text ) => text,
			} )
		).toBe( 'Temporary access ends in 2 hours 5 mins' );
	} );

	it( 'fills a translation that numbers its placeholders', () => {
		expect(
			run( {
				ends: 'Zugang endet in %1$s',
				plural: ( text ) =>
					text
						.replace( '%d hours', '%1$d Stunden' )
						.replace( '%d mins', '%1$d Min.' ),
			} )
		).toBe( 'Zugang endet in 2 Stunden 5 Min.' );
	} );
} );
