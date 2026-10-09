import { useEffect, useId } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useCoverage } from '../data/DataProvider';
import LoadingLine from '../LoadingLine';
import RetryButton from '../RetryButton';
import { listNames } from './twoStepModel';

/**
 * One role: how many of its users have two-step login on, with a bar, and
 * for a required role, who is still setting up and who can't skip anymore.
 *
 * @param {Object}  props           Props.
 * @param {Object}  props.row       A row of the coverage route.
 * @param {boolean} props.hideSetup Leave out who is still setting up.
 * @return {Element} The row.
 */
function CoverageRow( { row, hideSetup } ) {
	const total = Number( row.total ) || 0;
	const enabled = Number( row.enabled ) || 0;
	const settingUp = hideSetup ? 0 : Number( row.setting_up ) || 0;
	const late = Number( row.past_grace ) || 0;
	const share = total > 0 ? Math.round( ( enabled / total ) * 100 ) : 0;

	return (
		<li className="ha-coverage__row">
			<span className="ha-coverage__count">
				{ sprintf(
					/* translators: 1: a role, as a group of people, like "Administrators". 2: users with two-step login on. 3: all users with the role. */
					__( '%1$s: %2$d of %3$d', 'happyaccess' ),
					row.name,
					enabled,
					total
				) }
			</span>
			<span className="ha-coverage__bar" aria-hidden="true">
				<span style={ { inlineSize: `${ share }%` } } />
			</span>
			{ row.required && ( settingUp > 0 || late > 0 ) && (
				<span className="ha-coverage__notes">
					{ settingUp > 0 && (
						<span>
							{ sprintf(
								/* translators: %d: users who still have to set up two-step login. */
								_n(
									'%d still setting up',
									'%d still setting up',
									settingUp,
									'happyaccess'
								),
								settingUp
							) }
						</span>
					) }
					{ late > 0 && (
						<span className="ha-coverage__late">
							{ sprintf(
								/* translators: %d: users whose grace period is over, with no two-step method. */
								_n(
									"%d can't skip anymore",
									"%d can't skip anymore",
									late,
									'happyaccess'
								),
								late
							) }
						</span>
					) }
				</span>
			) }
		</li>
	);
}

/**
 * Who has two-step login, per role with users, the biggest roles first.
 * Shown only while two-step login is on, since the route exists only then.
 *
 * The counts read HappyAccess's own settings only. With another two-step
 * plugin active, accounts that use it would show as still setting up, so
 * that figure is left out and a line names the plugin instead.
 *
 * @param {Object}   props        Props.
 * @param {string[]} props.others Names of other active two-step plugins.
 * @return {Element} The card.
 */
export default function TwoStepCoverage( { others = [] } ) {
	const { coverage, error, loadOnce, retry } = useCoverage();
	const id = useId();

	useEffect( () => {
		loadOnce();
	}, [ loadOnce ] );

	const rows = Array.isArray( coverage?.roles ) ? coverage.roles : [];

	return (
		<section
			className="ha-loginprev ha-coverage"
			aria-labelledby={ `${ id }-title` }
		>
			<h2 id={ `${ id }-title` }>
				{ __( 'Who has two-step login', 'happyaccess' ) }
			</h2>
			<p className="ha-loginprev__lead">
				{ __(
					'People with the app or email codes on, by role.',
					'happyaccess'
				) }
			</p>
			{ ! coverage && ! error && (
				<LoadingLine loading className="ha-settings__loading">
					{ __( 'Loading counts', 'happyaccess' ) }
				</LoadingLine>
			) }
			{ ! coverage && error && (
				<div className="ha-coverage__error">
					<p>{ __( 'Could not load the counts.', 'happyaccess' ) }</p>
					<RetryButton
						variant="secondary"
						size="compact"
						error={ error }
						onRetry={ retry }
					/>
				</div>
			) }
			{ coverage && rows.length > 0 && (
				<ul className="ha-coverage__list">
					{ rows.map( ( row ) => (
						<CoverageRow
							key={ row.slug }
							row={ row }
							hideSetup={ others.length > 0 }
						/>
					) ) }
				</ul>
			) }
			{ coverage && others.length > 0 && (
				<p className="ha-help ha-coverage__note">
					{ sprintf(
						/* translators: %s: plugin names, like "WP 2FA" or "WP 2FA and Kadence Security". */
						__(
							"%s handles two-step login for some accounts. They aren't counted here.",
							'happyaccess'
						),
						listNames( others )
					) }
				</p>
			) }
			{ coverage && 0 === rows.length && (
				<p className="ha-help">
					{ __( 'No users yet.', 'happyaccess' ) }
				</p>
			) }
			{ coverage?.large && (
				<p className="ha-help ha-coverage__note">
					{ __(
						'Counts are updated every 5 minutes.',
						'happyaccess'
					) }
				</p>
			) }
		</section>
	);
}
