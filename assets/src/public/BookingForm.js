/**
 * The booking form: dates and guests → free rooms with their rates → guest details →
 * confirmation (or the payment page). The rooms chosen are held while the guest fills
 * in the details; every price and rule is checked again by the server.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	addDays,
	cancellationText,
	date,
	depositText,
	extraPrice,
	guestsLabel,
	MEAL_PLANS,
	money,
	nightsBetween,
	nightsLabel,
} from './format';
import { Captcha } from './Captcha';
import { Check, ErrorBox, Field, Spinner } from './ui';

function newRequestId() {
	if ( window.crypto?.randomUUID ) {
		return window.crypto.randomUUID();
	}
	return String( Date.now() ) + Math.random().toString( 16 ).slice( 2 );
}

/* ------------------------------------------------------------------ Search */

function SearchBar( { property, stay, setStay, onSearch, busy } ) {
	const nights = nightsBetween( stay.arrival, stay.departure );
	const minDeparture = addDays( stay.arrival || property.first_day, 1 );
	return (
		<form
			className="bkng-p-search"
			onSubmit={ ( e ) => {
				e.preventDefault();
				onSearch();
			} }
		>
			<Field label={ __( 'Arrivo', 'booking-manager' ) }>
				{ ( id ) => (
					<input
						id={ id }
						type="date"
						required
						value={ stay.arrival }
						min={ property.first_day }
						max={ property.last_day }
						onChange={ ( e ) => {
							const arrival = e.target.value;
							const departure =
								stay.departure && stay.departure > arrival
									? stay.departure
									: addDays(
											arrival,
											Math.max(
												1,
												property.min_stay || 1
											)
									  );
							setStay( { ...stay, arrival, departure } );
						} }
					/>
				) }
			</Field>
			<Field label={ __( 'Partenza', 'booking-manager' ) }>
				{ ( id ) => (
					<input
						id={ id }
						type="date"
						required
						value={ stay.departure }
						min={ minDeparture }
						onChange={ ( e ) =>
							setStay( { ...stay, departure: e.target.value } )
						}
					/>
				) }
			</Field>
			<Field label={ __( 'Adulti per camera', 'booking-manager' ) }>
				{ ( id ) => (
					<input
						id={ id }
						type="number"
						min="1"
						max="20"
						required
						value={ stay.adults }
						onChange={ ( e ) =>
							setStay( {
								...stay,
								adults: Math.max(
									1,
									Number( e.target.value ) || 1
								),
							} )
						}
					/>
				) }
			</Field>
			<Field
				label={
					property.children_max_age > 0
						? sprintf(
								/* translators: %d: maximum age of a child. */
								__(
									'Bambini (fino a %d anni)',
									'booking-manager'
								),
								property.children_max_age
						  )
						: __( 'Bambini', 'booking-manager' )
				}
			>
				{ ( id ) => (
					<input
						id={ id }
						type="number"
						min="0"
						max="20"
						value={ stay.children }
						onChange={ ( e ) =>
							setStay( {
								...stay,
								children: Math.max(
									0,
									Number( e.target.value ) || 0
								),
							} )
						}
					/>
				) }
			</Field>
			<div className="bkng-p-search__go">
				<button
					type="submit"
					className="bkng-p-button is-primary wp-element-button"
					disabled={ busy || nights < 1 }
				>
					{ busy ? <Spinner /> : null }
					{ __( 'Cerca disponibilità', 'booking-manager' ) }
				</button>
				{ nights > 0 && (
					<span className="bkng-p-muted">
						{ nightsLabel( nights ) }
					</span>
				) }
			</div>
		</form>
	);
}

/* ------------------------------------------------------------------ Results */

function Gallery( { type } ) {
	const [ index, setIndex ] = useState( 0 );
	const images = type.images || [];
	if ( ! images.length ) {
		return null;
	}
	const image = images[ index ] || images[ 0 ];
	return (
		<div className="bkng-p-gallery">
			<img src={ image.url } alt={ type.name } loading="lazy" />
			{ images.length > 1 && (
				<div className="bkng-p-gallery__nav">
					{ images.map( ( img, i ) => (
						<button
							key={ img.id }
							type="button"
							className={ i === index ? 'is-active' : '' }
							aria-label={ sprintf(
								/* translators: 1: photo number, 2: total photos. */
								__( 'Foto %1$d di %2$d', 'booking-manager' ),
								i + 1,
								images.length
							) }
							onClick={ () => setIndex( i ) }
						/>
					) ) }
				</div>
			) }
		</div>
	);
}

function RoomFacts( { type } ) {
	const facts = [];
	if ( type.max_occupancy ) {
		facts.push(
			sprintf(
				/* translators: %d: people. */
				_n(
					'Fino a %d persona',
					'Fino a %d persone',
					type.max_occupancy,
					'booking-manager'
				),
				type.max_occupancy
			)
		);
	}
	if ( type.size_sqm ) {
		facts.push( type.size_sqm + ' m²' );
	}
	if ( type.beds ) {
		facts.push( type.beds );
	}
	return (
		<>
			{ facts.length > 0 && (
				<p className="bkng-p-muted">{ facts.join( ' · ' ) }</p>
			) }
			{ type.amenities?.length > 0 && (
				<ul className="bkng-p-tags">
					{ type.amenities.map( ( a ) => (
						<li key={ a }>{ a }</li>
					) ) }
				</ul>
			) }
		</>
	);
}

function Offer( { property, type, offer, onChoose, busy } ) {
	const [ quantity, setQuantity ] = useState( 1 );
	const max = Math.max( 1, property.max_rooms || 1 );
	return (
		<article className="bkng-p-card bkng-p-offer">
			<Gallery type={ type } />
			<div className="bkng-p-offer__body">
				<h3 className="bkng-p-offer__title">{ type.name }</h3>
				<RoomFacts type={ type } />
				{ type.description && (
					<p className="bkng-p-offer__text">{ type.description }</p>
				) }
				{ offer.few_left && (
					<p className="bkng-p-alert">
						{ __( 'Ultime camere disponibili', 'booking-manager' ) }
					</p>
				) }
				{ max > 1 && (
					<Field label={ __( 'Camere', 'booking-manager' ) } inline>
						{ ( id ) => (
							<select
								id={ id }
								value={ quantity }
								onChange={ ( e ) =>
									setQuantity( Number( e.target.value ) )
								}
							>
								{ Array.from(
									{ length: max },
									( _, i ) => i + 1
								).map( ( n ) => (
									<option key={ n } value={ n }>
										{ n }
									</option>
								) ) }
							</select>
						) }
					</Field>
				) }
				<ul className="bkng-p-rates">
					{ offer.rates.map( ( rate ) => (
						<li
							key={ String( rate.rate_plan_id ) }
							className="bkng-p-rate"
						>
							<div className="bkng-p-rate__info">
								<strong>{ rate.name }</strong>
								<span className="bkng-p-muted">
									{ [
										MEAL_PLANS[ rate.meal_plan ],
										cancellationText( rate.cancellation ),
										depositText(
											rate.deposit,
											property.currency
										),
									]
										.filter( Boolean )
										.join( ' · ' ) }
								</span>
								{ rate.description && (
									<span className="bkng-p-muted">
										{ rate.description }
									</span>
								) }
							</div>
							<div className="bkng-p-rate__price">
								{ rate.total !== null && (
									<>
										<strong>
											{ money(
												rate.total * quantity,
												property.currency
											) }
										</strong>
										<span className="bkng-p-muted">
											{ nightsLabel( rate.nights ) }
											{ quantity > 1
												? ' · ' +
												  sprintf(
														/* translators: %d: rooms. */
														_n(
															'%d camera',
															'%d camere',
															quantity,
															'booking-manager'
														),
														quantity
												  )
												: '' }
										</span>
									</>
								) }
								<button
									type="button"
									className="bkng-p-button is-primary wp-element-button"
									disabled={ busy }
									onClick={ () =>
										onChoose( type, rate, quantity )
									}
								>
									{ __( 'Scegli', 'booking-manager' ) }
								</button>
							</div>
						</li>
					) ) }
				</ul>
			</div>
		</article>
	);
}

function Extras( { property, extras, chosen, setChosen } ) {
	if ( ! extras.length ) {
		return null;
	}
	return (
		<fieldset className="bkng-p-card bkng-p-extras">
			<legend>{ __( 'Servizi aggiuntivi', 'booking-manager' ) }</legend>
			{ extras.map( ( x ) => (
				<Check
					key={ x.id }
					checked={ chosen.includes( x.id ) }
					onChange={ ( on ) =>
						setChosen(
							on
								? [ ...chosen, x.id ]
								: chosen.filter( ( id ) => id !== x.id )
						)
					}
				>
					<strong>{ x.name }</strong>
					{ extraPrice( x, property.currency ) && (
						<span className="bkng-p-muted">
							{ ' ' }
							· { extraPrice( x, property.currency ) }
						</span>
					) }
					{ x.description && (
						<span className="bkng-p-muted bkng-p-block">
							{ x.description }
						</span>
					) }
				</Check>
			) ) }
		</fieldset>
	);
}

/* ------------------------------------------------------------------ Details */

function Countdown( { expiresAt, onExpire } ) {
	const [ left, setLeft ] = useState(
		() => new Date( expiresAt ) - Date.now()
	);
	useEffect( () => {
		const timer = window.setInterval( () => {
			const ms = new Date( expiresAt ) - Date.now();
			setLeft( ms );
			if ( ms <= 0 ) {
				window.clearInterval( timer );
				onExpire();
			}
		}, 1000 );
		return () => window.clearInterval( timer );
	}, [ expiresAt, onExpire ] );
	const s = Math.max( 0, Math.floor( left / 1000 ) );
	const time =
		Math.floor( s / 60 ) + ':' + String( s % 60 ).padStart( 2, '0' );
	return (
		<p className="bkng-p-note" role="status">
			{ sprintf(
				/* translators: %s: minutes:seconds. */
				__( 'Teniamo le camere per te ancora %s.', 'booking-manager' ),
				time
			) }
		</p>
	);
}

function Summary( { property, stay, choice } ) {
	const total =
		choice.rate.total !== null ? choice.rate.total * choice.quantity : null;
	return (
		<aside className="bkng-p-card bkng-p-summary">
			<h3>{ __( 'Il tuo soggiorno', 'booking-manager' ) }</h3>
			<p>
				<strong>{ property.name }</strong>
			</p>
			<p>
				{ date( stay.arrival ) } → { date( stay.departure ) }
				<br />
				<span className="bkng-p-muted">
					{ nightsLabel(
						nightsBetween( stay.arrival, stay.departure )
					) }{ ' ' }
					· { guestsLabel( stay.adults, stay.children ) }{ ' ' }
					{ __( 'per camera', 'booking-manager' ) }
				</span>
			</p>
			<p>
				{ choice.quantity > 1 ? choice.quantity + ' × ' : '' }
				{ choice.type.name }
				<br />
				<span className="bkng-p-muted">{ choice.rate.name }</span>
			</p>
			{ total !== null && (
				<p className="bkng-p-summary__total">
					<span>{ __( 'Totale', 'booking-manager' ) }</span>
					<strong>{ money( total, property.currency ) }</strong>
				</p>
			) }
			{ ( property.checkin_from || property.checkout_until ) && (
				<p className="bkng-p-muted">
					{ sprintf(
						/* translators: 1: check-in time, 2: check-out time. */
						__(
							'Check-in dalle %1$s · check-out entro le %2$s',
							'booking-manager'
						),
						property.checkin_from.slice( 0, 5 ),
						property.checkout_until.slice( 0, 5 )
					) }
				</p>
			) }
		</aside>
	);
}

function GuestForm( {
	api,
	property,
	stay,
	choice,
	extras,
	hold,
	onBack,
	onDone,
	onExpired,
} ) {
	const [ guest, setGuest ] = useState( {
		first_name: '',
		last_name: '',
		email: '',
		phone: '',
		country: '',
	} );
	const [ notes, setNotes ] = useState( '' );
	const [ arrivalTime, setArrivalTime ] = useState( '' );
	const [ privacy, setPrivacy ] = useState( false );
	const [ marketing, setMarketing ] = useState( false );
	const [ website, setWebsite ] = useState( '' );
	const [ captcha, setCaptcha ] = useState( '' );
	const [ captchaKey, setCaptchaKey ] = useState( 0 );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const requestId = useRef( newRequestId() );
	const set = ( k ) => ( e ) =>
		setGuest( { ...guest, [ k ]: e.target.value } );
	const fieldError = ( k ) =>
		error?.fields?.[ k ] || error?.fields?.[ 'guest.' + k ];

	const submit = async ( e ) => {
		e.preventDefault();
		setBusy( true );
		setError( null );
		try {
			const res = await api.post(
				'properties/' + property.slug + '/bookings',
				{
					arrival: stay.arrival,
					departure: stay.departure,
					rooms: Array.from( { length: choice.quantity }, () => ( {
						room_type_id: choice.type.id,
						rate_plan_id: choice.rate.rate_plan_id,
						adults: stay.adults,
						children: stay.children,
					} ) ),
					extras,
					guest,
					notes,
					arrival_time: arrivalTime || null,
					privacy,
					marketing_consent: marketing,
					website,
					form_token: property.form_token,
					captcha_token: captcha,
					hold_token: hold.token,
					request_id: requestId.current,
				}
			);
			onDone( res );
		} catch ( err ) {
			setError( err );
			setBusy( false );
			if ( property.captcha ) {
				// A Turnstile token is good for one check only.
				setCaptcha( '' );
				setCaptchaKey( ( k ) => k + 1 );
			}
		}
	};

	return (
		<div className="bkng-p-columns">
			<form
				className="bkng-p-card bkng-p-guest"
				onSubmit={ submit }
				noValidate={ false }
			>
				<h3>{ __( 'I tuoi dati', 'booking-manager' ) }</h3>
				<Countdown
					expiresAt={ hold.expires_at }
					onExpire={ onExpired }
				/>
				<ErrorBox error={ error } />
				<div className="bkng-p-grid">
					<Field
						label={ __( 'Nome', 'booking-manager' ) }
						error={ fieldError( 'first_name' ) }
					>
						{ ( id ) => (
							<input
								id={ id }
								required
								autoComplete="given-name"
								value={ guest.first_name }
								onChange={ set( 'first_name' ) }
							/>
						) }
					</Field>
					<Field
						label={ __( 'Cognome', 'booking-manager' ) }
						error={ fieldError( 'last_name' ) }
					>
						{ ( id ) => (
							<input
								id={ id }
								required
								autoComplete="family-name"
								value={ guest.last_name }
								onChange={ set( 'last_name' ) }
							/>
						) }
					</Field>
					<Field
						label={ __( 'Email', 'booking-manager' ) }
						error={ fieldError( 'email' ) }
					>
						{ ( id ) => (
							<input
								id={ id }
								type="email"
								required
								autoComplete="email"
								value={ guest.email }
								onChange={ set( 'email' ) }
							/>
						) }
					</Field>
					<Field
						label={ __( 'Telefono', 'booking-manager' ) }
						error={ fieldError( 'phone' ) }
					>
						{ ( id ) => (
							<input
								id={ id }
								type="tel"
								autoComplete="tel"
								value={ guest.phone }
								onChange={ set( 'phone' ) }
							/>
						) }
					</Field>
					<Field
						label={ __(
							'Paese (sigla, es. IT)',
							'booking-manager'
						) }
						error={ fieldError( 'country' ) }
					>
						{ ( id ) => (
							<input
								id={ id }
								maxLength={ 2 }
								autoComplete="country"
								value={ guest.country }
								onChange={ ( e ) =>
									setGuest( {
										...guest,
										country: e.target.value.toUpperCase(),
									} )
								}
							/>
						) }
					</Field>
					<Field
						label={ __(
							'Orario di arrivo previsto',
							'booking-manager'
						) }
						error={ fieldError( 'arrival_time' ) }
					>
						{ ( id ) => (
							<input
								id={ id }
								type="time"
								value={ arrivalTime }
								onChange={ ( e ) =>
									setArrivalTime( e.target.value )
								}
							/>
						) }
					</Field>
				</div>
				<Field
					label={ __(
						'Richieste o note per la struttura',
						'booking-manager'
					) }
					error={ fieldError( 'customer_notes' ) }
				>
					{ ( id ) => (
						<textarea
							id={ id }
							rows="3"
							maxLength={ 2000 }
							value={ notes }
							onChange={ ( e ) => setNotes( e.target.value ) }
						/>
					) }
				</Field>
				{ /* Left empty by people; bots fill every field. */ }
				<div className="bkng-p-hp" aria-hidden="true">
					<Field label="Website">
						{ ( id ) => (
							<input
								id={ id }
								tabIndex="-1"
								autoComplete="off"
								value={ website }
								onChange={ ( e ) =>
									setWebsite( e.target.value )
								}
							/>
						) }
					</Field>
				</div>
				{ property.terms_text && (
					<div className="bkng-p-terms">
						<strong>
							{ __(
								'Condizioni della struttura',
								'booking-manager'
							) }
						</strong>
						<p>{ property.terms_text }</p>
					</div>
				) }
				<Check required checked={ privacy } onChange={ setPrivacy }>
					{ __(
						'Ho letto l’informativa sulla privacy e accetto il trattamento dei dati per gestire la prenotazione.',
						'booking-manager'
					) }{ ' ' }
					{ property.privacy_url && (
						<a
							href={ property.privacy_url }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __( 'Leggi l’informativa', 'booking-manager' ) }
						</a>
					) }
				</Check>
				{ fieldError( 'privacy_accepted' ) && (
					<p className="bkng-p-field__error">
						{ fieldError( 'privacy_accepted' ) }
					</p>
				) }
				{ property.marketing_consent && (
					<Check checked={ marketing } onChange={ setMarketing }>
						{ __(
							'Voglio ricevere offerte e novità dalla struttura (facoltativo).',
							'booking-manager'
						) }
					</Check>
				) }
				{ property.captcha && (
					<Captcha
						key={ captchaKey }
						config={ property.captcha }
						onToken={ setCaptcha }
					/>
				) }
				{ fieldError( 'captcha' ) && (
					<p className="bkng-p-field__error">
						{ fieldError( 'captcha' ) }
					</p>
				) }
				<div className="bkng-p-actions">
					<button
						type="button"
						className="bkng-p-button"
						onClick={ onBack }
						disabled={ busy }
					>
						{ __( 'Indietro', 'booking-manager' ) }
					</button>
					<button
						type="submit"
						className="bkng-p-button is-primary wp-element-button"
						disabled={
							busy ||
							! privacy ||
							( property.captcha && ! captcha )
						}
					>
						{ busy ? <Spinner /> : null }
						{ property.request_only
							? __( 'Invia la richiesta', 'booking-manager' )
							: __( 'Prenota', 'booking-manager' ) }
					</button>
				</div>
				{ property.request_only && (
					<p className="bkng-p-muted">
						{ __(
							'La struttura controlla la richiesta e ti conferma la prenotazione via email.',
							'booking-manager'
						) }
					</p>
				) }
			</form>
			<Summary property={ property } stay={ stay } choice={ choice } />
		</div>
	);
}

/* ------------------------------------------------------------------ Done */

function Done( { property, result } ) {
	const b = result.booking;
	const paying = !! result.payment?.checkout_url;
	useEffect( () => {
		if ( paying ) {
			window.location.assign( result.payment.checkout_url );
		}
	}, [ paying, result ] );
	if ( paying ) {
		return (
			<div className="bkng-p-card bkng-p-done" role="status">
				<Spinner />
				<p>
					{ __(
						'Ti stiamo portando alla pagina di pagamento sicura…',
						'booking-manager'
					) }
				</p>
				<p>
					<a href={ result.payment.checkout_url }>
						{ __(
							'Se non si apre, clicca qui.',
							'booking-manager'
						) }
					</a>
				</p>
			</div>
		);
	}
	const confirmed = b.status === 'confirmed';
	return (
		<div className="bkng-p-card bkng-p-done" role="status" tabIndex="-1">
			<h3>
				{ confirmed
					? __( 'Prenotazione confermata', 'booking-manager' )
					: __( 'Richiesta inviata', 'booking-manager' ) }
			</h3>
			<p>
				{ confirmed
					? sprintf(
							/* translators: %s: guest's first name. */
							__(
								'Grazie %s, ti aspettiamo! Ti abbiamo inviato una email con i dettagli.',
								'booking-manager'
							),
							b.first_name
					  )
					: sprintf(
							/* translators: %s: guest's first name. */
							__(
								'Grazie %s! La struttura controlla la disponibilità e ti risponde via email.',
								'booking-manager'
							),
							b.first_name
					  ) }
			</p>
			<dl className="bkng-p-facts">
				<dt>{ __( 'Codice', 'booking-manager' ) }</dt>
				<dd>
					<strong>{ b.code }</strong>
				</dd>
				<dt>{ __( 'Soggiorno', 'booking-manager' ) }</dt>
				<dd>
					{ date( b.arrival ) } → { date( b.departure ) }
				</dd>
				{ b.total > 0 && (
					<>
						<dt>{ __( 'Totale', 'booking-manager' ) }</dt>
						<dd>{ money( b.total, b.currency ) }</dd>
					</>
				) }
			</dl>
			{ result.manage_url && (
				<p>
					<a className="bkng-p-button" href={ result.manage_url }>
						{ __(
							'Vedi o gestisci la prenotazione',
							'booking-manager'
						) }
					</a>
				</p>
			) }
			<p className="bkng-p-muted">{ property.name }</p>
		</div>
	);
}

/* ------------------------------------------------------------------ Form */

export default function BookingForm( { api, slug } ) {
	const [ property, setProperty ] = useState( null );
	const [ loadError, setLoadError ] = useState( null );
	const [ stay, setStay ] = useState( {
		arrival: '',
		departure: '',
		adults: 2,
		children: 0,
	} );
	const [ results, setResults ] = useState( null );
	const [ extras, setExtras ] = useState( [] );
	const [ searching, setSearching ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ choice, setChoice ] = useState( null );
	const [ hold, setHold ] = useState( null );
	const [ holding, setHolding ] = useState( false );
	const [ done, setDone ] = useState( null );
	const [ notice, setNotice ] = useState( '' );
	const top = useRef( null );

	useEffect( () => {
		api.get( 'properties/' + slug )
			.then( ( p ) => {
				setProperty( p );
				setStay( ( s ) => ( {
					...s,
					arrival: p.first_day,
					departure: addDays(
						p.first_day,
						Math.max( 1, p.min_stay || 1 )
					),
				} ) );
			} )
			.catch( setLoadError );
	}, [ api, slug ] );

	// Rooms held and the page closed: they go back on sale at once.
	useEffect( () => {
		if ( ! hold || done ) {
			return undefined;
		}
		const leave = () =>
			api.beacon( 'holds/release', { token: hold.token } );
		window.addEventListener( 'pagehide', leave );
		return () => window.removeEventListener( 'pagehide', leave );
	}, [ api, hold, done ] );

	const scrollTop = () =>
		top.current?.scrollIntoView( { behavior: 'smooth', block: 'start' } );

	const search = async ( chosenExtras = extras ) => {
		setSearching( true );
		setError( null );
		setNotice( '' );
		try {
			setResults(
				await api.get( 'properties/' + slug + '/search', {
					arrival: stay.arrival,
					departure: stay.departure,
					adults: stay.adults,
					children: stay.children,
					extras: chosenExtras,
				} )
			);
		} catch ( err ) {
			setError( err );
			setResults( null );
		}
		setSearching( false );
	};

	const changeExtras = ( next ) => {
		setExtras( next );
		if ( results ) {
			search( next );
		}
	};

	const choose = async ( type, rate, quantity ) => {
		setHolding( true );
		setError( null );
		try {
			const h = await api.post( 'properties/' + slug + '/holds', {
				room_type_id: type.id,
				arrival: stay.arrival,
				departure: stay.departure,
				quantity,
			} );
			setChoice( { type, rate, quantity } );
			setHold( h );
			scrollTop();
		} catch ( err ) {
			setError( err );
		}
		setHolding( false );
	};

	const back = () => {
		if ( hold ) {
			api.post( 'holds/release', { token: hold.token } ).catch(
				() => {}
			);
		}
		setHold( null );
		setChoice( null );
		scrollTop();
	};

	const expired = () => {
		setHold( null );
		setChoice( null );
		setNotice(
			__(
				'Il tempo per completare la prenotazione è scaduto: controlla di nuovo la disponibilità.',
				'booking-manager'
			)
		);
		search();
	};

	if ( loadError ) {
		return <ErrorBox error={ loadError } />;
	}
	if ( ! property ) {
		return (
			<p className="bkng-p-muted">
				<Spinner /> { __( 'Caricamento…', 'booking-manager' ) }
			</p>
		);
	}
	if ( ! property.available ) {
		return (
			<div className="bkng-p-card">
				<h3>{ property.name }</h3>
				<p>
					{ __(
						'Le prenotazioni online non sono disponibili in questo momento.',
						'booking-manager'
					) }
				</p>
				<Contacts property={ property } />
			</div>
		);
	}
	if ( done ) {
		return (
			<div ref={ top } className="bkng-p-stack">
				<Done property={ property } result={ done } />
			</div>
		);
	}

	const typeOf = ( id ) => property.room_types.find( ( t ) => t.id === id );

	return (
		<div ref={ top } className="bkng-p-stack">
			{ choice && hold ? (
				<GuestForm
					api={ api }
					property={ property }
					stay={ stay }
					choice={ choice }
					extras={ extras }
					hold={ hold }
					onBack={ back }
					onExpired={ expired }
					onDone={ ( res ) => {
						setDone( res );
						scrollTop();
					} }
				/>
			) : (
				<>
					<SearchBar
						property={ property }
						stay={ stay }
						setStay={ setStay }
						onSearch={ () => search() }
						busy={ searching }
					/>
					{ notice && <p className="bkng-p-note">{ notice }</p> }
					<ErrorBox error={ error } />
					{ results && (
						<>
							<Extras
								property={ property }
								extras={ property.extras || [] }
								chosen={ extras }
								setChosen={ changeExtras }
							/>
							{ results.offers.length === 0 ? (
								<div className="bkng-p-card">
									<p>
										<strong>
											{ __(
												'Nessuna camera disponibile per queste date.',
												'booking-manager'
											) }
										</strong>
									</p>
									{ results.rules.length > 0 && (
										<ul>
											{ results.rules.map( ( r ) => (
												<li key={ r }>{ r }</li>
											) ) }
										</ul>
									) }
									<p className="bkng-p-muted">
										{ __(
											'Prova a cambiare le date o il numero di ospiti.',
											'booking-manager'
										) }
									</p>
									<Contacts property={ property } />
								</div>
							) : (
								<div
									className="bkng-p-offers"
									aria-busy={ searching }
								>
									{ results.offers.map( ( offer ) => {
										const type = typeOf(
											offer.room_type_id
										);
										return type ? (
											<Offer
												key={ offer.room_type_id }
												property={ property }
												type={ type }
												offer={ offer }
												onChoose={ choose }
												busy={ holding }
											/>
										) : null;
									} ) }
								</div>
							) }
						</>
					) }
				</>
			) }
		</div>
	);
}

export function Contacts( { property } ) {
	if ( ! property.phone && ! property.email ) {
		return null;
	}
	return (
		<p className="bkng-p-muted">
			{ __( 'Contatti:', 'booking-manager' ) }{ ' ' }
			{ property.phone && (
				<a href={ 'tel:' + property.phone.replace( /\s+/g, '' ) }>
					{ property.phone }
				</a>
			) }
			{ property.phone && property.email ? ' · ' : '' }
			{ property.email && (
				<a href={ 'mailto:' + property.email }>{ property.email }</a>
			) }
		</p>
	);
}
