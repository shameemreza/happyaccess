import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { activitySummary } from '../api';

function minutesText( minutes ) {
	const hours = Math.floor( minutes / 60 );
	const rest = minutes % 60;
	if ( 0 === hours ) {
		return sprintf(
			/* translators: %d: number of minutes. */
			__( '%d min', 'happyaccess' ),
			rest
		);
	}
	return sprintf(
		/* translators: 1: number of hours. 2: number of minutes. */
		__( '%1$d hr %2$d min', 'happyaccess' ),
		hours,
		rest
	);
}

function sentence( summary ) {
	const parts = [
		sprintf(
			/* translators: %d: number of logins. */
			_n( '%d login', '%d logins', summary.logins, 'happyaccess' ),
			summary.logins
		),
	];
	if ( null !== summary.minutes && undefined !== summary.minutes ) {
		parts.push(
			sprintf(
				/* translators: %s: total time, like "1 hr 52 min". */
				__( '%s in total', 'happyaccess' ),
				minutesText( summary.minutes )
			)
		);
	}
	parts.push(
		sprintf(
			/* translators: %d: number of changes. */
			_n( '%d change', '%d changes', summary.changes, 'happyaccess' ),
			summary.changes
		)
	);
	if ( 1 === summary.ips.length ) {
		parts.push(
			sprintf(
				/* translators: %s: an IP address. */
				__( 'all from %s', 'happyaccess' ),
				summary.ips[ 0 ]
			)
		);
	} else if ( summary.ips.length > 1 ) {
		parts.push(
			sprintf(
				/* translators: %s: a list of IP addresses. */
				__( 'from %s', 'happyaccess' ),
				summary.ips.join( ', ' )
			)
		);
	}
	return parts.join( ', ' );
}

function Stat( { value, label } ) {
	return (
		<div className="ha-summary__stat">
			<div className="ha-summary__value">{ value }</div>
			<div className="ha-summary__label">{ label }</div>
		</div>
	);
}

/**
 * What one pass did: logins, changes and where it came from.
 *
 * @param {Object} props         Props.
 * @param {number} props.tokenId The pass id.
 * @param {string} props.label   The pass name.
 * @return {Element|null} The summary, or nothing while it loads or if it fails.
 */
export default function SessionSummary( { tokenId, label } ) {
	const [ loaded, setLoaded ] = useState( { tokenId: 0, summary: null } );
	const [ failed, setFailed ] = useState( 0 );

	useEffect( () => {
		let live = true;
		setFailed( 0 );
		activitySummary( tokenId ).then(
			( summary ) =>
				live &&
				setLoaded( {
					tokenId,
					summary: {
						...summary,
						ips: Array.isArray( summary.ips ) ? summary.ips : [],
					},
				} ),
			() => live && setFailed( tokenId )
		);
		return () => {
			live = false;
		};
	}, [ tokenId ] );

	const summary = loaded.tokenId === tokenId ? loaded.summary : null;
	const heading = sprintf(
		/* translators: %s: the pass name. */
		__( 'Session summary for %s', 'happyaccess' ),
		label
	);

	if ( failed === tokenId ) {
		return (
			<section className="ha-summary" aria-label={ heading }>
				<p className="ha-summary__note">
					{ __( 'Could not load the summary.', 'happyaccess' ) }
				</p>
			</section>
		);
	}

	return (
		<section
			className="ha-summary"
			aria-label={ heading }
			aria-busy={ ! summary }
		>
			<div className="ha-summary__lead">
				<div className="ha-summary__kicker">
					{ __( 'Session summary', 'happyaccess' ) }
				</div>
				<div className="ha-summary__title">{ label }</div>
				<div className="ha-summary__line">
					{ summary ? sentence( summary ) : ' ' }
				</div>
			</div>
			{ summary && (
				<div className="ha-summary__stats">
					<Stat
						value={ summary.logins }
						label={ __( 'Logins', 'happyaccess' ) }
					/>
					<Stat
						value={ summary.changes }
						label={ __( 'Changes', 'happyaccess' ) }
					/>
					{ null !== summary.minutes &&
						undefined !== summary.minutes && (
							<Stat
								value={ minutesText( summary.minutes ) }
								label={ __( 'Time in total', 'happyaccess' ) }
							/>
						) }
					<Stat
						value={ summary.ips.length }
						label={ _n(
							'IP address',
							'IP addresses',
							summary.ips.length,
							'happyaccess'
						) }
					/>
				</div>
			) }
		</section>
	);
}
