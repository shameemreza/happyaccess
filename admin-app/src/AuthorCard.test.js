import { readFileSync } from 'node:fs';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import apiFetch from '@wordpress/api-fetch';
import AuthorCard, {
	AuthorCardProvider,
	AUTHOR_URL,
	LAST_TIP_KEY,
	RATE_URL,
	SUPPORT_URL,
} from './AuthorCard';
import { TIP_BUCKET, tipLines } from './authorTips';

vi.mock( '@wordpress/api-fetch' );

const PHOTO =
	'https://example.test/wp-content/plugins/happyaccess/assets/author.jpg';

// The start of a three-hour slot, so a test knows which line shows.
const NOW = 1800000000 - ( 1800000000 % TIP_BUCKET );
const SLOT = NOW / TIP_BUCKET;

const ALL_ON = { support_access: true, passwordless: true, two_step: true };

// A link with target="_blank" would ask jsdom to open a window. The app's own
// click handlers still run.
const stopNavigation = ( event ) => {
	if ( event.target.closest( 'a' ) ) {
		event.preventDefault();
	}
};

let clock;

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockResolvedValue( { state: 'tips' } );
	document.addEventListener( 'click', stopNavigation );
	clock = vi.spyOn( Date, 'now' ).mockReturnValue( NOW * 1000 );
	window.localStorage.clear();
} );

afterEach( () => {
	document.removeEventListener( 'click', stopNavigation );
	clock.mockRestore();
} );

/**
 * The user id that shows the line at `index` in the current slot.
 *
 * @param {number} index Line index.
 * @param {number} count How many lines there are.
 * @return {number} User id.
 */
const userFor = ( index, count ) =>
	( ( ( index - SLOT ) % count ) + count ) % count;

function Card( { state, user = 1, facts = {}, features = ALL_ON, passes } ) {
	return (
		<AuthorCardProvider
			card={ { state, photo: PHOTO, user, facts } }
			features={ features }
			passes={ passes }
		>
			<AuthorCard />
		</AuthorCardProvider>
	);
}

function setup( state, props = {} ) {
	const user = userEvent.setup();
	const view = render( <Card state={ state } { ...props } /> );
	return { user, ...view };
}

const link = ( name ) =>
	screen.queryByRole( 'link', { name: `${ name } (opens in a new tab)` } );
const helloLink = () => link( 'Say hello' );
const forumLink = () => link( 'Questions? Ask in the forum' );
const rateLink = () => link( 'Rate it on WordPress.org' );
const tellLink = () => link( 'Something missing? Tell me' );
const hideButton = () => screen.queryByRole( 'button', { name: 'Hide tips' } );
const laterButton = () => screen.queryByRole( 'button', { name: 'Not now' } );
const body = ( container ) => container.querySelector( '.ha-author__body' );

const INTRO =
	'I kept seeing admin passwords sent by email, and accounts nobody deleted. So I built HappyAccess: access that ends on its own, and logins that skip the password.';
const ASK =
	'If it saved you from sending a password, a rating on WordPress.org helps the next site owner find it. It takes a minute.';
const THANKS =
	'Ratings are how other site owners find HappyAccess. Yours just made that a little easier.';

function expectHelloLinks() {
	expect( helloLink() ).toHaveAttribute( 'href', AUTHOR_URL );
	expect( forumLink() ).toHaveAttribute( 'href', SUPPORT_URL );
	for ( const item of [ helloLink(), forumLink() ] ) {
		expect( item ).toHaveAttribute( 'target', '_blank' );
		expect( item ).toHaveAttribute( 'rel', 'noopener noreferrer' );
		expect( item ).toHaveClass( 'ha-author__link' );
		expect( item ).toHaveTextContent( /↗/ );
	}
}

function expectPhoto( container ) {
	const photo = container.querySelector( '.ha-author__photo' );
	expect( photo ).toHaveAttribute( 'src', PHOTO );
	expect( photo ).toHaveAttribute( 'alt', '' );
	expect( photo ).toHaveAttribute( 'width', '76' );
	expect(
		container.querySelectorAll( '.ha-author__curve[aria-hidden="true"]' )
	).toHaveLength( 2 );
}

function expectNoTimerOrCounter( container ) {
	expect( container ).not.toHaveTextContent( /Next tip/ );
	expect( container ).not.toHaveTextContent( /Tip \d+ of \d+/ );
	expect( container.querySelector( '[aria-live]' ) ).toBeNull();
	expect( screen.queryByRole( 'button', { name: /tip/i } ) ).toBe(
		hideButton()
	);
}

describe( 'AuthorCard', () => {
	it( 'shows nothing without boot data, outside the provider, or once hidden', () => {
		const { container, unmount } = render( <AuthorCard /> );
		expect( container ).toBeEmptyDOMElement();
		unmount();

		const empty = render(
			<AuthorCardProvider>
				<AuthorCard />
			</AuthorCardProvider>
		);
		expect( empty.container ).toBeEmptyDOMElement();
		empty.unmount();

		const hidden = render( <Card state="none" /> );
		expect( hidden.container ).toBeEmptyDOMElement();
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	it( 'says hello in the intro, with the rotating line in a white tip box and no button in it', async () => {
		const { container } = setup( 'intro', { features: {}, user: 0 } );

		expect(
			screen.getByRole( 'heading', { name: "Hi, I'm Shameem." } )
		).toBeInTheDocument();
		expect( container ).toHaveTextContent( INTRO );
		const tipBox = await waitFor( () => {
			const found = container.querySelector( '.ha-author__tip' );
			expect( found ).not.toBeNull();
			return found;
		} );
		const lines = tipLines( { now: NOW } );
		expect( tipBox ).toHaveTextContent( lines[ SLOT % lines.length ].text );
		expect( tipBox.querySelector( 'svg' ) ).toHaveAttribute(
			'aria-hidden',
			'true'
		);
		expect( tipBox.querySelector( 'button' ) ).toBeNull();
		expect( tipBox.parentElement ).toHaveClass( 'ha-author__foot' );
		expectHelloLinks();
		expectPhoto( container );
		expect( hideButton() ).toBeNull();
		expect( rateLink() ).toBeNull();
		expectNoTimerOrCounter( container );
		expect( container ).not.toHaveTextContent( /["“”]/ );
		expect( await axe( container ) ).toHaveNoViolations();
	} );

	it( 'shows the line as the body in tips, with Hide tips and the two links, and no heading', async () => {
		const facts = { admins: 4 };
		const count = tipLines( { features: ALL_ON, facts, now: NOW } ).length;
		const { container } = setup( 'tips', {
			facts,
			user: userFor( 0, count ),
		} );

		await waitFor( () =>
			expect( body( container ) ).toHaveTextContent(
				'This site has 4 administrator accounts. Remove the ones nobody uses, so there are fewer ways in.'
			)
		);
		expect( screen.queryByRole( 'heading' ) ).toBeNull();
		expect( hideButton() ).toBeInTheDocument();
		expect( container.querySelector( '.ha-author__tip' ) ).toBeNull();
		expectHelloLinks();
		expectPhoto( container );
		expectNoTimerOrCounter( container );
		expect( await axe( container ) ).toHaveNoViolations();
	} );

	it( 'asks for a rating with one button to the review form, stars for the eye only', async () => {
		const { container } = setup( 'ask' );

		expect(
			screen.getByRole( 'heading', {
				name: 'Has HappyAccess earned a few stars?',
			} )
		).toBeInTheDocument();
		expect( container ).toHaveTextContent( ASK );
		expect( rateLink() ).toHaveAttribute( 'href', RATE_URL );
		expect( rateLink() ).toHaveAttribute( 'target', '_blank' );
		expect( rateLink() ).toHaveAttribute( 'rel', 'noopener noreferrer' );
		const stars = rateLink().querySelector( '.ha-author__stars' );
		expect( stars ).toHaveAttribute( 'aria-hidden', 'true' );
		expect( stars.textContent ).toBe( '★★★★★' );
		expect( rateLink() ).toHaveTextContent(
			'★★★★★Rate it on WordPress.org↗'
		);
		// One link to the reviews, never one per star, and none to the forum
		// in its place.
		const toReviews = screen
			.getAllByRole( 'link' )
			.filter( ( item ) => item.href.includes( '/reviews/' ) );
		expect( toReviews ).toEqual( [ rateLink() ] );
		expect( tellLink() ).toHaveAttribute( 'href', SUPPORT_URL );
		expect( laterButton() ).toBeInTheDocument();
		expect( hideButton() ).toBeNull();
		expect( helloLink() ).toBeNull();
		expect( forumLink() ).toBeNull();
		expectPhoto( container );
		expect( apiFetch ).not.toHaveBeenCalled();
		expect( await axe( container ) ).toHaveNoViolations();
	} );

	it( 'points at the right addresses, with the UTM tag on the author link', () => {
		expect( AUTHOR_URL ).toBe(
			'https://shameem.dev/?utm_source=happyaccess&utm_medium=plugin&utm_campaign=author-card'
		);
		expect( RATE_URL ).toBe(
			'https://wordpress.org/support/plugin/happyaccess/reviews/#new-post'
		);
		expect( SUPPORT_URL ).toBe(
			'https://wordpress.org/support/plugin/happyaccess/'
		);
	} );

	it( 'thanks the user after the rating click, with the heart on the photo', async () => {
		const { user, container } = setup( 'ask' );

		await user.click( rateLink() );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/happyaccess/v1/author-card',
				method: 'POST',
				data: { choice: 'rated' },
			} )
		);
		const title = screen.getByRole( 'heading', {
			name: 'Thank you. Really.',
		} );
		await waitFor( () => expect( title ).toHaveFocus() );
		expect( container ).toHaveTextContent( THANKS );
		expect(
			container.querySelector( '.ha-author__badge' )
		).toHaveAttribute( 'aria-hidden', 'true' );
		expectHelloLinks();
		expect( rateLink() ).toBeNull();
		expect( laterButton() ).toBeNull();
		expect( await axe( container ) ).toHaveNoViolations();
	} );

	it( 'moves to tips after Not now, saves later, and focuses the line', async () => {
		const { user, container } = setup( 'ask' );

		await user.click( laterButton() );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( { data: { choice: 'later' } } )
		);
		await waitFor( () => expect( body( container ) ).toHaveFocus() );
		expect( container.querySelector( '.ha-author' ) ).toHaveClass(
			'ha-author--tips'
		);
		expect( rateLink() ).toBeNull();
		expect( hideButton() ).toBeInTheDocument();
	} );

	it( 'keeps the ask after a click on Tell me, and saves nothing', async () => {
		const { user } = setup( 'ask' );

		await user.click( tellLink() );

		expect( apiFetch ).not.toHaveBeenCalled();
		expect( rateLink() ).toBeInTheDocument();
	} );

	it( 'hides the card for good with Hide tips, and leaves no credit line', async () => {
		const { user, container } = setup( 'tips' );
		await waitFor( () => expect( hideButton() ).toBeInTheDocument() );

		await user.click( hideButton() );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/happyaccess/v1/author-card',
				method: 'POST',
				data: { choice: 'hidden' },
			} )
		);
		expect( container ).toBeEmptyDOMElement();
		expect( container ).not.toHaveTextContent( 'Built by' );
	} );

	it( 'stays hidden when the save fails', async () => {
		apiFetch.mockRejectedValue( { code: 'fetch_error' } );
		const { user, container } = setup( 'tips' );
		await waitFor( () => expect( hideButton() ).toBeInTheDocument() );

		await user.click( hideButton() );

		await waitFor( () => expect( apiFetch ).toHaveBeenCalled() );
		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'reads an unknown state as tips', async () => {
		const { container } = setup( 'credit' );

		await waitFor( () => expect( body( container ) ).not.toBeNull() );
		expect( container ).not.toHaveTextContent( 'Built by' );
	} );

	it( 'shares one state and one line, so a card placed later shows both', async () => {
		const user = userEvent.setup();
		function Tabs( { second } ) {
			return (
				<AuthorCardProvider
					card={ { state: 'ask', photo: PHOTO, user: 2, facts: {} } }
					features={ ALL_ON }
				>
					<div data-testid={ second ? 'second' : 'first' }>
						<AuthorCard key={ second ? 'b' : 'a' } />
					</div>
				</AuthorCardProvider>
			);
		}
		const { rerender, container } = render( <Tabs second={ false } /> );

		await user.click( laterButton() );
		const line = await waitFor( () => {
			expect( body( container ) ).not.toBeNull();
			return body( container ).textContent;
		} );
		rerender( <Tabs second /> );

		expect( screen.getByTestId( 'second' ) ).toHaveTextContent( line );
		expect( rateLink() ).toBeNull();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'AuthorCard rotation', () => {
	it( 'shows the same line all through a three-hour slot and moves on in the next', async () => {
		const first = render( <Card state="tips" user={ 3 } /> );
		await waitFor( () => expect( body( first.container ) ).not.toBeNull() );
		const line = body( first.container ).textContent;
		first.unmount();

		clock.mockReturnValue( ( NOW + TIP_BUCKET - 1 ) * 1000 );
		const later = render( <Card state="tips" user={ 3 } /> );
		await waitFor( () => expect( body( later.container ) ).not.toBeNull() );
		expect( body( later.container ) ).toHaveTextContent( line );
		later.unmount();

		clock.mockReturnValue( ( NOW + TIP_BUCKET ) * 1000 );
		const next = render( <Card state="tips" user={ 3 } /> );
		await waitFor( () => expect( body( next.container ) ).not.toBeNull() );
		expect( body( next.container ).textContent ).not.toBe( line );
	} );

	it( 'gives two users a different line in the same slot', async () => {
		const one = render( <Card state="tips" user={ 1 } /> );
		await waitFor( () => expect( body( one.container ) ).not.toBeNull() );
		const line = body( one.container ).textContent;
		one.unmount();

		const two = render( <Card state="tips" user={ 2 } /> );
		await waitFor( () => expect( body( two.container ) ).not.toBeNull() );
		expect( body( two.container ).textContent ).not.toBe( line );
	} );

	it( 'never changes the line while the page is open, even as the passes refresh', async () => {
		const passes = ( items ) => ( { items, loading: false } );
		const pass = {
			id: 1,
			status: 'active',
			expires_at: NOW + 3600,
			last_login_at: 0,
		};
		const count = tipLines( {
			features: ALL_ON,
			passes: [ pass ],
			now: NOW,
		} ).length;
		const user = userFor( 0, count );
		const { container, rerender } = render(
			<Card state="tips" user={ user } passes={ passes( [ pass ] ) } />
		);
		await waitFor( () =>
			expect( body( container ) ).toHaveTextContent(
				'1 support pass is on, and it ends in 1 hour. You can extend it or end it early under Who has access.'
			)
		);

		// A minute later the list loads again with a second pass. The line
		// keeps its first numbers.
		clock.mockReturnValue( ( NOW + 60 ) * 1000 );
		rerender(
			<Card
				state="tips"
				user={ user }
				passes={ passes( [ pass, { ...pass, id: 2 } ] ) }
			/>
		);
		expect( body( container ) ).toHaveTextContent(
			'1 support pass is on, and it ends in 1 hour. You can extend it or end it early under Who has access.'
		);

		// Emergency lock ended every pass, so that line stopped being true.
		rerender( <Card state="tips" user={ user } passes={ passes( [] ) } /> );
		await waitFor( () =>
			expect( body( container ) ).not.toHaveTextContent( 'support pass' )
		);
	} );

	it( 'waits for the pass list before it picks a line', async () => {
		const { container, rerender } = render(
			<Card state="tips" passes={ { items: [], loading: true } } />
		);
		expect( container ).toBeEmptyDOMElement();

		rerender(
			<Card state="tips" passes={ { items: [], loading: false } } />
		);
		await waitFor( () => expect( body( container ) ).not.toBeNull() );
	} );

	it( 'fades a line in once when it is new to this browser, and not again', async () => {
		const first = render( <Card state="tips" /> );
		await waitFor( () => expect( body( first.container ) ).not.toBeNull() );
		const text = body( first.container );
		expect( text ).toHaveClass( 'ha-author__fresh' );
		const stored = window.localStorage.getItem( LAST_TIP_KEY );
		expect( stored ).toBeTruthy();

		fireEvent.animationEnd( text );
		expect( text ).not.toHaveClass( 'ha-author__fresh' );
		first.unmount();

		const again = render( <Card state="tips" /> );
		await waitFor( () => expect( body( again.container ) ).not.toBeNull() );
		expect( body( again.container ) ).not.toHaveClass( 'ha-author__fresh' );
	} );

	it( 'shows the line without a fade when storage is blocked', async () => {
		const blocked = vi
			.spyOn( Storage.prototype, 'getItem' )
			.mockImplementation( () => {
				throw new Error( 'blocked' );
			} );
		try {
			const { container } = render( <Card state="tips" /> );
			await waitFor( () => expect( body( container ) ).not.toBeNull() );
			expect( body( container ) ).not.toHaveClass( 'ha-author__fresh' );
		} finally {
			blocked.mockRestore();
		}
	} );
} );

describe( 'AuthorCard tips layout', () => {
	/**
	 * One block of the card styles, by selector, as written in the source.
	 * The tests run without CSS, so the layout rules are checked here.
	 *
	 * @param {string} selector Selector that opens the block.
	 * @return {string} The block's text up to its closing brace.
	 */
	const styleBlock = ( selector ) => {
		const scss = readFileSync(
			path.resolve( 'admin-app/src/style.scss' ),
			'utf8'
		);
		const start = scss.indexOf( selector + ' {' );
		return -1 === start
			? ''
			: scss.slice( start, scss.indexOf( '}', start ) );
	};

	it( 'is only as tall as the cut-out needs, with the links under the text', () => {
		const card = styleBlock( '.ha-author--tips' );
		expect( card ).toMatch( /box-sizing: border-box;/ );
		expect( card ).toMatch( /min-height: 124px;/ );

		const foot = styleBlock( '.ha-author--tips .ha-author__foot' );
		expect( foot ).toMatch( /min-height: 0;/ );
		expect( foot ).toMatch( /justify-content: flex-start;/ );

		// The text keeps the same clearance from the cut-out as the foot.
		const clear =
			'padding-inline-end: calc(var(--ha-author-notch) - var(--ha-author-pad) + 10px);';
		expect( styleBlock( '.ha-author--tips .ha-author__body' ) ).toContain(
			clear
		);
		expect( styleBlock( '.ha-author__foot' ) ).toContain( clear );
	} );

	it( 'has no border, so only the green fill follows the cut-out', () => {
		// The card's own block, not the side column rule above it.
		const card = styleBlock( '\t.ha-author' );
		expect( card ).toMatch( /background: var\(--ha-author-card\);/ );
		expect( card ).not.toMatch( /\bborder:/ );
	} );

	it( 'gives the tip the full width in a narrow card and keeps the links beside the cut-out', () => {
		const scss = readFileSync(
			path.resolve( 'admin-app/src/style.scss' ),
			'utf8'
		);
		const start = scss.indexOf( '@container (max-width: 420px) {' );
		expect( start ).toBeGreaterThan( -1 );
		const narrow = scss.slice( start, scss.indexOf( '\n\t}\n', start ) );
		const block = ( selector ) => {
			const at = narrow.indexOf( selector + ' {' );
			return -1 === at
				? ''
				: narrow.slice( at, narrow.indexOf( '}', at ) );
		};

		// The intro tip box spans the card; the links take the band beside
		// the cut-out, as tall as it, so the box ends above it.
		const foot = block( '.ha-author--intro .ha-author__foot' );
		expect( foot ).toMatch( /padding-inline-end: 0;/ );
		const links = block( '.ha-author--intro .ha-author__links' );
		expect( links ).toContain(
			'min-height: calc(var(--ha-author-notch) - var(--ha-author-pad-end));'
		);
		expect( links ).toContain(
			'padding-inline-end: calc(var(--ha-author-notch) - var(--ha-author-pad) + 10px);'
		);

		// The tips text does the same.
		expect( block( '.ha-author--tips .ha-author__body' ) ).toMatch(
			/padding-inline-end: 24px;/
		);
		expect( block( '.ha-author--tips .ha-author__foot' ) ).toContain(
			'min-height: calc(var(--ha-author-notch) - var(--ha-author-pad-end));'
		);
	} );
} );
