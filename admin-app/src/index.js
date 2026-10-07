import { createRoot } from '@wordpress/element';
import App from './App';
import './style.scss';

const root = document.getElementById( 'happyaccess-root' );

if ( root ) {
	createRoot( root ).render( <App boot={ window.happyaccessBoot } /> );
}
