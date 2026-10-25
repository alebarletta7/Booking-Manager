/**
 * Public booking form: every container printed by the shortcode (or by the "manage my
 * booking" page) gets the form, or the guest's booking when the address carries the
 * code and token of the e-mail link.
 */
import { createRoot } from '@wordpress/element';

import { createApi } from './api';
import BookingForm from './BookingForm';
import { setLocale } from './format';
import Manage from './Manage';
import './style.scss';

function mount( el ) {
	let config;
	try {
		config = JSON.parse( el.dataset.bkng || '{}' );
	} catch ( e ) {
		return;
	}
	setLocale( config.locale );
	const api = createApi( config.restUrl );
	const params = new URLSearchParams( window.location.search );
	const code = params.get( config.args?.code || 'bkng_code' );
	const token = params.get( config.args?.token || 'bkng_token' );
	const root = createRoot( el );
	if ( code && token ) {
		root.render(
			<Manage
				api={ api }
				code={ code }
				token={ token }
				paid={ params.get( 'bkng_paid' ) }
				root={ el }
			/>
		);
	} else if ( ! config.manageOnly && config.property ) {
		root.render(
			<BookingForm
				api={ api }
				slug={ config.property }
				hero={ !! config.hero }
				root={ el }
			/>
		);
	}
}

function start() {
	document
		.querySelectorAll( '.bkng-public[data-bkng]' )
		.forEach( ( el ) => mount( el ) );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', start );
} else {
	start();
}
