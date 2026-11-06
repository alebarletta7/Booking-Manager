/**
 * Calls to the plugin's REST API. WordPress sets the root URL and the nonce of
 * wp-api-fetch; here only the namespace and the errors.
 */
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

import { boot } from './boot';

const base = () => '/' + ( boot.restNamespace || 'booking-manager/v1' );

/** Query string from an object (empty values are left out). */
export function query( params = {} ) {
	const parts = Object.entries( params )
		.filter( ( [ , v ] ) => v !== undefined && v !== null && v !== '' )
		.map(
			( [ k, v ] ) =>
				encodeURIComponent( k ) +
				'=' +
				encodeURIComponent( Array.isArray( v ) ? v.join( ',' ) : v )
		);
	return parts.length ? '?' + parts.join( '&' ) : '';
}

/**
 * An error the screens can show: message, code, field errors, HTTP status.
 *
 * @param {Object} error What apiFetch threw.
 */
export function readableError( error ) {
	if ( ! error ) {
		return {
			message: __( 'Errore sconosciuto.', 'booking-manager' ),
			code: '',
			fields: {},
			status: 0,
		};
	}
	if ( error.code === 'fetch_error' || error instanceof TypeError ) {
		return {
			message: __(
				'Connessione assente: controlla la rete e riprova.',
				'booking-manager'
			),
			code: 'offline',
			fields: {},
			status: 0,
		};
	}
	const data = error.data || {};
	return {
		message:
			error.message ||
			( data.status
				? sprintf(
						/* translators: %d: HTTP status */
						__(
							'Operazione non riuscita (HTTP %d).',
							'booking-manager'
						),
						data.status
				  )
				: __( 'Operazione non riuscita.', 'booking-manager' ) ),
		code: error.code || '',
		fields: data.fields || data.params || {},
		status: data.status || 0,
		data,
	};
}

export function get( path, params ) {
	return apiFetch( { path: base() + path + query( params ) } );
}

/** GET returning items and the X-WP-Total header. */
export async function getPaged( path, params ) {
	const response = await apiFetch( {
		path: base() + path + query( params ),
		parse: false,
	} );
	const items = await response.json();
	return {
		items,
		total: parseInt( response.headers.get( 'X-WP-Total' ) || '0', 10 ),
	};
}

export function post( path, data = {} ) {
	return apiFetch( { path: base() + path, method: 'POST', data } );
}

export function put( path, data = {} ) {
	return apiFetch( { path: base() + path, method: 'PUT', data } );
}

export function patch( path, data = {} ) {
	return apiFetch( { path: base() + path, method: 'PATCH', data } );
}

export function del( path, params ) {
	return apiFetch( {
		path: base() + path + query( params ),
		method: 'DELETE',
	} );
}

/** A random id for requests that must not run twice (payments, refunds). */
export function requestId() {
	if ( window.crypto && window.crypto.randomUUID ) {
		return window.crypto.randomUUID();
	}
	return (
		'r-' +
		Date.now().toString( 36 ) +
		'-' +
		Math.random().toString( 36 ).slice( 2 )
	);
}
