/**
 * Rates: plans (meal plan, cancellation and deposit terms), prices by period, extras and
 * discounts, stay rules (minimum stay, closures).
 */
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { del, get, post, put } from '../lib/api';
import { date, MEAL_PLANS, money, options, WEEKDAYS } from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';
import { useProperty } from '../lib/property';
import {
	Card,
	Check,
	Confirm,
	DateField,
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
	Tabs,
	Text,
	Textarea,
} from '../components/ui';

const DEPOSITS = {
	none: __( 'Nessuna caparra', 'booking-manager' ),
	percent: __( 'Percentuale del totale', 'booking-manager' ),
	fixed: __( 'Importo fisso', 'booking-manager' ),
	first_night: __( 'Prima notte', 'booking-manager' ),
	full: __( 'Intero importo', 'booking-manager' ),
};

const PENALTIES = {
	none: __( 'Nessuna penale', 'booking-manager' ),
	first_night: __( 'Prima notte', 'booking-manager' ),
	percent: __( 'Percentuale del totale', 'booking-manager' ),
	full: __( 'Intero importo', 'booking-manager' ),
};

const ADJ_KINDS = {
	supplement: __( 'Supplemento', 'booking-manager' ),
	discount: __( 'Sconto', 'booking-manager' ),
	long_stay: __( 'Sconto soggiorno lungo', 'booking-manager' ),
};

const ADJ_PER = {
	stay: __( 'a soggiorno', 'booking-manager' ),
	night: __( 'a notte', 'booking-manager' ),
	person_night: __( 'a persona a notte', 'booking-manager' ),
	person_stay: __( 'a persona a soggiorno', 'booking-manager' ),
};

/** Basis points (1250 = 12,5%) to the text of a percentage and back. */
const bpText = ( bp ) =>
	bp === null || bp === undefined
		? ''
		: String( bp / 100 ).replace( '.', ',' );
const textBp = ( t ) => {
	const n = Number( String( t ).replace( ',', '.' ) );
	return t === '' || ! Number.isFinite( n ) ? null : Math.round( n * 100 );
};

function Percent( { label, value, onChange, help } ) {
	const [ text, setText ] = useState( bpText( value ) );
	return (
		<Text
			label={ label }
			help={ help }
			value={ text }
			inputMode="decimal"
			onChange={ ( t ) => {
				setText( t );
				onChange( textBp( t ) );
			} }
		/>
	);
}

function Weekdays( { value, onChange, label } ) {
	const list = String( value || '' )
		.split( ',' )
		.filter( Boolean )
		.map( Number );
	return (
		<fieldset>
			<legend className="bkng-field__label">{ label }</legend>
			<div className="bkng-checks">
				{ WEEKDAYS.map( ( [ n, name ] ) => (
					<Check
						key={ n }
						label={ name }
						checked={ list.includes( n ) }
						onChange={ ( on ) =>
							onChange(
								( on
									? [ ...list, n ]
									: list.filter( ( x ) => x !== n )
								)
									.sort()
									.join( ',' )
							)
						}
					/>
				) ) }
			</div>
		</fieldset>
	);
}

function TypePicker( { types, value, onChange } ) {
	const list = value || [];
	return (
		<fieldset>
			<legend className="bkng-field__label">
				{ __( 'Tipologie (nessuna = tutte)', 'booking-manager' ) }
			</legend>
			<div className="bkng-checks">
				{ types.map( ( t ) => (
					<Check
						key={ t.id }
						label={ t.name }
						checked={ list.includes( t.id ) }
						onChange={ ( on ) =>
							onChange(
								on
									? [ ...list, t.id ]
									: list.filter( ( x ) => x !== t.id )
							)
						}
					/>
				) ) }
			</div>
		</fieldset>
	);
}

/* ------------------------------------------------------------------ Plans */

function PlanModal( { property, types, plan, onClose, onDone } ) {
	const action = useAction();
	const [ form, setForm ] = useState(
		plan || {
			name: '',
			description: '',
			room_type_ids: [],
			modifier_bp: 0,
			meal_plan: 'room_only',
			cancellation_policy: {
				refundable: true,
				free_until_days: 7,
				penalty: 'first_night',
				percent: 0,
				text: '',
			},
			deposit_policy: { type: 'none', value: 0, due_days: 0 },
			is_default: false,
			is_public: true,
		}
	);
	const set = ( k ) => ( v ) => setForm( ( f ) => ( { ...f, [ k ]: v } ) );
	const setC = ( k ) => ( v ) =>
		setForm( ( f ) => ( {
			...f,
			cancellation_policy: { ...f.cancellation_policy, [ k ]: v },
		} ) );
	const setD = ( k ) => ( v ) =>
		setForm( ( f ) => ( {
			...f,
			deposit_policy: { ...f.deposit_policy, [ k ]: v },
		} ) );
	const c = form.cancellation_policy;
	const d = form.deposit_policy;
	return (
		<FormModal
			wide
			title={
				plan
					? plan.name
					: __( 'Nuovo piano tariffario', 'booking-manager' )
			}
			onClose={ onClose }
			busy={ action.busy }
			error={ action.error }
			onSubmit={ async () => {
				const body = { ...form };
				delete body.id;
				const res = await action.run( () =>
					plan
						? put( '/rate-plans/' + plan.id, body )
						: post(
								'/properties/' + property.id + '/rate-plans',
								body
						  )
				);
				if ( res ) {
					onDone(
						__( 'Piano tariffario salvato.', 'booking-manager' )
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
						'es. Non rimborsabile',
						'booking-manager'
					) }
				/>
				<Select
					label={ __( 'Trattamento', 'booking-manager' ) }
					value={ form.meal_plan }
					options={ options( MEAL_PLANS ) }
					onChange={ set( 'meal_plan' ) }
				/>
				<Percent
					label={ __(
						'Variazione sul prezzo (%)',
						'booking-manager'
					) }
					value={ form.modifier_bp }
					onChange={ ( v ) => set( 'modifier_bp' )( v ?? 0 ) }
					help={ __(
						'es. -10 per uno sconto del 10%, 15 per +15%.',
						'booking-manager'
					) }
				/>
			</Grid>
			<Textarea
				label={ __( "Descrizione per l'ospite", 'booking-manager' ) }
				value={ form.description || '' }
				onChange={ set( 'description' ) }
			/>
			<div style={ { marginTop: 12 } }>
				<TypePicker
					types={ types }
					value={ form.room_type_ids }
					onChange={ set( 'room_type_ids' ) }
				/>
			</div>
			<h3>{ __( 'Cancellazione', 'booking-manager' ) }</h3>
			<Check
				label={ __( 'Rimborsabile', 'booking-manager' ) }
				checked={ c.refundable }
				onChange={ setC( 'refundable' ) }
			/>
			{ c.refundable && (
				<Grid columns={ 3 }>
					<NumberField
						label={ __(
							"Gratis fino a giorni prima dell'arrivo",
							'booking-manager'
						) }
						min={ 0 }
						value={ c.free_until_days }
						onChange={ ( v ) =>
							setC( 'free_until_days' )( v || 0 )
						}
					/>
					<Select
						label={ __( 'Poi si paga', 'booking-manager' ) }
						value={ c.penalty }
						options={ options( PENALTIES ) }
						onChange={ setC( 'penalty' ) }
					/>
					{ c.penalty === 'percent' && (
						<Percent
							label={ __( 'Percentuale (%)', 'booking-manager' ) }
							value={ c.percent }
							onChange={ ( v ) => setC( 'percent' )( v ?? 0 ) }
						/>
					) }
				</Grid>
			) }
			<Textarea
				label={ __(
					"Testo delle condizioni (mostrato all'ospite)",
					'booking-manager'
				) }
				value={ c.text || '' }
				onChange={ setC( 'text' ) }
			/>
			<h3>{ __( 'Caparra', 'booking-manager' ) }</h3>
			<Grid columns={ 3 }>
				<Select
					label={ __( 'Caparra richiesta', 'booking-manager' ) }
					value={ d.type }
					options={ options( DEPOSITS ) }
					onChange={ setD( 'type' ) }
				/>
				{ d.type === 'percent' && (
					<Percent
						label={ __( 'Percentuale (%)', 'booking-manager' ) }
						value={ d.value }
						onChange={ ( v ) => setD( 'value' )( v ?? 0 ) }
					/>
				) }
				{ d.type === 'fixed' && (
					<MoneyField
						label={ __( 'Importo (€)', 'booking-manager' ) }
						value={ d.value }
						onChange={ ( v ) => setD( 'value' )( v ?? 0 ) }
					/>
				) }
				{ d.type !== 'none' && (
					<NumberField
						label={ __(
							"Da pagare entro giorni prima dell'arrivo (0 = subito)",
							'booking-manager'
						) }
						min={ 0 }
						value={ d.due_days }
						onChange={ ( v ) => setD( 'due_days' )( v || 0 ) }
					/>
				) }
			</Grid>
			<div className="bkng-checks">
				<Check
					label={ __(
						'Visibile nel modulo online',
						'booking-manager'
					) }
					checked={ !! form.is_public }
					onChange={ set( 'is_public' ) }
				/>
				<Check
					label={ __( 'Piano predefinito', 'booking-manager' ) }
					checked={ !! form.is_default }
					onChange={ set( 'is_default' ) }
				/>
			</div>
		</FormModal>
	);
}

function Plans( { property, types } ) {
	const list = useLoad(
		() => get( '/properties/' + property.id + '/rate-plans' ),
		[ property.id ]
	);
	const [ modal, setModal ] = useState( null );
	const [ notice, setNotice ] = useState( '' );
	const action = useAction();
	const done = ( m ) => {
		setModal( null );
		setNotice( m );
		list.reload();
	};
	return (
		<Card
			title={ __( 'Piani tariffari', 'booking-manager' ) }
			actions={
				<Button variant="primary" onClick={ () => setModal( {} ) }>
					{ __( 'Nuovo piano', 'booking-manager' ) }
				</Button>
			}
		>
			<Success onDismiss={ () => setNotice( '' ) }>{ notice }</Success>
			{ list.loading && <Loading /> }
			<ErrorNotice
				error={ list.error || action.error }
				onRetry={ list.error ? list.reload : undefined }
			/>
			{ list.data && ! list.data.length && (
				<p className="bkng-help">
					{ __(
						'Nessun piano: si vende al prezzo base, senza condizioni particolari. Crea almeno un piano per indicare caparra e cancellazione.',
						'booking-manager'
					) }
				</p>
			) }
			<ul className="bkng-list">
				{ ( list.data || [] ).map( ( p ) => (
					<li key={ p.id }>
						<div className="bkng-list__main">
							<span className="bkng-strong">{ p.name }</span>
							{ p.is_default && (
								<span className="bkng-badge is-ok">
									{ __( 'predefinito', 'booking-manager' ) }
								</span>
							) }
							{ ! p.is_public && (
								<span className="bkng-badge">
									{ __( 'solo staff', 'booking-manager' ) }
								</span>
							) }
							<div className="bkng-small bkng-muted">
								{ MEAL_PLANS[ p.meal_plan ] } ·{ ' ' }
								{ p.cancellation_policy.refundable
									? __( 'Rimborsabile', 'booking-manager' )
									: __(
											'Non rimborsabile',
											'booking-manager'
									  ) }{ ' ' }
								· { DEPOSITS[ p.deposit_policy.type ] }
								{ p.modifier_bp
									? ' · ' +
									  ( p.modifier_bp > 0 ? '+' : '' ) +
									  bpText( p.modifier_bp ) +
									  '%'
									: '' }
							</div>
						</div>
						<Button
							variant="link"
							onClick={ () => setModal( { plan: p } ) }
						>
							{ __( 'Modifica', 'booking-manager' ) }
						</Button>
						<Button
							variant="link"
							isDestructive
							onClick={ () => setModal( { archive: p } ) }
						>
							{ __( 'Archivia', 'booking-manager' ) }
						</Button>
					</li>
				) ) }
			</ul>
			{ modal && ! modal.archive && (
				<PlanModal
					property={ property }
					types={ types }
					plan={ modal.plan }
					onClose={ () => setModal( null ) }
					onDone={ done }
				/>
			) }
			{ modal?.archive && (
				<Confirm
					destructive
					title={ __( 'Archivia il piano', 'booking-manager' ) }
					text={ __(
						'Non sarà più proposto. Le prenotazioni già fatte mantengono le loro condizioni.',
						'booking-manager'
					) }
					confirmLabel={ __( 'Archivia', 'booking-manager' ) }
					busy={ action.busy }
					onCancel={ () => setModal( null ) }
					onConfirm={ async () =>
						( await action.run( () =>
							post(
								'/rate-plans/' + modal.archive.id + '/archive'
							)
						) )
							? done(
									__( 'Piano archiviato.', 'booking-manager' )
							  )
							: setModal( null )
					}
				/>
			) }
		</Card>
	);
}

/* ------------------------------------------------------------------ Prices */

function PriceModal( { property, types, plans, price, onClose, onDone } ) {
	const action = useAction();
	const [ form, setForm ] = useState(
		price || {
			room_type_id: types[ 0 ]?.id,
			rate_plan_id: null,
			label: '',
			date_from: property.today,
			date_to: property.today,
			weekdays: '1,2,3,4,5,6,7',
			price_night: null,
			extra_adult_price: null,
			extra_child_price: null,
			priority: 0,
		}
	);
	const set = ( k ) => ( v ) => setForm( ( f ) => ( { ...f, [ k ]: v } ) );
	return (
		<FormModal
			title={
				price
					? __( 'Modifica prezzo', 'booking-manager' )
					: __( 'Nuovo prezzo per periodo', 'booking-manager' )
			}
			onClose={ onClose }
			busy={ action.busy }
			error={ action.error }
			onSubmit={ async () => {
				const body = {
					...form,
					room_type_id: Number( form.room_type_id ),
					rate_plan_id: form.rate_plan_id
						? Number( form.rate_plan_id )
						: null,
				};
				delete body.id;
				const res = await action.run( () =>
					price
						? put( '/prices/' + price.id, body )
						: post( '/properties/' + property.id + '/prices', body )
				);
				if ( res ) {
					onDone( __( 'Prezzo salvato.', 'booking-manager' ) );
				}
			} }
		>
			<Grid>
				<Select
					label={ __( 'Tipologia', 'booking-manager' ) }
					value={ String( form.room_type_id ) }
					options={ types.map( ( t ) => ( {
						value: String( t.id ),
						label: t.name,
					} ) ) }
					onChange={ set( 'room_type_id' ) }
				/>
				<Select
					label={ __( 'Piano', 'booking-manager' ) }
					value={ String( form.rate_plan_id || '' ) }
					options={ [
						{
							value: '',
							label: __( 'Tutti i piani', 'booking-manager' ),
						},
						...plans.map( ( p ) => ( {
							value: String( p.id ),
							label: p.name,
						} ) ),
					] }
					onChange={ set( 'rate_plan_id' ) }
				/>
				<DateField
					label={ __( 'Dal', 'booking-manager' ) }
					value={ form.date_from }
					onChange={ set( 'date_from' ) }
				/>
				<DateField
					label={ __( 'Al (compreso)', 'booking-manager' ) }
					value={ form.date_to }
					min={ form.date_from }
					onChange={ set( 'date_to' ) }
				/>
				<MoneyField
					label={ __( 'Prezzo a notte (€)', 'booking-manager' ) }
					value={ form.price_night }
					onChange={ set( 'price_night' ) }
					required
				/>
				<Text
					label={ __( 'Nome del periodo', 'booking-manager' ) }
					value={ form.label || '' }
					onChange={ set( 'label' ) }
					placeholder={ __( 'es. Alta stagione', 'booking-manager' ) }
				/>
				<MoneyField
					label={ __(
						'Adulto in più (€, vuoto = come tipologia)',
						'booking-manager'
					) }
					value={ form.extra_adult_price }
					onChange={ set( 'extra_adult_price' ) }
				/>
				<MoneyField
					label={ __(
						'Bambino in più (€, vuoto = come tipologia)',
						'booking-manager'
					) }
					value={ form.extra_child_price }
					onChange={ set( 'extra_child_price' ) }
				/>
			</Grid>
			<Weekdays
				label={ __( 'Giorni della settimana', 'booking-manager' ) }
				value={ form.weekdays }
				onChange={ set( 'weekdays' ) }
			/>
			<NumberField
				label={ __(
					'Priorità (vince il numero più alto se i periodi si sovrappongono)',
					'booking-manager'
				) }
				value={ form.priority }
				onChange={ ( v ) => set( 'priority' )( v || 0 ) }
			/>
		</FormModal>
	);
}

function Prices( { property, types, plans } ) {
	const list = useLoad(
		() => get( '/properties/' + property.id + '/prices' ),
		[ property.id ]
	);
	const [ modal, setModal ] = useState( null );
	const [ notice, setNotice ] = useState( '' );
	const action = useAction();
	const typeName = ( id ) => types.find( ( t ) => t.id === id )?.name || '';
	const done = ( m ) => {
		setModal( null );
		setNotice( m );
		list.reload();
	};
	return (
		<Card
			title={ __( 'Prezzi per periodo', 'booking-manager' ) }
			actions={
				types.length > 0 && (
					<Button variant="primary" onClick={ () => setModal( {} ) }>
						{ __( 'Nuovo prezzo', 'booking-manager' ) }
					</Button>
				)
			}
		>
			<p className="bkng-help">
				{ __(
					"Dove non c'è un prezzo per la data vale il prezzo base della tipologia.",
					'booking-manager'
				) }
			</p>
			<Success onDismiss={ () => setNotice( '' ) }>{ notice }</Success>
			{ list.loading && <Loading /> }
			<ErrorNotice error={ list.error || action.error } />
			{ list.data && list.data.length > 0 && (
				<div className="bkng-table-wrap">
					<table className="bkng-table">
						<thead>
							<tr>
								<th>{ __( 'Periodo', 'booking-manager' ) }</th>
								<th>
									{ __( 'Tipologia', 'booking-manager' ) }
								</th>
								<th>{ __( 'Piano', 'booking-manager' ) }</th>
								<th>{ __( 'Giorni', 'booking-manager' ) }</th>
								<th className="is-num">
									{ __( 'A notte', 'booking-manager' ) }
								</th>
								<th />
							</tr>
						</thead>
						<tbody>
							{ list.data.map( ( p ) => (
								<tr key={ p.id }>
									<td>
										{ p.label && (
											<div className="bkng-strong">
												{ p.label }
											</div>
										) }
										{ date( p.date_from ) } →{ ' ' }
										{ date( p.date_to ) }
									</td>
									<td>{ typeName( p.room_type_id ) }</td>
									<td>
										{ p.rate_plan_id
											? plans.find(
													( x ) =>
														x.id === p.rate_plan_id
											  )?.name
											: __( 'Tutti', 'booking-manager' ) }
									</td>
									<td className="bkng-small">
										{ String( p.weekdays || '' ) ===
										'1,2,3,4,5,6,7'
											? __( 'Tutti', 'booking-manager' )
											: String( p.weekdays || '' )
													.split( ',' )
													.map(
														( n ) =>
															WEEKDAYS.find(
																( w ) =>
																	w[ 0 ] ===
																	Number( n )
															)?.[ 1 ]
													)
													.join( ' ' ) }
									</td>
									<td className="is-num">
										{ money(
											p.price_night,
											property.currency
										) }
									</td>
									<td className="bkng-right bkng-nowrap">
										<Button
											variant="link"
											onClick={ () =>
												setModal( { price: p } )
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
												action
													.run( () =>
														del( '/prices/' + p.id )
													)
													.then(
														( r ) =>
															r &&
															done(
																__(
																	'Prezzo eliminato.',
																	'booking-manager'
																)
															)
													)
											}
										>
											{ __(
												'Elimina',
												'booking-manager'
											) }
										</Button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
			{ modal && (
				<PriceModal
					property={ property }
					types={ types }
					plans={ plans }
					price={ modal.price }
					onClose={ () => setModal( null ) }
					onDone={ done }
				/>
			) }
		</Card>
	);
}

/* ------------------------------------------------------------------ Extras and discounts */

function AdjustmentModal( { property, types, plans, adj, onClose, onDone } ) {
	const action = useAction();
	const [ form, setForm ] = useState(
		adj || {
			kind: 'supplement',
			name: '',
			description: '',
			amount: null,
			percent_bp: null,
			per: 'stay',
			min_nights: null,
			max_nights: null,
			date_from: '',
			date_to: '',
			room_type_ids: [],
			rate_plan_id: null,
			is_optional: true,
			is_public: true,
			free_cancel_days: null,
		}
	);
	const [ usePercent, setUsePercent ] = useState(
		!! ( adj && adj.percent_bp )
	);
	const set = ( k ) => ( v ) => setForm( ( f ) => ( { ...f, [ k ]: v } ) );
	const offersCancel = 'supplement' === form.kind && !! form.is_optional;
	return (
		<FormModal
			wide
			title={
				adj
					? adj.name
					: __( 'Nuovo supplemento o sconto', 'booking-manager' )
			}
			onClose={ onClose }
			busy={ action.busy }
			error={ action.error }
			onSubmit={ async () => {
				const body = {
					...form,
					amount: usePercent ? null : form.amount,
					percent_bp: usePercent ? form.percent_bp : null,
					date_from: form.date_from || null,
					date_to: form.date_to || null,
					rate_plan_id: form.rate_plan_id
						? Number( form.rate_plan_id )
						: null,
					free_cancel_days: offersCancel
						? form.free_cancel_days || null
						: null,
				};
				delete body.id;
				const res = await action.run( () =>
					adj
						? put( '/adjustments/' + adj.id, body )
						: post(
								'/properties/' + property.id + '/adjustments',
								body
						  )
				);
				if ( res ) {
					onDone( __( 'Salvato.', 'booking-manager' ) );
				}
			} }
		>
			<Grid columns={ 3 }>
				<Select
					label={ __( 'Tipo', 'booking-manager' ) }
					value={ form.kind }
					options={ options( ADJ_KINDS ) }
					onChange={ set( 'kind' ) }
				/>
				<Text
					label={ __( 'Nome', 'booking-manager' ) }
					value={ form.name }
					onChange={ set( 'name' ) }
					required
					placeholder={ __(
						'es. Animale domestico',
						'booking-manager'
					) }
				/>
				<Select
					label={ __( 'Calcolo', 'booking-manager' ) }
					value={ form.per }
					options={ options( ADJ_PER ) }
					onChange={ set( 'per' ) }
				/>
			</Grid>
			<Check
				label={ __(
					'In percentuale sul soggiorno',
					'booking-manager'
				) }
				checked={ usePercent }
				onChange={ setUsePercent }
			/>
			<Grid columns={ 3 }>
				{ usePercent ? (
					<Percent
						label={ __( 'Percentuale (%)', 'booking-manager' ) }
						value={ form.percent_bp }
						onChange={ set( 'percent_bp' ) }
					/>
				) : (
					<MoneyField
						label={ __( 'Importo (€)', 'booking-manager' ) }
						value={ form.amount }
						onChange={ set( 'amount' ) }
					/>
				) }
				<NumberField
					label={ __( 'Da notti', 'booking-manager' ) }
					min={ 1 }
					value={ form.min_nights }
					onChange={ set( 'min_nights' ) }
				/>
				<NumberField
					label={ __( 'Fino a notti', 'booking-manager' ) }
					min={ 1 }
					value={ form.max_nights }
					onChange={ set( 'max_nights' ) }
				/>
				<DateField
					label={ __( 'Valido dal', 'booking-manager' ) }
					value={ form.date_from || '' }
					onChange={ set( 'date_from' ) }
				/>
				<DateField
					label={ __( 'al', 'booking-manager' ) }
					value={ form.date_to || '' }
					onChange={ set( 'date_to' ) }
				/>
				<Select
					label={ __( 'Piano', 'booking-manager' ) }
					value={ String( form.rate_plan_id || '' ) }
					options={ [
						{
							value: '',
							label: __( 'Tutti i piani', 'booking-manager' ),
						},
						...plans.map( ( p ) => ( {
							value: String( p.id ),
							label: p.name,
						} ) ),
					] }
					onChange={ set( 'rate_plan_id' ) }
				/>
			</Grid>
			<TypePicker
				types={ types }
				value={ form.room_type_ids }
				onChange={ set( 'room_type_ids' ) }
			/>
			<Textarea
				label={ __( 'Descrizione', 'booking-manager' ) }
				value={ form.description || '' }
				onChange={ set( 'description' ) }
			/>
			<div className="bkng-checks" style={ { marginTop: 12 } }>
				<Check
					label={ __(
						"Facoltativo (l'ospite lo sceglie)",
						'booking-manager'
					) }
					checked={ !! form.is_optional }
					onChange={ set( 'is_optional' ) }
				/>
				<Check
					label={ __(
						'Visibile nel modulo online',
						'booking-manager'
					) }
					checked={ !! form.is_public }
					onChange={ set( 'is_public' ) }
				/>
			</div>
			{ offersCancel && (
				<Grid columns={ 2 }>
					<NumberField
						label={ __(
							'Include la cancellazione gratuita fino a (giorni prima dell’arrivo)',
							'booking-manager'
						) }
						min={ 1 }
						max={ 365 }
						value={ form.free_cancel_days }
						onChange={ set( 'free_cancel_days' ) }
						help={ __(
							"Lascia vuoto per un servizio normale (es. colazione). Con i giorni, chi sceglie l'opzione può cancellare gratis fino a quel giorno, anche su una tariffa non rimborsabile.",
							'booking-manager'
						) }
					/>
				</Grid>
			) }
		</FormModal>
	);
}

function Adjustments( { property, types, plans } ) {
	const list = useLoad(
		() => get( '/properties/' + property.id + '/adjustments' ),
		[ property.id ]
	);
	const [ modal, setModal ] = useState( null );
	const [ notice, setNotice ] = useState( '' );
	const action = useAction();
	const done = ( m ) => {
		setModal( null );
		setNotice( m );
		list.reload();
	};
	return (
		<Card
			title={ __( 'Supplementi e sconti', 'booking-manager' ) }
			actions={
				<Button variant="primary" onClick={ () => setModal( {} ) }>
					{ __( 'Nuovo', 'booking-manager' ) }
				</Button>
			}
		>
			<Success onDismiss={ () => setNotice( '' ) }>{ notice }</Success>
			{ list.loading && <Loading /> }
			<ErrorNotice error={ list.error || action.error } />
			{ list.data && ! list.data.length && (
				<p className="bkng-help">
					{ __(
						'Es. colazione, animale, letto aggiunto, sconto 7 notti.',
						'booking-manager'
					) }
				</p>
			) }
			<ul className="bkng-list">
				{ ( list.data || [] ).map( ( a ) => (
					<li key={ a.id }>
						<div className="bkng-list__main">
							<span className="bkng-strong">{ a.name }</span> ·{ ' ' }
							{ ADJ_KINDS[ a.kind ] }
							<div className="bkng-small bkng-muted">
								{ a.percent_bp
									? bpText( a.percent_bp ) + '%'
									: money(
											a.amount,
											property.currency
									  ) }{ ' ' }
								{ ADJ_PER[ a.per ] }
								{ a.is_optional
									? ' · ' +
									  __( 'facoltativo', 'booking-manager' )
									: ' · ' +
									  __( 'automatico', 'booking-manager' ) }
								{ a.free_cancel_days
									? ' · ' +
									  sprintf(
											/* translators: %d: days before arrival */
											__(
												'cancellazione gratuita fino a %d giorni prima',
												'booking-manager'
											),
											a.free_cancel_days
									  )
									: '' }
							</div>
						</div>
						<Button
							variant="link"
							onClick={ () => setModal( { adj: a } ) }
						>
							{ __( 'Modifica', 'booking-manager' ) }
						</Button>
						<Button
							variant="link"
							isDestructive
							onClick={ () =>
								action
									.run( () =>
										post(
											'/adjustments/' + a.id + '/archive'
										)
									)
									.then(
										( r ) =>
											r &&
											done(
												__(
													'Archiviato.',
													'booking-manager'
												)
											)
									)
							}
						>
							{ __( 'Archivia', 'booking-manager' ) }
						</Button>
					</li>
				) ) }
			</ul>
			{ modal && (
				<AdjustmentModal
					property={ property }
					types={ types }
					plans={ plans }
					adj={ modal.adj }
					onClose={ () => setModal( null ) }
					onDone={ done }
				/>
			) }
		</Card>
	);
}

/* ------------------------------------------------------------------ Stay rules */

function RuleModal( { property, types, plans, rule, onClose, onDone } ) {
	const action = useAction();
	const [ form, setForm ] = useState(
		rule || {
			date_from: property.today,
			date_to: property.today,
			room_type_id: null,
			rate_plan_id: null,
			min_stay: null,
			max_stay: null,
			min_advance_days: null,
			max_advance_days: null,
			arrival_weekdays: '',
			departure_weekdays: '',
			closed: false,
			closed_to_arrival: false,
			closed_to_departure: false,
			note: '',
		}
	);
	const set = ( k ) => ( v ) => setForm( ( f ) => ( { ...f, [ k ]: v } ) );
	return (
		<FormModal
			wide
			title={
				rule
					? __( 'Modifica regola', 'booking-manager' )
					: __( 'Nuova regola di soggiorno', 'booking-manager' )
			}
			onClose={ onClose }
			busy={ action.busy }
			error={ action.error }
			onSubmit={ async () => {
				const body = {
					...form,
					room_type_id: form.room_type_id
						? Number( form.room_type_id )
						: null,
					rate_plan_id: form.rate_plan_id
						? Number( form.rate_plan_id )
						: null,
				};
				delete body.id;
				const res = await action.run( () =>
					rule
						? put( '/restrictions/' + rule.id, body )
						: post(
								'/properties/' + property.id + '/restrictions',
								body
						  )
				);
				if ( res ) {
					onDone( __( 'Regola salvata.', 'booking-manager' ) );
				}
			} }
		>
			<Grid columns={ 4 }>
				<DateField
					label={ __( 'Dal', 'booking-manager' ) }
					value={ form.date_from }
					onChange={ set( 'date_from' ) }
				/>
				<DateField
					label={ __( 'Al (compreso)', 'booking-manager' ) }
					value={ form.date_to }
					min={ form.date_from }
					onChange={ set( 'date_to' ) }
				/>
				<Select
					label={ __( 'Tipologia', 'booking-manager' ) }
					value={ String( form.room_type_id || '' ) }
					options={ [
						{ value: '', label: __( 'Tutte', 'booking-manager' ) },
						...types.map( ( t ) => ( {
							value: String( t.id ),
							label: t.name,
						} ) ),
					] }
					onChange={ set( 'room_type_id' ) }
				/>
				<Select
					label={ __( 'Piano', 'booking-manager' ) }
					value={ String( form.rate_plan_id || '' ) }
					options={ [
						{ value: '', label: __( 'Tutti', 'booking-manager' ) },
						...plans.map( ( p ) => ( {
							value: String( p.id ),
							label: p.name,
						} ) ),
					] }
					onChange={ set( 'rate_plan_id' ) }
				/>
				<NumberField
					label={ __(
						'Soggiorno minimo (notti)',
						'booking-manager'
					) }
					min={ 1 }
					value={ form.min_stay }
					onChange={ set( 'min_stay' ) }
				/>
				<NumberField
					label={ __(
						'Soggiorno massimo (notti)',
						'booking-manager'
					) }
					min={ 1 }
					value={ form.max_stay }
					onChange={ set( 'max_stay' ) }
				/>
				<NumberField
					label={ __(
						'Anticipo minimo (giorni)',
						'booking-manager'
					) }
					min={ 0 }
					value={ form.min_advance_days }
					onChange={ set( 'min_advance_days' ) }
				/>
				<NumberField
					label={ __(
						'Anticipo massimo (giorni)',
						'booking-manager'
					) }
					min={ 0 }
					value={ form.max_advance_days }
					onChange={ set( 'max_advance_days' ) }
				/>
			</Grid>
			<div className="bkng-checks">
				<Check
					label={ __(
						'Chiuso (nessuna vendita)',
						'booking-manager'
					) }
					checked={ !! form.closed }
					onChange={ set( 'closed' ) }
				/>
				<Check
					label={ __( 'Arrivi chiusi', 'booking-manager' ) }
					checked={ !! form.closed_to_arrival }
					onChange={ set( 'closed_to_arrival' ) }
				/>
				<Check
					label={ __( 'Partenze chiuse', 'booking-manager' ) }
					checked={ !! form.closed_to_departure }
					onChange={ set( 'closed_to_departure' ) }
				/>
			</div>
			<Weekdays
				label={ __(
					'Arrivo solo nei giorni (nessuno = tutti)',
					'booking-manager'
				) }
				value={ form.arrival_weekdays }
				onChange={ set( 'arrival_weekdays' ) }
			/>
			<Weekdays
				label={ __(
					'Partenza solo nei giorni (nessuno = tutti)',
					'booking-manager'
				) }
				value={ form.departure_weekdays }
				onChange={ set( 'departure_weekdays' ) }
			/>
			<Text
				label={ __( 'Nota', 'booking-manager' ) }
				value={ form.note || '' }
				onChange={ set( 'note' ) }
			/>
		</FormModal>
	);
}

function Rules( { property, types, plans } ) {
	const list = useLoad(
		() =>
			get( '/properties/' + property.id + '/restrictions', {
				from: property.today,
			} ),
		[ property.id ]
	);
	const [ modal, setModal ] = useState( null );
	const [ notice, setNotice ] = useState( '' );
	const action = useAction();
	const done = ( m ) => {
		setModal( null );
		setNotice( m );
		list.reload();
	};
	const describe = ( r ) =>
		[
			r.closed && __( 'chiuso', 'booking-manager' ),
			r.closed_to_arrival && __( 'arrivi chiusi', 'booking-manager' ),
			r.closed_to_departure && __( 'partenze chiuse', 'booking-manager' ),
			r.min_stay &&
				sprintf(
					/* translators: %d: nights. */ __(
						'minimo %d notti',
						'booking-manager'
					),
					r.min_stay
				),
			r.max_stay &&
				sprintf(
					/* translators: %d: nights. */ __(
						'massimo %d notti',
						'booking-manager'
					),
					r.max_stay
				),
			r.min_advance_days &&
				sprintf(
					/* translators: %d: days. */ __(
						'almeno %d giorni prima',
						'booking-manager'
					),
					r.min_advance_days
				),
			r.arrival_weekdays &&
				__( 'giorni di arrivo fissi', 'booking-manager' ),
		]
			.filter( Boolean )
			.join( ', ' );
	return (
		<Card
			title={ __( 'Regole di soggiorno', 'booking-manager' ) }
			actions={
				<Button variant="primary" onClick={ () => setModal( {} ) }>
					{ __( 'Nuova regola', 'booking-manager' ) }
				</Button>
			}
		>
			<p className="bkng-help">
				{ __(
					'Le regole generali (soggiorno minimo, anticipo) sono in Struttura. Qui le eccezioni per periodo: Ferragosto minimo 7 notti, chiusure, arrivo solo il sabato.',
					'booking-manager'
				) }
			</p>
			<Success onDismiss={ () => setNotice( '' ) }>{ notice }</Success>
			{ list.loading && <Loading /> }
			<ErrorNotice error={ list.error || action.error } />
			<ul className="bkng-list">
				{ ( list.data || [] ).map( ( r ) => (
					<li key={ r.id }>
						<div className="bkng-list__main">
							<span className="bkng-strong">
								{ date( r.date_from ) } → { date( r.date_to ) }
							</span>
							<div className="bkng-small bkng-muted">
								{ describe( r ) }
								{ r.room_type_id
									? ' · ' +
									  ( types.find(
											( t ) => t.id === r.room_type_id
									  )?.name || '' )
									: '' }
								{ r.note ? ' · ' + r.note : '' }
							</div>
						</div>
						<Button
							variant="link"
							onClick={ () => setModal( { rule: r } ) }
						>
							{ __( 'Modifica', 'booking-manager' ) }
						</Button>
						<Button
							variant="link"
							isDestructive
							onClick={ () =>
								action
									.run( () => del( '/restrictions/' + r.id ) )
									.then(
										( x ) =>
											x &&
											done(
												__(
													'Regola eliminata.',
													'booking-manager'
												)
											)
									)
							}
						>
							{ __( 'Elimina', 'booking-manager' ) }
						</Button>
					</li>
				) ) }
			</ul>
			{ modal && (
				<RuleModal
					property={ property }
					types={ types }
					plans={ plans }
					rule={ modal.rule }
					onClose={ () => setModal( null ) }
					onDone={ done }
				/>
			) }
		</Card>
	);
}

export default function Rates() {
	const { property } = useProperty();
	const [ tab, setTab ] = useState( 'plans' );
	const types = useLoad(
		() => get( '/properties/' + property.id + '/room-types' ),
		[ property.id ]
	);
	const plans = useLoad(
		() => get( '/properties/' + property.id + '/rate-plans' ),
		[ property.id, tab ]
	);

	if ( types.loading && ! types.data ) {
		return <Loading />;
	}
	if ( types.error ) {
		return <ErrorNotice error={ types.error } onRetry={ types.reload } />;
	}
	const t = types.data;
	const p = plans.data || [];
	return (
		<Page title={ __( 'Tariffe', 'booking-manager' ) }>
			{ ! t.length && (
				<Empty
					title={ __( 'Prima le camere', 'booking-manager' ) }
					text={ __(
						'Crea almeno una tipologia in «Camere» per impostare le tariffe.',
						'booking-manager'
					) }
				/>
			) }
			{ t.length > 0 && (
				<>
					<Tabs
						current={ tab }
						onChange={ setTab }
						tabs={ [
							[
								'plans',
								__( 'Piani tariffari', 'booking-manager' ),
							],
							[
								'prices',
								__( 'Prezzi per periodo', 'booking-manager' ),
							],
							[
								'adjustments',
								__( 'Supplementi e sconti', 'booking-manager' ),
							],
							[
								'rules',
								__( 'Regole di soggiorno', 'booking-manager' ),
							],
						] }
					/>
					{ tab === 'plans' && (
						<Plans property={ property } types={ t } />
					) }
					{ tab === 'prices' && (
						<Prices property={ property } types={ t } plans={ p } />
					) }
					{ tab === 'adjustments' && (
						<Adjustments
							property={ property }
							types={ t }
							plans={ p }
						/>
					) }
					{ tab === 'rules' && (
						<Rules property={ property } types={ t } plans={ p } />
					) }
				</>
			) }
		</Page>
	);
}
