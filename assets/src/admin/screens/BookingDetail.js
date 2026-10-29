/**
 * One booking: stay and rooms, guest, status changes, payments, QR, messages, history.
 */
import { Button } from '@wordpress/components';
import { RawHTML, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { get, patch, post, put, requestId } from '../lib/api';
import { can } from '../lib/boot';
import {
	actionLabel,
	BOOKING_STATUS,
	date,
	dateTime,
	guestsLabel,
	money,
	nightsBetween,
	nightsLabel,
	PAYMENT_METHODS,
	SOURCES,
	STAY_STATUS,
} from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';
import { useProperty } from '../lib/property';
import { navigate } from '../lib/router';
import {
	Badge,
	Card,
	Check,
	Confirm,
	DateField,
	Details,
	ErrorNotice,
	FormModal,
	Grid,
	Loading,
	MoneyField,
	NumberField,
	Page,
	Select,
	Success,
	Text,
	Textarea,
} from '../components/ui';

const STATUS_ACTIONS = {
	confirmed: __( 'Conferma', 'booking-manager' ),
	option: __( 'Metti in opzione', 'booking-manager' ),
	in_house: __( 'Check-in', 'booking-manager' ),
	completed: __( 'Check-out', 'booking-manager' ),
	cancelled: __( 'Annulla prenotazione', 'booking-manager' ),
	no_show: __( 'Segna no-show', 'booking-manager' ),
	pending: __( 'Rimetti in attesa', 'booking-manager' ),
};

// A guest's request when the property takes online payments: accepting it holds the
// rooms and asks the guest to pay; the payment confirms it.
const ACCEPT_AND_PAY = __( 'Accetta e chiedi il pagamento', 'booking-manager' );

function statusLabel( booking, to ) {
	if ( booking.status === 'in_house' && to === 'confirmed' ) {
		return __( 'Annulla il check-in', 'booking-manager' );
	}
	if ( to === 'confirmed' && booking.accept_to === 'option' ) {
		return ACCEPT_AND_PAY;
	}
	return STATUS_ACTIONS[ to ];
}

// Same mapping as BookingsController::STATUS_CAPS.
const STATUS_CAPS = {
	pending: 'bkng_edit_booking',
	option: 'bkng_edit_booking',
	confirmed: 'bkng_confirm_booking',
	cancelled: 'bkng_cancel_booking',
	no_show: 'bkng_mark_no_show',
	in_house: 'bkng_checkin',
	completed: 'bkng_checkin',
};

const TEMPLATES = {
	guest_received: __( 'Richiesta ricevuta', 'booking-manager' ),
	guest_accepted: __(
		'Richiesta accettata: completa il pagamento',
		'booking-manager'
	),
	guest_confirmed: __( 'Prenotazione confermata', 'booking-manager' ),
	guest_updated: __( 'Prenotazione modificata', 'booking-manager' ),
	guest_cancelled: __( 'Prenotazione annullata', 'booking-manager' ),
	guest_pre_arrival: __( "Promemoria prima dell'arrivo", 'booking-manager' ),
	guest_payment: __( 'Pagamento ricevuto', 'booking-manager' ),
};

function StatusModal( { booking, to, onClose, onDone } ) {
	const action = useAction();
	const needsReason = to === 'cancelled';
	const texts = {
		cancelled: __(
			'Le camere tornano disponibili. I pagamenti ricevuti non vengono rimborsati da soli: usa «Rimborsa» nei pagamenti.',
			'booking-manager'
		),
		no_show: __(
			"L'ospite non si è presentato: le camere tornano disponibili.",
			'booking-manager'
		),
		in_house: __(
			'Tutte le camere in arrivo oggi risultano in struttura.',
			'booking-manager'
		),
		completed: __(
			"Tutte le camere risultano liberate. Le camere vanno in «Da pulire» se l'housekeeping è attivo.",
			'booking-manager'
		),
	};
	if ( to === 'confirmed' && booking.accept_to === 'option' ) {
		texts.confirmed = __(
			"Le camere restano riservate all'ospite, che riceve una email per pagare online. La prenotazione si conferma da sola quando arriva il pagamento, e solo allora parte la mail di conferma.",
			'booking-manager'
		);
	}
	const label = statusLabel( booking, to );
	return (
		<Confirm
			title={ label || BOOKING_STATUS[ to ] }
			text={ texts[ to ] }
			confirmLabel={ label || __( 'Conferma', 'booking-manager' ) }
			destructive={ to === 'cancelled' || to === 'no_show' }
			reasonLabel={
				needsReason
					? __( "Motivo dell'annullamento", 'booking-manager' )
					: undefined
			}
			busy={ action.busy }
			error={ action.error }
			onCancel={ onClose }
			onConfirm={ async ( reason ) => {
				const done = await action.run( () =>
					post( '/bookings/' + booking.id + '/status', {
						status: to,
						version: booking.version,
						reason: reason || undefined,
					} )
				);
				if ( done ) {
					onDone(
						done,
						__( 'Stato aggiornato.', 'booking-manager' )
					);
				}
			} }
		/>
	);
}

function DetailsModal( { booking, onClose, onDone } ) {
	const action = useAction();
	const [ form, setForm ] = useState( {
		first_name: booking.first_name,
		last_name: booking.last_name,
		email: booking.email || '',
		phone: booking.phone || '',
		language: booking.language || 'it',
		arrival_time: booking.arrival_time || '',
		customer_notes: booking.customer_notes || '',
		internal_notes: booking.internal_notes || '',
	} );
	const set = ( k ) => ( v ) => setForm( ( f ) => ( { ...f, [ k ]: v } ) );
	return (
		<FormModal
			title={ __( 'Modifica ospite e note', 'booking-manager' ) }
			onClose={ onClose }
			busy={ action.busy }
			error={ action.error }
			onSubmit={ async () => {
				const done = await action.run( () =>
					patch( '/bookings/' + booking.id, {
						version: booking.version,
						guest: {
							first_name: form.first_name,
							last_name: form.last_name,
							email: form.email,
							phone: form.phone,
							language: form.language,
						},
						arrival_time: form.arrival_time || null,
						customer_notes: form.customer_notes,
						internal_notes: form.internal_notes,
					} )
				);
				if ( done ) {
					onDone( done, __( 'Dati salvati.', 'booking-manager' ) );
				}
			} }
		>
			<Grid>
				<Text
					label={ __( 'Nome', 'booking-manager' ) }
					value={ form.first_name }
					onChange={ set( 'first_name' ) }
					required
				/>
				<Text
					label={ __( 'Cognome', 'booking-manager' ) }
					value={ form.last_name }
					onChange={ set( 'last_name' ) }
					required
				/>
				<Text
					label={ __( 'Email', 'booking-manager' ) }
					type="email"
					value={ form.email }
					onChange={ set( 'email' ) }
				/>
				<Text
					label={ __( 'Telefono', 'booking-manager' ) }
					value={ form.phone }
					onChange={ set( 'phone' ) }
				/>
				<Select
					label={ __( 'Lingua delle email', 'booking-manager' ) }
					value={ form.language }
					options={ [
						{ value: 'it', label: 'Italiano' },
						{ value: 'en', label: 'English' },
						{ value: 'de', label: 'Deutsch' },
						{ value: 'fr', label: 'Français' },
						{ value: 'es', label: 'Español' },
					] }
					onChange={ set( 'language' ) }
				/>
				<Text
					label={ __(
						'Orario di arrivo previsto',
						'booking-manager'
					) }
					type="time"
					value={ form.arrival_time }
					onChange={ set( 'arrival_time' ) }
				/>
			</Grid>
			<div className="bkng-stack">
				<Textarea
					label={ __( "Richieste dell'ospite", 'booking-manager' ) }
					value={ form.customer_notes }
					onChange={ set( 'customer_notes' ) }
				/>
				<Textarea
					label={ __(
						"Note interne (non visibili all'ospite)",
						'booking-manager'
					) }
					value={ form.internal_notes }
					onChange={ set( 'internal_notes' ) }
				/>
			</div>
		</FormModal>
	);
}

function StayModal( { booking, types, onClose, onDone } ) {
	const action = useAction();
	const [ arrival, setArrival ] = useState( booking.arrival );
	const [ departure, setDeparture ] = useState( booking.departure );
	const [ keepPrice, setKeepPrice ] = useState( false );
	const [ override, setOverride ] = useState( false );
	const [ rooms, setRooms ] = useState(
		booking.rooms
			.filter( ( r ) => r.stay_status !== 'removed' )
			.map( ( r ) => ( {
				id: r.id,
				room_type_id: r.room_type_id,
				rate_plan_id: r.rate_plan_id,
				unit_id: r.unit_id,
				adults: r.adults,
				children: r.children,
				departed: r.stay_status === 'departed',
			} ) )
	);
	const setRoom = ( i, k, v ) =>
		setRooms( ( list ) =>
			list.map( ( r, j ) => ( i === j ? { ...r, [ k ]: v } : r ) )
		);
	const typeOptions = types.map( ( t ) => ( {
		value: String( t.id ),
		label: t.name,
	} ) );
	return (
		<FormModal
			wide
			title={ __( 'Modifica soggiorno', 'booking-manager' ) }
			onClose={ onClose }
			busy={ action.busy }
			error={ action.error }
			submitLabel={ __( 'Salva il soggiorno', 'booking-manager' ) }
			onSubmit={ async () => {
				const done = await action.run( () =>
					put( '/bookings/' + booking.id + '/stay', {
						version: booking.version,
						arrival,
						departure,
						keep_price: keepPrice,
						override_restrictions: override,
						rooms: rooms.map( ( r ) => ( {
							id: r.id,
							room_type_id: Number( r.room_type_id ),
							rate_plan_id: r.rate_plan_id,
							unit_id:
								Number( r.room_type_id ) ===
								booking.rooms.find( ( o ) => o.id === r.id )
									?.room_type_id
									? r.unit_id
									: null,
							adults: r.adults,
							children: r.children,
						} ) ),
					} )
				);
				if ( done ) {
					onDone(
						done,
						__( 'Soggiorno aggiornato.', 'booking-manager' )
					);
				}
			} }
		>
			<Grid>
				<DateField
					label={ __( 'Arrivo', 'booking-manager' ) }
					value={ arrival }
					onChange={ setArrival }
				/>
				<DateField
					label={ __( 'Partenza', 'booking-manager' ) }
					value={ departure }
					min={ arrival }
					onChange={ setDeparture }
				/>
			</Grid>
			<p className="bkng-help">
				{ arrival && departure && departure > arrival
					? nightsLabel( nightsBetween( arrival, departure ) )
					: __( 'Controlla le date.', 'booking-manager' ) }
			</p>
			{ rooms.map( ( r, i ) => (
				<div key={ r.id || i } className="bkng-row">
					<Select
						label={ __( 'Tipologia', 'booking-manager' ) }
						value={ String( r.room_type_id ) }
						options={ typeOptions }
						disabled={ r.departed }
						onChange={ ( v ) => setRoom( i, 'room_type_id', v ) }
					/>
					<NumberField
						label={ __( 'Adulti', 'booking-manager' ) }
						min={ 1 }
						value={ r.adults }
						onChange={ ( v ) => setRoom( i, 'adults', v || 1 ) }
					/>
					<NumberField
						label={ __( 'Bambini', 'booking-manager' ) }
						min={ 0 }
						value={ r.children }
						onChange={ ( v ) => setRoom( i, 'children', v || 0 ) }
					/>
					{ rooms.length > 1 && ! r.departed && (
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () =>
								setRooms( ( list ) =>
									list.filter( ( _, j ) => j !== i )
								)
							}
						>
							{ __( 'Togli', 'booking-manager' ) }
						</Button>
					) }
				</div>
			) ) }
			<Button
				variant="secondary"
				onClick={ () =>
					setRooms( ( list ) => [
						...list,
						{
							room_type_id: types[ 0 ]?.id,
							adults: 2,
							children: 0,
						},
					] )
				}
			>
				{ __( 'Aggiungi una camera', 'booking-manager' ) }
			</Button>
			<div className="bkng-checks" style={ { marginTop: 14 } }>
				{ can( 'bkng_manage_rates' ) && (
					<Check
						label={ __(
							'Mantieni il prezzo attuale',
							'booking-manager'
						) }
						checked={ keepPrice }
						onChange={ setKeepPrice }
					/>
				) }
				{ can( 'bkng_override_restrictions' ) && (
					<Check
						label={ __(
							'Ignora soggiorno minimo e chiusure',
							'booking-manager'
						) }
						checked={ override }
						onChange={ setOverride }
					/>
				) }
			</div>
		</FormModal>
	);
}

function PaymentModal( { booking, data, onClose, onDone } ) {
	const action = useAction();
	const [ amount, setAmount ] = useState( data.totals.due || null );
	const [ method, setMethod ] = useState( 'cash' );
	const [ note, setNote ] = useState( '' );
	const [ id ] = useState( requestId );
	return (
		<FormModal
			title={ __( 'Registra un pagamento', 'booking-manager' ) }
			onClose={ onClose }
			busy={ action.busy }
			error={ action.error }
			submitLabel={ __( 'Registra', 'booking-manager' ) }
			onSubmit={ async () => {
				const done = await action.run( () =>
					post( '/bookings/' + booking.id + '/payments', {
						amount,
						method,
						note,
						request_id: id,
					} )
				);
				if ( done ) {
					onDone(
						null,
						__( 'Pagamento registrato.', 'booking-manager' )
					);
				}
			} }
		>
			<p className="bkng-help">
				{ __(
					'Per incassi in struttura (contanti, POS, bonifico). Nessun dato di carta viene chiesto o salvato.',
					'booking-manager'
				) }
			</p>
			<Grid>
				<MoneyField
					label={ __( 'Importo (€)', 'booking-manager' ) }
					value={ amount }
					onChange={ setAmount }
					required
				/>
				<Select
					label={ __( 'Metodo', 'booking-manager' ) }
					value={ method }
					options={ (
						data.methods || Object.keys( PAYMENT_METHODS )
					).map( ( m ) => ( {
						value: m,
						label: PAYMENT_METHODS[ m ] || m,
					} ) ) }
					onChange={ setMethod }
				/>
			</Grid>
			<Text
				label={ __( 'Nota (facoltativa)', 'booking-manager' ) }
				value={ note }
				onChange={ setNote }
			/>
		</FormModal>
	);
}

function RefundModal( { payment, onClose, onDone } ) {
	const action = useAction();
	const [ amount, setAmount ] = useState( payment.refundable_cents );
	const [ reason, setReason ] = useState( '' );
	const [ id ] = useState( requestId );
	return (
		<FormModal
			destructive
			title={ __( 'Rimborsa', 'booking-manager' ) }
			onClose={ onClose }
			busy={ action.busy }
			error={ action.error }
			submitLabel={ __( 'Rimborsa', 'booking-manager' ) }
			onSubmit={ async () => {
				const done = await action.run( () =>
					post( '/payments/' + payment.id + '/refund', {
						amount,
						reason,
						request_id: id,
					} )
				);
				if ( done ) {
					onDone(
						null,
						__(
							'Rimborso inviato. Lo stato si aggiorna quando il gestore lo conferma.',
							'booking-manager'
						)
					);
				}
			} }
		>
			<p className="bkng-help">
				{ sprintf(
					/* translators: %s: refundable amount. */
					__( 'Rimborsabile: %s', 'booking-manager' ),
					money( payment.refundable_cents, payment.currency )
				) }
			</p>
			<MoneyField
				label={ __( 'Importo (€)', 'booking-manager' ) }
				value={ amount }
				onChange={ setAmount }
				disabled={ ! payment.can_partial }
			/>
			<div style={ { marginTop: 12 } }>
				<Text
					label={ __( 'Motivo', 'booking-manager' ) }
					value={ reason }
					onChange={ setReason }
				/>
			</div>
		</FormModal>
	);
}

function Payments( { booking, onChanged } ) {
	const data = useLoad(
		() => get( '/bookings/' + booking.id + '/payments' ),
		[ booking.id, booking.version ]
	);
	const [ modal, setModal ] = useState( null );
	const [ link, setLink ] = useState( null );
	const [ notice, setNotice ] = useState( '' );
	const action = useAction();
	const open = [ 'pending', 'option', 'confirmed', 'in_house' ].includes(
		booking.status
	);
	const done = ( _, message ) => {
		setModal( null );
		setNotice( message );
		data.reload();
		onChanged();
	};
	if ( data.loading ) {
		return <Loading />;
	}
	if ( data.error ) {
		return <ErrorNotice error={ data.error } onRetry={ data.reload } />;
	}
	const d = data.data;
	return (
		<Card
			title={ __( 'Pagamenti', 'booking-manager' ) }
			actions={
				open &&
				can( 'bkng_record_payment' ) && (
					<>
						<Button
							variant="secondary"
							onClick={ () => setModal( { kind: 'pay' } ) }
						>
							{ __( 'Registra pagamento', 'booking-manager' ) }
						</Button>
						{ d.online.available && d.totals.due > 0 && (
							<Button
								variant="secondary"
								isBusy={ action.busy }
								onClick={ async () => {
									const res = await action.run( () =>
										post(
											'/bookings/' +
												booking.id +
												'/payment-link',
											{}
										)
									);
									if ( res ) {
										setLink( res.checkout_url );
										data.reload();
									}
								} }
							>
								{ __( 'Link di pagamento', 'booking-manager' ) }
							</Button>
						) }
					</>
				)
			}
		>
			<Success onDismiss={ () => setNotice( '' ) }>{ notice }</Success>
			<ErrorNotice
				error={ action.error }
				onDismiss={ () => action.setError( null ) }
			/>
			{ link && (
				<div className="bkng-notice">
					<p>
						{ __(
							"Pagina di pagamento pronta: copiala e inviala all'ospite.",
							'booking-manager'
						) }
					</p>
					<div className="bkng-code">{ link }</div>
					{ d.online.test_mode && (
						<p className="bkng-muted bkng-small">
							{ __(
								'Modalità di prova: nessun addebito reale.',
								'booking-manager'
							) }
						</p>
					) }
				</div>
			) }
			<Details
				rows={ [
					[
						__( 'Totale', 'booking-manager' ),
						money( d.totals.total, d.currency ),
					],
					[
						__( 'Incassato', 'booking-manager' ),
						money( d.totals.paid, d.currency ),
					],
					d.totals.refunded > 0 && [
						__( 'Rimborsato', 'booking-manager' ),
						money( d.totals.refunded, d.currency ),
					],
					[
						__( 'Da incassare', 'booking-manager' ),
						<strong key="due">
							{ money( d.totals.due, d.currency ) }
						</strong>,
					],
					d.totals.deposit_missing > 0 && [
						__( 'Caparra mancante', 'booking-manager' ),
						money( d.totals.deposit_missing, d.currency ) +
							( booking.deposit_due_date
								? ' · ' +
								  sprintf(
										/* translators: %s: date. */ __(
											'entro il %s',
											'booking-manager'
										),
										date( booking.deposit_due_date )
								  )
								: '' ),
					],
				].filter( Boolean ) }
			/>
			{ d.payments.length > 0 && (
				<div className="bkng-table-wrap" style={ { marginTop: 14 } }>
					<table className="bkng-table">
						<thead>
							<tr>
								<th>{ __( 'Data', 'booking-manager' ) }</th>
								<th>{ __( 'Metodo', 'booking-manager' ) }</th>
								<th>{ __( 'Stato', 'booking-manager' ) }</th>
								<th className="is-num">
									{ __( 'Importo', 'booking-manager' ) }
								</th>
								<th />
							</tr>
						</thead>
						<tbody>
							{ d.payments.map( ( p ) => (
								<tr key={ p.id }>
									<td className="bkng-nowrap">
										{ dateTime(
											p.received_at || p.created_at
										) }
									</td>
									<td>
										{ p.provider === 'manual'
											? PAYMENT_METHODS[ p.method ] ||
											  p.provider_label
											: p.provider_label }
										{ p.livemode === false && (
											<span className="bkng-badge is-warn">
												{ __(
													'prova',
													'booking-manager'
												) }
											</span>
										) }
										{ p.failure_message && (
											<div className="bkng-small bkng-muted">
												{ p.failure_message }
											</div>
										) }
									</td>
									<td>
										<span className="bkng-badge">
											{ p.status }
										</span>
									</td>
									<td className="is-num">
										{ money( p.amount, p.currency ) }
										{ p.refunded > 0 && (
											<div className="bkng-small bkng-muted">
												-
												{ money(
													p.refunded,
													p.currency
												) }
											</div>
										) }
									</td>
									<td className="bkng-right">
										{ p.can_refund &&
											can( 'bkng_refund_payment' ) && (
												<Button
													variant="link"
													isDestructive
													onClick={ () =>
														setModal( {
															kind: 'refund',
															payment: p,
														} )
													}
												>
													{ __(
														'Rimborsa',
														'booking-manager'
													) }
												</Button>
											) }
										{ p.provider !== 'manual' && (
											<Button
												variant="link"
												onClick={ () =>
													action
														.run( () =>
															post(
																'/payments/' +
																	p.id +
																	'/sync'
															)
														)
														.then(
															( r ) =>
																r &&
																data.reload()
														)
												}
											>
												{ __(
													'Aggiorna',
													'booking-manager'
												) }
											</Button>
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
			{ modal?.kind === 'pay' && (
				<PaymentModal
					booking={ booking }
					data={ d }
					onClose={ () => setModal( null ) }
					onDone={ done }
				/>
			) }
			{ modal?.kind === 'refund' && (
				<RefundModal
					payment={ modal.payment }
					onClose={ () => setModal( null ) }
					onDone={ done }
				/>
			) }
		</Card>
	);
}

function Qr( { booking } ) {
	const qr = useLoad(
		() => get( '/bookings/' + booking.id + '/qr' ),
		[ booking.id, booking.version ]
	);
	const action = useAction();
	const [ confirm, setConfirm ] = useState( false );
	if ( ! [ 'confirmed', 'in_house' ].includes( booking.status ) ) {
		return null;
	}
	return (
		<Card title={ __( 'QR di check-in', 'booking-manager' ) }>
			{ qr.loading && <Loading /> }
			<ErrorNotice error={ qr.error || action.error } />
			{ qr.data && (
				<>
					<div style={ { maxWidth: 180 } }>
						<RawHTML>{ qr.data.svg }</RawHTML>
					</div>
					<p className="bkng-help">
						{ __(
							'È nella email di conferma. Alla reception si legge con la fotocamera.',
							'booking-manager'
						) }
					</p>
					{ can( 'bkng_checkin' ) && (
						<Button
							variant="link"
							onClick={ () => setConfirm( true ) }
						>
							{ __( 'Genera un nuovo QR', 'booking-manager' ) }
						</Button>
					) }
				</>
			) }
			{ confirm && (
				<Confirm
					title={ __( 'Nuovo QR', 'booking-manager' ) }
					text={ __(
						'Il QR attuale smette subito di funzionare (email inoltrata o persa). Ricorda di inviare di nuovo la conferma.',
						'booking-manager'
					) }
					confirmLabel={ __( 'Genera', 'booking-manager' ) }
					busy={ action.busy }
					onCancel={ () => setConfirm( false ) }
					onConfirm={ async () => {
						const ok = await action.run( () =>
							post( '/bookings/' + booking.id + '/qr/regenerate' )
						);
						setConfirm( false );
						if ( ok ) {
							qr.reload();
						}
					} }
				/>
			) }
		</Card>
	);
}

const DEFAULT_TEMPLATE = {
	cancelled: 'guest_cancelled',
	pending: 'guest_received',
};

function SendMessage( { booking } ) {
	const [ key, setKey ] = useState(
		DEFAULT_TEMPLATE[ booking.status ] || 'guest_confirmed'
	);
	const action = useAction();
	const [ sent, setSent ] = useState( '' );
	if ( ! booking.email ) {
		return null;
	}
	return (
		<Card title={ __( "Invia un'email", 'booking-manager' ) }>
			<ErrorNotice
				error={ action.error }
				onDismiss={ () => action.setError( null ) }
			/>
			<Success onDismiss={ () => setSent( '' ) }>{ sent }</Success>
			<Select
				label={ __( 'Messaggio', 'booking-manager' ) }
				value={ key }
				options={ Object.entries( TEMPLATES ).map(
					( [ value, label ] ) => ( { value, label } )
				) }
				onChange={ setKey }
			/>
			<div style={ { marginTop: 10 } }>
				<Button
					variant="secondary"
					isBusy={ action.busy }
					onClick={ async () => {
						const res = await action.run( () =>
							post( '/bookings/' + booking.id + '/notify', {
								template: key,
							} )
						);
						if ( res ) {
							setSent(
								res.status === 'sent'
									? __( 'Email inviata.', 'booking-manager' )
									: __(
											'Email in coda: verrà ritentata.',
											'booking-manager'
									  )
							);
						}
					} }
				>
					{ sprintf(
						/* translators: %s: e-mail address. */ __(
							'Invia a %s',
							'booking-manager'
						),
						booking.email
					) }
				</Button>
			</div>
		</Card>
	);
}

function History( { booking } ) {
	const list = useLoad(
		() => get( '/bookings/' + booking.id + '/history' ),
		[ booking.id, booking.version ]
	);
	if ( ! list.data?.length ) {
		return null;
	}
	return (
		<Card title={ __( 'Storico', 'booking-manager' ) }>
			<ul className="bkng-list">
				{ list.data.map( ( row ) => (
					<li key={ row.id }>
						<div className="bkng-list__main">
							<span className="bkng-strong">
								{ actionLabel( row.action ) }
							</span>
							{ row.reason && <span> · { row.reason }</span> }
							<div className="bkng-small bkng-muted">
								{ dateTime( row.created_at ) } ·{ ' ' }
								{ row.user_name ||
									( row.actor_type === 'guest'
										? __( 'Ospite', 'booking-manager' )
										: __( 'Sistema', 'booking-manager' ) ) }
							</div>
						</div>
					</li>
				) ) }
			</ul>
		</Card>
	);
}

export default function BookingDetail( { params } ) {
	const { property } = useProperty();
	const booking = useLoad(
		() => get( '/bookings/' + params.id ),
		[ params.id ]
	);
	const types = useLoad(
		() => get( '/properties/' + property.id + '/room-types' ),
		[ property.id ]
	);
	const units = useLoad(
		() => get( '/properties/' + property.id + '/units' ),
		[ property.id ]
	);
	const [ modal, setModal ] = useState( null );
	const [ notice, setNotice ] = useState( '' );
	const assign = useAction();

	if ( booking.loading ) {
		return <Loading />;
	}
	if ( booking.error ) {
		return (
			<ErrorNotice error={ booking.error } onRetry={ booking.reload } />
		);
	}
	const b = booking.data;
	const typeName = ( id ) =>
		( types.data || [] ).find( ( t ) => t.id === id )?.name || '';
	const done = ( fresh, message ) => {
		setModal( null );
		setNotice( message );
		if ( fresh && fresh.id ) {
			booking.setData( fresh );
		} else {
			booking.reload();
		}
	};
	const editable = [ 'pending', 'option', 'confirmed', 'in_house' ].includes(
		b.status
	);
	// The dates column only when a room does not follow the booking's dates.
	const ownDates = b.rooms.some(
		( r ) => r.arrival !== b.arrival || r.departure !== b.departure
	);

	return (
		<Page
			title={ b.first_name + ' ' + b.last_name }
			subtitle={
				<>
					{ b.code } · { SOURCES[ b.source ] || b.source }
					{ b.external_source
						? ' (' + b.external_source + ')'
						: '' }{ ' ' }
					· <Badge status={ b.status } />
					{ can( 'bkng_view_payments' ) && (
						<>
							{ ' ' }
							<Badge status={ b.payment_status } kind="payment" />
						</>
					) }
				</>
			}
			actions={
				<>
					<Button
						variant="tertiary"
						onClick={ () => navigate( '/bookings' ) }
					>
						{ __( 'Elenco', 'booking-manager' ) }
					</Button>
					{ b.next_statuses
						.filter( ( s ) =>
							can(
								b.status === 'in_house' && s === 'confirmed'
									? 'bkng_checkin'
									: STATUS_CAPS[ s ]
							)
						)
						.map( ( s ) => (
							<Button
								key={ s }
								variant={
									[
										'confirmed',
										'in_house',
										'completed',
									].includes( s ) &&
									! (
										b.status === 'in_house' &&
										s === 'confirmed'
									)
										? 'primary'
										: 'secondary'
								}
								isDestructive={ s === 'cancelled' }
								onClick={ () =>
									setModal( { kind: 'status', to: s } )
								}
							>
								{ statusLabel( b, s ) }
							</Button>
						) ) }
				</>
			}
		>
			<Success onDismiss={ () => setNotice( '' ) }>{ notice }</Success>
			{ b.status === 'option' && b.option_expires_at && (
				<p className="bkng-help">
					{ sprintf(
						/* translators: %s: date and time. */ __(
							'Opzione valida fino al %s: poi si annulla da sola.',
							'booking-manager'
						),
						dateTime( b.option_expires_at )
					) }
				</p>
			) }
			{ b.cancel_reason && (
				<p className="bkng-help">
					{ sprintf(
						/* translators: %s: reason. */ __(
							"Motivo dell'annullamento: %s",
							'booking-manager'
						),
						b.cancel_reason
					) }
				</p>
			) }
			<div className="bkng-columns">
				<div>
					<Card
						title={ __( 'Soggiorno', 'booking-manager' ) }
						actions={
							editable &&
							can( 'bkng_edit_booking' ) && (
								<Button
									variant="secondary"
									onClick={ () =>
										setModal( { kind: 'stay' } )
									}
									disabled={ ! types.data }
								>
									{ __(
										'Modifica date e camere',
										'booking-manager'
									) }
								</Button>
							)
						}
					>
						<Details
							rows={ [
								[
									__( 'Arrivo', 'booking-manager' ),
									date( b.arrival, {
										weekday: 'long',
										day: 'numeric',
										month: 'long',
										year: 'numeric',
									} ) +
										( b.arrival_time
											? ' · ' + b.arrival_time
											: '' ),
								],
								[
									__( 'Partenza', 'booking-manager' ),
									date( b.departure, {
										weekday: 'long',
										day: 'numeric',
										month: 'long',
										year: 'numeric',
									} ),
								],
								[
									__( 'Notti', 'booking-manager' ),
									nightsLabel( b.nights ),
								],
								[
									__( 'Ospiti', 'booking-manager' ),
									guestsLabel( b.adults, b.children ),
								],
							] }
						/>
						<ErrorNotice
							error={ assign.error }
							onDismiss={ () => assign.setError( null ) }
						/>
						<div
							className="bkng-table-wrap"
							style={ { marginTop: 14 } }
						>
							<table className="bkng-table">
								<thead>
									<tr>
										<th>
											{ __(
												'Tipologia',
												'booking-manager'
											) }
										</th>
										<th>
											{ __(
												'Camera',
												'booking-manager'
											) }
										</th>
										{ ownDates && (
											<th>
												{ __(
													'Date',
													'booking-manager'
												) }
											</th>
										) }
										<th>
											{ __(
												'Ospiti',
												'booking-manager'
											) }
										</th>
										<th>
											{ __( 'Stato', 'booking-manager' ) }
										</th>
										{ can( 'bkng_view_payments' ) && (
											<th className="is-num">
												{ __(
													'Prezzo',
													'booking-manager'
												) }
											</th>
										) }
									</tr>
								</thead>
								<tbody>
									{ b.rooms
										.filter(
											( r ) => r.stay_status !== 'removed'
										)
										.map( ( r ) => {
											const choices = (
												units.data || []
											).filter(
												( u ) =>
													u.room_type_id ===
														r.room_type_id &&
													u.status === 'active'
											);
											return (
												<tr key={ r.id }>
													<td>
														{ typeName(
															r.room_type_id
														) }
													</td>
													<td>
														{ editable &&
														( can(
															'bkng_edit_booking'
														) ||
															can(
																'bkng_checkin'
															) ) &&
														r.stay_status !==
															'departed' ? (
															<Select
																className="bkng-unit-select"
																label={ __(
																	'Camera',
																	'booking-manager'
																) }
																hideLabelFromVision
																value={
																	r.unit_id
																		? String(
																				r.unit_id
																		  )
																		: ''
																}
																options={ [
																	{
																		value: '',
																		label: __(
																			'Da assegnare',
																			'booking-manager'
																		),
																	},
																	...choices.map(
																		(
																			u
																		) => ( {
																			value: String(
																				u.id
																			),
																			label:
																				u.code +
																				( u.name &&
																				u.name !==
																					u.code
																					? ' · ' +
																					  u.name
																					: '' ),
																		} )
																	),
																] }
																disabled={
																	assign.busy
																}
																onChange={ async (
																	v
																) => {
																	const fresh =
																		await assign.run(
																			() =>
																				post(
																					'/booking-rooms/' +
																						r.id +
																						'/assign',
																					{
																						unit_id:
																							v
																								? Number(
																										v
																								  )
																								: null,
																						version:
																							b.version,
																					}
																				)
																		);
																	if (
																		fresh
																	) {
																		done(
																			fresh,
																			__(
																				'Camera assegnata.',
																				'booking-manager'
																			)
																		);
																	}
																} }
															/>
														) : (
															(
																units.data || []
															).find(
																( u ) =>
																	u.id ===
																	r.unit_id
															)?.code || '—'
														) }
													</td>
													{ ownDates && (
														<td>
															{ date(
																r.arrival
															) }{ ' ' }
															→{ ' ' }
															{ date(
																r.departure
															) }
														</td>
													) }
													<td className="bkng-nowrap">
														{ guestsLabel(
															r.adults,
															r.children
														) }
													</td>
													<td>
														<Badge
															status={
																r.stay_status
															}
															kind="stay"
														>
															{
																STAY_STATUS[
																	r
																		.stay_status
																]
															}
														</Badge>
														{ r.checked_in_at && (
															<div className="bkng-small bkng-muted">
																{ dateTime(
																	r.checked_in_at
																) }
															</div>
														) }
													</td>
													{ can(
														'bkng_view_payments'
													) && (
														<td className="is-num">
															{ money(
																r.price_total,
																b.currency
															) }
														</td>
													) }
												</tr>
											);
										} ) }
								</tbody>
							</table>
						</div>
					</Card>

					{ can( 'bkng_view_payments' ) && (
						<Payments booking={ b } onChanged={ booking.reload } />
					) }
					<History booking={ b } />
				</div>
				<div>
					<Card
						title={ __( 'Ospite', 'booking-manager' ) }
						actions={
							can( 'bkng_edit_booking' ) && (
								<Button
									variant="link"
									onClick={ () =>
										setModal( { kind: 'details' } )
									}
								>
									{ __( 'Modifica', 'booking-manager' ) }
								</Button>
							)
						}
					>
						<Details
							rows={ [
								[
									__( 'Nome', 'booking-manager' ),
									b.guest_id && can( 'bkng_view_guests' ) ? (
										<a href={ '#/guests/' + b.guest_id }>
											{ b.first_name + ' ' + b.last_name }
										</a>
									) : (
										b.first_name + ' ' + b.last_name
									),
								],
								[
									__( 'Email', 'booking-manager' ),
									b.email ? (
										<a href={ 'mailto:' + b.email }>
											{ b.email }
										</a>
									) : null,
								],
								[
									__( 'Telefono', 'booking-manager' ),
									b.phone ? (
										<a href={ 'tel:' + b.phone }>
											{ b.phone }
										</a>
									) : null,
								],
								[
									__( 'Richieste', 'booking-manager' ),
									b.customer_notes,
								],
								[
									__( 'Note interne', 'booking-manager' ),
									b.internal_notes,
								],
								[
									__( 'Creata il', 'booking-manager' ),
									dateTime( b.created_at ),
								],
							] }
						/>
					</Card>
					<Qr booking={ b } />
					{ can( 'bkng_edit_booking' ) && (
						<SendMessage booking={ b } />
					) }
				</div>
			</div>

			{ modal?.kind === 'status' && (
				<StatusModal
					booking={ b }
					to={ modal.to }
					onClose={ () => setModal( null ) }
					onDone={ done }
				/>
			) }
			{ modal?.kind === 'details' && (
				<DetailsModal
					booking={ b }
					onClose={ () => setModal( null ) }
					onDone={ done }
				/>
			) }
			{ modal?.kind === 'stay' && (
				<StayModal
					booking={ b }
					types={ types.data || [] }
					onClose={ () => setModal( null ) }
					onDone={ done }
				/>
			) }
		</Page>
	);
}
