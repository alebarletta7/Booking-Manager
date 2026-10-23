/**
 * Room types (what is sold: capacity, base price, photos) and the rooms (units) of each
 * type. Without an active licence the server refuses changes and the message says why.
 */
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { get, post, put } from '../lib/api';
import { can } from '../lib/boot';
import { money } from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';
import { useProperty } from '../lib/property';
import {
	Badge,
	Card,
	Check,
	Confirm,
	Empty,
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

function pickImages( current, onChange ) {
	if ( ! window.wp || ! window.wp.media ) {
		return;
	}
	const frame = window.wp.media( {
		title: __( 'Foto della tipologia', 'booking-manager' ),
		library: { type: 'image' },
		multiple: true,
		button: { text: __( 'Usa queste foto', 'booking-manager' ) },
	} );
	frame.on( 'select', () => {
		const chosen = frame
			.state()
			.get( 'selection' )
			.toJSON()
			.map( ( a ) => ( {
				id: a.id,
				url: a.sizes?.medium?.url || a.url,
			} ) );
		const ids = new Set( current.map( ( i ) => i.id ) );
		onChange( [
			...current,
			...chosen.filter( ( c ) => ! ids.has( c.id ) ),
		] );
	} );
	frame.open();
}

function TypeModal( { property, type, onClose, onDone } ) {
	const action = useAction();
	const [ form, setForm ] = useState(
		type || {
			name: '',
			description: '',
			max_adults: 2,
			max_children: 0,
			max_occupancy: 2,
			base_occupancy: 2,
			base_price: null,
			extra_adult_price: 0,
			extra_child_price: 0,
			size_sqm: null,
			beds: '',
			amenities: [],
			images: [],
			online_enabled: true,
		}
	);
	const [ amenities, setAmenities ] = useState(
		( form.amenities || [] ).join( ', ' )
	);
	const set = ( k ) => ( v ) => setForm( ( f ) => ( { ...f, [ k ]: v } ) );
	return (
		<FormModal
			wide
			title={
				type
					? sprintf(
							/* translators: %s: room type. */ __(
								'Modifica «%s»',
								'booking-manager'
							),
							type.name
					  )
					: __( 'Nuova tipologia', 'booking-manager' )
			}
			onClose={ onClose }
			busy={ action.busy }
			error={ action.error }
			onSubmit={ async () => {
				const body = {
					name: form.name,
					description: form.description,
					max_adults: form.max_adults,
					max_children: form.max_children,
					max_occupancy: form.max_occupancy,
					base_occupancy: form.base_occupancy,
					base_price: form.base_price ?? 0,
					extra_adult_price: form.extra_adult_price ?? 0,
					extra_child_price: form.extra_child_price ?? 0,
					size_sqm: form.size_sqm,
					beds: form.beds,
					amenities: amenities
						.split( ',' )
						.map( ( a ) => a.trim() )
						.filter( Boolean ),
					images: ( form.images || [] ).map( ( i ) => i.id ),
					online_enabled: form.online_enabled,
				};
				const res = await action.run( () =>
					type
						? put( '/room-types/' + type.id, {
								...body,
								version: type.version,
						  } )
						: post(
								'/properties/' + property.id + '/room-types',
								body
						  )
				);
				if ( res ) {
					onDone(
						type
							? __( 'Tipologia salvata.', 'booking-manager' )
							: __(
									'Tipologia creata: ora aggiungi le camere.',
									'booking-manager'
							  )
					);
				}
			} }
		>
			<Grid>
				<Text
					label={ __( 'Nome', 'booking-manager' ) }
					value={ form.name }
					onChange={ set( 'name' ) }
					required
					placeholder={ __(
						'es. Camera doppia vista mare',
						'booking-manager'
					) }
				/>
				<MoneyField
					label={ __( 'Prezzo base a notte (€)', 'booking-manager' ) }
					value={ form.base_price }
					onChange={ set( 'base_price' ) }
					help={ __(
						"Usato quando non c'è un prezzo per le date nelle Tariffe.",
						'booking-manager'
					) }
				/>
			</Grid>
			<Grid columns={ 4 }>
				<NumberField
					label={ __( 'Adulti max', 'booking-manager' ) }
					min={ 1 }
					value={ form.max_adults }
					onChange={ set( 'max_adults' ) }
				/>
				<NumberField
					label={ __( 'Bambini max', 'booking-manager' ) }
					min={ 0 }
					value={ form.max_children }
					onChange={ set( 'max_children' ) }
				/>
				<NumberField
					label={ __( 'Persone max', 'booking-manager' ) }
					min={ 1 }
					value={ form.max_occupancy }
					onChange={ set( 'max_occupancy' ) }
				/>
				<NumberField
					label={ __(
						'Persone incluse nel prezzo',
						'booking-manager'
					) }
					min={ 1 }
					value={ form.base_occupancy }
					onChange={ set( 'base_occupancy' ) }
				/>
			</Grid>
			<Grid columns={ 4 }>
				<MoneyField
					label={ __(
						'Adulto in più / notte (€)',
						'booking-manager'
					) }
					value={ form.extra_adult_price }
					onChange={ set( 'extra_adult_price' ) }
				/>
				<MoneyField
					label={ __(
						'Bambino in più / notte (€)',
						'booking-manager'
					) }
					value={ form.extra_child_price }
					onChange={ set( 'extra_child_price' ) }
				/>
				<NumberField
					label={ __( 'Metri quadri', 'booking-manager' ) }
					min={ 1 }
					value={ form.size_sqm }
					onChange={ set( 'size_sqm' ) }
				/>
				<Text
					label={ __( 'Letti', 'booking-manager' ) }
					value={ form.beds || '' }
					onChange={ set( 'beds' ) }
					placeholder={ __(
						'es. 1 matrimoniale',
						'booking-manager'
					) }
				/>
			</Grid>
			<div className="bkng-stack">
				<Textarea
					label={ __(
						'Descrizione per il modulo online',
						'booking-manager'
					) }
					value={ form.description || '' }
					onChange={ set( 'description' ) }
				/>
				<Text
					label={ __(
						'Servizi (separati da virgola)',
						'booking-manager'
					) }
					value={ amenities }
					onChange={ setAmenities }
					placeholder={ __(
						'Wi-Fi, aria condizionata, balcone',
						'booking-manager'
					) }
				/>
				<div>
					<div className="bkng-row">
						{ ( form.images || [] ).map( ( img ) => (
							<span
								key={ img.id }
								style={ { position: 'relative' } }
							>
								<img
									src={ img.url }
									alt=""
									width="72"
									height="54"
									style={ {
										objectFit: 'cover',
										borderRadius: 6,
									} }
								/>
								<Button
									size="small"
									icon="no-alt"
									label={ __(
										'Togli la foto',
										'booking-manager'
									) }
									onClick={ () =>
										set( 'images' )(
											form.images.filter(
												( i ) => i.id !== img.id
											)
										)
									}
								/>
							</span>
						) ) }
					</div>
					{ window.wp && window.wp.media && (
						<Button
							variant="secondary"
							onClick={ () =>
								pickImages( form.images || [], set( 'images' ) )
							}
						>
							{ __( 'Scegli le foto', 'booking-manager' ) }
						</Button>
					) }
				</div>
				<Check
					label={ __(
						'Prenotabile dal modulo online',
						'booking-manager'
					) }
					checked={ !! form.online_enabled }
					onChange={ set( 'online_enabled' ) }
				/>
			</div>
		</FormModal>
	);
}

function UnitModal( { property, types, unit, defaultType, onClose, onDone } ) {
	const action = useAction();
	const [ form, setForm ] = useState(
		unit || {
			code: '',
			name: '',
			room_type_id: defaultType || types[ 0 ]?.id,
			floor: '',
			notes: '',
		}
	);
	const set = ( k ) => ( v ) => setForm( ( f ) => ( { ...f, [ k ]: v } ) );
	return (
		<FormModal
			title={
				unit
					? sprintf(
							/* translators: %s: room code. */ __(
								'Camera %s',
								'booking-manager'
							),
							unit.code
					  )
					: __( 'Nuova camera', 'booking-manager' )
			}
			onClose={ onClose }
			busy={ action.busy }
			error={ action.error }
			onSubmit={ async () => {
				const body = {
					code: form.code,
					name: form.name,
					room_type_id: Number( form.room_type_id ),
					floor: form.floor || '',
					notes: form.notes || '',
				};
				const res = await action.run( () =>
					unit
						? put( '/units/' + unit.id, {
								...body,
								version: unit.version,
						  } )
						: post( '/properties/' + property.id + '/units', body )
				);
				if ( res ) {
					onDone( __( 'Camera salvata.', 'booking-manager' ) );
				}
			} }
		>
			<Grid>
				<Text
					label={ __( 'Numero o codice', 'booking-manager' ) }
					value={ form.code }
					onChange={ set( 'code' ) }
					required
					placeholder="101"
				/>
				<Text
					label={ __( 'Nome (facoltativo)', 'booking-manager' ) }
					value={ form.name || '' }
					onChange={ set( 'name' ) }
				/>
				<Select
					label={ __( 'Tipologia', 'booking-manager' ) }
					value={ String( form.room_type_id || '' ) }
					options={ types.map( ( t ) => ( {
						value: String( t.id ),
						label: t.name,
					} ) ) }
					onChange={ set( 'room_type_id' ) }
				/>
				<Text
					label={ __( 'Piano', 'booking-manager' ) }
					value={ form.floor || '' }
					onChange={ set( 'floor' ) }
				/>
			</Grid>
			<Textarea
				label={ __( 'Note interne', 'booking-manager' ) }
				value={ form.notes || '' }
				onChange={ set( 'notes' ) }
			/>
		</FormModal>
	);
}

export default function Rooms() {
	const { property } = useProperty();
	const [ archived, setArchived ] = useState( false );
	const types = useLoad(
		() =>
			get( '/properties/' + property.id + '/room-types', {
				include_archived: archived ? 1 : '',
			} ),
		[ property.id, archived ]
	);
	const units = useLoad(
		() =>
			get( '/properties/' + property.id + '/units', {
				include_archived: archived ? 1 : '',
			} ),
		[ property.id, archived ]
	);
	const [ modal, setModal ] = useState( null );
	const [ notice, setNotice ] = useState( '' );
	const action = useAction();
	const manage = can( 'bkng_manage_rooms' );

	const done = ( message ) => {
		setModal( null );
		setNotice( message );
		types.reload();
		units.reload();
	};

	if (
		( types.loading && ! types.data ) ||
		( units.loading && ! units.data )
	) {
		return <Loading />;
	}
	if ( types.error || units.error ) {
		return (
			<ErrorNotice
				error={ types.error || units.error }
				onRetry={ types.reload }
			/>
		);
	}
	const active = types.data.filter( ( t ) => t.status === 'active' );

	return (
		<Page
			title={ __( 'Camere', 'booking-manager' ) }
			subtitle={
				property.room_is_type
					? __(
							'In questa struttura ogni alloggio si vende da solo: crea una tipologia per ogni alloggio.',
							'booking-manager'
					  )
					: __(
							'Le tipologie sono ciò che vendi; le camere sono le stanze fisiche di ogni tipologia.',
							'booking-manager'
					  )
			}
			actions={
				<>
					<Check
						label={ __( 'Mostra archiviate', 'booking-manager' ) }
						checked={ archived }
						onChange={ setArchived }
					/>
					{ manage && (
						<Button
							variant="primary"
							onClick={ () => setModal( { kind: 'type' } ) }
						>
							{ __( 'Nuova tipologia', 'booking-manager' ) }
						</Button>
					) }
				</>
			}
		>
			<Success onDismiss={ () => setNotice( '' ) }>{ notice }</Success>
			<ErrorNotice
				error={ action.error }
				onDismiss={ () => action.setError( null ) }
			/>
			{ ! types.data.length && (
				<Empty
					title={ __( 'Nessuna tipologia', 'booking-manager' ) }
					text={ __(
						'Crea la prima tipologia (es. «Camera doppia») e poi aggiungi le camere.',
						'booking-manager'
					) }
				>
					{ manage && (
						<Button
							variant="primary"
							onClick={ () => setModal( { kind: 'type' } ) }
						>
							{ __( 'Nuova tipologia', 'booking-manager' ) }
						</Button>
					) }
				</Empty>
			) }
			{ types.data.map( ( t ) => {
				const list = units.data.filter(
					( u ) => u.room_type_id === t.id
				);
				return (
					<Card
						key={ t.id }
						title={
							<>
								{ t.name }{ ' ' }
								{ t.status !== 'active' && (
									<span className="bkng-badge">
										{ __(
											'archiviata',
											'booking-manager'
										) }
									</span>
								) }
								{ ! t.online_enabled && (
									<span className="bkng-badge is-warn">
										{ __(
											'non online',
											'booking-manager'
										) }
									</span>
								) }
							</>
						}
						actions={
							manage &&
							t.status === 'active' && (
								<>
									<Button
										variant="secondary"
										onClick={ () =>
											setModal( {
												kind: 'unit',
												type: t.id,
											} )
										}
									>
										{ __(
											'Aggiungi camera',
											'booking-manager'
										) }
									</Button>
									<Button
										variant="tertiary"
										onClick={ () =>
											setModal( {
												kind: 'type',
												type: t,
											} )
										}
									>
										{ __( 'Modifica', 'booking-manager' ) }
									</Button>
									<Button
										variant="tertiary"
										isDestructive
										onClick={ () =>
											setModal( {
												kind: 'archive-type',
												type: t,
											} )
										}
									>
										{ __( 'Archivia', 'booking-manager' ) }
									</Button>
								</>
							)
						}
					>
						<p className="bkng-muted">
							{ sprintf(
								/* translators: 1: max guests, 2: base price. */
								__(
									'Fino a %1$d persone · da %2$s a notte',
									'booking-manager'
								),
								t.max_occupancy,
								money( t.base_price, property.currency )
							) }
							{ t.beds ? ' · ' + t.beds : '' }
							{ t.size_sqm ? ' · ' + t.size_sqm + ' m²' : '' }
						</p>
						{ ! list.length && (
							<p className="bkng-help">
								{ __(
									'Nessuna camera: senza camere la tipologia non è prenotabile.',
									'booking-manager'
								) }
							</p>
						) }
						{ list.length > 0 && (
							<ul className="bkng-list">
								{ list.map( ( u ) => (
									<li key={ u.id }>
										<div className="bkng-list__main">
											<span className="bkng-strong">
												{ u.code }
											</span>
											{ u.name && u.name !== u.code
												? ' · ' + u.name
												: '' }
											{ u.floor ? (
												<span className="bkng-muted">
													{ ' ' }
													·{ ' ' }
													{ __(
														'Piano',
														'booking-manager'
													) }{ ' ' }
													{ u.floor }
												</span>
											) : null }
										</div>
										{ u.status === 'active' ? (
											<Badge
												status={ u.op_status }
												kind="op"
											/>
										) : (
											<span className="bkng-badge">
												{ __(
													'archiviata',
													'booking-manager'
												) }
											</span>
										) }
										{ manage && u.status === 'active' && (
											<>
												<Button
													variant="link"
													onClick={ () =>
														setModal( {
															kind: 'unit',
															unit: u,
														} )
													}
												>
													{ __(
														'Modifica',
														'booking-manager'
													) }
												</Button>
												<Button
													variant="link"
													isDestructive
													onClick={ () =>
														setModal( {
															kind: 'archive-unit',
															unit: u,
														} )
													}
												>
													{ __(
														'Archivia',
														'booking-manager'
													) }
												</Button>
											</>
										) }
										{ manage && u.status !== 'active' && (
											<Button
												variant="link"
												onClick={ () =>
													action
														.run( () =>
															post(
																'/units/' +
																	u.id +
																	'/restore'
															)
														)
														.then(
															( r ) =>
																r &&
																done(
																	__(
																		'Camera ripristinata.',
																		'booking-manager'
																	)
																)
														)
												}
											>
												{ __(
													'Ripristina',
													'booking-manager'
												) }
											</Button>
										) }
									</li>
								) ) }
							</ul>
						) }
					</Card>
				);
			} ) }
			{ modal?.kind === 'type' && (
				<TypeModal
					property={ property }
					type={ modal.type }
					onClose={ () => setModal( null ) }
					onDone={ done }
				/>
			) }
			{ modal?.kind === 'unit' && (
				<UnitModal
					property={ property }
					types={ active }
					unit={ modal.unit }
					defaultType={ modal.type }
					onClose={ () => setModal( null ) }
					onDone={ done }
				/>
			) }
			{ modal?.kind === 'archive-type' && (
				<Confirm
					destructive
					title={ __( 'Archivia la tipologia', 'booking-manager' ) }
					text={ __(
						'Non sarà più prenotabile. Le prenotazioni esistenti restano. Non è possibile se ci sono soggiorni futuri.',
						'booking-manager'
					) }
					confirmLabel={ __( 'Archivia', 'booking-manager' ) }
					busy={ action.busy }
					error={ action.error }
					onCancel={ () => setModal( null ) }
					onConfirm={ async () =>
						( await action.run( () =>
							post( '/room-types/' + modal.type.id + '/archive' )
						) ) &&
						done( __( 'Tipologia archiviata.', 'booking-manager' ) )
					}
				/>
			) }
			{ modal?.kind === 'archive-unit' && (
				<Confirm
					destructive
					title={ sprintf(
						/* translators: %s: room code. */ __(
							'Archivia la camera %s',
							'booking-manager'
						),
						modal.unit.code
					) }
					text={ __(
						'La camera esce dalla disponibilità. Non è possibile se ha soggiorni futuri assegnati.',
						'booking-manager'
					) }
					confirmLabel={ __( 'Archivia', 'booking-manager' ) }
					busy={ action.busy }
					error={ action.error }
					onCancel={ () => setModal( null ) }
					onConfirm={ async () =>
						( await action.run( () =>
							post( '/units/' + modal.unit.id + '/archive' )
						) ) &&
						done( __( 'Camera archiviata.', 'booking-manager' ) )
					}
				/>
			) }
		</Page>
	);
}
