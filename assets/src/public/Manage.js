/**
 * The guest's booking, opened from the link in the e-mail (code + token in the address):
 * what was booked, what is paid, payment of what is due and cancellation when the
 * property allows it online.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { date, dateTime, guestsLabel, money, nightsLabel } from './format';
import { ErrorBox, Spinner } from './ui';

const STATUS = {
	pending: __( 'In attesa di conferma', 'booking-manager' ),
	option: __( 'In attesa del pagamento', 'booking-manager' ),
	confirmed: __( 'Confermata', 'booking-manager' ),
	in_house: __( 'Soggiorno in corso', 'booking-manager' ),
	completed: __( 'Conclusa', 'booking-manager' ),
	cancelled: __( 'Annullata', 'booking-manager' ),
	no_show: __( 'Non presentato', 'booking-manager' ),
};

export default function Manage( { api, code, token, paid } ) {
	const [ booking, setBooking ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ actionError, setActionError ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ confirming, setConfirming ] = useState( false );
	const [ checks, setChecks ] = useState( 0 );

	const load = useCallback(
		() =>
			api
				.get( 'manage/' + encodeURIComponent( code ), { token } )
				.then( ( b ) => {
					setBooking( b );
					setError( null );
				} )
				.catch( setError ),
		[ api, code, token ]
	);

	useEffect( () => {
		load();
	}, [ load ] );

	// Back from the payment page: the server asks the provider; a few more looks while it confirms.
	useEffect( () => {
		if ( paid !== '1' || ! booking?.awaiting_payment || checks >= 6 ) {
			return undefined;
		}
		const timer = window.setTimeout( () => {
			setChecks( ( n ) => n + 1 );
			load();
		}, 5000 );
		return () => window.clearTimeout( timer );
	}, [ paid, booking, checks, load ] );

	const pay = async () => {
		setBusy( true );
		setActionError( null );
		try {
			const res = await api.post(
				'manage/' + encodeURIComponent( code ) + '/pay',
				{},
				{ token }
			);
			window.location.assign( res.checkout_url );
		} catch ( err ) {
			setActionError( err );
			setBusy( false );
		}
	};

	const cancel = async () => {
		setBusy( true );
		setActionError( null );
		try {
			setBooking(
				await api.post(
					'manage/' + encodeURIComponent( code ) + '/cancel',
					{},
					{ token }
				)
			);
			setConfirming( false );
		} catch ( err ) {
			setActionError( err );
		}
		setBusy( false );
	};

	if ( error ) {
		return <ErrorBox error={ error } />;
	}
	if ( ! booking ) {
		return (
			<p className="bkng-p-muted">
				<Spinner /> { __( 'Caricamento…', 'booking-manager' ) }
			</p>
		);
	}
	const b = booking;
	const p = b.property;

	return (
		<div className="bkng-p-stack">
			{ paid === '1' && b.awaiting_payment && (
				<p className="bkng-p-note" role="status">
					{ __(
						'Grazie! Stiamo ricevendo la conferma del pagamento: la pagina si aggiorna da sola.',
						'booking-manager'
					) }
				</p>
			) }
			{ paid === '0' && b.awaiting_payment && (
				<p className="bkng-p-error" role="alert">
					{ __(
						'Il pagamento non è stato completato. Puoi riprovare qui sotto.',
						'booking-manager'
					) }
				</p>
			) }
			<div className="bkng-p-card">
				<p className="bkng-p-muted">{ p.name }</p>
				<h3 className="bkng-p-manage__title">
					{ sprintf(
						/* translators: %s: booking code. */
						__( 'Prenotazione %s', 'booking-manager' ),
						b.code
					) }
				</h3>
				<p>
					<span className={ 'bkng-p-badge is-' + b.status }>
						{ STATUS[ b.status ] || b.status }
					</span>
				</p>
				{ b.awaiting_payment && b.option_expires_at && (
					<p>
						{ sprintf(
							/* translators: %s: date and time. */
							__(
								'Le camere restano bloccate fino al %s: completa il pagamento per confermare.',
								'booking-manager'
							),
							dateTime( b.option_expires_at )
						) }
					</p>
				) }
				<dl className="bkng-p-facts">
					<dt>{ __( 'Arrivo', 'booking-manager' ) }</dt>
					<dd>
						{ date( b.arrival ) }
						{ p.checkin_from &&
							' · ' +
								sprintf(
									/* translators: %s: time. */
									__( 'dalle %s', 'booking-manager' ),
									p.checkin_from.slice( 0, 5 )
								) }
					</dd>
					<dt>{ __( 'Partenza', 'booking-manager' ) }</dt>
					<dd>
						{ date( b.departure ) }
						{ p.checkout_until &&
							' · ' +
								sprintf(
									/* translators: %s: time. */
									__( 'entro le %s', 'booking-manager' ),
									p.checkout_until.slice( 0, 5 )
								) }
					</dd>
					<dt>{ __( 'Soggiorno', 'booking-manager' ) }</dt>
					<dd>
						{ nightsLabel( b.nights ) } ·{ ' ' }
						{ guestsLabel( b.adults, b.children ) }
					</dd>
					<dt>{ __( 'Camere', 'booking-manager' ) }</dt>
					<dd>
						{ b.rooms.map( ( r, i ) => (
							<span key={ i } className="bkng-p-block">
								{ r.room_type } ·{ ' ' }
								{ guestsLabel( r.adults, r.children ) }
							</span>
						) ) }
					</dd>
					{ b.total > 0 && (
						<>
							<dt>{ __( 'Totale', 'booking-manager' ) }</dt>
							<dd>{ money( b.total, b.currency ) }</dd>
							<dt>{ __( 'Pagato', 'booking-manager' ) }</dt>
							<dd>{ money( b.paid, b.currency ) }</dd>
							{ b.balance > 0 && (
								<>
									<dt>
										{ __( 'Da pagare', 'booking-manager' ) }
									</dt>
									<dd>
										<strong>
											{ money( b.balance, b.currency ) }
										</strong>
									</dd>
								</>
							) }
						</>
					) }
				</dl>
				<ErrorBox error={ actionError } />
				<div className="bkng-p-actions">
					{ b.can_pay && (
						<button
							type="button"
							className="bkng-p-button is-primary wp-element-button"
							onClick={ pay }
							disabled={ busy }
						>
							{ busy ? <Spinner /> : null }
							{ __( 'Paga online', 'booking-manager' ) }
						</button>
					) }
					{ b.can_cancel && ! confirming && (
						<button
							type="button"
							className="bkng-p-button"
							onClick={ () => setConfirming( true ) }
							disabled={ busy }
						>
							{ __(
								'Annulla la prenotazione',
								'booking-manager'
							) }
						</button>
					) }
				</div>
				{ confirming && (
					<div
						className="bkng-p-confirm"
						role="alertdialog"
						aria-labelledby="bkng-p-confirm-title"
					>
						<p id="bkng-p-confirm-title">
							<strong>
								{ __(
									'Vuoi annullare la prenotazione?',
									'booking-manager'
								) }
							</strong>
						</p>
						<p>
							{ b.cancel_penalty > 0
								? sprintf(
										/* translators: %s: amount kept by the property. */
										__(
											'Secondo le condizioni della tariffa la struttura trattiene %s.',
											'booking-manager'
										),
										money( b.cancel_penalty, b.currency )
								  )
								: __(
										'L’annullamento è gratuito.',
										'booking-manager'
								  ) }{ ' ' }
							{ b.paid > 0 &&
								__(
									'Per i rimborsi di quanto pagato la struttura ti contatterà.',
									'booking-manager'
								) }
						</p>
						<div className="bkng-p-actions">
							<button
								type="button"
								className="bkng-p-button"
								onClick={ () => setConfirming( false ) }
								disabled={ busy }
							>
								{ __( 'No, tienila', 'booking-manager' ) }
							</button>
							<button
								type="button"
								className="bkng-p-button is-danger"
								onClick={ cancel }
								disabled={ busy }
							>
								{ busy ? <Spinner /> : null }
								{ __( 'Sì, annulla', 'booking-manager' ) }
							</button>
						</div>
					</div>
				) }
				{ ! b.can_cancel &&
					[ 'pending', 'option', 'confirmed' ].includes(
						b.status
					) && (
						<p className="bkng-p-muted">
							{ __(
								'Per modifiche o annullamenti contatta la struttura.',
								'booking-manager'
							) }
						</p>
					) }
				{ ( p.phone || p.email ) && (
					<p className="bkng-p-muted">
						{ p.address && (
							<span className="bkng-p-block">{ p.address }</span>
						) }
						{ p.phone && (
							<a href={ 'tel:' + p.phone.replace( /\s+/g, '' ) }>
								{ p.phone }
							</a>
						) }
						{ p.phone && p.email ? ' · ' : '' }
						{ p.email && (
							<a href={ 'mailto:' + p.email }>{ p.email }</a>
						) }
					</p>
				) }
			</div>
		</div>
	);
}
