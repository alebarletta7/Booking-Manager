/*
 * Booking Manager: adapts the height of the booking iframes on the client's page.
 *
 * The booking page sends { type: 'bkng:height', height } with postMessage; only
 * messages from the origin this script was loaded from, and from one of the page's
 * iframes, are taken. It sends nothing to the page.
 *
 * Back from the payment on the customer's page (?bkng_paid=1&bkng_return=CODE), it
 * passes those two values to the booking iframes, which then say that the payment was
 * received. The booking code is not a credential: nothing of the booking is shown.
 */
( function () {
	'use strict';
	var script = document.currentScript;
	if ( ! script || window.bkngEmbed ) {
		return;
	}
	window.bkngEmbed = true;
	var origin;
	try {
		origin = new URL( script.src, window.location.href ).origin;
	} catch ( e ) {
		return;
	}
	try {
		var params = new URLSearchParams( window.location.search );
		var code = params.get( 'bkng_return' ) || '';
		if ( params.get( 'bkng_paid' ) === '1' && /^[A-Za-z0-9-]{4,32}$/.test( code ) ) {
			var all = document.getElementsByTagName( 'iframe' );
			for ( var j = 0; j < all.length; j++ ) {
				var src = new URL( all[ j ].src, window.location.href );
				if ( src.origin === origin && src.searchParams.get( 'bkng_return' ) !== code ) {
					src.searchParams.set( 'bkng_paid', '1' );
					src.searchParams.set( 'bkng_return', code );
					all[ j ].src = src.toString();
				}
			}
		}
	} catch ( e ) {}

	window.addEventListener( 'message', function ( event ) {
		var data = event.data;
		if ( event.origin !== origin || ! data || data.type !== 'bkng:height' ) {
			return;
		}
		var height = Math.ceil( Number( data.height ) );
		if ( ! isFinite( height ) || height < 1 || height > 20000 ) {
			return;
		}
		var frames = document.getElementsByTagName( 'iframe' );
		for ( var i = 0; i < frames.length; i++ ) {
			if ( frames[ i ].contentWindow === event.source ) {
				frames[ i ].style.height = height + 'px';
				frames[ i ].style.minHeight = '0';
				return;
			}
		}
	} );
} )();
