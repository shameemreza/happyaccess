import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import LoginMock from './LoginMock';

const BUBBLE_ID = 'ha-loginprev-bubble';

/**
 * A small copy of the WordPress login screen. When Support access is on, a
 * hand-drawn marker circles the link HappyAccess adds, and a bubble explains
 * it. Clicking the circled link opens or closes the bubble.
 *
 * @param {Object}  props               Props.
 * @param {boolean} props.supportAccess Whether Support access is on.
 * @return {Element} The aside.
 */
export default function LoginPreview( { supportAccess } ) {
	const [ open, setOpen ] = useState( true );
	const [ visible, setVisible ] = useState( false );
	const rootRef = useRef( null );

	// Draw the marker only once the preview scrolls into view, so the
	// animation is never spent off screen.
	useEffect( () => {
		const node = rootRef.current;
		if ( ! node || 'function' !== typeof window.IntersectionObserver ) {
			setVisible( true );
			return undefined;
		}
		const observer = new window.IntersectionObserver(
			( entries ) => {
				if ( entries.some( ( entry ) => entry.isIntersecting ) ) {
					setVisible( true );
					observer.disconnect();
				}
			},
			{ threshold: 0.4 }
		);
		observer.observe( node );
		return () => observer.disconnect();
	}, [] );

	const onKeyDown = ( event ) => {
		if ( 'Escape' === event.key ) {
			setOpen( false );
		}
	};

	const bubble = open && (
		<div id={ BUBBLE_ID } className="ha-loginprev__bubble" role="note">
			<svg
				className="ha-loginprev__shape"
				viewBox="0 0 200 110"
				preserveAspectRatio="none"
				aria-hidden="true"
				focusable="false"
			>
				<path
					pathLength="1"
					d="M24 14 C 62 2, 146 0, 182 16 C 200 26, 202 78, 184 92 C 150 110, 62 110, 24 96 C 4 86, 0 30, 24 14 Z"
				/>
			</svg>
			<svg
				className="ha-loginprev__arrow"
				viewBox="0 0 64 32"
				aria-hidden="true"
				focusable="false"
			>
				<path
					pathLength="1"
					d="M62 18 C 52 8, 40 6, 41 14 C 42 22, 53 19, 48 11 C 42 3, 22 10, 6 16"
				/>
				<path
					className="ha-loginprev__arrowhead"
					pathLength="1"
					d="M15 10 L 6 16 L 15 21"
				/>
			</svg>
			<strong>{ __( 'Temporary access', 'happyaccess' ) }</strong>
		</div>
	);

	return (
		<aside
			ref={ rootRef }
			className={ visible ? 'ha-loginprev is-visible' : 'ha-loginprev' }
			aria-labelledby="ha-loginprev-title"
		>
			<h2 id="ha-loginprev-title">
				{ __( 'Your login screen', 'happyaccess' ) }
			</h2>
			<p className="ha-loginprev__lead">
				{ __( 'Updates as you change features.', 'happyaccess' ) }
			</p>
			<div className="ha-loginprev__screen">
				<LoginMock />
				{ supportAccess && (
					<div
						className={
							open
								? 'ha-loginprev__code has-bubble'
								: 'ha-loginprev__code'
						}
					>
						<div className="ha-loginprev__spot">
							<button
								type="button"
								className="ha-loginprev__link"
								aria-expanded={ open }
								aria-controls={ BUBBLE_ID }
								onClick={ () =>
									setOpen( ( value ) => ! value )
								}
								onKeyDown={ onKeyDown }
							>
								{ __(
									'Log in with an access code',
									'happyaccess'
								) }
							</button>
							<svg
								className="ha-loginprev__loop"
								viewBox="0 0 240 64"
								preserveAspectRatio="none"
								aria-hidden="true"
								focusable="false"
							>
								<path
									pathLength="1"
									d="M30 50 C 8 44, 6 22, 34 14 C 80 2, 176 4, 214 14 C 238 22, 236 46, 206 54 C 160 64, 70 62, 28 48 C 16 44, 18 30, 44 22"
								/>
							</svg>
							{ bubble }
						</div>
					</div>
				) }
				<div aria-hidden="true" className="ha-loginprev__lost">
					{ __( 'Lost your password?', 'happyaccess' ) }
				</div>
			</div>
		</aside>
	);
}
