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
			'emergency-lock',
			'suspend',
			'activity',
			'protected',
			'woo-passwordless',
			'require-admins',
			'backup-codes',
			'device-alerts-tip',
			'own-accounts',
			'unused-admins',
			'woo-api-keys',
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
		).toEqual( [
			'own-accounts',
			'unused-admins',
			'woo-api-keys',
			'registration',
		] );
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
			'1 support pass is on, and it ends in 2 days 4 hours. You can extend it or end it early under Who has access.'
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
			'2 support passes are on, and the next one ends in 45 minutes. You can extend or end any of them under Who has access.'
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
		).toBe(
			'The last login with a support pass was 3 days ago. The Activity tab shows what they changed after that.'
		);
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
		).toBe(
			'The last login with a support pass was 14 minutes ago. The Activity tab shows what they changed after that.'
		);
		expect(
			line( { features: ALL_ON, facts: {} }, 'last-pass-login' )
		).toBeUndefined();
	} );

	it( 'names the administrator count only from two up', () => {
		expect( line( { facts: { admins: 1 } }, 'admins' ) ).toBeUndefined();
		expect( line( { facts: { admins: 2 } }, 'admins' ).text ).toBe(
			'This site has 2 administrator accounts. Remove the ones nobody uses, so there are fewer ways in.'
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
		).toBe(
			'2 of 5 administrators have two-step login set up. Login and security shows who still needs it.'
		);
		expect(
			line( { features: { two_step: true }, facts }, 'device-alerts' )
				.text
		).toBe(
			'New device alerts are on for administrators. They get an email when their account logs in from a new browser.'
		);
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

	it( 'says every administrator has two-step login when they all do', () => {
		const text = ( enabled, total ) =>
			line(
				{
					features: { two_step: true },
					facts: { twoStepAdmins: { enabled, total } },
				},
				'two-step-admins'
			).text;
		const allSet =
			'Every administrator here has two-step login set up. Login and security shows how the other roles are doing.';

		expect( text( 1, 1 ) ).toBe( allSet );
		expect( text( 4, 4 ) ).toBe( allSet );
		expect( text( 3, 4 ) ).toBe(
			'3 of 4 administrators have two-step login set up. Login and security shows who still needs it.'
		);
		expect( allSet.length ).toBeGreaterThanOrEqual( 90 );
		expect( allSet.length ).toBeLessThanOrEqual( 130 );
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

	it( 'names where Emergency lock is only while temporary access is on', () => {
		expect( ids( { features: {} } ) ).not.toContain( 'emergency-lock' );
		expect(
			line( { features: { support_access: true } }, 'emergency-lock' )
				.text
		).toBe(
			'Emergency lock, at the top of this page, ends every support pass at once. Use it if a link or code ever reaches the wrong person.'
		);
	} );

	it( 'explains new device alerts only while two-step login is on', () => {
		expect( ids( { features: {} } ) ).not.toContain( 'device-alerts-tip' );
		expect(
			line( { features: { two_step: true } }, 'device-alerts-tip' ).text
		).toBe(
			"New device alerts email an admin when their account logs in from a browser it hasn't seen. Set the roles in Login and security."
		);
	} );

	it( 'shows the REST API keys tip only with WooCommerce', () => {
		expect( ids( { facts: {} } ) ).not.toContain( 'woo-api-keys' );
		expect(
			line( { facts: { woocommerce: true } }, 'woo-api-keys' ).text
		).toBe(
			'WooCommerce REST API keys work like passwords. Remove old ones under WooCommerce, Settings, Advanced, REST API keys.'
		);
	} );

	it( 'never says right now in the passes line', () => {
		const passes = [ { status: 'active', expires_at: NOW + HOUR } ];
		expect(
			line( { features: ALL_ON, passes }, 'passes-on' ).text
		).not.toMatch( /right now/ );
	} );

	it( 'leaves the sign-up tip out on a network', () => {
		expect( ids( { facts: { multisite: true } } ) ).not.toContain(
			'registration'
		);
		expect( textOf( line( {}, 'registration' ) ) ).toBe(
			'Leave Anyone can register off under Settings, General, unless your site needs visitors to sign up on their own.'
		);
	} );

	it( 'keeps every line at 90 to 130 characters, with real numbers in', () => {
		const passes = ( count, ends ) =>
			Array.from( { length: count }, () => ( {
				status: 'active',
				expires_at: NOW + ends,
				last_login_at: 0,
			} ) );
		const cases = [
			// One pass and several, ending soon or in days.
			{ passes: passes( 1, 45 * 60 ) },
			{ passes: passes( 1, 2 * DAY + 4 * HOUR ) },
			{ passes: passes( 3, 12 * DAY + 23 * HOUR ) },
			{
				facts: {
					admins: 2,
					lastPassLogin: NOW - 5 * 60,
					twoStepAdmins: { enabled: 1, total: 1 },
				},
			},
			{
				facts: {
					admins: 12,
					lastPassLogin: NOW - 11 * DAY,
					twoStepAdmins: { enabled: 9, total: 12 },
				},
			},
		];
		const seen = new Set();
		for ( const input of cases ) {
			const lines = tipLines( {
				features: ALL_ON,
				now: NOW,
				passes: [],
				...input,
				facts: {
					deviceAlerts: true,
					woocommerce: true,
					...input.facts,
				},
			} );
			for ( const item of lines ) {
				const text = textOf( item );
				seen.add( item.id );
				expect(
					text.length,
					`${ item.id }: ${ text }`
				).toBeGreaterThanOrEqual( 90 );
				expect(
					text.length,
					`${ item.id }: ${ text }`
				).toBeLessThanOrEqual( 130 );
			}
		}
		// Every line the card can show was measured: 17 ids, with the passes
		// line in both its singular and plural forms.
		expect( seen.size ).toBe( 17 );
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
