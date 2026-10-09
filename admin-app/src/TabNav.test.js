import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import TabNav from './TabNav';

const tabs = [
	{ slug: 'support', label: 'Temporary access' },
	{ slug: 'settings', label: 'Settings' },
];

// jsdom cannot navigate, and complains when a click on a link is left alone.
// This reads whether the app cancelled the click, then cancels it for jsdom.
function clickAndReport( link, init ) {
	let prevented = null;
	const swallow = ( event ) => {
		prevented = event.defaultPrevented;
		event.preventDefault();
	};
	document.addEventListener( 'click', swallow );
	fireEvent.click( link, init );
	document.removeEventListener( 'click', swallow );
	return prevented;
}

function setup() {
	const onSelect = vi.fn();
	render( <TabNav tabs={ tabs } current="support" onSelect={ onSelect } /> );
	return { onSelect, link: screen.getByRole( 'link', { name: 'Settings' } ) };
}

describe( 'TabNav', () => {
	it( 'switches tabs in place on a plain click', () => {
		const { onSelect, link } = setup();
		expect( clickAndReport( link ) ).toBe( true );
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
		expect( clickAndReport( link, init ) ).toBe( false );
		expect( onSelect ).not.toHaveBeenCalled();
	} );
} );
