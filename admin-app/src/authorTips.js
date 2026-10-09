import { createInterpolateElement } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { durationWords } from './support/Ring';

/**
 * How long one line stays: the same person sees the same line for this
 * long, and a new one on a later visit.
 */
export const TIP_BUCKET = 3 * 3600;

/**
 * Passes that let someone in right now: not suspended and not over.
 *
 * @param {Array}  passes Passes from the list route.
 * @param {number} now    Unix time in seconds.
 * @return {Array} The passes that are on.
 */
function passesOn( passes, now ) {
	return passes.filter(
		( pass ) =>
			'suspended' !== pass.status && Number( pass.expires_at ) > now
	);
}

/**
 * Lines about this site, each only while its numbers are real.
 *
 * @param {Object} input          Input.
 * @param {Object} input.features The feature switches.
 * @param {Object} input.facts    authorCard.facts from the boot data.
 * @param {Array}  input.passes   Passes from the list route.
 * @param {number} input.now      Unix time in seconds.
 * @return {Array<{id: string, text: string}>} The lines.
 */
function siteLines( { features, facts, passes, now } ) {
	const lines = [];

	if ( features.support_access ) {
		const on = passesOn( passes, now );
		if ( on.length > 0 ) {
			const next = Math.min(
				...on.map( ( pass ) => Number( pass.expires_at ) )
			);
			lines.push( {
				id: 'passes-on',
				text: sprintf(
					/* translators: 1: number of support passes that are on. 2: time until the next one ends, like "2 days 4 hours". */
					_n(
						'%1$d support pass is on right now. It ends in %2$s.',
						'%1$d support passes are on right now. The next one ends in %2$s.',
						on.length,
						'happyaccess'
					),
					on.length,
					durationWords( next - now )
				),
			} );
		}

		const last = Math.max(
			Number( facts.lastPassLogin ) || 0,
			...passes.map( ( pass ) => Number( pass.last_login_at ) || 0 )
		);
		if ( last > 0 ) {
			lines.push( {
				id: 'last-pass-login',
				text: sprintf(
					/* translators: %s: how long ago, like "3 hours". */
					__(
						'Last login with a support pass: %s ago.',
						'happyaccess'
					),
					durationWords( Math.max( 60, now - last ), 1 )
				),
			} );
		}
	}

	const admins = Number( facts.admins ) || 0;
	if ( admins >= 2 ) {
		lines.push( {
			id: 'admins',
			text: sprintf(
				/* translators: %d: number of administrator accounts. */
				_n(
					'This site has %d administrator account. Remove the ones nobody uses.',
					'This site has %d administrator accounts. Remove the ones nobody uses.',
					admins,
					'happyaccess'
				),
				admins
			),
		} );
	}

	const coverage = facts.twoStepAdmins;
	if ( features.two_step && coverage && Number( coverage.total ) > 0 ) {
		const total = Number( coverage.total );
		lines.push( {
			id: 'two-step-admins',
			text: sprintf(
				/* translators: 1: administrators with two-step login. 2: all administrators. */
				_n(
					'%1$d of %2$d administrator has two-step login set up.',
					'%1$d of %2$d administrators have two-step login set up.',
					total,
					'happyaccess'
				),
				Math.min( total, Number( coverage.enabled ) || 0 ),
				total
			),
		} );
	}

	if ( features.two_step && facts.deviceAlerts ) {
		lines.push( {
			id: 'device-alerts',
			text: __(
				'New device alerts are on for administrators.',
				'happyaccess'
			),
		} );
	}

	return lines;
}

/**
 * Tips, each only while the feature it names is on. Every claim was checked
 * against the code it describes; see the task 9 report.
 *
 * @param {Object} input          Input.
 * @param {Object} input.features The feature switches.
 * @param {Object} input.facts    authorCard.facts from the boot data.
 * @return {Array<{id: string, text: string|Element}>} The tips.
 */
function tips( { features, facts } ) {
	const list = [];
	if ( features.support_access ) {
		list.push(
			{
				id: 'suspend',
				text: __(
					'Suspend a pass to pause it. Resume it later and the same link works again.',
					'happyaccess'
				),
			},
			{
				id: 'activity',
				text: __(
					'The Activity tab shows what each pass changed. Export it as CSV for your records.',
					'happyaccess'
				),
			},
			{
				id: 'protected',
				text: __(
					"Protected admin can't change passwords or emails, delete admins, or turn off HappyAccess.",
					'happyaccess'
				),
			}
		);
	}
	// The My Account and checkout switches exist only with WooCommerce.
	if ( features.passwordless && facts.woocommerce ) {
		list.push( {
			id: 'woo-passwordless',
			text: __(
				'Passwordless login can show on WooCommerce My Account and checkout too.',
				'happyaccess'
			),
		} );
	}
	if ( features.two_step ) {
		list.push(
			{
				id: 'require-admins',
				text: __(
					'You can require two-step login for administrators only, and leave customers alone.',
					'happyaccess'
				),
			},
			{
				id: 'backup-codes',
				text: __(
					'Keep your two-step backup codes somewhere safe. Each code works once.',
					'happyaccess'
				),
			}
		);
	}
	list.push(
		{
			id: 'own-accounts',
			text: __(
				'Give each person their own account. A shared login makes the activity log useless.',
				'happyaccess'
			),
		},
		{
			id: 'unused-admins',
			text: __(
				'Remove admin accounts nobody uses. Go to Users, then filter the list by Administrator.',
				'happyaccess'
			),
		}
	);
	// On a network the sign-up switch is in the network settings instead.
	if ( ! facts.multisite ) {
		list.push( {
			id: 'registration',
			text: createInterpolateElement(
				/* translators: <b> wraps the name of a WordPress setting. */
				__(
					'Leave <b>Anyone can register</b> off under Settings, General, unless your site needs sign-ups.',
					'happyaccess'
				),
				{ b: <b /> }
			),
		} );
	}
	return list;
}

/**
 * Every line the card may show now: lines about this site first, then
 * tips.
 *
 * @param {Object} input          Input.
 * @param {Object} input.features The feature switches.
 * @param {Object} input.facts    authorCard.facts from the boot data.
 * @param {Array}  input.passes   Passes from the list route.
 * @param {number} input.now      Unix time in seconds.
 * @return {Array<{id: string, text: string|Element}>} The lines.
 */
export function tipLines( { features = {}, facts = {}, passes = [], now } ) {
	return [
		...siteLines( { features, facts, passes, now } ),
		...tips( { features, facts } ),
	];
}

/**
 * Which line to show: it moves on every three hours, and each person starts
 * at a different place.
 *
 * @param {number} count  How many lines there are.
 * @param {number} now    Unix time in seconds.
 * @param {number} userId The viewer's user id.
 * @return {number} An index into the lines.
 */
export function tipIndex( count, now, userId ) {
	if ( count < 1 ) {
		return -1;
	}
	return (
		( Math.floor( now / TIP_BUCKET ) + Math.max( 0, userId || 0 ) ) % count
	);
}
