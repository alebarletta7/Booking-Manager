/**
 * The form inside an iframe on the client's site (the page /booking/{slug}/): the
 * payment page opens in the whole window, never inside the frame, and the frame's
 * height follows the form.
 */

/** True when the page is shown inside a frame of another page. */
export function isFramed() {
	try {
		return window.self !== window.top;
	} catch ( e ) {
		return true;
	}
}

/**
 * Sends the height of the page to the parent (assets/embed/embed.js adapts the iframe).
 * Only on the embeddable page, and only the height: nothing about the booking.
 *
 * @param {HTMLElement} page The page's main element.
 */
export function reportHeight( page ) {
	if (
		! isFramed() ||
		! page ||
		typeof window.ResizeObserver !== 'function'
	) {
		return;
	}
	let last = 0;
	const send = () => {
		const height = Math.ceil( page.getBoundingClientRect().height );
		if ( height > 0 && height !== last ) {
			last = height;
			window.parent.postMessage( { type: 'bkng:height', height }, '*' );
		}
	};
	new window.ResizeObserver( send ).observe( page );
	send();
}
