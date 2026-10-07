import { beforeEach, describe, expect, it, vi } from 'vitest';
import apiFetch from '@wordpress/api-fetch';
import * as api from './api';

vi.mock( '@wordpress/api-fetch' );

const base = '/happyaccess/v1';

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockResolvedValue( {} );
} );

function lastCall() {
	return apiFetch.mock.calls[ apiFetch.mock.calls.length - 1 ][ 0 ];
}

describe( 'grant routes', () => {
	it.each( [
		[ 'listGrants', [], '/grants', 'GET', undefined ],
		[ 'getGrant', [ 5 ], '/grants/5', 'GET', undefined ],
		[
			'extendGrant',
			[ 5, 86400 ],
			'/grants/5/extend',
			'POST',
			{ seconds: 86400 },
		],
		[ 'suspendGrant', [ 5 ], '/grants/5/suspend', 'POST', undefined ],
		[ 'resumeGrant', [ 5 ], '/grants/5/resume', 'POST', undefined ],
		[
			'regenerateGrant',
			[ 5, true ],
			'/grants/5/regenerate',
			'POST',
			{ send_email: true },
		],
		[ 'revokeGrant', [ 5 ], '/grants/5', 'DELETE', undefined ],
		[ 'revokeAll', [], '/grants/revoke-all', 'POST', undefined ],
		[ 'getSettings', [], '/settings', 'GET', undefined ],
		[ 'getCatalog', [], '/catalog', 'GET', undefined ],
		[ 'emergencyLock', [], '/lock', 'POST', undefined ],
		[
			'saveSettings',
			[ { security: { max_attempts: 3 } } ],
			'/settings',
			'POST',
			{ security: { max_attempts: 3 } },
		],
		[
			'runSetup',
			[ { features: { support_access: true }, consent: true } ],
			'/setup',
			'POST',
			{ features: { support_access: true }, consent: true },
		],
		[
			'activitySummary',
			[ 7 ],
			'/activity/summary?token_id=7',
			'GET',
			undefined,
		],
	] )( '%s', async ( name, args, path, method, data ) => {
		await api[ name ]( ...args );
		const call = lastCall();
		expect( call.path ).toBe( base + path );
		expect( call.method ).toBe( method );
		expect( call.data ).toEqual( data );
	} );
} );

describe( 'activity routes', () => {
	it( 'sends only the filters that are set', async () => {
		await api.listActivity( {
			feature: 'support',
			event: '',
			user_id: 0,
			token_id: 4,
			since: '2026-01-02',
			page: 2,
		} );
		const call = lastCall();
		expect( call.method ).toBe( 'GET' );
		expect( call.path ).toBe(
			base +
				'/activity?feature=support&user_id=0&token_id=4&since=2026-01-02&page=2'
		);
	} );

	it( 'returns the export file as { filename, csv }', async () => {
		apiFetch.mockResolvedValue( { filename: 'a.csv', csv: 'time\n' } );
		const result = await api.exportActivity( { search: 'a b' } );
		expect( lastCall().path ).toBe( base + '/activity/export?search=a+b' );
		expect( result ).toEqual( { filename: 'a.csv', csv: 'time\n' } );
	} );
} );

describe( 'createGrant', () => {
	const form = {
		label: 'Acme support',
		email: '',
		level: 'protected',
		caps: [ 'edit_posts' ],
		confirmFull: false,
		durationSeconds: 86400,
		oneTime: false,
		notify: 'first',
		menus: [],
		ips: '',
		redirectTo: '',
		role: 'administrator',
		sendEmail: false,
		allowInstalls: true,
	};

	it( 'posts to /grants with the mapped body and leaves a blank email out', async () => {
		await api.createGrant( form );
		const call = lastCall();
		expect( call.path ).toBe( base + '/grants' );
		expect( call.method ).toBe( 'POST' );
		expect( call.data ).toEqual( {
			label: 'Acme support',
			level: 'protected',
			duration: 86400,
			one_time: false,
			notify: 'first',
			send_email: false,
		} );
		expect( call.data ).not.toHaveProperty( 'email' );
	} );

	it( 'never sends allow_installs', async () => {
		await api.createGrant( form );
		expect( lastCall().data ).not.toHaveProperty( 'allow_installs' );
	} );

	it( 'sends caps for custom and not for protected', async () => {
		await api.createGrant( { ...form, level: 'custom' } );
		expect( lastCall().data.caps ).toEqual( [ 'edit_posts' ] );

		await api.createGrant( { ...form, level: 'protected' } );
		expect( lastCall().data ).not.toHaveProperty( 'caps' );

		await api.createGrant( { ...form, level: 'custom', caps: [] } );
		expect( lastCall().data ).not.toHaveProperty( 'caps' );
	} );

	it( 'sends confirm_full only for custom and full', async () => {
		await api.createGrant( { ...form, level: 'full', confirmFull: true } );
		expect( lastCall().data.confirm_full ).toBe( true );

		await api.createGrant( { ...form, level: 'full', confirmFull: false } );
		expect( lastCall().data ).not.toHaveProperty( 'confirm_full' );

		await api.createGrant( {
			...form,
			level: 'protected',
			confirmFull: true,
		} );
		expect( lastCall().data ).not.toHaveProperty( 'confirm_full' );
	} );

	it( 'sends role only for protected and not for administrator', async () => {
		await api.createGrant( { ...form, role: 'editor' } );
		expect( lastCall().data.role ).toBe( 'editor' );

		await api.createGrant( { ...form, role: 'administrator' } );
		expect( lastCall().data ).not.toHaveProperty( 'role' );

		await api.createGrant( { ...form, level: 'full', role: 'editor' } );
		expect( lastCall().data ).not.toHaveProperty( 'role' );
	} );

	it( 'sends the email, restrictions and redirect when set', async () => {
		await api.createGrant( {
			...form,
			email: ' help@example.com ',
			ips: '1.2.3.4, 5.6.7.8\n\n9.9.9.9 ,',
			menus: [ 'edit.php', 'woocommerce::wc-settings' ],
			redirectTo: ' /wp-admin/edit.php ',
			sendEmail: true,
		} );
		expect( lastCall().data ).toMatchObject( {
			email: 'help@example.com',
			ips: [ '1.2.3.4', '5.6.7.8', '9.9.9.9' ],
			menus: [ 'edit.php', 'woocommerce::wc-settings' ],
			redirect_to: '/wp-admin/edit.php',
			send_email: true,
		} );
	} );
} );

describe( 'errors', () => {
	it( 'maps a WP_Error response to { code, message, status }', async () => {
		apiFetch.mockRejectedValue( {
			code: 'happyaccess_conflict',
			message: 'Not now.',
			data: { status: 409 },
		} );
		await expect( api.suspendGrant( 5 ) ).rejects.toEqual( {
			code: 'happyaccess_conflict',
			message: 'Not now.',
			status: 409,
		} );
	} );

	it( 'maps a network failure to the network code with a message', async () => {
		apiFetch.mockRejectedValue( {
			code: 'fetch_error',
			message: 'You are probably offline.',
		} );
		const error = await api.getSettings().catch( ( e ) => e );
		expect( error.code ).toBe( 'network' );
		expect( error.status ).toBe( 0 );
		expect( error.message ).toMatch( /could not reach the server/i );

		apiFetch.mockRejectedValue( new TypeError( 'Failed to fetch' ) );
		const second = await api.getSettings().catch( ( e ) => e );
		expect( second.code ).toBe( 'network' );
	} );
} );
