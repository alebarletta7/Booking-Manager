/**
 * Booking Manager admin app.
 */
import { createRoot } from '@wordpress/element';

import App from './App';
import './style.scss';

const node = document.getElementById( 'bkng-app' );
if ( node ) {
	createRoot( node ).render( <App /> );
}
