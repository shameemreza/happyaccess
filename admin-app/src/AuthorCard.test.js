import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import apiFetch from '@wordpress/api-fetch';
import AuthorCard, {
	AuthorCardProvider,
	AUTHOR_URL,
	DOCS_URL,
	RATE_URL,
	SUPPORT_URL,
} from './AuthorCard';

vi.mock( '@wordpress/api-fetch' );

const PHOTO =
	'https://example.test/wp-content/plugins/happyaccess/assets/author.jpg';

// A link with target="_blank" would ask jsdom to open a window. The app's own
// click handlers still run.
const stopNavigation = ( event ) => {
	if ( event.target.closest( 'a' ) ) {
		event.preventDefault();
	}
};

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockResolvedValue( { state: 'credit' } );
	document.addEventListener( 'click', stopNavigation );
} );

afterEach( () => {
	document.removeEventListener( 'click', stopNavigation );
} );

function setup( state ) {
	const user = userEvent.setup();
	const view = render(
		<AuthorCardProvider card={ { state, photo: PHOTO } }>
			<AuthorCard />
		</AuthorCardProvider>
	);
	return { user, ...view };
}

const authorLink = () =>
	screen.getByRole( 'link', {
		name: 'Shameem Reza (opens in a new tab)',
	} );
const rateLink = () =>
	screen.queryByRole( 'link', {
		name: 'Rate it on WordPress.org (opens in a new tab)',
	} );
const tellLink = () =>
	screen.queryByRole( 'link', {
		name: 'Something missing? Tell me (opens in a new tab)',
	} );
const hideButton = () => screen.queryByRole( 'button', { name: 'Hide this' } );

describe( 'AuthorCard', () => {
	it( 'shows nothing without boot data or outside the provider', () => {
		const { container, unmount } = render( <AuthorCard /> );
		expect( container ).toBeEmptyDOMElement();
		unmount();

		const view = render(
			<AuthorCardProvider>
				<AuthorCard />
			</AuthorCardProvider>
		);
		expect( view.container ).toBeEmptyDOMElement();
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	it( 'starts early with the credit, the photo and a Docs link, and no ask', async () => {
		const { container } = setup( 'early' );

		expect( container ).toHaveTextContent( 'Shameem Reza' );
		expect( container ).toHaveTextContent( 'Built HappyAccess' );
		expect( container ).not.toHaveTextContent( 'Built by' );
		expect( authorLink() ).toHaveAttribute( 'href', AUTHOR_URL );
		expect( authorLink() ).toHaveAttribute( 'target', '_blank' );
		expect( authorLink() ).toHaveAttribute( 'rel', 'noopener noreferrer' );
		expect( container.querySelector( 'img' ) ).toHaveAttribute(
			'src',
			PHOTO
		);
		expect(
			screen.getByRole( 'link', { name: 'Docs (opens in a new tab)' } )
		).toHaveAttribute( 'href', DOCS_URL );
		expect( rateLink() ).toBeNull();
		expect( tellLink() ).toBeNull();
		expect( hideButton() ).toBeNull();
		expect( container ).not.toHaveTextContent(
			'Is HappyAccess helping you?'
		);
		expect( apiFetch ).not.toHaveBeenCalled();
		expect( await axe( container ) ).toHaveNoViolations();
	} );

	it( 'asks for a rating with both links and a labelled hide button', async () => {
		const { container } = setup( 'ask' );

		expect( container ).toHaveTextContent(
			'Is HappyAccess helping you? A quick rating helps others find it.'
		);
		expect( rateLink() ).toHaveAttribute( 'href', RATE_URL );
		expect( rateLink() ).toHaveAttribute( 'target', '_blank' );
		expect( tellLink() ).toHaveAttribute( 'href', SUPPORT_URL );
		expect( tellLink() ).toHaveAttribute( 'target', '_blank' );
		expect( hideButton() ).toBeInTheDocument();
		expect( authorLink() ).toHaveAttribute( 'href', AUTHOR_URL );
		expect( container ).toHaveTextContent( 'Built HappyAccess' );
		// The close button sits outside the name row, so it never crowds it.
		expect(
			container.querySelector( '.ha-author__top .ha-author__hide' )
		).toBeNull();
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

	it( 'hides on dismiss, saves it and moves focus to the small credit', async () => {
		const { user, container } = setup( 'ask' );

		await user.click( hideButton() );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/happyaccess/v1/author-card',
				method: 'POST',
				data: { choice: 'dismissed' },
			} )
		);
		expect( rateLink() ).toBeNull();
		expect( hideButton() ).toBeNull();
		expect( container.querySelector( 'img' ) ).toBeNull();
		expect( container ).toHaveTextContent( 'Built by Shameem Reza' );
		await waitFor( () => expect( authorLink() ).toHaveFocus() );
		expect( await axe( container ) ).toHaveNoViolations();
	} );

	it( 'thanks the user after a rate click and saves it', async () => {
		const { user, container } = setup( 'ask' );

		await user.click( rateLink() );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/happyaccess/v1/author-card',
				method: 'POST',
				data: { choice: 'rated' },
			} )
		);
		const thanks = screen.getByText( 'Thank you! That really helps.' );
		expect( thanks ).toBeInTheDocument();
		await waitFor( () => expect( thanks ).toHaveFocus() );
		expect( rateLink() ).toBeNull();
		expect( tellLink() ).toBeNull();
		expect( authorLink() ).toBeInTheDocument();
		expect( container ).toHaveTextContent( 'Built HappyAccess' );
		expect( await axe( container ) ).toHaveNoViolations();
	} );

	it( 'keeps the ask after a click on Tell me, and saves nothing', async () => {
		const { user } = setup( 'ask' );

		await user.click( tellLink() );

		expect( apiFetch ).not.toHaveBeenCalled();
		expect( rateLink() ).toBeInTheDocument();
	} );

	it( 'stays hidden when the save fails', async () => {
		apiFetch.mockRejectedValue( { code: 'fetch_error' } );
		const { user } = setup( 'ask' );

		await user.click( hideButton() );

		await waitFor( () => expect( apiFetch ).toHaveBeenCalled() );
		expect( rateLink() ).toBeNull();
		expect( authorLink() ).toBeInTheDocument();
	} );

	it( 'shows only the small credit line once a choice was made', async () => {
		const { container } = setup( 'credit' );

		expect( container ).toHaveTextContent( 'Built by Shameem Reza' );
		expect( authorLink() ).toHaveAttribute( 'href', AUTHOR_URL );
		expect( container.querySelector( 'img' ) ).toBeNull();
		expect( rateLink() ).toBeNull();
		expect( hideButton() ).toBeNull();
		expect(
			screen.queryByRole( 'link', { name: /Docs/ } )
		).not.toBeInTheDocument();
		expect( await axe( container ) ).toHaveNoViolations();
	} );

	it( 'shares one state, so a card placed later shows the choice', async () => {
		const user = userEvent.setup();
		function Tabs( { second } ) {
			return (
				<AuthorCardProvider card={ { state: 'ask', photo: PHOTO } }>
					{ second ? (
						<div data-testid="second">
							<AuthorCard />
						</div>
					) : (
						<div data-testid="first">
							<AuthorCard />
						</div>
					) }
				</AuthorCardProvider>
			);
		}
		const { rerender } = render( <Tabs second={ false } /> );

		await user.click( hideButton() );
		rerender( <Tabs second /> );

		expect( screen.getByTestId( 'second' ) ).toHaveTextContent(
			'Built by Shameem Reza'
		);
		expect( rateLink() ).toBeNull();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );
} );
