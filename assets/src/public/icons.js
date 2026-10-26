/**
 * Line icons of the public form (24×24, stroke in the text colour), inline so the form
 * needs no icon font or extra request. amenityIcon() picks one for the services written
 * by the property («Wi-Fi», «Aria condizionata»…), with a plain tick for the rest.
 */
const PATHS = {
	calendar: 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4',
	user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM5 20a7 7 0 0 1 14 0',
	bed: 'M3 18V7M3 14h18v4M21 14v-2a3 3 0 0 0-3-3h-7v5M7 11.5a1.5 1.5 0 1 0 0-.01',
	area: 'M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5',
	wifi: 'M2 9a15 15 0 0 1 20 0M5.5 12.5a10 10 0 0 1 13 0M9 16a5 5 0 0 1 6 0M12 19.5h.01',
	snow: 'M12 3v18M4.2 7.5l15.6 9M4.2 16.5l15.6-9M9 4.5l3 2 3-2M9 19.5l3-2 3 2',
	tv: 'M3 6h18v12H3zM8 21h8',
	bath: 'M4 12h16v3a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4zM6 12V5a2 2 0 0 1 4 0M7 19l-1 2M17 19l1 2',
	balcony: 'M4 21V11h16v10M4 15h16M8 11v10M12 11v10M16 11v10M7 11V4h10v7',
	coffee: 'M4 9h13v5a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5zM17 10h1.5a2.5 2.5 0 0 1 0 5H17M8 3v3M12 3v3',
	car: 'M5 16l1.5-5h11l1.5 5M4 16h16v3H4zM7 19v2M17 19v2M7.5 13.5h.01M16.5 13.5h.01',
	safe: 'M4 4h16v16H4zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM12 9v1M8 20v1M16 20v1',
	fridge: 'M6 3h12v18H6zM6 10h12M9 6v2M9 13v3',
	view: 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
	paw: 'M12 20c-3 0-5-2-5-4s2-4 5-4 5 2 5 4-2 4-5 4zM6 10h.01M9 6h.01M15 6h.01M18 10h.01',
	kitchen: 'M4 4h16v16H4zM4 9h16M8 6.5h.01M12 6.5h.01M9 14h6',
	heat: 'M8 4c-2 3 2 5 0 8M12 4c-2 3 2 5 0 8M16 4c-2 3 2 5 0 8M4 18h16',
	check: 'M5 12.5l4.5 4.5L19 7',
	chevronDown: 'M6 9l6 6 6-6',
	chevronRight: 'M9 6l6 6-6 6',
	chevronLeft: 'M15 6l-6 6 6 6',
	refresh:
		'M20 11a8 8 0 0 0-14.5-4.5L4 8M4 4v4h4M4 13a8 8 0 0 0 14.5 4.5L20 16M20 20v-4h-4',
	search: 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM20 20l-4-4',
	filter: 'M4 6h16M7 12h10M10 18h4',
	close: 'M6 6l12 12M18 6L6 18',
	info: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 11v5M12 8h.01',
	minus: 'M6 12h12',
	moon: 'M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z',
	users: 'M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM2.5 20a6.5 6.5 0 0 1 13 0zM16 4.5a3.5 3.5 0 0 1 0 6.5M18 14a5.5 5.5 0 0 1 3.5 6',
	coins: 'M12 8c4.4 0 8-1.3 8-3s-3.6-3-8-3-8 1.3-8 3 3.6 3 8 3zM4 5v4c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 9v4c0 1.7 3.6 3 8 3s8-1.3 8-3V9M4 13v4c0 1.7 3.6 3 8 3s8-1.3 8-3v-4',
	tag: 'M3 12V4h8l10 10-8 8zM7.5 8.5h.01',
	pin: 'M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21zM12 12a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
	phone: 'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2',
	mail: 'M3 6h18v12H3zM3 7l9 6 9-6',
	arrowRight: 'M5 12h14M13 6l6 6-6 6',
	plus: 'M12 6v12M6 12h12',
};

export function Icon( { name, size = 18, className = '' } ) {
	const d = PATHS[ name ] || PATHS.check;
	return (
		<svg
			className={ 'bkng-p-icon ' + className }
			width={ size }
			height={ size }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinecap="round"
			strokeLinejoin="round"
			aria-hidden="true"
			focusable="false"
		>
			<path d={ d } />
		</svg>
	);
}

const AMENITIES = [
	[ /wi-?fi|internet/i, 'wifi' ],
	[ /aria condizionata|climatizz|condizionatore|air.?con/i, 'snow' ],
	[ /\btv\b|televis|smart ?tv/i, 'tv' ],
	[ /bagno|doccia|vasca|bath|shower/i, 'bath' ],
	[ /balcon|terrazz|patio|terrace/i, 'balcony' ],
	[ /colazion|breakfast|caff|bollitore|kettle/i, 'coffee' ],
	[ /parchegg|parking|garage|posto auto/i, 'car' ],
	[ /cassaforte|safe/i, 'safe' ],
	[ /mini ?bar|frigo|fridge/i, 'fridge' ],
	[ /vista|view|panoram/i, 'view' ],
	[ /animali|pet|cani/i, 'paw' ],
	[ /cucina|angolo cottura|kitchen/i, 'kitchen' ],
	[ /riscaldamento|heating/i, 'heat' ],
];

export function amenityIcon( text ) {
	const found = AMENITIES.find( ( [ re ] ) => re.test( text ) );
	return found ? found[ 1 ] : 'check';
}
