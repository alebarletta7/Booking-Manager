/**
 * Money, dates and the texts of rates (meal plan, cancellation, deposit) as the guest
 * reads them, in the language of the site.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

let locale = 'it-IT';

export function setLocale( value ) {
	if ( value ) {
		locale = value;
	}
}

export function money( cents, currency ) {
	if ( cents === null || cents === undefined ) {
		return '';
	}
	return new Intl.NumberFormat( locale, {
		style: 'currency',
		currency: currency || 'EUR',
	} ).format( cents / 100 );
}

function parseDate( ymd ) {
	const [ y, m, d ] = String( ymd ).split( '-' ).map( Number );
	return new Date( Date.UTC( y, m - 1, d, 12 ) );
}

export function date(
	ymd,
	shape = {
		weekday: 'short',
		day: 'numeric',
		month: 'short',
		year: 'numeric',
	}
) {
	if ( ! ymd ) {
		return '';
	}
	return new Intl.DateTimeFormat( locale, {
		...shape,
		timeZone: 'UTC',
	} ).format( parseDate( ymd ) );
}

export function dateTime( iso ) {
	return iso
		? new Intl.DateTimeFormat( locale, {
				dateStyle: 'medium',
				timeStyle: 'short',
		  } ).format( new Date( iso ) )
		: '';
}

export function addDays( ymd, days ) {
	const d = parseDate( ymd );
	d.setUTCDate( d.getUTCDate() + days );
	return d.toISOString().slice( 0, 10 );
}

export function nightsBetween( from, to ) {
	if ( ! from || ! to ) {
		return 0;
	}
	return Math.round( ( parseDate( to ) - parseDate( from ) ) / 86400000 );
}

export function nightsLabel( n ) {
	/* translators: %d: number of nights. */
	return sprintf( _n( '%d notte', '%d notti', n, 'booking-manager' ), n );
}

export function guestsLabel( adults, children ) {
	let out = sprintf(
		/* translators: %d: number of adults. */
		_n( '%d adulto', '%d adulti', adults, 'booking-manager' ),
		adults
	);
	if ( children > 0 ) {
		out +=
			', ' +
			sprintf(
				/* translators: %d: number of children. */
				_n( '%d bambino', '%d bambini', children, 'booking-manager' ),
				children
			);
	}
	return out;
}

export const MEAL_PLANS = {
	room_only: __( 'Solo pernottamento', 'booking-manager' ),
	breakfast: __( 'Colazione inclusa', 'booking-manager' ),
	half_board: __( 'Mezza pensione', 'booking-manager' ),
	full_board: __( 'Pensione completa', 'booking-manager' ),
	all_inclusive: __( 'All inclusive', 'booking-manager' ),
};

/** The cancellation terms of a rate in one sentence. */
export function cancellationText( policy ) {
	if ( ! policy ) {
		return '';
	}
	if ( policy.text ) {
		return policy.text;
	}
	if ( policy.refundable === false ) {
		return __( 'Non rimborsabile', 'booking-manager' );
	}
	const days = Number( policy.free_until_days || 0 );
	if ( days > 0 ) {
		return sprintf(
			/* translators: %d: days before arrival. */
			_n(
				'Cancellazione gratuita fino a %d giorno prima dell’arrivo',
				'Cancellazione gratuita fino a %d giorni prima dell’arrivo',
				days,
				'booking-manager'
			),
			days
		);
	}
	return policy.penalty && policy.penalty !== 'none'
		? __( 'Cancellazione con penale', 'booking-manager' )
		: __( 'Cancellazione gratuita', 'booking-manager' );
}

/** The deposit asked by a rate, in one sentence. */
export function depositText( policy, currency ) {
	if ( ! policy || ! policy.type || policy.type === 'none' ) {
		return '';
	}
	switch ( policy.type ) {
		case 'percent':
			return sprintf(
				/* translators: %s: percentage. */
				__( 'Caparra del %s%%', 'booking-manager' ),
				String( Number( policy.value || 0 ) / 100 ).replace( '.', ',' )
			);
		case 'fixed':
			return sprintf(
				/* translators: %s: amount. */
				__( 'Caparra di %s', 'booking-manager' ),
				money( policy.value, currency )
			);
		case 'first_night':
			return __( 'Caparra: la prima notte', 'booking-manager' );
		case 'full':
			return __(
				'Pagamento anticipato dell’intero soggiorno',
				'booking-manager'
			);
	}
	return '';
}

const PER = {
	stay: __( 'a soggiorno', 'booking-manager' ),
	night: __( 'a notte', 'booking-manager' ),
	person_night: __( 'a persona a notte', 'booking-manager' ),
	person_stay: __( 'a persona', 'booking-manager' ),
};

/** Price of an optional extra as written in the rate ("€ 10,00 a notte"). */
export function extraPrice( extra, currency ) {
	if ( extra.amount ) {
		return (
			money( extra.amount, currency ) + ' ' + ( PER[ extra.per ] || '' )
		);
	}
	if ( extra.percent_bp ) {
		return '+' + String( extra.percent_bp / 100 ).replace( '.', ',' ) + '%';
	}
	return '';
}

/**
 * The colour chosen in Struttura → Modulo online, on the form's container; the text on
 * it is white or dark, whichever reads better.
 */
export function applyColor( el, hex ) {
	if ( ! el || ! /^#[0-9a-f]{6}$/i.test( hex || '' ) ) {
		return;
	}
	const [ r, g, b ] = [ 1, 3, 5 ].map( ( i ) =>
		parseInt( hex.slice( i, i + 2 ), 16 )
	);
	const light = ( r * 299 + g * 587 + b * 114 ) / 1000 > 150;
	el.style.setProperty( '--bkng-color', hex );
	el.style.setProperty( '--bkng-color-text', light ? '#1e1e1e' : '#fff' );
}
