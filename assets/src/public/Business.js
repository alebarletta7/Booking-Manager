/**
 * The business behind the form (0.2.4): name, logo and contacts the customer made
 * public, and the WhatsApp click-to-chat button (wa.me, no API), at the bottom of the
 * form and of the guest's booking page. Shown only when the customer set something.
 */
import { __ } from '@wordpress/i18n';

import { Icon } from './icons';

/**
 * Link of the WhatsApp chat with the customer's text, or the default one.
 *
 * @param {Object} business     business of the API.
 * @param {string} propertyName name of the property.
 * @param {string} code         booking code, '' in the form.
 */
export function whatsappUrl( business, propertyName, code = '' ) {
	if ( ! business?.whatsapp ) {
		return '';
	}
	let text = business.whatsapp_text || '';
	if ( ! text ) {
		text = code
			? __(
					'Ciao, ho una domanda sulla prenotazione {codice_prenotazione}.',
					'booking-manager'
			  )
			: __(
					'Ciao, vorrei informazioni su {struttura}.',
					'booking-manager'
			  );
	}
	text = text
		.split( '{codice_prenotazione}' )
		.join( code )
		.split( '{struttura}' )
		.join( propertyName || '' )
		.replace( /[ \t]{2,}/g, ' ' )
		.trim();
	return (
		'https://wa.me/' +
		encodeURIComponent( business.whatsapp ) +
		( text ? '?text=' + encodeURIComponent( text ) : '' )
	);
}

/** The font the customer chose, on the form's container. */
export function applyFont( el, business ) {
	if ( el && business?.font ) {
		el.style.setProperty( '--bkng-font', business.font );
		el.classList.add( 'has-font' );
	}
}

export function WhatsAppButton( { business, propertyName, code = '' } ) {
	const url = whatsappUrl( business, propertyName, code );
	if ( ! url ) {
		return null;
	}
	return (
		<a
			className="bkng-p-button bkng-p-whatsapp"
			href={ url }
			target="_blank"
			rel="noopener noreferrer"
		>
			<Icon name="chat" />
			{ __( 'Scrivici su WhatsApp', 'booking-manager' ) }
		</a>
	);
}

/**
 * @param {Object} props
 * @param {Object} props.place  property of the API (name, phone, email, business).
 * @param {string} [props.code] booking code on the guest's page.
 */
export default function Business( { place, code = '' } ) {
	const b = place?.business;
	if ( ! b?.footer ) {
		return null;
	}
	const website = b.website || '';
	return (
		<footer className="bkng-p-business">
			{ ( b.logo || b.name ) && (
				<div className="bkng-p-business__brand">
					{ b.logo && (
						<img
							className="bkng-p-business__logo"
							src={ b.logo }
							alt={ b.name || place.name || '' }
						/>
					) }
					{ b.name && (
						<strong className="bkng-p-business__name">
							{ b.name }
						</strong>
					) }
				</div>
			) }
			<div className="bkng-p-business__contacts">
				{ place.phone && (
					<a href={ 'tel:' + place.phone.replace( /[^0-9+]/g, '' ) }>
						<Icon name="phone" />
						{ place.phone }
					</a>
				) }
				{ place.email && (
					<a href={ 'mailto:' + place.email }>
						<Icon name="mail" />
						{ place.email }
					</a>
				) }
				{ website && (
					<a
						href={ website }
						target="_blank"
						rel="noopener noreferrer"
					>
						<Icon name="globe" />
						{ website
							.replace( /^https?:\/\//, '' )
							.replace( /\/$/, '' ) }
					</a>
				) }
			</div>
			<WhatsAppButton
				business={ b }
				propertyName={ place.name }
				code={ code }
			/>
		</footer>
	);
}

/** Back from the payment on the customer's own page: only the code, no booking data. */
export function PaidReturn( { code } ) {
	return (
		<div className="bkng-p-card bkng-p-done" role="status">
			<h3>{ __( 'Pagamento ricevuto, grazie!', 'booking-manager' ) }</h3>
			<p>
				{ __( 'Codice prenotazione:', 'booking-manager' ) }{ ' ' }
				<strong>{ code }</strong>
			</p>
			<p className="bkng-p-muted">
				{ __(
					'Riceverai a breve l’email di conferma con il link per vedere o gestire la prenotazione.',
					'booking-manager'
				) }
			</p>
		</div>
	);
}
