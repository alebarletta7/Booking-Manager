/**
 * Guided setup after the first activation: property, room types with their rooms and
 * base price, the page with the online form. Every step saves at once; it can be left
 * and resumed, or skipped.
 */
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { get, post, put } from '../lib/api';
import { boot } from '../lib/boot';
import { money } from '../lib/format';
import { expandCodes } from '../lib/rooms';
import { useAction, useLoad } from '../lib/hooks';
import { useProperty } from '../lib/property';
import { navigate } from '../lib/router';
import {
	Card,
	ErrorNotice,
	Grid,
	Loading,
	MoneyField,
	NumberField,
	Page,
	Select,
	Success,
	Text,
} from '../components/ui';

const STEPS = [
	[ 'property', __( 'Struttura', 'booking-manager' ) ],
	[ 'rooms', __( 'Camere e prezzi', 'booking-manager' ) ],
	[ 'page', __( 'Modulo online', 'booking-manager' ) ],
	[ 'done', __( 'Fatto', 'booking-manager' ) ],
];

/** Where to resume: the first step not done yet. */
function firstStep( d ) {
	if ( ! d.property ) {
		return 'property';
	}
	if ( ! d.units.length ) {
		return 'rooms';
	}
	return d.page ? 'done' : 'page';
}

function stepClass( i, index ) {
	if ( i === index ) {
		return 'is-current';
	}
	return i < index ? 'is-done' : '';
}

function PropertyStep( { data, onDone } ) {
	const p = data.property;
	const action = useAction();
	const [ form, setForm ] = useState( {
		name: p?.name || '',
		kind: p?.kind || 'bnb',
		email: p?.email || data.admin_email || '',
		phone: p?.phone || '',
		address: p?.address || '',
		city: p?.city || '',
		checkin_from: p?.checkin_from || '14:00',
		checkin_to: p?.checkin_to || '20:00',
		checkout_until: p?.checkout_until || '10:00',
		timezone: p?.timezone || data.site_timezone || 'Europe/Rome',
	} );
	const set = ( k ) => ( v ) => setForm( ( f ) => ( { ...f, [ k ]: v } ) );
	return (
		<Card title={ __( 'La tua struttura', 'booking-manager' ) }>
			<ErrorNotice error={ action.error } />
			<form
				onSubmit={ async ( e ) => {
					e.preventDefault();
					const res = await action.run( () =>
						p
							? put( '/properties/' + p.id, {
									...form,
									version: p.version,
							  } )
							: post( '/properties', form )
					);
					if ( res ) {
						onDone( res );
					}
				} }
			>
				<Grid>
					<Text
						label={ __( 'Nome', 'booking-manager' ) }
						value={ form.name }
						onChange={ set( 'name' ) }
						required
					/>
					<Select
						label={ __( 'Tipo', 'booking-manager' ) }
						value={ form.kind }
						options={ Object.entries( data.kinds ).map(
							( [ value, label ] ) => ( { value, label } )
						) }
						onChange={ set( 'kind' ) }
					/>
					<Text
						label={ __(
							'Email per le prenotazioni',
							'booking-manager'
						) }
						type="email"
						value={ form.email }
						onChange={ set( 'email' ) }
						required
					/>
					<Text
						label={ __( 'Telefono', 'booking-manager' ) }
						value={ form.phone }
						onChange={ set( 'phone' ) }
					/>
					<Text
						label={ __( 'Indirizzo', 'booking-manager' ) }
						value={ form.address }
						onChange={ set( 'address' ) }
					/>
					<Text
						label={ __( 'Città', 'booking-manager' ) }
						value={ form.city }
						onChange={ set( 'city' ) }
					/>
				</Grid>
				<Grid columns={ 3 }>
					<Text
						label={ __( 'Check-in dalle', 'booking-manager' ) }
						type="time"
						value={ form.checkin_from }
						onChange={ set( 'checkin_from' ) }
					/>
					<Text
						label={ __( 'Check-in fino alle', 'booking-manager' ) }
						type="time"
						value={ form.checkin_to }
						onChange={ set( 'checkin_to' ) }
					/>
					<Text
						label={ __( 'Check-out entro le', 'booking-manager' ) }
						type="time"
						value={ form.checkout_until }
						onChange={ set( 'checkout_until' ) }
					/>
				</Grid>
				<Button variant="primary" type="submit" isBusy={ action.busy }>
					{ __( 'Salva e continua', 'booking-manager' ) }
				</Button>
			</form>
		</Card>
	);
}

function RoomsStep( { data, onChanged, onNext } ) {
	const property = data.property;
	const action = useAction();
	const [ form, setForm ] = useState( {
		name: '',
		max_occupancy: 2,
		base_price: null,
		codes: '',
	} );
	const [ notice, setNotice ] = useState( '' );
	const set = ( k ) => ( v ) => setForm( ( f ) => ( { ...f, [ k ]: v } ) );
	return (
		<>
			<Card title={ __( 'Tipologie e camere', 'booking-manager' ) }>
				<p className="bkng-help">
					{ __(
						'Per ogni tipologia che vendi indica capienza, prezzo base a notte e i numeri delle camere (es. 101, 102 oppure una serie: 101–110). Tariffe stagionali, caparre e cancellazione si impostano dopo in «Tariffe».',
						'booking-manager'
					) }
				</p>
				<Success onDismiss={ () => setNotice( '' ) }>
					{ notice }
				</Success>
				<ErrorNotice error={ action.error } />
				<form
					onSubmit={ async ( e ) => {
						e.preventDefault();
						const type = await action.run( () =>
							post(
								'/properties/' + property.id + '/room-types',
								{
									name: form.name,
									max_adults: form.max_occupancy,
									max_occupancy: form.max_occupancy,
									base_occupancy: form.max_occupancy,
									base_price: form.base_price ?? 0,
								}
							)
						);
						if ( ! type ) {
							return;
						}
						const codes = expandCodes( form.codes );
						const units = await action.run( () =>
							codes.length
								? post(
										'/properties/' +
											property.id +
											'/units/batch',
										{ room_type_id: type.id, codes }
								  )
								: post(
										'/properties/' + property.id + '/units',
										{
											code: form.name.slice( 0, 30 ),
											room_type_id: type.id,
										}
								  )
						);
						if ( ! units ) {
							// The type exists: the list below shows it without rooms.
							onChanged();
							return;
						}
						setForm( {
							name: '',
							max_occupancy: 2,
							base_price: null,
							codes: '',
						} );
						setNotice(
							sprintf(
								/* translators: %s: room type. */ __(
									'«%s» aggiunta.',
									'booking-manager'
								),
								type.name
							)
						);
						onChanged();
					} }
				>
					<Grid columns={ 4 }>
						<Text
							label={ __( 'Tipologia', 'booking-manager' ) }
							value={ form.name }
							onChange={ set( 'name' ) }
							required
							placeholder={ __(
								'es. Camera doppia',
								'booking-manager'
							) }
						/>
						<NumberField
							label={ __( 'Persone', 'booking-manager' ) }
							min={ 1 }
							value={ form.max_occupancy }
							onChange={ ( v ) =>
								set( 'max_occupancy' )( v || 1 )
							}
						/>
						<MoneyField
							label={ __(
								'Prezzo a notte (€)',
								'booking-manager'
							) }
							value={ form.base_price }
							onChange={ set( 'base_price' ) }
							required
						/>
						<Text
							label={ __(
								'Numeri delle camere',
								'booking-manager'
							) }
							value={ form.codes }
							onChange={ set( 'codes' ) }
							placeholder="101-110, 201"
						/>
					</Grid>
					<Button
						variant="secondary"
						type="submit"
						isBusy={ action.busy }
					>
						{ __( 'Aggiungi', 'booking-manager' ) }
					</Button>
				</form>
			</Card>
			{ data.room_types.length > 0 && (
				<Card title={ __( 'Già inserite', 'booking-manager' ) }>
					<ul className="bkng-list">
						{ data.room_types.map( ( t ) => (
							<li key={ t.id }>
								<div className="bkng-list__main">
									<span className="bkng-strong">
										{ t.name }
									</span>{ ' ' }
									·{ ' ' }
									{ money( t.base_price, property.currency ) }
								</div>
								<span className="bkng-small bkng-muted">
									{ data.units
										.filter(
											( u ) => u.room_type_id === t.id
										)
										.map( ( u ) => u.code )
										.join( ', ' ) }
								</span>
							</li>
						) ) }
					</ul>
				</Card>
			) }
			<Button
				variant="primary"
				disabled={ ! data.units.length }
				onClick={ onNext }
			>
				{ __( 'Continua', 'booking-manager' ) }
			</Button>
		</>
	);
}

function PageStep( { data, onChanged, onNext } ) {
	const action = useAction();
	const [ title, setTitle ] = useState(
		__( 'Prenota il tuo soggiorno', 'booking-manager' )
	);
	return (
		<Card title={ __( 'Pagina di prenotazione', 'booking-manager' ) }>
			<ErrorNotice error={ action.error } />
			{ ! data.permalinks_set && (
				<p className="bkng-help">
					{ __(
						'Consiglio: imposta i permalink «Nome articolo» in Impostazioni → Permalink.',
						'booking-manager'
					) }
				</p>
			) }
			{ data.page ? (
				<p>
					{ __( 'La pagina esiste:', 'booking-manager' ) }{ ' ' }
					<a href={ data.page.url } target="_blank" rel="noreferrer">
						{ data.page.title }
					</a>
				</p>
			) : (
				<form
					className="bkng-row"
					onSubmit={ async ( e ) => {
						e.preventDefault();
						if (
							await action.run( () =>
								post( '/setup/page', {
									property_id: data.property.id,
									title,
								} )
							)
						) {
							onChanged();
						}
					} }
				>
					<div style={ { flex: 1 } }>
						<Text
							label={ __(
								'Titolo della pagina',
								'booking-manager'
							) }
							value={ title }
							onChange={ setTitle }
						/>
					</div>
					<Button
						variant="secondary"
						type="submit"
						isBusy={ action.busy }
					>
						{ __( 'Crea la pagina', 'booking-manager' ) }
					</Button>
				</form>
			) }
			<p className="bkng-help">
				{ __(
					'Oppure inserisci lo shortcode in una pagina esistente:',
					'booking-manager'
				) }{ ' ' }
				<code>
					[booking_manager_prenota struttura=&quot;
					{ data.property.slug }&quot;]
				</code>
			</p>
			<Button variant="primary" onClick={ onNext }>
				{ __( 'Continua', 'booking-manager' ) }
			</Button>
		</Card>
	);
}

export default function Setup() {
	const { refreshMe, select } = useProperty();
	const data = useLoad( () => get( '/setup' ), [] );
	const [ step, setStep ] = useState( null );
	const finish = useAction();

	const close = async ( skipped ) => {
		if ( await finish.run( () => post( '/setup/finish', { skipped } ) ) ) {
			boot.setupPending = false;
			await refreshMe();
			navigate( '/' );
		}
	};

	if ( data.loading && ! data.data ) {
		return <Loading />;
	}
	if ( data.error ) {
		return <ErrorNotice error={ data.error } onRetry={ data.reload } />;
	}
	const d = data.data;
	const current = step || firstStep( d );
	const index = STEPS.findIndex( ( s ) => s[ 0 ] === current );

	return (
		<Page
			title={ __( 'Configurazione guidata', 'booking-manager' ) }
			subtitle={ __(
				'Pochi minuti per ricevere la prima prenotazione.',
				'booking-manager'
			) }
			actions={
				current !== 'done' && (
					<Button
						variant="tertiary"
						onClick={ () => close( true ) }
						isBusy={ finish.busy }
					>
						{ __( 'Salta la configurazione', 'booking-manager' ) }
					</Button>
				)
			}
		>
			<ol className="bkng-steps">
				{ STEPS.map( ( [ key, label ], i ) => (
					<li key={ key } className={ stepClass( i, index ) }>
						{ i + 1 }. { label }
					</li>
				) ) }
			</ol>
			<ErrorNotice error={ finish.error } />
			{ current === 'property' && (
				<PropertyStep
					data={ d }
					onDone={ async ( p ) => {
						await refreshMe();
						select( p.id );
						await data.reload();
						setStep( 'rooms' );
					} }
				/>
			) }
			{ current === 'rooms' && d.property && (
				<RoomsStep
					data={ d }
					onChanged={ data.reload }
					onNext={ () => setStep( 'page' ) }
				/>
			) }
			{ current === 'page' && d.property && (
				<PageStep
					data={ d }
					onChanged={ data.reload }
					onNext={ () => setStep( 'done' ) }
				/>
			) }
			{ current === 'done' && (
				<Card title={ __( 'Tutto pronto', 'booking-manager' ) }>
					<p>
						{ __(
							'Puoi già ricevere prenotazioni. Per completare:',
							'booking-manager'
						) }
					</p>
					<ul style={ { listStyle: 'disc', marginLeft: 18 } }>
						<li>
							<a href="#/rates">
								{ __(
									'Tariffe stagionali, caparra e cancellazione',
									'booking-manager'
								) }
							</a>
						</li>
						<li>
							<a href="#/property">
								{ __(
									'Pagamenti online con Stripe o Nexi',
									'booking-manager'
								) }
							</a>
						</li>
						<li>
							<a href="#/staff">
								{ __(
									'Account per lo staff',
									'booking-manager'
								) }
							</a>
						</li>
					</ul>
					{ d.page && (
						<p>
							<a
								href={ d.page.url }
								target="_blank"
								rel="noreferrer"
							>
								{ __(
									'Apri il modulo online',
									'booking-manager'
								) }
							</a>
						</p>
					) }
					<Button
						variant="primary"
						onClick={ () => close( false ) }
						isBusy={ finish.busy }
					>
						{ __( 'Vai alla dashboard', 'booking-manager' ) }
					</Button>
				</Card>
			) }
		</Page>
	);
}
