/**
 * The booking form: dates and guests → free rooms with their rates → guest details →
 * confirmation (or the payment page). The rooms chosen are held while the guest fills
 * in the details; every price and rule is checked again by the server.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	addDays,
	applyColor,
	date,
	guestsLabel,
	money,
	nightsBetween,
	nightsLabel,
} from './format';
import Business, { applyFont } from './Business';
import { Captcha } from './Captcha';
import { isFramed } from './frame';
import { comboTitle, Combos } from './Combos';
import { Hero, Results, SearchBar } from './Results';
import { Icon } from './icons';
import {
	CalendarLink,
	LONG,
	StayFooter,
	StayHead,
	StaySummary,
	stayItems,
} from './StayCard';
import { Check, ErrorBox, Field, Spinner } from './ui';

function newRequestId() {
	if ( window.crypto?.randomUUID ) {
		return window.crypto.randomUUID();
	}
	return String( Date.now() ) + Math.random().toString( 16 ).slice( 2 );
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
	let total = null;
	if ( choice.rate.total !== null ) {
		// A combination's rate already adds up all its rooms.
		total = choice.combo
			? choice.rate.total
			: choice.rate.total * choice.quantity;
	}
	const adults = choice.lines.reduce( ( n, l ) => n + l.adults, 0 );
	const children = choice.lines.reduce( ( n, l ) => n + l.children, 0 );
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
					·{ ' ' }
					{ choice.combo
						? guestsLabel( adults, children )
						: guestsLabel( stay.adults, stay.children ) +
						  ' ' +
						  __( 'per camera', 'booking-manager' ) }
				</span>
			</p>
			{ choice.combo ? (
				<>
					<p>
						{ comboTitle( choice.lines ) }
						<br />
						<span className="bkng-p-muted">
							{ choice.rate.name }
						</span>
					</p>
					<ul className="bkng-p-summary__extras">
						{ choice.lines.map( ( l, i ) => (
							<li key={ i }>
								{ l.type.name }:{ ' ' }
								{ guestsLabel( l.adults, l.children ) }
							</li>
						) ) }
					</ul>
				</>
			) : (
				<p>
					{ choice.quantity > 1 ? choice.quantity + ' × ' : '' }
					{ choice.type.name }
					<br />
					<span className="bkng-p-muted">{ choice.rate.name }</span>
				</p>
			) }
			{ choice.extras?.length > 0 && (
				<ul className="bkng-p-summary__extras">
					{ ( property.extras || [] )
						.filter( ( x ) => choice.extras.includes( x.id ) )
						.map( ( x ) => (
							<li key={ x.id }>+ { x.name }</li>
						) ) }
				</ul>
			) }
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
	const [ noWhatsApp, setNoWhatsApp ] = useState( false );
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
					rooms: choice.lines.map( ( l ) => ( {
						room_type_id: l.type.id,
						rate_plan_id: choice.rate.rate_plan_id,
						adults: l.adults,
						children: l.children,
					} ) ),
					extras,
					guest,
					notes,
					arrival_time: arrivalTime || null,
					privacy,
					marketing_consent: marketing,
					whatsapp_opt_out: property.whatsapp
						? noWhatsApp
						: undefined,
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
						label={
							property.whatsapp
								? __( 'Cellulare', 'booking-manager' )
								: __( 'Telefono', 'booking-manager' )
						}
						error={ fieldError( 'phone' ) }
					>
						{ ( id ) => (
							<input
								id={ id }
								type="tel"
								required={ !! property.whatsapp }
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
				{ property.whatsapp && (
					<Check checked={ noWhatsApp } onChange={ setNoWhatsApp }>
						{ __(
							'Non voglio ricevere messaggi WhatsApp sulla prenotazione.',
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
						className="bkng-p-button is-primary"
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
	// In the client's iframe the payment opens in the whole window, on the guest's click.
	const framed = isFramed();
	useEffect( () => {
		if ( paying && ! framed ) {
			window.location.assign( result.payment.checkout_url );
		}
	}, [ paying, framed, result ] );
	if ( paying && framed ) {
		return (
			<div className="bkng-p-card bkng-p-done" role="status">
				<p>
					{ __(
						'Per completare la prenotazione manca solo il pagamento.',
						'booking-manager'
					) }
				</p>
				<p>
					<a
						className="bkng-p-button is-primary"
						href={ result.payment.checkout_url }
						target="_top"
						rel="noopener"
					>
						{ __( 'Vai al pagamento sicuro', 'booking-manager' ) }
					</a>
				</p>
			</div>
		);
	}
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
			<StayHead
				title={
					confirmed
						? __( 'Prenotazione confermata', 'booking-manager' )
						: __(
								'Abbiamo ricevuto la tua richiesta',
								'booking-manager'
						  )
				}
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
				{ confirmed
					? sprintf(
							/* translators: 1: arrival date, 2: departure date. */
							__(
								'il tuo soggiorno dal %1$s al %2$s è confermato. Ti abbiamo inviato una email con tutti i dettagli: ti aspettiamo!',
								'booking-manager'
							),
							date( b.arrival, LONG ),
							date( b.departure, LONG )
					  )
					: sprintf(
							/* translators: 1: arrival date, 2: departure date. */
							__(
								'la tua richiesta per il soggiorno dal %1$s al %2$s è arrivata. La controlliamo e ti scriviamo appena è confermata.',
								'booking-manager'
							),
							date( b.arrival, LONG ),
							date( b.departure, LONG )
					  ) }
			</p>
			<StaySummary items={ stayItems( b ) } />
			<CalendarLink file={ b.calendar } />
			{ result.manage_url && (
				<p className="bkng-p-done__go">
					<a
						className="bkng-p-button is-primary"
						href={ result.manage_url }
					>
						{ __(
							'Vedi o gestisci la prenotazione',
							'booking-manager'
						) }
						<Icon name="arrowRight" size={ 18 } />
					</a>
				</p>
			) }
			<StayFooter
				name={ property.name }
				where={ b.property }
				signoff={ property.signoff }
			/>
		</div>
	);
}

/* ------------------------------------------------------------------ Form */

export default function BookingForm( { api, slug, hero = false, root } ) {
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
				applyColor( root, p.color );
				applyFont( root, p.business );
				setProperty( p );
				const first = p.first_free || p.first_day;
				setStay( ( s ) => ( {
					...s,
					arrival: first,
					departure: addDays( first, Math.max( 1, p.min_stay || 1 ) ),
				} ) );
			} )
			.catch( setLoadError );
	}, [ api, slug, root ] );

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

	const search = async () => {
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
				} )
			);
		} catch ( err ) {
			setError( err );
			setResults( null );
		}
		setSearching( false );
	};

	// The room sheet asks the price again with the options the guest ticks.
	const quote = useCallback(
		( chosen ) =>
			api.get( 'properties/' + slug + '/search', {
				arrival: results.arrival,
				departure: results.departure,
				adults: results.adults,
				children: results.children,
				extras: chosen,
			} ),
		[ api, slug, results ]
	);

	const choose = async ( type, rate, quantity, chosen = [] ) => {
		setHolding( true );
		setError( null );
		try {
			const h = await api.post( 'properties/' + slug + '/holds', {
				room_type_id: type.id,
				arrival: stay.arrival,
				departure: stay.departure,
				quantity,
			} );
			setExtras( chosen );
			setChoice( {
				type,
				rate,
				quantity,
				extras: chosen,
				lines: Array.from( { length: quantity }, () => ( {
					type,
					adults: stay.adults,
					children: stay.children,
				} ) ),
			} );
			setHold( h );
			setHolding( false );
			scrollTop();
			return true;
		} catch ( err ) {
			setError( err );
		}
		setHolding( false );
		return false;
	};

	// Rooms of different types together (2+2 for four, 3+2 for five…): one hold for all.
	const chooseCombo = async ( lines, rate ) => {
		setHolding( true );
		setError( null );
		const counts = {};
		lines.forEach( ( l ) => {
			counts[ l.type.id ] = ( counts[ l.type.id ] || 0 ) + 1;
		} );
		try {
			const h = await api.post( 'properties/' + slug + '/holds', {
				arrival: results.arrival,
				departure: results.departure,
				rooms: Object.keys( counts ).map( ( id ) => ( {
					room_type_id: Number( id ),
					quantity: counts[ id ],
				} ) ),
			} );
			setExtras( [] );
			// The rooms were split for the dates and guests of the search.
			setStay( ( st ) => ( {
				...st,
				arrival: results.arrival,
				departure: results.departure,
				adults: results.adults,
				children: results.children,
			} ) );
			setChoice( {
				combo: true,
				rate,
				quantity: lines.length,
				extras: [],
				lines,
			} );
			setHold( h );
			scrollTop();
		} catch ( err ) {
			// Taken in the meantime: show what is free now, with the reason.
			await search();
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
				{ property.business?.footer ? (
					<Business place={ property } />
				) : (
					<Contacts property={ property } />
				) }
			</div>
		);
	}
	if ( done ) {
		return (
			<div ref={ top } className="bkng-p-stack">
				<Done property={ property } result={ done } />
				<Business
					place={ property }
					code={ done.booking?.code || '' }
				/>
			</div>
		);
	}

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
					{ hero && <Hero property={ property } /> }
					<SearchBar
						property={ property }
						stay={ stay }
						setStay={ setStay }
						onSearch={ () => search() }
						busy={ searching }
						searched={ !! results }
					/>
					{ notice && <p className="bkng-p-note">{ notice }</p> }
					<ErrorBox error={ error } />
					{ ! results && ! searching && (
						<p className="bkng-p-hint">
							{ __(
								'Scegli le date e il numero di ospiti, poi cerca: vedrai le camere libere e potrai filtrarle.',
								'booking-manager'
							) }
						</p>
					) }
					{ results && (
						<Results
							property={ property }
							stay={ results }
							results={ results }
							onChoose={ choose }
							busy={ holding }
							searching={ searching }
							quote={ quote }
							empty={
								results.combos?.length ? (
									<Combos
										property={ property }
										stay={ results }
										results={ results }
										onChoose={ chooseCombo }
										busy={ holding }
									>
										<Contacts property={ property } />
									</Combos>
								) : (
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
								)
							}
						/>
					) }
				</>
			) }
			<Business place={ property } />
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
