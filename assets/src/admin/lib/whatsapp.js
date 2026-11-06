/**
 * «WhatsApp» section helpers (like Night Manager's): approval badges, the connection
 * form (secrets never come back from the server, only whether they are set), local
 * checks of a text, the log's states. Pure functions; the server re-checks everything.
 */
import { __, sprintf } from '@wordpress/i18n';

/** Filters of the log, «Tutti» first. */
export const LOG_FILTERS = [
	[ '', __( 'Tutti', 'booking-manager' ) ],
	[ 'sent', __( 'Inviati', 'booking-manager' ) ],
	[ 'failed', __( 'Non inviati', 'booking-manager' ) ],
	[ 'queued', __( 'In coda', 'booking-manager' ) ],
	[ 'skipped', __( 'Saltati', 'booking-manager' ) ],
];

/**
 * @param {string}  status    none | pending | approved | rejected | paused | disabled
 * @param {boolean} connected Without a connection «none» means «sent when connected».
 * @return {{label: string, tone: string}} badge
 */
export function approvalBadge( status, connected = true ) {
	switch ( status ) {
		case 'approved':
			return {
				label: __( 'Approvato', 'booking-manager' ),
				tone: 'ok',
			};
		case 'pending':
			return {
				label: __( 'In approvazione', 'booking-manager' ),
				tone: 'warn',
			};
		case 'rejected':
			return {
				label: __( 'Rifiutato', 'booking-manager' ),
				tone: 'bad',
			};
		case 'paused':
			return {
				label: __( 'In pausa', 'booking-manager' ),
				tone: 'warn',
			};
		case 'disabled':
			return {
				label: __( 'Disattivato da WhatsApp', 'booking-manager' ),
				tone: 'bad',
			};
		default:
			return connected
				? {
						label: __( 'Da approvare', 'booking-manager' ),
						tone: 'muted',
				  }
				: {
						label: __( 'Non ancora inviato', 'booking-manager' ),
						tone: 'muted',
				  };
	}
}

/**
 * The provider's fields with the saved values (secrets empty: «leave empty to keep»).
 *
 * @param {Object} settings settings of GET /whatsapp
 * @param {string} provider id of the chosen provider
 * @return {Object} form values
 */
export function connectionForm( settings, provider ) {
	const def = ( settings?.providers || [] ).find(
		( p ) => p.id === provider
	);
	const same = provider === settings?.provider;
	const form = { provider };
	( def?.fields || [] ).forEach( ( f ) => {
		const saved = same ? settings.credentials?.[ f.name ] : '';
		form[ f.name ] = f.secret || typeof saved !== 'string' ? '' : saved;
	} );
	return form;
}

/**
 * Required fields left empty (a secret already saved may stay empty).
 *
 * @param {Object} settings
 * @param {Object} form     connectionForm()
 * @return {Object} field → error
 */
export function connectionErrors( settings, form ) {
	const def = ( settings?.providers || [] ).find(
		( p ) => p.id === form.provider
	);
	const errors = {};
	if ( ! def ) {
		errors.provider = __(
			'Scegli il servizio WhatsApp.',
			'booking-manager'
		);
		return errors;
	}
	const same = form.provider === settings.provider;
	def.fields.forEach( ( f ) => {
		const kept = f.secret && same && settings.credentials?.[ f.name ];
		if ( f.required && ! String( form[ f.name ] || '' ).trim() && ! kept ) {
			errors[ f.name ] = __( 'Campo obbligatorio.', 'booking-manager' );
		}
	} );
	return errors;
}

/**
 * The same checks as the server's WhatsAppTemplates::problems().
 *
 * @param {string}   body
 * @param {string[]} known     variables without braces
 * @param {number}   maxLength
 * @return {string[]} problems (empty when fine)
 */
export function textProblems( body, known, maxLength = 1024 ) {
	const text = String( body || '' )
		.replace( /\{\{\s*([a-z_]+)\s*\}\}/g, '{$1}' )
		.trim();
	if ( ! text ) {
		return [ __( 'Scrivi il testo del messaggio.', 'booking-manager' ) ];
	}
	const problems = [];
	if ( text.length > maxLength ) {
		problems.push(
			__(
				'Testo troppo lungo: WhatsApp accetta al massimo 1024 caratteri.',
				'booking-manager'
			)
		);
	}
	const used = [ ...text.matchAll( /\{([a-z_]+)\}/g ) ].map(
		( m ) => m[ 1 ]
	);
	const unknown = [
		...new Set( used.filter( ( v ) => ! known.includes( v ) ) ),
	];
	if ( unknown.length ) {
		problems.push(
			sprintf(
				/* translators: %s: list of variables such as {nmoe} */
				__( 'Variabili sconosciute: %s', 'booking-manager' ),
				unknown.map( ( v ) => `{${ v }}` ).join( ', ' )
			)
		);
	}
	if (
		/^[\s\p{P}]*\{[a-z_]+\}/u.test( text ) ||
		/\{[a-z_]+\}[\s\p{P}]*$/u.test( text )
	) {
		problems.push(
			__(
				'Il testo non può iniziare o finire con una variabile: WhatsApp lo rifiuterebbe. Aggiungi qualche parola prima o dopo.',
				'booking-manager'
			)
		);
	}
	if ( /\}\s*\{/.test( text ) ) {
		problems.push(
			__(
				'Tra due variabili ci vuole qualche parola: WhatsApp rifiuta variabili attaccate.',
				'booking-manager'
			)
		);
	}
	return problems;
}

/**
 * The text with a variable inserted where the cursor is.
 *
 * @param {string} body
 * @param {string} tag   "{nome}"
 * @param {number} start selection start (end of text when unknown)
 * @param {number} end   selection end
 * @return {{body: string, cursor: number}} new text and cursor
 */
export function insertTag( body, tag, start, end ) {
	const text = String( body || '' );
	const from = Number.isInteger( start ) ? start : text.length;
	const to = Number.isInteger( end ) ? end : from;
	return {
		body: text.slice( 0, from ) + tag + text.slice( to ),
		cursor: from + tag.length,
	};
}

/**
 * @param {string} status queued | sending | sent | failed | skipped
 * @return {{label: string, tone: string}} badge
 */
export function messageStatus( status ) {
	switch ( status ) {
		case 'sent':
			return {
				label: __( 'Inviato', 'booking-manager' ),
				tone: 'ok',
			};
		case 'failed':
			return {
				label: __( 'Non inviato', 'booking-manager' ),
				tone: 'bad',
			};
		case 'skipped':
			return {
				label: __( 'Saltato', 'booking-manager' ),
				tone: 'muted',
			};
		case 'sending':
			return {
				label: __( 'In invio', 'booking-manager' ),
				tone: 'warn',
			};
		default:
			return {
				label: __( 'In coda', 'booking-manager' ),
				tone: 'warn',
			};
	}
}

/**
 * What Meta said happened after sending («Consegnato», «Letto»), or null.
 *
 * @param {Object} m a message of the log
 * @return {{label: string, tone: string}|null} badge
 */
export function deliveryBadge( m ) {
	if ( m?.status !== 'sent' ) {
		return null;
	}
	switch ( m.delivery_status ) {
		case 'delivered':
			return {
				label: __( 'Consegnato', 'booking-manager' ),
				tone: 'ok',
			};
		case 'read':
			return {
				label: __( 'Letto', 'booking-manager' ),
				tone: 'ok',
			};
		default:
			return null;
	}
}

/**
 * Why a message was skipped or failed (the server writes it readable).
 *
 * @param {Object} m a message of the log
 * @return {string} text ("" when there is nothing to say)
 */
export function messageNote( m ) {
	return m?.status === 'sent' ? '' : m?.error || '';
}

/**
 * The button of a log row: «Invia di nuovo» for a sent one, «Riprova» for one that
 * did not go, nothing while it is on its way.
 *
 * @param {Object} m a message of the log
 * @return {string|null} label
 */
export function resendLabel( m ) {
	if ( m?.status === 'sent' ) {
		return __( 'Invia di nuovo', 'booking-manager' );
	}
	if ( m?.status === 'failed' || m?.status === 'skipped' ) {
		return __( 'Riprova', 'booking-manager' );
	}
	return null;
}
