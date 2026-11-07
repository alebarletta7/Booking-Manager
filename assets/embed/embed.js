/*
 * Booking Manager: adapts the height of the booking iframes on the client's page.
 *
 * The booking page sends { type: 'bkng:height', height } with postMessage; only
 * messages from the origin this script was loaded from, and from one of the page's
 * iframes, are taken. It reads nothing of the page and sends nothing.
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
