/**
 * The guest's booking, opened from the link in the e-mail (code + token in the address):
 * what was booked, what is paid, payment of what is due and cancellation when the
 * property allows it online.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { applyColor, dateTime, money } from './format';
import { StayFooter, StayHead, StaySummary, stayItems } from './StayCard';
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

const TITLES = {
	pending: __(
		'La tua richiesta è in attesa di conferma',
		'booking-manager'
	),
	option: __( 'Manca solo il pagamento', 'booking-manager' ),
	confirmed: __( 'La tua prenotazione è confermata', 'booking-manager' ),
	in_house: __( 'Buon soggiorno!', 'booking-manager' ),
	completed: __( 'Grazie per essere stato nostro ospite', 'booking-manager' ),
	cancelled: __( 'La prenotazione è annullata', 'booking-manager' ),
};

export default function Manage( { api, code, token, paid, root } ) {
	const [ booking, setBooking ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ actionError, setActionError ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ confirming, setConfirming ] = useState( false );
	const [ checks, setChecks ] = useState( 0 );

	useEffect( () => {
		applyColor( root, booking?.property?.color );
	}, [ root, booking ] );

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
	const tone =
		{ cancelled: 'off', no_show: 'off', pending: 'wait', option: 'wait' }[
			b.status
		] || 'ok';
	const more =
		b.total > 0
			? [
					b.paid > 0 && [
						'card',
						__( 'Già pagato', 'booking-manager' ),
						money( b.paid, b.currency ),
					],
					b.balance > 0 && [
						'coins',
						__( 'Da pagare', 'booking-manager' ),
						money( b.balance, b.currency ),
					],
			  ].filter( Boolean )
			: [];

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
			<div className="bkng-p-card bkng-p-done bkng-p-manage">
				<StayHead
					title={
						TITLES[ b.status ] || STATUS[ b.status ] || b.status
					}
					tone={ tone }
				/>
				<p className="bkng-p-done__text">
					<strong>
						{ sprintf(
							/* translators: %s: guest's first name. */
							__( 'Ciao %s,', 'booking-manager' ),
							b.first_name
						) }
					</strong>
					<br />
					{ __(
						'qui trovi i dettagli del tuo soggiorno e puoi gestire la prenotazione.',
						'booking-manager'
					) }{ ' ' }
					<span className={ 'bkng-p-badge is-' + b.status }>
						{ STATUS[ b.status ] || b.status }
					</span>
				</p>
				{ b.awaiting_payment && b.option_expires_at && (
					<p className="bkng-p-note">
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
				<StaySummary items={ stayItems( b, more ) } />
				<ErrorBox error={ actionError } />
				{ ( b.can_pay || ( b.can_cancel && ! confirming ) ) && (
					<div className="bkng-p-actions bkng-p-manage__actions">
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
						{ b.can_pay && (
							<button
								type="button"
								className="bkng-p-button is-primary"
								onClick={ pay }
								disabled={ busy }
							>
								{ busy ? <Spinner /> : null }
								{ __( 'Paga online', 'booking-manager' ) }
							</button>
						) }
					</div>
				) }
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
				<StayFooter name={ p.name } where={ p } signoff={ p.signoff } />
			</div>
		</div>
	);
}
