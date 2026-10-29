/**
 * The parts of a booking shown to the guest in the look of the e-mails: a heading with
 * a tick, the summary of the stay with icons and the property's contacts. Used after the
 * booking and on the page opened from the link in the e-mail.
 */
import { __, sprintf } from '@wordpress/i18n';

import { date, guestsLabel, money } from './format';
import { Icon } from './icons';

export const LONG = { day: 'numeric', month: 'long', year: 'numeric' };

const time = ( t ) => String( t || '' ).slice( 0, 5 );

export function StayHead( { title, tone = 'ok' } ) {
	const icon = { ok: 'check', wait: 'info', off: 'close' }[ tone ];
	return (
		<div className="bkng-p-done__head">
			<span
				className={ 'bkng-p-done__tick is-' + tone }
				aria-hidden="true"
			>
				<Icon name={ icon } size={ 26 } />
			</span>
			<h3>{ title }</h3>
		</div>
	);
}

/**
 * Rows of the summary: arrival, departure, nights, guests, rooms, total, code; $more
 * adds rows (what is paid, what is due).
 */
export function stayItems( b, more = [] ) {
	const where = b.property || {};
	const rooms = {};
	( b.rooms || [] ).forEach( ( r ) => {
		rooms[ r.room_type ] = ( rooms[ r.room_type ] || 0 ) + 1;
	} );
	const roomText = Object.entries( rooms )
		.map( ( [ name, n ] ) => ( n > 1 ? n + ' × ' : '' ) + name )
		.join( ', ' );
	return [
		[
			'calendar',
			__( 'Arrivo', 'booking-manager' ),
			date( b.arrival, LONG ),
			where.checkin_from &&
				sprintf(
					/* translators: %s: time. */
					__( 'check-in dalle %s', 'booking-manager' ),
					time( where.checkin_from )
				),
		],
		[
			'calendar',
			__( 'Partenza', 'booking-manager' ),
			date( b.departure, LONG ),
			where.checkout_until &&
				sprintf(
					/* translators: %s: time. */
					__( 'check-out entro le %s', 'booking-manager' ),
					time( where.checkout_until )
				),
		],
		[ 'moon', __( 'Notti', 'booking-manager' ), String( b.nights ) ],
		[
			'users',
			__( 'Ospiti', 'booking-manager' ),
			guestsLabel( b.adults, b.children ),
		],
		roomText && [
			'bed',
			( b.rooms || [] ).length > 1
				? __( 'Camere', 'booking-manager' )
				: __( 'Camera', 'booking-manager' ),
			roomText,
		],
		b.total > 0 && [
			'coins',
			__( 'Totale', 'booking-manager' ),
			money( b.total, b.currency ),
		],
		...more,
		[ 'tag', __( 'Codice prenotazione', 'booking-manager' ), b.code ],
	].filter( Boolean );
}

export function StaySummary( { items } ) {
	return (
		<section className="bkng-p-done__summary">
			<h4>
				<Icon name="calendar" size={ 22 } />
				{ __( 'Riepilogo prenotazione', 'booking-manager' ) }
			</h4>
			<dl>
				{ items.map( ( [ icon, label, value, extra ] ) => (
					<div key={ label }>
						<Icon name={ icon } size={ 24 } />
						<span>
							<dt>{ label }</dt>
							<dd>
								<strong>{ value }</strong>
								{ extra && (
									<span className="bkng-p-block">
										{ extra }
									</span>
								) }
							</dd>
						</span>
					</div>
				) ) }
			</dl>
		</section>
	);
}

export function StayFooter( { name, where = {}, signoff } ) {
	return (
		<footer className="bkng-p-done__foot">
			<div>
				<Icon name="pin" />
				<span>
					<strong>{ name }</strong>
					{ where.address && (
						<span className="bkng-p-block">{ where.address }</span>
					) }
				</span>
			</div>
			{ ( where.phone || where.email ) && (
				<div>
					<span className="bkng-p-done__contacts">
						{ where.phone && (
							<a
								href={
									'tel:' +
									where.phone.replace( /[^0-9+]/g, '' )
								}
							>
								<Icon name="phone" />
								{ where.phone }
							</a>
						) }
						{ where.email && (
							<a href={ 'mailto:' + where.email }>
								<Icon name="mail" />
								{ where.email }
							</a>
						) }
					</span>
				</div>
			) }
			{ signoff && <p className="bkng-p-done__signoff">{ signoff } ♡</p> }
		</footer>
	);
}

/**
 * "Aggiungi al calendario": the stay as a .ics file (the same attached to the e-mails),
 * only once the booking is confirmed.
 *
 * @param {Object} props
 * @param {Object} props.file { name, data } from the booking, or null.
 */
export function CalendarLink( { file } ) {
	if ( ! file ) {
		return null;
	}
	return (
		<p className="bkng-p-calendar">
			<a
				className="bkng-p-button"
				href={
					'data:text/calendar;charset=utf-8,' +
					encodeURIComponent( file.data )
				}
				download={ file.name }
			>
				<Icon name="calendar" />
				{ __( 'Aggiungi al calendario', 'booking-manager' ) }
			</a>
		</p>
	);
}
