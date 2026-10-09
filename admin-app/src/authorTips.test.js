import { describe, expect, it } from 'vitest';
import { renderToString } from '@wordpress/element';
import { TIP_BUCKET, tipIndex, tipLines } from './authorTips';

const NOW = 1800000000;
const HOUR = 3600;
const DAY = 86400;
const ALL_ON = { support_access: true, passwordless: true, two_step: true };

const ids = ( input ) =>
	tipLines( { now: NOW, ...input } ).map( ( l ) => l.id );
const textOf = ( line ) =>
	'string' === typeof line.text
		? line.text
		: renderToString( line.text ).replace( /<[^>]+>/g, '' );
const line = ( input, id ) =>
	tipLines( { now: NOW, ...input } ).find( ( item ) => item.id === id );

describe( 'tipLines', () => {
	it( 'lists the lines about this site first, then the tips', () => {
		expect(
			ids( {
				features: ALL_ON,
				facts: {
					admins: 3,
					lastPassLogin: NOW - HOUR,
					twoStepAdmins: { enabled: 1, total: 3 },
					deviceAlerts: true,
					woocommerce: true,
				},
				passes: [
					{
						status: 'active',
						expires_at: NOW + HOUR,
						last_login_at: 0,
					},
				],
			} )
		).toEqual( [
			'passes-on',
			'last-pass-login',
			'admins',
			'two-step-admins',
			'device-alerts',
			'suspend',
			'activity',
			'protected',
			'woo-passwordless',
			'require-admins',
			'backup-codes',
			'own-accounts',
			'unused-admins',
			'registration',
		] );
	} );

	it( 'keeps only the general tips while every feature is off', () => {
		expect(
			ids( {
				features: {},
				facts: {
					admins: 1,
					lastPassLogin: NOW - HOUR,
					twoStepAdmins: { enabled: 1, total: 3 },
					deviceAlerts: true,
					woocommerce: true,
				},
				passes: [ { status: 'active', expires_at: NOW + HOUR } ],
			} )
		).toEqual( [ 'own-accounts', 'unused-admins', 'registration' ] );
	} );

	it( 'counts passes that are on, with the next end, in the singular and the plural', () => {
		const one = line(
			{
				features: ALL_ON,
				passes: [
					{ status: 'active', expires_at: NOW + 2 * DAY + 4 * HOUR },
					{ status: 'suspended', expires_at: NOW + HOUR },
					{ status: 'active', expires_at: NOW - 10 },
				],
			},
			'passes-on'
		);
		expect( one.text ).toBe(
			'1 support pass is on right now. It ends in 2 days 4 hours.'
		);

		const two = line(
			{
				features: ALL_ON,
				passes: [
					{ status: 'active', expires_at: NOW + 3 * DAY },
					{ status: 'used', expires_at: NOW + 45 * 60 },
				],
			},
			'passes-on'
		);
		expect( two.text ).toBe(
			'2 support passes are on right now. The next one ends in 45 minutes.'
		);

		expect(
			line(
				{
					features: ALL_ON,
					passes: [ { status: 'suspended', expires_at: NOW + HOUR } ],
				},
				'passes-on'
			)
		).toBeUndefined();
	} );

	it( 'names the last pass login from the boot data or the list, the later one', () => {
		expect(
			line(
				{ features: ALL_ON, facts: { lastPassLogin: NOW - 3 * DAY } },
				'last-pass-login'
			).text
		).toBe( 'Last login with a support pass: 3 days ago.' );
		expect(
			line(
				{
					features: ALL_ON,
					facts: { lastPassLogin: NOW - 3 * DAY },
					passes: [
						{
							status: 'active',
							expires_at: NOW + HOUR,
							last_login_at: NOW - 14 * 60,
						},
					],
				},
				'last-pass-login'
			).text
		).toBe( 'Last login with a support pass: 14 minutes ago.' );
		expect(
			line( { features: ALL_ON, facts: {} }, 'last-pass-login' )
		).toBeUndefined();
	} );

	it( 'names the administrator count only from two up', () => {
		expect( line( { facts: { admins: 1 } }, 'admins' ) ).toBeUndefined();
		expect( line( { facts: { admins: 2 } }, 'admins' ).text ).toBe(
			'This site has 2 administrator accounts. Remove the ones nobody uses.'
		);
	} );

	it( 'states two-step coverage and alerts only while two-step login is on', () => {
		const facts = {
			twoStepAdmins: { enabled: 2, total: 5 },
			deviceAlerts: true,
		};
		expect(
			line( { features: { two_step: true }, facts }, 'two-step-admins' )
				.text
		).toBe( '2 of 5 administrators have two-step login set up.' );
		expect(
			line( { features: { two_step: true }, facts }, 'device-alerts' )
				.text
		).toBe( 'New device alerts are on for administrators.' );
		expect( ids( { features: {}, facts } ) ).not.toContain(
			'two-step-admins'
		);
		expect( ids( { features: {}, facts } ) ).not.toContain(
			'device-alerts'
		);
		expect(
			ids( {
				features: { two_step: true },
				facts: { twoStepAdmins: null, deviceAlerts: false },
			} )
		).not.toContain( 'two-step-admins' );
	} );

	it( 'shows the WooCommerce tip only with passwordless login and WooCommerce', () => {
		expect(
			ids( { features: { passwordless: true }, facts: {} } )
		).not.toContain( 'woo-passwordless' );
		expect(
			ids( {
				features: { passwordless: true },
				facts: { woocommerce: true },
			} )
		).toContain( 'woo-passwordless' );
	} );

	it( 'leaves the sign-up tip out on a network', () => {
		expect( ids( { facts: { multisite: true } } ) ).not.toContain(
			'registration'
		);
		expect( textOf( line( {}, 'registration' ) ) ).toBe(
			'Leave Anyone can register off under Settings, General, unless your site needs sign-ups.'
		);
	} );

	it( 'never uses quote marks or dashes in a line', () => {
		const lines = tipLines( {
			features: ALL_ON,
			facts: {
				admins: 3,
				lastPassLogin: NOW - HOUR,
				twoStepAdmins: { enabled: 1, total: 3 },
				deviceAlerts: true,
				woocommerce: true,
			},
			passes: [ { status: 'active', expires_at: NOW + HOUR } ],
			now: NOW,
		} );
		for ( const item of lines ) {
			expect( textOf( item ) ).not.toMatch( /["“”—–]/ );
		}
	} );
} );

describe( 'tipIndex', () => {
	it( 'moves on every three hours and starts each user at a different line', () => {
		const slot = Math.floor( NOW / TIP_BUCKET );
		expect( tipIndex( 7, NOW, 0 ) ).toBe( slot % 7 );
		expect(
			tipIndex( 7, NOW + TIP_BUCKET - 1 - ( NOW % TIP_BUCKET ), 0 )
		).toBe( slot % 7 );
		expect( tipIndex( 7, NOW + TIP_BUCKET, 0 ) ).toBe( ( slot + 1 ) % 7 );
		expect( tipIndex( 7, NOW, 5 ) ).toBe( ( slot + 5 ) % 7 );
		expect( tipIndex( 0, NOW, 5 ) ).toBe( -1 );
	} );
} );
