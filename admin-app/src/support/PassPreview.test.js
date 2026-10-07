import { expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import PassPreview from './PassPreview';

it( 'shows the label, level, end time and the placeholder code', () => {
	render(
		<PassPreview
			label="Acme Plugin Support"
			levelName="Administrator (protected)"
			validUntil="Fri, Oct 9, 4:40 pm"
		/>
	);

	expect( screen.getByText( 'Acme Plugin Support' ) ).toBeInTheDocument();
	expect(
		screen.getByText( 'Administrator (protected)' )
	).toBeInTheDocument();
	expect(
		screen.getByText( 'Valid until Fri, Oct 9, 4:40 pm' )
	).toBeInTheDocument();
	expect( screen.getByText( /••••/ ) ).toBeInTheDocument();
	expect(
		screen.getByText( 'Link and code appear when you create the pass.' )
	).toBeInTheDocument();
} );

it( 'asks who it is for while the label is empty or blank', () => {
	const { rerender } = render(
		<PassPreview label="" levelName="Custom access" validUntil="x" />
	);
	expect( screen.getByText( 'Who is it for?' ) ).toBeInTheDocument();

	rerender(
		<PassPreview label="   " levelName="Custom access" validUntil="x" />
	);
	expect( screen.getByText( 'Who is it for?' ) ).toBeInTheDocument();
} );
