import {
	createContext,
	useCallback,
	useContext,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { close } from '@wordpress/icons';
import { saveAuthorCard } from './api';
import { tipIndex, tipLines } from './authorTips';
import { useAnnounce } from './hooks/useAnnounce';

export const AUTHOR_URL =
	'https://shameem.dev/?utm_source=happyaccess&utm_medium=plugin&utm_campaign=author-card';
export const RATE_URL =
	'https://wordpress.org/support/plugin/happyaccess/reviews/#new-post';
export const SUPPORT_URL = 'https://wordpress.org/support/plugin/happyaccess/';

/**
 * Where this browser keeps the id of the last line it showed, so a new
 * line fades in once.
 */
export const LAST_TIP_KEY = 'happyaccess-author-tip';

const VIEWS = [ 'intro', 'tips', 'ask', 'thanked' ];
const LINE_VIEWS = [ 'intro', 'tips' ];
const NO_FACTS = {};
const NO_FEATURES = {};

/**
 * The first view, from the boot data state: none is the hidden card.
 *
 * @param {Object} [card] Boot data.
 * @return {string} A view, or empty for no card.
 */
function firstView( card ) {
	if ( ! card || 'none' === card.state ) {
		return '';
	}
	return VIEWS.includes( card.state ) ? card.state : 'tips';
}

/**
 * Notes the line in this browser and says whether it differs from the
 * last one. Storage can be missing or blocked, and then nothing fades.
 *
 * @param {string} id Line id.
 * @return {boolean} Whether the line is new to this browser.
 */
function rememberTip( id ) {
	try {
		const last = window.localStorage.getItem( LAST_TIP_KEY );
		window.localStorage.setItem( LAST_TIP_KEY, id );
		return last !== id;
	} catch {
		return false;
	}
}

/**
 * Text read after a link that opens a new tab.
 *
 * @return {Element} Hidden text for screen readers.
 */
function NewTabNote() {
	return (
		<span className="screen-reader-text">
			{ ' ' }
			{ __( '(opens in a new tab)', 'happyaccess' ) }
		</span>
	);
}

/**
 * The arrow after a link that leaves the site. Screen readers get the new
 * tab note instead.
 *
 * @return {Element} The arrow.
 */
function OutArrow() {
	return (
		<span className="ha-author__arrow" aria-hidden="true">
			↗
		</span>
	);
}

/**
 * A green text link that opens in a new tab.
 *
 * @param {Object}  props          Props.
 * @param {string}  props.href     Address.
 * @param {Element} props.children Link text.
 * @return {Element} The link.
 */
function OutLink( { href, children } ) {
	return (
		<a
			className="ha-author__link"
			href={ href }
			target="_blank"
			rel="noopener noreferrer"
		>
			{ children }
			<OutArrow />
			<NewTabNote />
		</a>
	);
}

/**
 * "Say hello" and the forum link.
 *
 * @return {Element} The row.
 */
function HelloLinks() {
	return (
		<p className="ha-author__links">
			<OutLink href={ AUTHOR_URL }>
				{ __( 'Say hello', 'happyaccess' ) }
			</OutLink>
			<OutLink href={ SUPPORT_URL }>
				{ __( 'Questions? Ask in the forum', 'happyaccess' ) }
			</OutLink>
		</p>
	);
}

/**
 * The bulb in the intro's tip box.
 *
 * @return {Element} The icon.
 */
function Bulb() {
	return (
		<svg
			className="ha-author__bulb"
			width="16"
			height="16"
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="2"
			strokeLinecap="round"
			strokeLinejoin="round"
			aria-hidden="true"
			focusable="false"
		>
			<path d="M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.7V17h8v-2.3A7 7 0 0 0 12 2z" />
		</svg>
	);
}

const AuthorCardContext = createContext( null );

/**
 * Holds the card's state for the whole app. Each tab places its own
 * AuthorCard, so a choice in one tab shows in the others without a reload,
 * and every tab shows the same line.
 *
 * The line is picked once, when the data it may name has loaded, from a
 * three-hour time slot and the user id. It stays for the whole page view;
 * only a line that stopped being true, like a count of passes after
 * Emergency lock, is swapped for another.
 *
 * @param {Object}  props            Props.
 * @param {Object}  [props.card]     Boot data: state (intro, tips, ask or none), photo, user and facts.
 * @param {Object}  [props.features] The feature switches as they are now.
 * @param {Object}  [props.passes]   The pass list: { items, loading }. Without it, no pass lines.
 * @param {Element} props.children   The app.
 * @return {Element} The provider.
 */
export function AuthorCardProvider( {
	card,
	features = NO_FEATURES,
	passes,
	children,
} ) {
	const [ view, setView ] = useState( () => firstView( card ) );
	const [ tip, setTip ] = useState( null );
	const picked = useRef( null );
	const facts = card?.facts || NO_FACTS;
	const userId = Number( card?.user ) || 0;
	const items = passes?.items;
	const waiting = !! features.support_access && !! passes?.loading;

	useEffect( () => {
		if ( ! LINE_VIEWS.includes( view ) || waiting ) {
			return;
		}
		const now = Math.floor( Date.now() / 1000 );
		const lines = tipLines( {
			features,
			facts,
			passes: Array.isArray( items ) ? items : [],
			now,
		} );
		const current = picked.current;
		if ( current && lines.some( ( line ) => line.id === current.id ) ) {
			return;
		}
		const line = lines[ tipIndex( lines.length, now, userId ) ];
		if ( ! line ) {
			return;
		}
		// Only the first line of a page view may fade in.
		const next = { ...line, fresh: ! current && rememberTip( line.id ) };
		picked.current = next;
		setTip( next );
	}, [ view, waiting, features, facts, items, userId ] );

	const settle = useCallback( () => {
		setTip( ( current ) =>
			current?.fresh ? { ...current, fresh: false } : current
		);
	}, [] );

	// The choice only changes what this card shows, so a failed save is
	// not worth an error: the card comes back on the next page load.
	const choose = useCallback( ( choice, next ) => {
		setView( next );
		saveAuthorCard( choice ).catch( () => {} );
	}, [] );

	const value = useMemo(
		() => ( { view, tip, photo: card?.photo || '', choose, settle } ),
		[ view, tip, card, choose, settle ]
	);

	return (
		<AuthorCardContext.Provider value={ value }>
			{ children }
		</AuthorCardContext.Provider>
	);
}

/**
 * The line in the card, faded in when it is new to this browser.
 *
 * @param {Object}  props             Props.
 * @param {Object}  props.tip         The line: { text, fresh }.
 * @param {string}  props.className   Class of the text.
 * @param {string}  [props.as]        Element: span or p.
 * @param {Object}  [props.textRef]   Ref for focus.
 * @param {boolean} [props.focusable] Whether code may move focus here.
 * @return {Element} The text.
 */
function TipText( { tip, className, as: Tag = 'span', textRef, focusable } ) {
	const context = useContext( AuthorCardContext );
	return (
		<Tag
			ref={ textRef }
			className={ `${ className }${ tip.fresh ? ' ha-author__fresh' : '' }` }
			tabIndex={ focusable ? -1 : undefined }
			onAnimationEnd={ context.settle }
		>
			{ tip.text }
		</Tag>
	);
}

const FOCUSABLE =
	'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * The last control before a node in page order, where focus goes when the
 * node goes away, as if the person had tabbed back once.
 *
 * @param {Element} node The node that is about to go.
 * @return {Element|null} The control, or nothing.
 */
function controlBefore( node ) {
	const before = Array.from(
		node.ownerDocument.querySelectorAll( FOCUSABLE )
	).filter(
		( element ) =>
			! node.contains( element ) &&
			// eslint-disable-next-line no-bitwise
			element.compareDocumentPosition( node ) &
				node.ownerDocument.defaultView.Node.DOCUMENT_POSITION_FOLLOWING
	);
	return before.length ? before[ before.length - 1 ] : null;
}

/**
 * The author card, at the bottom of a tab's right-hand column. A hello for
 * the first day, a rating ask once the plugin has done its job, and a
 * rotating line about this site or a tip the rest of the time. Hide tips
 * removes it for good for this user.
 *
 * @return {Element|null} The card, or nothing.
 */
export default function AuthorCard() {
	const context = useContext( AuthorCardContext );
	const announce = useAnnounce();
	const view = context?.view || '';
	const tip = context?.tip || null;
	const title = useRef( null );
	const body = useRef( null );
	const root = useRef( null );
	const fallback = useRef( null );
	const moveFocus = useRef( false );

	// After a choice, focus goes to what replaced the button. Tips wait
	// for their line to render first.
	useEffect( () => {
		if ( ! moveFocus.current ) {
			return;
		}
		let target = null;
		if ( 'thanked' === view ) {
			target = title.current;
		} else if ( 'tips' === view ) {
			target = body.current;
			if ( ! target ) {
				return;
			}
		} else if ( '' === view ) {
			// The card is gone, so focus goes to the control before it.
			target = fallback.current?.isConnected ? fallback.current : null;
			fallback.current = null;
		}
		moveFocus.current = false;
		target?.focus();
	}, [ view, tip ] );

	// Tips wait for their line, so the card never shows an empty body.
	if ( ! view || ( 'tips' === view && ! tip ) ) {
		return null;
	}

	const choose = ( choice, next ) => {
		moveFocus.current = true;
		context.choose( choice, next );
	};

	const hide = () => {
		fallback.current = root.current ? controlBefore( root.current ) : null;
		choose( 'hidden', '' );
		announce( __( 'Tips hidden', 'happyaccess' ) );
	};

	return (
		<div ref={ root } className={ `ha-author ha-author--${ view }` }>
			<span
				className="ha-author__curve ha-author__curve--top"
				aria-hidden="true"
			/>
			<span
				className="ha-author__curve ha-author__curve--side"
				aria-hidden="true"
			/>

			{ 'intro' === view && (
				<>
					<h2 className="ha-author__title">
						{ __( "Hi, I'm Shameem.", 'happyaccess' ) }
					</h2>
					<p className="ha-author__text">
						{ __(
							'I kept seeing admin passwords sent by email, and accounts nobody deleted. So I built HappyAccess: access that ends on its own, and logins that skip the password.',
							'happyaccess'
						) }
					</p>
					<div className="ha-author__foot">
						{ tip && (
							<p className="ha-author__tip">
								<Bulb />
								<TipText
									tip={ tip }
									className="ha-author__tip-text"
								/>
							</p>
						) }
						<HelloLinks />
					</div>
				</>
			) }

			{ 'tips' === view && (
				<>
					<Button
						className="ha-author__hide"
						icon={ close }
						iconSize={ 16 }
						size="small"
						label={ __( 'Hide tips', 'happyaccess' ) }
						onClick={ hide }
					/>
					<TipText
						as="p"
						tip={ tip }
						className="ha-author__body"
						textRef={ body }
						focusable
					/>
					<div className="ha-author__foot">
						<HelloLinks />
					</div>
				</>
			) }

			{ 'ask' === view && (
				<>
					<h2 className="ha-author__title">
						{ __(
							'Has HappyAccess earned a few stars?',
							'happyaccess'
						) }
					</h2>
					<p className="ha-author__text">
						{ __(
							'If it saved you from sending a password, a rating on WordPress.org helps the next site owner find it. It takes a minute.',
							'happyaccess'
						) }
					</p>
					<a
						className="ha-author__rate"
						href={ RATE_URL }
						target="_blank"
						rel="noopener noreferrer"
						onClick={ () => choose( 'rated', 'thanked' ) }
					>
						<span className="ha-author__stars" aria-hidden="true">
							{ [ 1, 2, 3, 4, 5 ].map( ( n ) => (
								<span key={ n } className="ha-author__star">
									★
								</span>
							) ) }
						</span>
						<span className="ha-author__rate-text">
							{ __( 'Rate it on WordPress.org', 'happyaccess' ) }
							<OutArrow />
						</span>
						<NewTabNote />
					</a>
					<div className="ha-author__foot">
						<p className="ha-author__links">
							<OutLink href={ SUPPORT_URL }>
								{ __(
									'Something missing? Tell me',
									'happyaccess'
								) }
							</OutLink>
							<button
								type="button"
								className="ha-author__later"
								onClick={ () => choose( 'later', 'tips' ) }
							>
								{ __( 'Not now', 'happyaccess' ) }
							</button>
						</p>
					</div>
				</>
			) }

			{ 'thanked' === view && (
				<>
					<h2
						className="ha-author__title"
						ref={ title }
						tabIndex={ -1 }
					>
						{ __( 'Thank you. Really.', 'happyaccess' ) }
					</h2>
					<p className="ha-author__text">
						{ __(
							'Ratings are how other site owners find HappyAccess. Yours just made that a little easier.',
							'happyaccess'
						) }
					</p>
					<div className="ha-author__foot">
						<HelloLinks />
					</div>
				</>
			) }

			<img
				className="ha-author__photo"
				src={ context.photo }
				alt=""
				width="76"
				height="76"
			/>
			{ 'thanked' === view && (
				<span className="ha-author__badge" aria-hidden="true">
					<svg
						width="13"
						height="13"
						viewBox="0 0 24 24"
						fill="currentColor"
						focusable="false"
					>
						<path d="M12 21s-7.5-4.6-9.6-9.2C.9 8.4 3 4.5 6.6 4.5c2.1 0 3.6 1.2 5.4 3.2 1.8-2 3.3-3.2 5.4-3.2 3.6 0 5.7 3.9 4.2 7.3C19.5 16.4 12 21 12 21z" />
					</svg>
				</span>
			) }
		</div>
	);
}
