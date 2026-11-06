/** What the page gives the app (AdminApp::enqueue): user, licence, links. */
export const boot = window.bkngBoot || {};

export function can( cap ) {
	return !! (
		boot.me &&
		boot.me.capabilities &&
		boot.me.capabilities.includes( cap )
	);
}
