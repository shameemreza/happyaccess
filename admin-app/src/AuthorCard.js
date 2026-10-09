import {
	createContext,
	createInterpolateElement,
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

export const AUTHOR_URL =
	'https://shameem.dev/?utm_source=happyaccess&utm_medium=plugin&utm_campaign=author-card';
export const RATE_URL =
	'https://wordpress.org/support/plugin/happyaccess/reviews/#new-post';
export const SUPPORT_URL = 'https://wordpress.org/support/plugin/happyaccess/';
export const DOCS_URL =
	'https://github.com/shameemreza/happyaccess/tree/main/docs';

const VIEWS = [ 'early', 'ask', 'credit' ];

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
 * "Built by Shameem Reza", with the name linking to his site in a new tab.
 *
 * @param {Object}                       props           Props.
 * @param {string}                       props.className Class of the line.
 * @param {import('react').Ref<Element>} [props.linkRef] Ref to the link.
 * @return {Element} The credit line.
 */
function Credit( { className, linkRef } ) {
	return (
		<p className={ className }>
			{ createInterpolateElement(
				/* translators: <a> and <note /> wrap the name in a link that opens a new tab. */
				__( 'Built by <a>Shameem Reza<note /></a>', 'happyaccess' ),
				{
					a: (
						// eslint-disable-next-line jsx-a11y/anchor-has-content -- createInterpolateElement fills it.
						<a
							ref={ linkRef }
							href={ AUTHOR_URL }
							target="_blank"
							rel="noopener noreferrer"
						/>
					),
					note: <NewTabNote />,
				}
			) }
		</p>
	);
}

const AuthorCardContext = createContext( null );

/**
 * Holds the card's state for the whole app. Each tab places its own
 * AuthorCard, so a dismiss or a rate click in one tab shows in the others
 * without a reload. The state comes from the boot data, so the card shows
 * at once with no request.
 *
 * @param {Object}  props          Props.
 * @param {Object}  [props.card]   Boot data: state (early, ask or credit) and photo (URL of the bundled photo).
 * @param {Element} props.children The app.
 * @return {Element} The provider.
 */
export function AuthorCardProvider( { card, children } ) {
	const [ view, setView ] = useState( () => {
		if ( ! card ) {
			return '';
		}
		return VIEWS.includes( card.state ) ? card.state : 'early';
	} );

	// The choice only changes what this card shows, so a failed save is
	// not worth an error: the card comes back on the next page load.
	const choose = useCallback( ( choice, next ) => {
		setView( next );
		saveAuthorCard( choice ).catch( () => {} );
	}, [] );

	const value = useMemo(
		() => ( { view, photo: card?.photo || '', choose } ),
		[ view, card, choose ]
	);

	return (
		<AuthorCardContext.Provider value={ value }>
			{ children }
		</AuthorCardContext.Provider>
	);
}

/**
 * The author card, placed at the bottom of a tab's right-hand column. It
 * asks for a rating only in the ask state, and a dismiss or a rate click is
 * saved for this user for good.
 *
 * @return {Element|null} The card, the small credit line, or nothing.
 */
export default function AuthorCard() {
	const context = useContext( AuthorCardContext );
	const view = context?.view || '';
	const creditLink = useRef( null );
	const thanks = useRef( null );
	const moveFocus = useRef( false );

	useEffect( () => {
		if ( ! moveFocus.current ) {
			return;
		}
		moveFocus.current = false;
		if ( 'credit' === view ) {
			creditLink.current?.focus();
		} else if ( 'thanked' === view ) {
			thanks.current?.focus();
		}
	}, [ view ] );

	if ( ! view ) {
		return null;
	}

	const choose = ( choice, next ) => {
		moveFocus.current = true;
		context.choose( choice, next );
	};

	if ( 'credit' === view ) {
		return <Credit className="ha-author-credit" linkRef={ creditLink } />;
	}

	return (
		<div className={ `ha-author ha-author--${ view }` }>
			<div className="ha-author__top">
				<img
					className="ha-author__photo"
					src={ context.photo }
					alt=""
					width="40"
					height="40"
				/>
				<div className="ha-author__who">
					<a
						className="ha-author__name"
						href={ AUTHOR_URL }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'Shameem Reza', 'happyaccess' ) }
						<NewTabNote />
					</a>
					<div className="ha-author__role">
						{ __( 'Built HappyAccess', 'happyaccess' ) }
						<span aria-hidden="true"> · </span>
						<a
							href={ DOCS_URL }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __( 'Docs', 'happyaccess' ) }
							<NewTabNote />
						</a>
						<span aria-hidden="true"> · </span>
						<a
							href={ SUPPORT_URL }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __( 'Support', 'happyaccess' ) }
							<NewTabNote />
						</a>
					</div>
				</div>
			</div>
			{ 'ask' === view && (
				<Button
					className="ha-author__hide"
					icon={ close }
					iconSize={ 16 }
					size="small"
					label={ __( 'Hide this', 'happyaccess' ) }
					onClick={ () => choose( 'dismissed', 'credit' ) }
				/>
			) }
			{ 'ask' === view && (
				<>
					<p className="ha-author__line">
						{ __(
							'Is HappyAccess helping you? A quick rating helps others find it.',
							'happyaccess'
						) }
					</p>
					<div className="ha-author__actions">
						<Button
							variant="primary"
							size="compact"
							href={ RATE_URL }
							target="_blank"
							rel="noopener noreferrer"
							onClick={ () => choose( 'rated', 'thanked' ) }
						>
							{ __( 'Rate it on WordPress.org', 'happyaccess' ) }
							<NewTabNote />
						</Button>
						<Button
							variant="secondary"
							size="compact"
							href={ SUPPORT_URL }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __(
								'Something missing? Tell me',
								'happyaccess'
							) }
							<NewTabNote />
						</Button>
					</div>
				</>
			) }
			{ 'thanked' === view && (
				<p className="ha-author__line" ref={ thanks } tabIndex={ -1 }>
					{ __( 'Thank you! That really helps.', 'happyaccess' ) }
				</p>
			) }
		</div>
	);
}
