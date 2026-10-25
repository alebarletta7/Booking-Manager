/**
 * The search page of the public form: banner, search bar (dates and guests), filters,
 * sorting and one card per room type with its photos. Filters and sorting work on the
 * result already loaded: changing them asks nothing to the server.
 */
import {
	createPortal,
	useEffect,
	useId,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	addDays,
	cancellationText,
	date,
	depositText,
	MEAL_PLANS,
	money,
	nightsBetween,
	nightsLabel,
} from './format';
import { amenityIcon, Icon } from './icons';
import { Spinner } from './ui';

const SHORT = { day: 'numeric', month: 'short' };
const MAX_GUESTS = 20;

/* ------------------------------------------------------------------ Banner */

export function Hero( { property } ) {
	const image = property.room_types.find( ( t ) => t.images?.length )
		?.images[ 0 ];
	return (
		<header
			className={ 'bkng-p-hero' + ( image ? ' has-image' : '' ) }
			style={
				image
					? { backgroundImage: 'url(' + image.url + ')' }
					: undefined
			}
		>
			<div className="bkng-p-hero__text">
				<p className="bkng-p-hero__eyebrow">{ property.name }</p>
				<h2 className="bkng-p-hero__title">
					{ __( 'Seleziona la tua camera', 'booking-manager' ) }
				</h2>
				<p className="bkng-p-hero__lead">
					{ __(
						'Scegli le date, guarda le camere disponibili e completa la prenotazione.',
						'booking-manager'
					) }
				</p>
			</div>
		</header>
	);
}

/* ------------------------------------------------------------------ Search bar */

function Stepper( { label, hint, value, min, max, onChange } ) {
	return (
		<div className="bkng-p-stepper">
			<span className="bkng-p-stepper__label">
				{ label }
				{ hint && <span className="bkng-p-muted">{ hint }</span> }
			</span>
			<span className="bkng-p-stepper__controls">
				<button
					type="button"
					disabled={ value <= min }
					onClick={ () => onChange( value - 1 ) }
					aria-label={ sprintf(
						/* translators: %s: adults or children. */
						__( 'Meno: %s', 'booking-manager' ),
						label
					) }
				>
					<Icon name="minus" size={ 16 } />
				</button>
				<output aria-live="polite">{ value }</output>
				<button
					type="button"
					disabled={ value >= max }
					onClick={ () => onChange( value + 1 ) }
					aria-label={ sprintf(
						/* translators: %s: adults or children. */
						__( 'Più: %s', 'booking-manager' ),
						label
					) }
				>
					<Icon name="plus" size={ 16 } />
				</button>
			</span>
		</div>
	);
}

function Guests( { property, stay, setStay } ) {
	const [ open, setOpen ] = useState( false );
	const box = useRef( null );
	useEffect( () => {
		if ( ! open ) {
			return undefined;
		}
		const outside = ( e ) => {
			if ( box.current && ! box.current.contains( e.target ) ) {
				setOpen( false );
			}
		};
		const key = ( e ) => e.key === 'Escape' && setOpen( false );
		document.addEventListener( 'mousedown', outside );
		document.addEventListener( 'keydown', key );
		return () => {
			document.removeEventListener( 'mousedown', outside );
			document.removeEventListener( 'keydown', key );
		};
	}, [ open ] );
	const summary = [
		sprintf(
			/* translators: %d: adults. */
			_n( '%d adulto', '%d adulti', stay.adults, 'booking-manager' ),
			stay.adults
		),
		stay.children > 0
			? sprintf(
					/* translators: %d: children. */
					_n(
						'%d bambino',
						'%d bambini',
						stay.children,
						'booking-manager'
					),
					stay.children
			  )
			: '',
	]
		.filter( Boolean )
		.join( ', ' );
	return (
		<div className="bkng-p-searchbar__field bkng-p-guests" ref={ box }>
			<Icon name="user" size={ 22 } />
			<button
				type="button"
				className="bkng-p-guests__toggle"
				aria-expanded={ open }
				onClick={ () => setOpen( ! open ) }
			>
				<span className="bkng-p-searchbar__label">
					{ __( 'Ospiti per camera', 'booking-manager' ) }
				</span>
				<span className="bkng-p-searchbar__value">{ summary }</span>
				<Icon name="chevronDown" size={ 18 } />
			</button>
			{ open && (
				<div className="bkng-p-guests__panel">
					<Stepper
						label={ __( 'Adulti', 'booking-manager' ) }
						value={ stay.adults }
						min={ 1 }
						max={ MAX_GUESTS }
						onChange={ ( adults ) =>
							setStay( { ...stay, adults } )
						}
					/>
					<Stepper
						label={ __( 'Bambini', 'booking-manager' ) }
						hint={
							property.children_max_age > 0
								? sprintf(
										/* translators: %d: maximum age of a child. */
										__(
											'fino a %d anni',
											'booking-manager'
										),
										property.children_max_age
								  )
								: ''
						}
						value={ stay.children }
						min={ 0 }
						max={ MAX_GUESTS }
						onChange={ ( children ) =>
							setStay( { ...stay, children } )
						}
					/>
					<button
						type="button"
						className="bkng-p-button"
						onClick={ () => setOpen( false ) }
					>
						{ __( 'Fatto', 'booking-manager' ) }
					</button>
				</div>
			) }
		</div>
	);
}

function DateBox( { label, value, min, max, onChange } ) {
	const id = 'bkng-p-d-' + useId();
	return (
		<div className="bkng-p-searchbar__field">
			<Icon name="calendar" size={ 22 } />
			<span className="bkng-p-searchbar__stack">
				<label className="bkng-p-searchbar__label" htmlFor={ id }>
					{ label }
				</label>
				<input
					id={ id }
					type="date"
					required
					value={ value }
					min={ min }
					max={ max }
					onChange={ ( e ) => onChange( e.target.value ) }
				/>
			</span>
		</div>
	);
}

export function SearchBar( {
	property,
	stay,
	setStay,
	onSearch,
	busy,
	searched,
} ) {
	const nights = nightsBetween( stay.arrival, stay.departure );
	return (
		<form
			className="bkng-p-searchbar"
			onSubmit={ ( e ) => {
				e.preventDefault();
				onSearch();
			} }
		>
			<div className="bkng-p-searchbar__fields">
				<DateBox
					label={ __( 'Check-in', 'booking-manager' ) }
					value={ stay.arrival }
					min={ property.first_day }
					max={ property.last_day }
					onChange={ ( arrival ) => {
						if ( ! arrival ) {
							return;
						}
						const departure =
							stay.departure && stay.departure > arrival
								? stay.departure
								: addDays(
										arrival,
										Math.max( 1, property.min_stay || 1 )
								  );
						setStay( { ...stay, arrival, departure } );
					} }
				/>
				<DateBox
					label={ __( 'Check-out', 'booking-manager' ) }
					value={ stay.departure }
					min={ addDays( stay.arrival || property.first_day, 1 ) }
					onChange={ ( departure ) =>
						departure && setStay( { ...stay, departure } )
					}
				/>
				<Guests
					property={ property }
					stay={ stay }
					setStay={ setStay }
				/>
			</div>
			<div className="bkng-p-searchbar__go">
				<button
					type="submit"
					className="bkng-p-button is-primary"
					disabled={ busy || nights < 1 }
				>
					{ busy ? (
						<Spinner />
					) : (
						<Icon name={ searched ? 'refresh' : 'search' } />
					) }
					{ searched
						? __( 'Aggiorna disponibilità', 'booking-manager' )
						: __( 'Cerca disponibilità', 'booking-manager' ) }
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

/* ------------------------------------------------------------------ Photos */

function Lightbox( { images, start, title, onClose } ) {
	const [ index, setIndex ] = useState( start );
	const closeRef = useRef( null );
	const count = images.length;
	const go = ( step ) => setIndex( ( i ) => ( i + step + count ) % count );
	useEffect( () => {
		const before = closeRef.current?.ownerDocument.activeElement;
		closeRef.current?.focus();
		const key = ( e ) => {
			if ( e.key === 'Escape' ) {
				onClose();
			} else if ( e.key === 'ArrowRight' ) {
				setIndex( ( i ) => ( i + 1 ) % count );
			} else if ( e.key === 'ArrowLeft' ) {
				setIndex( ( i ) => ( i - 1 + count ) % count );
			}
		};
		document.addEventListener( 'keydown', key );
		const overflow = document.body.style.overflow;
		document.body.style.overflow = 'hidden';
		return () => {
			document.removeEventListener( 'keydown', key );
			document.body.style.overflow = overflow;
			before?.focus?.();
		};
	}, [ count, onClose ] );
	const image = images[ index ];
	// On the page body: a card with container queries would trap a fixed element.
	return createPortal(
		<div
			className="bkng-p-lightbox"
			role="dialog"
			aria-modal="true"
			aria-label={ title }
		>
			<button
				type="button"
				className="bkng-p-lightbox__backdrop"
				tabIndex="-1"
				aria-hidden="true"
				onClick={ onClose }
			/>
			<figure className="bkng-p-lightbox__figure">
				<img src={ image.url } alt={ image.alt || title } />
				<figcaption>
					{ title } · { index + 1 } / { count }
				</figcaption>
			</figure>
			<button
				ref={ closeRef }
				type="button"
				className="bkng-p-lightbox__close"
				onClick={ onClose }
				aria-label={ __( 'Chiudi le foto', 'booking-manager' ) }
			>
				<Icon name="close" size={ 24 } />
			</button>
			{ count > 1 && (
				<>
					<button
						type="button"
						className="bkng-p-lightbox__prev"
						onClick={ () => go( -1 ) }
						aria-label={ __(
							'Foto precedente',
							'booking-manager'
						) }
					>
						<Icon name="chevronLeft" size={ 28 } />
					</button>
					<button
						type="button"
						className="bkng-p-lightbox__next"
						onClick={ () => go( 1 ) }
						aria-label={ __(
							'Foto successiva',
							'booking-manager'
						) }
					>
						<Icon name="chevronRight" size={ 28 } />
					</button>
				</>
			) }
		</div>,
		document.body
	);
}

const THUMBS = 3;

function Photos( { type } ) {
	const images = type.images || [];
	const [ index, setIndex ] = useState( 0 );
	const [ zoom, setZoom ] = useState( null );
	if ( ! images.length ) {
		return (
			<div className="bkng-p-photos is-empty" aria-hidden="true">
				<Icon name="bed" size={ 40 } />
			</div>
		);
	}
	const main = images[ index ] || images[ 0 ];
	const thumbs = images.slice( 1, 1 + THUMBS );
	const more = images.length - 1 - THUMBS;
	return (
		<div
			className={
				'bkng-p-photos' + ( thumbs.length ? ' has-thumbs' : '' )
			}
		>
			<button
				type="button"
				className="bkng-p-photos__main"
				onClick={ () => setZoom( index ) }
				aria-label={ sprintf(
					/* translators: %s: room type. */
					__( 'Ingrandisci le foto di %s', 'booking-manager' ),
					type.name
				) }
			>
				<img
					src={ main.url }
					alt={ main.alt || type.name }
					loading="lazy"
				/>
				{ images.length > 1 && (
					<span className="bkng-p-photos__count">
						{ index + 1 } / { images.length }
					</span>
				) }
			</button>
			{ thumbs.length > 0 && (
				<div className="bkng-p-photos__thumbs">
					{ thumbs.map( ( img, i ) => {
						const at = i + 1;
						const last = i === thumbs.length - 1 && more > 0;
						return (
							<button
								type="button"
								key={ img.id }
								className={ at === index ? 'is-active' : '' }
								onClick={ () =>
									last ? setZoom( at ) : setIndex( at )
								}
								aria-label={
									last
										? sprintf(
												/* translators: %d: number of other photos. */
												_n(
													'Vedi tutte le foto (altra %d)',
													'Vedi tutte le foto (altre %d)',
													more,
													'booking-manager'
												),
												more
										  )
										: sprintf(
												/* translators: 1: photo number, 2: total photos. */
												__(
													'Foto %1$d di %2$d',
													'booking-manager'
												),
												at + 1,
												images.length
										  )
								}
							>
								<img
									src={ img.thumb || img.url }
									alt=""
									loading="lazy"
								/>
								{ last && (
									<span className="bkng-p-photos__more">
										+{ more }
									</span>
								) }
							</button>
						);
					} ) }
				</div>
			) }
			{ zoom !== null && (
				<Lightbox
					images={ images }
					start={ zoom }
					title={ type.name }
					onClose={ () => setZoom( null ) }
				/>
			) }
		</div>
	);
}

/* ------------------------------------------------------------------ Room card */

function Facts( { type } ) {
	return (
		<ul className="bkng-p-facts-row">
			{ type.max_occupancy > 0 && (
				<li>
					<Icon name="user" />
					{ sprintf(
						/* translators: %d: guests. */
						_n(
							'%d ospite',
							'%d ospiti',
							type.max_occupancy,
							'booking-manager'
						),
						type.max_occupancy
					) }
				</li>
			) }
			{ type.beds && (
				<li>
					<Icon name="bed" />
					{ type.beds }
				</li>
			) }
			{ type.size_sqm > 0 && (
				<li>
					<Icon name="area" />
					{ type.size_sqm } m²
				</li>
			) }
		</ul>
	);
}

function Amenities( { list } ) {
	if ( ! list?.length ) {
		return null;
	}
	return (
		<ul className="bkng-p-amenities">
			{ list.map( ( a ) => (
				<li key={ a }>
					<Icon name={ amenityIcon( a ) } />
					{ a }
				</li>
			) ) }
		</ul>
	);
}

function Rates( { property, item, onChoose, busy } ) {
	const [ quantity, setQuantity ] = useState( 1 );
	const qtyId = 'bkng-p-q-' + useId();
	const max = Math.max( 1, property.max_rooms || 1 );
	return (
		<div className="bkng-p-room__rates">
			{ max > 1 && (
				<div className="bkng-p-room__qty">
					<label htmlFor={ qtyId }>
						{ __( 'Camere', 'booking-manager' ) }
					</label>
					<select
						id={ qtyId }
						value={ quantity }
						onChange={ ( e ) =>
							setQuantity( Number( e.target.value ) )
						}
					>
						{ Array.from( { length: max }, ( _, i ) => i + 1 ).map(
							( n ) => (
								<option key={ n } value={ n }>
									{ n }
								</option>
							)
						) }
					</select>
				</div>
			) }
			<ul>
				{ item.rates.map( ( rate ) => (
					<li key={ String( rate.rate_plan_id ) }>
						<div className="bkng-p-room__rate-info">
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
						<div className="bkng-p-room__rate-go">
							{ rate.total !== null && (
								<strong>
									{ money(
										rate.total * quantity,
										property.currency
									) }
								</strong>
							) }
							<button
								type="button"
								className="bkng-p-button is-primary"
								disabled={ busy }
								onClick={ () =>
									onChoose( item.type, rate, quantity )
								}
							>
								{ __( 'Prenota', 'booking-manager' ) }
							</button>
						</div>
					</li>
				) ) }
			</ul>
		</div>
	);
}

function RoomCard( { property, stay, item, onChoose, busy } ) {
	const [ open, setOpen ] = useState( false );
	const { type } = item;
	const nights = nightsBetween( stay.arrival, stay.departure );
	const direct = item.rates.length === 1 && ( property.max_rooms || 1 ) <= 1;
	const select = () => {
		if ( direct ) {
			onChoose( type, item.rates[ 0 ], 1 );
		} else {
			setOpen( ! open );
		}
	};
	return (
		<article
			className={
				'bkng-p-room' +
				( item.available ? '' : ' is-unavailable' ) +
				( open ? ' is-open' : '' )
			}
		>
			<div className="bkng-p-room__grid">
				<Photos type={ type } />
				<div className="bkng-p-room__body">
					<h3 className="bkng-p-room__title">{ type.name }</h3>
					<Facts type={ type } />
					{ type.description && (
						<p className="bkng-p-room__text">
							{ type.description }
						</p>
					) }
					<Amenities list={ type.amenities } />
				</div>
				<div className="bkng-p-room__side">
					{ item.available ? (
						<>
							<div className="bkng-p-room__badges">
								{ item.offer.few_left && (
									<span className="bkng-p-pill is-warn">
										{ __(
											'Ultime camere',
											'booking-manager'
										) }
									</span>
								) }
								{ item.freeCancel && (
									<span className="bkng-p-pill">
										{ __(
											'Cancellazione gratuita',
											'booking-manager'
										) }
									</span>
								) }
							</div>
							{ item.perNight !== null && (
								<div className="bkng-p-room__price">
									<p>
										{ item.rates.length > 1 && (
											<span className="bkng-p-muted">
												{ __(
													'da',
													'booking-manager'
												) }{ ' ' }
											</span>
										) }
										<strong>
											{ money(
												item.perNight,
												property.currency
											) }
										</strong>
										<span className="bkng-p-muted">
											{ ' ' }
											{ __(
												'/ notte',
												'booking-manager'
											) }
										</span>
									</p>
									<p className="bkng-p-room__total">
										{ __( 'Totale', 'booking-manager' ) }{ ' ' }
										<strong>
											{ money(
												item.total,
												property.currency
											) }
										</strong>
									</p>
									<p className="bkng-p-muted">
										{ nightsLabel( nights ) } ·{ ' ' }
										{ date( stay.arrival, SHORT ) } –{ ' ' }
										{ date( stay.departure, SHORT ) }
									</p>
								</div>
							) }
							<button
								type="button"
								className="bkng-p-button is-primary"
								aria-expanded={ direct ? undefined : open }
								disabled={ busy }
								onClick={ select }
							>
								{ busy && direct ? <Spinner /> : null }
								{ open
									? __( 'Chiudi', 'booking-manager' )
									: __( 'Seleziona', 'booking-manager' ) }
								<Icon
									name={
										open ? 'chevronDown' : 'chevronRight'
									}
									size={ 16 }
								/>
							</button>
						</>
					) : (
						<p className="bkng-p-room__none">
							{ __(
								'Non disponibile per queste date o per questo numero di ospiti',
								'booking-manager'
							) }
						</p>
					) }
				</div>
			</div>
			{ open && item.available && (
				<Rates
					property={ property }
					item={ item }
					onChoose={ onChoose }
					busy={ busy }
				/>
			) }
		</article>
	);
}

/* ------------------------------------------------------------------ Filters */

const NO_FILTERS = {
	types: [],
	capacity: [],
	amenities: [],
	price: null,
	onlyAvailable: false,
	freeCancel: false,
};

function toggle( list, value ) {
	return list.includes( value )
		? list.filter( ( v ) => v !== value )
		: [ ...list, value ];
}

function CheckRow( { label, count, checked, onChange } ) {
	const id = 'bkng-p-f-' + useId();
	return (
		<div className="bkng-p-filter__row">
			<input
				id={ id }
				type="checkbox"
				checked={ checked }
				onChange={ () => onChange() }
			/>
			<label htmlFor={ id }>{ label }</label>
			{ count !== undefined && (
				<span className="bkng-p-filter__count">{ count }</span>
			) }
		</div>
	);
}

function PriceRange( { bounds, value, currency, onChange } ) {
	const [ lo, hi ] = value || bounds;
	const step = 100;
	return (
		<div className="bkng-p-range">
			<div className="bkng-p-range__track">
				<span
					className="bkng-p-range__fill"
					style={ {
						left:
							( ( lo - bounds[ 0 ] ) /
								( bounds[ 1 ] - bounds[ 0 ] ) ) *
								100 +
							'%',
						right:
							( ( bounds[ 1 ] - hi ) /
								( bounds[ 1 ] - bounds[ 0 ] ) ) *
								100 +
							'%',
					} }
				/>
				<input
					type="range"
					min={ bounds[ 0 ] }
					max={ bounds[ 1 ] }
					step={ step }
					value={ lo }
					aria-label={ __(
						'Prezzo minimo a notte',
						'booking-manager'
					) }
					onChange={ ( e ) =>
						onChange( [
							Math.min( Number( e.target.value ), hi ),
							hi,
						] )
					}
				/>
				<input
					type="range"
					min={ bounds[ 0 ] }
					max={ bounds[ 1 ] }
					step={ step }
					value={ hi }
					aria-label={ __(
						'Prezzo massimo a notte',
						'booking-manager'
					) }
					onChange={ ( e ) =>
						onChange( [
							lo,
							Math.max( Number( e.target.value ), lo ),
						] )
					}
				/>
			</div>
			<div className="bkng-p-range__labels">
				<span>{ money( lo, currency ) }</span>
				<span>{ money( hi, currency ) }</span>
			</div>
		</div>
	);
}

function Filters( { property, items, filters, setFilters, bounds } ) {
	const set = ( patch ) => setFilters( { ...filters, ...patch } );
	const capacities = [
		...new Set( items.map( ( i ) => i.type.max_occupancy ) ),
	].sort( ( a, b ) => a - b );
	const amenities = [
		...new Set( items.flatMap( ( i ) => i.type.amenities || [] ) ),
	].sort( ( a, b ) => a.localeCompare( b ) );
	const anyFree = items.some( ( i ) => i.freeCancel );
	return (
		<div className="bkng-p-filters__body">
			{ items.length > 1 && (
				<fieldset className="bkng-p-filter">
					<legend>
						{ __( 'Tipologia di camera', 'booking-manager' ) }
					</legend>
					{ items.map( ( i ) => (
						<CheckRow
							key={ i.type.id }
							label={ i.type.name }
							checked={ filters.types.includes( i.type.id ) }
							onChange={ () =>
								set( {
									types: toggle( filters.types, i.type.id ),
								} )
							}
						/>
					) ) }
				</fieldset>
			) }
			{ capacities.length > 1 && (
				<fieldset className="bkng-p-filter">
					<legend>{ __( 'Capacità', 'booking-manager' ) }</legend>
					{ capacities.map( ( n ) => (
						<CheckRow
							key={ n }
							label={ sprintf(
								/* translators: %d: people. */
								_n(
									'%d persona',
									'%d persone',
									n,
									'booking-manager'
								),
								n
							) }
							count={
								items.filter(
									( i ) => i.type.max_occupancy === n
								).length
							}
							checked={ filters.capacity.includes( n ) }
							onChange={ () =>
								set( {
									capacity: toggle( filters.capacity, n ),
								} )
							}
						/>
					) ) }
				</fieldset>
			) }
			{ amenities.length > 0 && (
				<fieldset className="bkng-p-filter">
					<legend>{ __( 'Servizi', 'booking-manager' ) }</legend>
					{ amenities.map( ( a ) => (
						<CheckRow
							key={ a }
							label={ a }
							count={
								items.filter( ( i ) =>
									( i.type.amenities || [] ).includes( a )
								).length
							}
							checked={ filters.amenities.includes( a ) }
							onChange={ () =>
								set( {
									amenities: toggle( filters.amenities, a ),
								} )
							}
						/>
					) ) }
				</fieldset>
			) }
			{ bounds && (
				<fieldset className="bkng-p-filter">
					<legend>
						{ __( 'Prezzo per notte', 'booking-manager' ) }
					</legend>
					<PriceRange
						bounds={ bounds }
						value={ filters.price }
						currency={ property.currency }
						onChange={ ( price ) => set( { price } ) }
					/>
				</fieldset>
			) }
			<fieldset className="bkng-p-filter">
				<legend>{ __( 'Altre opzioni', 'booking-manager' ) }</legend>
				<CheckRow
					label={ __( 'Solo camere disponibili', 'booking-manager' ) }
					checked={ filters.onlyAvailable }
					onChange={ () =>
						set( { onlyAvailable: ! filters.onlyAvailable } )
					}
				/>
				{ anyFree && (
					<CheckRow
						label={ __(
							'Cancellazione gratuita',
							'booking-manager'
						) }
						checked={ filters.freeCancel }
						onChange={ () =>
							set( { freeCancel: ! filters.freeCancel } )
						}
					/>
				) }
			</fieldset>
		</div>
	);
}

/* ------------------------------------------------------------------ Results */

/** One entry per room type sold online, with its offer for the dates (if any). */
function buildItems( property, results, freeOnly ) {
	return property.room_types.map( ( type ) => {
		const offer = results.offers.find(
			( o ) => o.room_type_id === type.id
		);
		const rates = offer
			? offer.rates.filter( ( r ) => ! freeOnly || r.free_cancel )
			: [];
		const priced = rates.filter( ( r ) => r.total !== null );
		const cheapest = priced.length
			? priced.reduce( ( a, b ) => ( b.total < a.total ? b : a ) )
			: null;
		return {
			type,
			offer,
			rates,
			available: rates.length > 0,
			freeCancel: !! offer?.rates.some( ( r ) => r.free_cancel ),
			total: cheapest ? cheapest.total : null,
			perNight: cheapest
				? Math.round( cheapest.total / Math.max( 1, cheapest.nights ) )
				: null,
		};
	} );
}

const SORTS = {
	price_asc: ( a, b ) => ( a.perNight ?? 0 ) - ( b.perNight ?? 0 ),
	price_desc: ( a, b ) => ( b.perNight ?? 0 ) - ( a.perNight ?? 0 ),
	capacity: ( a, b ) => b.type.max_occupancy - a.type.max_occupancy,
	size: ( a, b ) => ( b.type.size_sqm || 0 ) - ( a.type.size_sqm || 0 ),
};

export function Results( {
	property,
	stay,
	results,
	onChoose,
	busy,
	searching,
	extras,
	empty,
} ) {
	const [ filters, setFilters ] = useState( NO_FILTERS );
	const [ sort, setSort ] = useState(
		property.show_prices ? 'price_asc' : 'capacity'
	);
	const [ showFilters, setShowFilters ] = useState( false );
	const filtersRef = useRef( null );
	useEffect( () => {
		if ( showFilters ) {
			filtersRef.current?.scrollIntoView( {
				behavior: 'smooth',
				block: 'start',
			} );
		}
	}, [ showFilters ] );
	const sortId = 'bkng-p-s-' + useId();

	const all = useMemo(
		() => buildItems( property, results, filters.freeCancel ),
		[ property, results, filters.freeCancel ]
	);
	const prices = all
		.filter( ( i ) => i.available && i.perNight !== null )
		.map( ( i ) => i.perNight );
	const bounds =
		prices.length > 1 && Math.min( ...prices ) < Math.max( ...prices )
			? [
					Math.floor( Math.min( ...prices ) / 100 ) * 100,
					Math.ceil( Math.max( ...prices ) / 100 ) * 100,
			  ]
			: null;
	// A new search can move the prices: a range set before no longer applies.
	useEffect( () => {
		setFilters( ( f ) => ( { ...f, price: null } ) );
	}, [ results ] );

	const shown = all
		.filter( ( i ) => ! filters.onlyAvailable || i.available )
		.filter(
			( i ) =>
				! filters.types.length || filters.types.includes( i.type.id )
		)
		.filter(
			( i ) =>
				! filters.capacity.length ||
				filters.capacity.includes( i.type.max_occupancy )
		)
		.filter( ( i ) =>
			filters.amenities.every( ( a ) =>
				( i.type.amenities || [] ).includes( a )
			)
		)
		.filter(
			( i ) =>
				! filters.price ||
				! i.available ||
				i.perNight === null ||
				( i.perNight >= filters.price[ 0 ] &&
					i.perNight <= filters.price[ 1 ] )
		)
		.filter( ( i ) => ! filters.freeCancel || i.available )
		.sort(
			( a, b ) =>
				Number( b.available ) - Number( a.available ) ||
				SORTS[ sort ]( a, b )
		);
	const available = shown.filter( ( i ) => i.available ).length;
	const active =
		filters.types.length +
		filters.capacity.length +
		filters.amenities.length +
		( filters.price ? 1 : 0 ) +
		( filters.onlyAvailable ? 1 : 0 ) +
		( filters.freeCancel ? 1 : 0 );
	const nothingFree = ! all.some( ( i ) => i.available ) && ! active;

	if ( nothingFree ) {
		return empty;
	}

	return (
		<div className="bkng-p-results">
			<aside
				ref={ filtersRef }
				className={
					'bkng-p-filters' + ( showFilters ? ' is-open' : '' )
				}
				aria-label={ __( 'Filtri', 'booking-manager' ) }
			>
				<div className="bkng-p-filters__head">
					<h3>{ __( 'Filtri', 'booking-manager' ) }</h3>
					{ active > 0 && (
						<button
							type="button"
							className="bkng-p-link"
							onClick={ () => setFilters( NO_FILTERS ) }
						>
							{ __( 'Azzera', 'booking-manager' ) }
						</button>
					) }
				</div>
				<Filters
					property={ property }
					items={ all }
					filters={ filters }
					setFilters={ setFilters }
					bounds={ property.show_prices ? bounds : null }
				/>
				<button
					type="button"
					className="bkng-p-button is-primary bkng-p-filters__done"
					onClick={ () => setShowFilters( false ) }
				>
					{ sprintf(
						/* translators: %d: rooms shown. */
						_n(
							'Mostra %d camera',
							'Mostra %d camere',
							available,
							'booking-manager'
						),
						available
					) }
				</button>
			</aside>
			<div className="bkng-p-list" aria-busy={ searching }>
				<div className="bkng-p-list__head">
					<p className="bkng-p-list__count" role="status">
						{ sprintf(
							/* translators: %d: rooms available. */
							_n(
								'%d camera disponibile',
								'%d camere disponibili',
								available,
								'booking-manager'
							),
							available
						) }
					</p>
					<button
						type="button"
						className="bkng-p-button bkng-p-filters__toggle"
						aria-expanded={ showFilters }
						onClick={ () => setShowFilters( ! showFilters ) }
					>
						<Icon name="filter" />
						{ active > 0
							? sprintf(
									/* translators: %d: active filters. */
									__( 'Filtri (%d)', 'booking-manager' ),
									active
							  )
							: __( 'Filtri', 'booking-manager' ) }
					</button>
					<div className="bkng-p-sort">
						<label htmlFor={ sortId }>
							{ __( 'Ordina per', 'booking-manager' ) }
						</label>
						<select
							id={ sortId }
							value={ sort }
							onChange={ ( e ) => setSort( e.target.value ) }
						>
							{ property.show_prices && (
								<>
									<option value="price_asc">
										{ __(
											'Prezzo (dal più basso)',
											'booking-manager'
										) }
									</option>
									<option value="price_desc">
										{ __(
											'Prezzo (dal più alto)',
											'booking-manager'
										) }
									</option>
								</>
							) }
							<option value="capacity">
								{ __( 'Capienza', 'booking-manager' ) }
							</option>
							<option value="size">
								{ __( 'Dimensione', 'booking-manager' ) }
							</option>
						</select>
					</div>
				</div>
				{ extras }
				{ shown.length === 0 ? (
					<div className="bkng-p-card">
						<p>
							{ __(
								'Nessuna camera corrisponde ai filtri scelti.',
								'booking-manager'
							) }
						</p>
						<button
							type="button"
							className="bkng-p-button"
							onClick={ () => setFilters( NO_FILTERS ) }
						>
							{ __( 'Azzera i filtri', 'booking-manager' ) }
						</button>
					</div>
				) : (
					shown.map( ( item ) => (
						<RoomCard
							key={ item.type.id }
							property={ property }
							stay={ stay }
							item={ item }
							onChoose={ onChoose }
							busy={ busy }
						/>
					) )
				) }
			</div>
		</div>
	);
}
