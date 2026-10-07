import { __, _n, sprintf } from '@wordpress/i18n';

const RADIUS = 20;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;
const AMBER = '#dba617';
const GRAY = '#a7aaad';
const ACCENT = 'var(--wp-admin-theme-color, #2271b1)';

const DAY = 86400;
const HOUR = 3600;

/**
 * The short label in the middle of the ring: 2d, 5h or 40m.
 *
 * @param {number} seconds Seconds left.
 * @return {string} Short label.
 */
export function shortLeft( seconds ) {
	const left = Math.max( 0, Math.floor( seconds ) );
	if ( left >= DAY ) {
		return sprintf(
			/* translators: %d: number of days, as in 2d. */
			__( '%dd', 'happyaccess' ),
			Math.floor( left / DAY )
		);
	}
	if ( left >= HOUR ) {
		return sprintf(
			/* translators: %d: number of hours, as in 5h. */
			__( '%dh', 'happyaccess' ),
			Math.floor( left / HOUR )
		);
	}
	return sprintf(
		/* translators: %d: number of minutes, as in 40m. */
		__( '%dm', 'happyaccess' ),
		Math.floor( left / 60 )
	);
}

const days = ( n ) =>
	sprintf(
		/* translators: %d: number of days. */
		_n( '%d day', '%d days', n, 'happyaccess' ),
		n
	);
const hours = ( n ) =>
	sprintf(
		/* translators: %d: number of hours. */
		_n( '%d hour', '%d hours', n, 'happyaccess' ),
		n
	);
const minutes = ( n ) =>
	sprintf(
		/* translators: %d: number of minutes. */
		_n( '%d minute', '%d minutes', n, 'happyaccess' ),
		n
	);

/**
 * The spoken form: "Ends in 2 days 4 hours".
 *
 * @param {number} seconds Seconds left.
 * @return {string} Sentence for screen readers and tooltips.
 */
export function longLeft( seconds ) {
	if ( seconds <= 0 ) {
		return __( 'Ending now', 'happyaccess' );
	}
	const d = Math.floor( seconds / DAY );
	const h = Math.floor( ( seconds % DAY ) / HOUR );
	const m = Math.max( 1, Math.floor( ( seconds % HOUR ) / 60 ) );
	let parts;
	if ( d > 0 ) {
		parts = h ? [ days( d ), hours( h ) ] : [ days( d ) ];
	} else if ( h > 0 ) {
		const mins = Math.floor( ( seconds % HOUR ) / 60 );
		parts = mins ? [ hours( h ), minutes( mins ) ] : [ hours( h ) ];
	} else {
		parts = [ minutes( m ) ];
	}
	/* translators: %s: time left, like 2 days 4 hours. */
	return sprintf( __( 'Ends in %s', 'happyaccess' ), parts.join( ' ' ) );
}

/**
 * A ring that empties as a pass runs out. It is for the eye only: the row
 * beside it says the time left in words.
 *
 * @param {Object} props             Props.
 * @param {number} props.secondsLeft Seconds until the pass ends.
 * @param {number} props.total       Length of the whole pass in seconds.
 * @param {string} props.state       "active" or "suspended".
 * @return {Element} The ring.
 */
export default function Ring( { secondsLeft, total, state = 'active' } ) {
	const share =
		total > 0 ? Math.min( 1, Math.max( 0, secondsLeft / total ) ) : 0;
	const suspended = 'suspended' === state;
	let stroke = ACCENT;
	if ( suspended ) {
		stroke = GRAY;
	} else if ( share < 0.25 ) {
		stroke = AMBER;
	}
	const filled = ( share * CIRCUMFERENCE ).toFixed( 1 );

	return (
		<div className="ha-ring" aria-hidden="true">
			<svg
				className="ha-ring__svg"
				width="48"
				height="48"
				viewBox="0 0 48 48"
				focusable="false"
			>
				<circle
					className="ha-ring__track"
					cx="24"
					cy="24"
					r={ RADIUS }
					fill="none"
					stroke="#f0f0f1"
					strokeWidth="4"
				/>
				<circle
					className="ha-ring__arc"
					cx="24"
					cy="24"
					r={ RADIUS }
					fill="none"
					strokeWidth="4"
					strokeLinecap="round"
					style={ {
						stroke,
						strokeDasharray: `${ filled } ${ CIRCUMFERENCE.toFixed(
							1
						) }`,
					} }
				/>
			</svg>
			<span className="ha-ring__label">
				<bdi>{ shortLeft( secondsLeft ) }</bdi>
			</span>
		</div>
	);
}
