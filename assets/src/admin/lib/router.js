/**
 * Hash routes ("#/bookings/12"): links work, the back button works, a page reload keeps
 * the screen.
 */
import { useEffect, useState } from '@wordpress/element';

function current() {
	const hash = window.location.hash.replace( /^#/, '' ) || '/';
	const [ path, search = '' ] = hash.split( '?' );
	return {
		path: path || '/',
		params: Object.fromEntries( new URLSearchParams( search ) ),
	};
}

export function useRoute() {
	const [ route, setRoute ] = useState( current );
	useEffect( () => {
		const onChange = () => {
			setRoute( current() );
			window.scrollTo( 0, 0 );
		};
		window.addEventListener( 'hashchange', onChange );
		return () => window.removeEventListener( 'hashchange', onChange );
	}, [] );
	return route;
}

export function navigate( path ) {
	window.location.hash = path;
}

/**
 * Matches "/bookings/12" against "/bookings/:id".
 *
 * @return {Object|null} the parameters, or null.
 */
export function match( pattern, path ) {
	const p = pattern.split( '/' );
	const s = path.split( '/' );
	if ( p.length !== s.length ) {
		return null;
	}
	const out = {};
	for ( let i = 0; i < p.length; i++ ) {
		if ( p[ i ].startsWith( ':' ) ) {
			out[ p[ i ].slice( 1 ) ] = decodeURIComponent( s[ i ] );
		} else if ( p[ i ] !== s[ i ] ) {
			return null;
		}
	}
	return out;
}
