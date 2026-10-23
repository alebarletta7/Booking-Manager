/**
 * A booking taken by the staff (phone, e-mail, walk-in): dates and guests, availability
 * with the price of every rate, rooms chosen, guest details.
 */
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { get, post } from '../lib/api';
import { can } from '../lib/boot';
import {
	addDays,
	guestsLabel,
	MEAL_PLANS,
	money,
	nightsBetween,
	nightsLabel,
} from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';
import { useProperty } from '../lib/property';
import { navigate } from '../lib/router';
import {
	Card,
	Check,
	DateField,
	Empty,
	ErrorNotice,
	Grid,
	Loading,
	MoneyField,
	NumberField,
	Page,
	Select,
	Text,
	Textarea,
} from '../components/ui';

export default function NewBooking( { query } ) {
	const { property } = useProperty();
	const today = property.today;
	const [ stay, setStay ] = useState( {
		arrival: query.arrival || today,
		departure: query.departure || addDays( query.arrival || today, 1 ),
		adults: 2,
		children: 0,
	} );
	const [ searched, setSearched ] = useState( null );
	const [ rooms, setRooms ] = useState( [] );
	const [ guest, setGuest ] = useState( {
		first_name: '',
		last_name: '',
		email: '',
		phone: '',
		language: 'it',
	} );
	const [ status, setStatus ] = useState( 'confirmed' );
	const [ notes, setNotes ] = useState( {
		customer_notes: '',
		internal_notes: '',
	} );
	const [ notify, setNotify ] = useState( true );
	const [ override, setOverride ] = useState( false );
	const types = useLoad(
		() => get( '/properties/' + property.id + '/room-types' ),
		[ property.id ]
	);
	const search = useAction();
	const create = useAction();

	const typeName = ( id ) =>
		( types.data || [] ).find( ( t ) => t.id === id )?.name || '';
	const nights =
		stay.departure > stay.arrival
			? nightsBetween( stay.arrival, stay.departure )
			: 0;
	const changeStay = ( k, v ) => {
		setStay( ( s ) => {
			const next = { ...s, [ k ]: v };
			if ( k === 'arrival' && next.departure <= v ) {
				next.departure = addDays( v, 1 );
			}
			return next;
		} );
		setSearched( null );
		if ( k === 'arrival' || k === 'departure' ) {
			setRooms( [] );
		}
	};

	const runSearch = async () => {
		const res = await search.run( () =>
			get( '/properties/' + property.id + '/availability/search', stay )
		);
		if ( res ) {
			setSearched( res );
		}
	};

	const chosenOf = ( typeId ) =>
		rooms.filter( ( r ) => r.room_type_id === typeId ).length;

	const submit = async () => {
		const res = await create.run( () =>
			post( '/properties/' + property.id + '/bookings', {
				arrival: stay.arrival,
				departure: stay.departure,
				status,
				guest,
				notify_guest: notify,
				override_restrictions: override,
				customer_notes: notes.customer_notes,
				internal_notes: notes.internal_notes,
				rooms: rooms.map( ( r ) => {
					const line = {
						room_type_id: r.room_type_id,
						rate_plan_id: r.rate_plan_id,
						adults: r.adults,
						children: r.children,
					};
					if (
						query.unit_id &&
						Number( query.room_type_id ) === r.room_type_id &&
						r ===
							rooms.find(
								( x ) => x.room_type_id === r.room_type_id
							)
					) {
						line.unit_id = Number( query.unit_id );
					}
					if ( r.manual && r.price_total !== null ) {
						line.price_total = r.price_total;
					}
					return line;
				} ),
			} )
		);
		if ( res ) {
			navigate( '/bookings/' + res.id );
		}
	};

	if ( ! can( 'bkng_create_booking' ) ) {
		return (
			<Empty
				title={ __(
					'Non puoi creare prenotazioni',
					'booking-manager'
				) }
			/>
		);
	}

	const total = rooms.reduce(
		( sum, r ) =>
			sum +
			( r.manual && r.price_total !== null
				? r.price_total
				: r.quote.total ),
		0
	);

	return (
		<Page
			title={ __( 'Nuova prenotazione', 'booking-manager' ) }
			subtitle={ property.name }
		>
			<Card title={ __( '1. Date e ospiti', 'booking-manager' ) }>
				<form
					className="bkng-row bkng-search-row"
					onSubmit={ ( e ) => {
						e.preventDefault();
						runSearch();
					} }
				>
					<DateField
						label={ __( 'Arrivo', 'booking-manager' ) }
						value={ stay.arrival }
						onChange={ ( v ) => changeStay( 'arrival', v ) }
						required
					/>
					<DateField
						label={ __( 'Partenza', 'booking-manager' ) }
						value={ stay.departure }
						min={ addDays( stay.arrival, 1 ) }
						onChange={ ( v ) => changeStay( 'departure', v ) }
						required
					/>
					<NumberField
						label={ __( 'Adulti per camera', 'booking-manager' ) }
						min={ 1 }
						value={ stay.adults }
						onChange={ ( v ) =>
							changeStay( 'adults', Math.max( 1, v || 1 ) )
						}
					/>
					<NumberField
						label={ __( 'Bambini per camera', 'booking-manager' ) }
						min={ 0 }
						value={ stay.children }
						onChange={ ( v ) =>
							changeStay( 'children', Math.max( 0, v || 0 ) )
						}
					/>
					<Button
						variant="primary"
						type="submit"
						isBusy={ search.busy }
						disabled={ ! nights }
					>
						{ __( 'Cerca disponibilità', 'booking-manager' ) }
					</Button>
				</form>
				{ nights > 0 && (
					<p className="bkng-help">{ nightsLabel( nights ) }</p>
				) }
				<ErrorNotice error={ search.error } />
				{ search.busy && <Loading /> }
				{ searched && ! searched.offers.length && (
					<Empty
						title={ __(
							'Nessuna camera disponibile',
							'booking-manager'
						) }
						text={
							Object.values( searched.rules || {} ).join( ' ' ) ||
							__(
								'Prova altre date o meno ospiti per camera.',
								'booking-manager'
							)
						}
					/>
				) }
				{ searched &&
					searched.offers.map( ( offer ) => (
						<div
							key={ offer.room_type_id }
							className="bkng-card"
							style={ { marginTop: 14 } }
						>
							<div className="bkng-card__header">
								<h3 className="bkng-card__title">
									{ typeName( offer.room_type_id ) }
								</h3>
								<span className="bkng-muted">
									{ sprintf(
										/* translators: %d: rooms still free. */
										__( '%d libere', 'booking-manager' ),
										offer.free -
											chosenOf( offer.room_type_id )
									) }
								</span>
							</div>
							<ul
								className="bkng-list"
								style={ { padding: '0 18px' } }
							>
								{ offer.rates.map( ( rate ) => (
									<li key={ String( rate.rate_plan_id ) }>
										<div className="bkng-list__main">
											<span className="bkng-strong">
												{ rate.name }
											</span>
											<div className="bkng-small bkng-muted">
												{ MEAL_PLANS[
													rate.meal_plan
												] || '' }
												{ rate.cancellation &&
												rate.cancellation.refundable ===
													false
													? ' · ' +
													  __(
															'Non rimborsabile',
															'booking-manager'
													  )
													: '' }
											</div>
										</div>
										<span className="bkng-strong">
											{ money(
												rate.quote.total,
												property.currency
											) }
										</span>
										<Button
											variant="secondary"
											disabled={
												chosenOf(
													offer.room_type_id
												) >= offer.free
											}
											onClick={ () =>
												setRooms( ( list ) => [
													...list,
													{
														key: Date.now(),
														room_type_id:
															offer.room_type_id,
														rate_plan_id:
															rate.rate_plan_id,
														rate_name: rate.name,
														adults: stay.adults,
														children: stay.children,
														quote: rate.quote,
														manual: false,
														price_total:
															rate.quote.total,
													},
												] )
											}
										>
											{ __(
												'Aggiungi',
												'booking-manager'
											) }
										</Button>
									</li>
								) ) }
							</ul>
						</div>
					) ) }
			</Card>

			{ rooms.length > 0 && (
				<Card title={ __( '2. Camere scelte', 'booking-manager' ) }>
					<ul className="bkng-list">
						{ rooms.map( ( r ) => (
							<li key={ r.key }>
								<div className="bkng-list__main">
									<span className="bkng-strong">
										{ typeName( r.room_type_id ) }
									</span>{ ' ' }
									· { r.rate_name }
									<div className="bkng-small bkng-muted">
										{ guestsLabel( r.adults, r.children ) }
									</div>
									{ can( 'bkng_manage_rates' ) && (
										<div
											className="bkng-row"
											style={ {
												marginTop: 8,
												marginBottom: 0,
											} }
										>
											<Check
												label={ __(
													'Prezzo concordato',
													'booking-manager'
												) }
												checked={ r.manual }
												onChange={ ( v ) =>
													setRooms( ( list ) =>
														list.map( ( x ) =>
															x.key === r.key
																? {
																		...x,
																		manual: v,
																  }
																: x
														)
													)
												}
											/>
											{ r.manual && (
												<MoneyField
													label={ __(
														'Totale camera (€)',
														'booking-manager'
													) }
													value={ r.price_total }
													onChange={ ( v ) =>
														setRooms( ( list ) =>
															list.map( ( x ) =>
																x.key === r.key
																	? {
																			...x,
																			price_total:
																				v,
																	  }
																	: x
															)
														)
													}
												/>
											) }
										</div>
									) }
								</div>
								<span>
									{ money(
										r.manual && r.price_total !== null
											? r.price_total
											: r.quote.total,
										property.currency
									) }
								</span>
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () =>
										setRooms( ( list ) =>
											list.filter(
												( x ) => x.key !== r.key
											)
										)
									}
								>
									{ __( 'Togli', 'booking-manager' ) }
								</Button>
							</li>
						) ) }
					</ul>
					<p className="bkng-strong bkng-right">
						{ __( 'Totale', 'booking-manager' ) }:{ ' ' }
						{ money( total, property.currency ) }
					</p>
				</Card>
			) }

			{ rooms.length > 0 && (
				<Card title={ __( '3. Ospite e conferma', 'booking-manager' ) }>
					<form
						onSubmit={ ( e ) => {
							e.preventDefault();
							submit();
						} }
					>
						<ErrorNotice error={ create.error } />
						<Grid>
							<Text
								label={ __( 'Nome', 'booking-manager' ) }
								value={ guest.first_name }
								onChange={ ( v ) =>
									setGuest( { ...guest, first_name: v } )
								}
								required
							/>
							<Text
								label={ __( 'Cognome', 'booking-manager' ) }
								value={ guest.last_name }
								onChange={ ( v ) =>
									setGuest( { ...guest, last_name: v } )
								}
								required
							/>
							<Text
								label={ __( 'Email', 'booking-manager' ) }
								type="email"
								value={ guest.email }
								onChange={ ( v ) =>
									setGuest( { ...guest, email: v } )
								}
							/>
							<Text
								label={ __( 'Telefono', 'booking-manager' ) }
								value={ guest.phone }
								onChange={ ( v ) =>
									setGuest( { ...guest, phone: v } )
								}
							/>
							<Select
								label={ __(
									'Lingua delle email',
									'booking-manager'
								) }
								value={ guest.language }
								options={ [
									{ value: 'it', label: 'Italiano' },
									{ value: 'en', label: 'English' },
									{ value: 'de', label: 'Deutsch' },
									{ value: 'fr', label: 'Français' },
									{ value: 'es', label: 'Español' },
								] }
								onChange={ ( v ) =>
									setGuest( { ...guest, language: v } )
								}
							/>
							<Select
								label={ __( 'Stato', 'booking-manager' ) }
								value={ status }
								options={ [
									{
										value: 'confirmed',
										label: __(
											'Confermata',
											'booking-manager'
										),
									},
									{
										value: 'option',
										label: __(
											'Opzione (scade da sola)',
											'booking-manager'
										),
									},
									{
										value: 'pending',
										label: __(
											'In attesa',
											'booking-manager'
										),
									},
								] }
								onChange={ setStatus }
							/>
						</Grid>
						<div className="bkng-stack">
							<Textarea
								label={ __(
									"Richieste dell'ospite",
									'booking-manager'
								) }
								value={ notes.customer_notes }
								onChange={ ( v ) =>
									setNotes( { ...notes, customer_notes: v } )
								}
							/>
							<Textarea
								label={ __(
									'Note interne',
									'booking-manager'
								) }
								value={ notes.internal_notes }
								onChange={ ( v ) =>
									setNotes( { ...notes, internal_notes: v } )
								}
							/>
						</div>
						<div
							className="bkng-checks"
							style={ { marginTop: 14 } }
						>
							<Check
								label={ __(
									"Invia l'email all'ospite",
									'booking-manager'
								) }
								checked={ notify }
								onChange={ setNotify }
							/>
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
						<Button
							variant="primary"
							type="submit"
							isBusy={ create.busy }
							disabled={ create.busy }
						>
							{ __( 'Crea la prenotazione', 'booking-manager' ) }
						</Button>
					</form>
				</Card>
			) }
		</Page>
	);
}
