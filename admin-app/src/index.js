import { createRoot } from '@wordpress/element';
import App, { readBoot } from './App';
import './style.scss';

const root = document.getElementById( 'happyaccess-root' );

if ( root ) {
	createRoot( root ).render( <App boot={ readBoot() } /> );
}
