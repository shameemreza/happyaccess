import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import TabNav from './TabNav';

const tabs = [
	{ slug: 'support', label: 'Support access' },
	{ slug: 'settings', label: 'Settings' },
];

function setup() {
	const onSelect = vi.fn();
	render( <TabNav tabs={ tabs } current="support" onSelect={ onSelect } /> );
	return { onSelect, link: screen.getByRole( 'link', { name: 'Settings' } ) };
}

describe( 'TabNav', () => {
	it( 'switches tabs in place on a plain click', () => {
		const { onSelect, link } = setup();
		// fireEvent returns false when the default was prevented.
		expect( fireEvent.click( link ) ).toBe( false );
		expect( onSelect ).toHaveBeenCalledWith( 'settings' );
	} );

	it.each( [
		[ 'ctrl', { ctrlKey: true } ],
		[ 'cmd', { metaKey: true } ],
		[ 'shift', { shiftKey: true } ],
		[ 'alt', { altKey: true } ],
		[ 'middle button', { button: 1 } ],
	] )( 'leaves a %s click to the browser', ( name, init ) => {
		const { onSelect, link } = setup();
		expect( fireEvent.click( link, init ) ).toBe( true );
		expect( onSelect ).not.toHaveBeenCalled();
	} );
} );
