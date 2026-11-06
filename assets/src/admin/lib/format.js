/** Amounts (integer cents), dates and labels shown by the app. */
import { __, _n, sprintf } from '@wordpress/i18n';

import { boot } from './boot';

const locale = boot.locale || 'it-IT';

export function money( cents, currency = 'EUR' ) {
	const value = ( Number( cents ) || 0 ) / 100;
	try {
		return new Intl.NumberFormat( locale, {
			style: 'currency',
			currency,
		} ).format( value );
	} catch ( e ) {
		return value.toFixed( 2 ) + ' ' + currency;
	}
}

/** "123,45" or "123.45" typed by a person → cents (null when not a number). */
export function toCents( text ) {
	if ( text === '' || text === null || text === undefined ) {
		return null;
	}
	const clean = String( text )
		.trim()
		.replace( /\s/g, '' )
		.replace( /\.(?=\d{3}(\D|$))/g, '' )
		.replace( ',', '.' );
	const value = Number( clean );
	return Number.isFinite( value ) ? Math.round( value * 100 ) : null;
}

/** Cents → text for an input ("123,45"). */
export function fromCents( cents ) {
	if ( cents === null || cents === undefined || cents === '' ) {
		return '';
	}
	return ( Number( cents ) / 100 ).toFixed( 2 ).replace( '.', ',' );
}

function parseDate( ymd ) {
	const [ y, m, d ] = String( ymd ).split( '-' ).map( Number );
	return new Date( Date.UTC( y, m - 1, d, 12 ) );
}

export function date(
	ymd,
	shape = { day: 'numeric', month: 'short', year: 'numeric' }
) {
	if ( ! ymd ) {
		return '';
	}
	return new Intl.DateTimeFormat( locale, {
		...shape,
		timeZone: 'UTC',
	} ).format( parseDate( ymd ) );
}

export function weekday( ymd ) {
	return date( ymd, { weekday: 'short' } );
}

export function dateTime( iso ) {
	if ( ! iso ) {
		return '';
	}
	return new Intl.DateTimeFormat( locale, {
		dateStyle: 'short',
		timeStyle: 'short',
	} ).format( new Date( iso ) );
}

export function addDays( ymd, days ) {
	const d = parseDate( ymd );
	d.setUTCDate( d.getUTCDate() + days );
	return d.toISOString().slice( 0, 10 );
}

export function nightsBetween( from, to ) {
	return Math.round( ( parseDate( to ) - parseDate( from ) ) / 86400000 );
}

export function nightsLabel( n ) {
	return sprintf(
		/* translators: %d: number of nights. */
		_n( '%d notte', '%d notti', n, 'booking-manager' ),
		n
	);
}

export function guestsLabel( adults, children = 0 ) {
	const a = sprintf(
		/* translators: %d: number of adults. */
		_n( '%d adulto', '%d adulti', adults, 'booking-manager' ),
		adults
	);
	return children
		? a +
				', ' +
				sprintf(
					/* translators: %d: number of children. */
					_n(
						'%d bambino',
						'%d bambini',
						children,
						'booking-manager'
					),
					children
				)
		: a;
}

export const BOOKING_STATUS = {
	pending: __( 'In attesa', 'booking-manager' ),
	option: __( 'Opzione', 'booking-manager' ),
	confirmed: __( 'Confermata', 'booking-manager' ),
	in_house: __( 'In soggiorno', 'booking-manager' ),
	completed: __( 'Conclusa', 'booking-manager' ),
	cancelled: __( 'Annullata', 'booking-manager' ),
	no_show: __( 'No-show', 'booking-manager' ),
};

export const PAYMENT_STATUS = {
	unpaid: __( 'Da pagare', 'booking-manager' ),
	deposit_paid: __( 'Caparra pagata', 'booking-manager' ),
	partially_paid: __( 'Pagata in parte', 'booking-manager' ),
	paid: __( 'Pagata', 'booking-manager' ),
	refunded: __( 'Rimborsata', 'booking-manager' ),
};

export const STAY_STATUS = {
	expected: __( 'In arrivo', 'booking-manager' ),
	in_house: __( 'In soggiorno', 'booking-manager' ),
	departed: __( 'Partito', 'booking-manager' ),
	removed: __( 'Rimossa', 'booking-manager' ),
};

export const OP_STATUS = {
	dirty: __( 'Da pulire', 'booking-manager' ),
	cleaning: __( 'In pulizia', 'booking-manager' ),
	clean: __( 'Pulita, da controllare', 'booking-manager' ),
	ready: __( 'Pronta', 'booking-manager' ),
	maintenance: __( 'Manutenzione', 'booking-manager' ),
};

export const SOURCES = {
	manual: __( 'Inserita dallo staff', 'booking-manager' ),
	public_form: __( 'Sito web', 'booking-manager' ),
	api: __( 'API', 'booking-manager' ),
	import: __( 'Canale esterno', 'booking-manager' ),
};

export const MEAL_PLANS = {
	room_only: __( 'Solo pernottamento', 'booking-manager' ),
	breakfast: __( 'Colazione inclusa', 'booking-manager' ),
	half_board: __( 'Mezza pensione', 'booking-manager' ),
	full_board: __( 'Pensione completa', 'booking-manager' ),
	all_inclusive: __( 'All inclusive', 'booking-manager' ),
};

export const PAYMENT_METHODS = {
	cash: __( 'Contanti', 'booking-manager' ),
	pos: __( 'POS / carta', 'booking-manager' ),
	bank_transfer: __( 'Bonifico', 'booking-manager' ),
	other: __( 'Altro', 'booking-manager' ),
};

export const WEEKDAYS = [
	[ 1, __( 'Lun', 'booking-manager' ) ],
	[ 2, __( 'Mar', 'booking-manager' ) ],
	[ 3, __( 'Mer', 'booking-manager' ) ],
	[ 4, __( 'Gio', 'booking-manager' ) ],
	[ 5, __( 'Ven', 'booking-manager' ) ],
	[ 6, __( 'Sab', 'booking-manager' ) ],
	[ 7, __( 'Dom', 'booking-manager' ) ],
];

export function options( map ) {
	return Object.entries( map ).map( ( [ value, label ] ) => ( {
		value,
		label,
	} ) );
}

/** What the history and the audit log show for each recorded action. */
const ACTIONS = {
	'booking.created': __( 'Prenotazione creata', 'booking-manager' ),
	'booking.updated': __( 'Dati modificati', 'booking-manager' ),
	'booking.status_changed': __( 'Stato cambiato', 'booking-manager' ),
	'booking.stay_changed': __( 'Date o camere cambiate', 'booking-manager' ),
	'booking.unit_assigned': __( 'Camera assegnata', 'booking-manager' ),
	'booking.rooms_checked_in': __( 'Check-in', 'booking-manager' ),
	'booking.rooms_checked_out': __( 'Check-out', 'booking-manager' ),
	'booking.qr_regenerated': __( 'Nuovo QR generato', 'booking-manager' ),
	'guest.updated': __( 'Ospite modificato', 'booking-manager' ),
	'guest.exported': __( 'Dati ospite esportati', 'booking-manager' ),
	'guest.anonymized': __( 'Ospite anonimizzato', 'booking-manager' ),
	'payment.created': __( 'Pagamento avviato', 'booking-manager' ),
	'payment.recorded': __( 'Pagamento registrato', 'booking-manager' ),
	'payment.paid': __( 'Pagamento ricevuto', 'booking-manager' ),
	'payment.failed': __( 'Pagamento non riuscito', 'booking-manager' ),
	'payment.cancelled': __( 'Pagamento annullato', 'booking-manager' ),
	'payment.capture_failed': __( 'Incasso non riuscito', 'booking-manager' ),
	'payment.checkout_failed': __(
		'Link di pagamento non creato',
		'booking-manager'
	),
	'payment.amount_mismatch': __(
		'Importo pagato diverso dal dovuto',
		'booking-manager'
	),
	'payment.dispute_opened': __( 'Contestazione aperta', 'booking-manager' ),
	'payment.refund_requested': __( 'Rimborso richiesto', 'booking-manager' ),
	'payment.refund_updated': __( 'Rimborso aggiornato', 'booking-manager' ),
	'payment.refund_failed': __( 'Rimborso non riuscito', 'booking-manager' ),
	'payment.refund_uncertain': __(
		'Rimborso da verificare',
		'booking-manager'
	),
	'payment.refunded': __( 'Rimborsato', 'booking-manager' ),
	'payment.late_refund': __(
		'Rimborso di un pagamento arrivato tardi',
		'booking-manager'
	),
	'payment.late_refund_failed': __(
		'Rimborso di un pagamento tardivo non riuscito',
		'booking-manager'
	),
	'payment.webhook_rejected': __(
		'Notifica di pagamento rifiutata',
		'booking-manager'
	),
	'payment_account.connected': __( 'Conto collegato', 'booking-manager' ),
	'payment_account.disconnected': __( 'Conto scollegato', 'booking-manager' ),
	'payment_account.error': __( 'Errore del conto', 'booking-manager' ),
	'payment_account.incomplete': __( 'Conto incompleto', 'booking-manager' ),
	'payment_settings.provider_selected': __(
		'Fornitore di pagamento scelto',
		'booking-manager'
	),
	'property.created': __( 'Struttura creata', 'booking-manager' ),
	'property.updated': __( 'Struttura modificata', 'booking-manager' ),
	'property.settings_updated': __(
		'Impostazioni struttura modificate',
		'booking-manager'
	),
	'property.archived': __( 'Struttura archiviata', 'booking-manager' ),
	'property.restored': __( 'Struttura ripristinata', 'booking-manager' ),
	'room_type.created': __( 'Tipologia creata', 'booking-manager' ),
	'room_type.updated': __( 'Tipologia modificata', 'booking-manager' ),
	'room_type.archived': __( 'Tipologia archiviata', 'booking-manager' ),
	'unit.created': __( 'Camera creata', 'booking-manager' ),
	'unit.updated': __( 'Camera modificata', 'booking-manager' ),
	'unit.archived': __( 'Camera archiviata', 'booking-manager' ),
	'unit.restored': __( 'Camera ripristinata', 'booking-manager' ),
	'unit.blocked': __( 'Camera chiusa', 'booking-manager' ),
	'unit.unblocked': __( 'Camera riaperta', 'booking-manager' ),
	'rate_plan.created': __( 'Tariffa creata', 'booking-manager' ),
	'rate_plan.updated': __( 'Tariffa modificata', 'booking-manager' ),
	'rate_plan.archived': __( 'Tariffa archiviata', 'booking-manager' ),
	'rate_price.saved': __( 'Prezzi del periodo salvati', 'booking-manager' ),
	'rate_price.deleted': __( 'Prezzi del periodo tolti', 'booking-manager' ),
	'rate_adjustment.saved': __(
		'Supplemento o sconto salvato',
		'booking-manager'
	),
	'rate_adjustment.archived': __(
		'Supplemento o sconto archiviato',
		'booking-manager'
	),
	'restriction.saved': __( 'Regola salvata', 'booking-manager' ),
	'restriction.deleted': __( 'Regola tolta', 'booking-manager' ),
	'staff.added': __( 'Membro dello staff aggiunto', 'booking-manager' ),
	'staff.updated': __( 'Membro dello staff modificato', 'booking-manager' ),
	'staff.removed': __( 'Membro dello staff tolto', 'booking-manager' ),
	'role.updated': __( 'Ruolo modificato', 'booking-manager' ),
	'settings.updated': __( 'Impostazioni modificate', 'booking-manager' ),
	'cloudflare.settings_updated': __(
		'Cloudflare configurato',
		'booking-manager'
	),
	'cloudflare.rules_applied': __(
		'Regole Cloudflare applicate',
		'booking-manager'
	),
	'license.activated': __( 'Licenza attivata', 'booking-manager' ),
	'license.reactivated': __( 'Licenza riattivata', 'booking-manager' ),
	'license.deactivated': __( 'Licenza disattivata', 'booking-manager' ),
	'license.status_changed': __(
		'Stato della licenza cambiato',
		'booking-manager'
	),
	'license.bad_signature': __(
		'Risposta del server licenze non valida',
		'booking-manager'
	),
	'setup.page_created': __(
		'Pagina di prenotazione creata',
		'booking-manager'
	),
	'setup.finished': __( 'Configurazione completata', 'booking-manager' ),
	'setup.skipped': __( 'Configurazione saltata', 'booking-manager' ),
};

export const actionLabel = ( action ) => ACTIONS[ action ] || action;
