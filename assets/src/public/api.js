/**
 * Calls to booking-manager/public/v1. The base address is the one printed by the
 * shortcode (index.php?rest_route=/…), so the form works with or without pretty
 * permalinks. No cookies or nonces: the public API needs no login.
 */
import { __ } from '@wordpress/i18n';

export class ApiError extends Error {
	constructor( message, code, status, fields ) {
		super( message );
		this.code = code;
		this.status = status;
		this.fields = fields || {};
	}
}

export function createApi( restUrl ) {
	const url = ( path, query ) => {
		const u = new URL( restUrl, window.location.href );
		const base = ( u.searchParams.get( 'rest_route' ) || '' ).replace(
			/\/$/,
			''
		);
		u.searchParams.set( 'rest_route', base + '/' + path );
		Object.entries( query || {} ).forEach( ( [ k, v ] ) => {
			if ( Array.isArray( v ) ) {
				v.forEach( ( item ) =>
					u.searchParams.append( k + '[]', item )
				);
			} else if ( v !== undefined && v !== null && v !== '' ) {
				u.searchParams.set( k, v );
			}
		} );
		return u.toString();
	};

	const request = async ( method, path, { query, body } = {} ) => {
		let res;
		try {
			res = await window.fetch( url( path, query ), {
				method,
				credentials: 'omit',
				headers: body ? { 'Content-Type': 'application/json' } : {},
				body: body ? JSON.stringify( body ) : undefined,
			} );
		} catch ( e ) {
			throw new ApiError(
				__(
					'Connessione non riuscita. Controlla la rete e riprova.',
					'booking-manager'
				),
				'network',
				0
			);
		}
		let data = null;
		try {
			data = await res.json();
		} catch ( e ) {
			data = null;
		}
		if ( ! res.ok ) {
			throw new ApiError(
				data?.message ||
					__(
						'Si è verificato un errore. Riprova tra poco.',
						'booking-manager'
					),
				data?.code || 'error',
				res.status,
				data?.data?.fields
			);
		}
		return data;
	};

	return {
		get: ( path, query ) => request( 'GET', path, { query } ),
		post: ( path, body, query ) =>
			request( 'POST', path, { body: body || {}, query } ),
		/** For the page being closed: the browser sends it even while leaving. */
		beacon: ( path, body ) => {
			const data = JSON.stringify( body );
			if ( window.navigator.sendBeacon ) {
				window.navigator.sendBeacon(
					url( path ),
					new window.Blob( [ data ], { type: 'application/json' } )
				);
			}
		},
	};
}
