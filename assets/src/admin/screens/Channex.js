/**
 * Channex (bkng_manage_integrations): the channel manager that carries availability,
 * prices and rules to Booking.com (and the other portals connected there) and brings
 * their reservations back.
 *
 * Warnings on top, then four tabs. Collegamento: key, environment, webhook.
 * Mappatura: the property, its room types and rates linked to Channex's.
 * Sincronizzazione: queue, pause, buttons and the register of calls (task id).
 * Prenotazioni: reservations received, with what happened to each one.
 */
import { Button } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { get, post, put } from '../lib/api';
import { date, dateTime } from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';
import { useProperty } from '../lib/property';
import {
	Card,
	Check,
	Confirm,
	Empty,
	ErrorNotice,
	Kpi,
	Loading,
	Page,
	Select,
	Success,
	Tabs,
	Text,
	Toggle,
} from '../components/ui';

const TABS = [
	[ 'connection', __( 'Collegamento', 'booking-manager' ) ],
	[ 'mapping', __( 'Mappatura', 'booking-manager' ) ],
	[ 'sync', __( 'Sincronizzazione', 'booking-manager' ) ],
	[ 'bookings', __( 'Prenotazioni ricevute', 'booking-manager' ) ],
];

const OUTCOMES = {
	created: [ 'ok', __( 'Creata', 'booking-manager' ) ],
	modified: [ 'info', __( 'Modificata', 'booking-manager' ) ],
	cancelled: [ 'muted', __( 'Annullata', 'booking-manager' ) ],
	unmapped: [ 'bad', __( 'Camera non collegata', 'booking-manager' ) ],
	ignored: [ 'muted', __( 'Ignorata', 'booking-manager' ) ],
	duplicate: [ 'muted', __( 'Già ricevuta', 'booking-manager' ) ],
};

const KINDS = {
	availability: __( 'Disponibilità', 'booking-manager' ),
	restrictions: __( 'Prezzi e regole', 'booking-manager' ),
	full_availability: __( 'Disponibilità (tutto)', 'booking-manager' ),
	full_restrictions: __( 'Prezzi e regole (tutto)', 'booking-manager' ),
};

function Tone( { tone, children } ) {
	return <span className={ 'bkng-badge is-' + tone }>{ children }</span>;
}

/** Copies a value, saying so on the button for a moment. */
function CopyButton( { text, label } ) {
	const [ done, setDone ] = useState( false );
	return (
		<Button
			variant="secondary"
			size="compact"
			onClick={ async () => {
				try {
					await window.navigator.clipboard.writeText( text );
					setDone( true );
					window.setTimeout( () => setDone( false ), 2000 );
				} catch ( e ) {
					window.prompt( label, text ); // eslint-disable-line no-alert
				}
			} }
		>
			{ done ? __( 'Copiato', 'booking-manager' ) : label }
		</Button>
	);
}

/* ------------------------------------------------------------------ Avvisi */

function Alerts( { overview, setOverview } ) {
	const action = useAction();
	const unread = overview.alerts.filter( ( a ) => ! a.read );
	if ( ! unread.length ) {
		return null;
	}
	return (
		<Card
			title={ __( 'Avvisi da controllare', 'booking-manager' ) }
			className="bkng-cx__alerts"
			actions={
				<Button
					variant="secondary"
					size="compact"
					isBusy={ action.busy }
					onClick={ async () => {
						const o = await action.run( () =>
							post( '/channex/alerts/read' )
						);
						if ( o ) {
							setOverview( o );
						}
					} }
				>
					{ __( 'Segna come letti', 'booking-manager' ) }
				</Button>
			}
		>
			<ErrorNotice error={ action.error } />
			<ul className="bkng-cx__alert-list">
				{ unread.slice( 0, 8 ).map( ( a ) => (
					<li key={ a.id }>
						<span className="bkng-muted bkng-small">
							{ dateTime( a.at ) }
						</span>{ ' ' }
						{ a.message }
					</li>
				) ) }
			</ul>
		</Card>
	);
}

/* ------------------------------------------------------------------ Collegamento */

function ConnectionTab( { overview, setOverview, notify } ) {
	const s = overview.settings;
	const [ form, setForm ] = useState( {
		environment: s.environment,
		base_url: s.base_url,
		nightly: s.nightly,
		api_key: '',
		webhook_secret: '',
	} );
	const [ test, setTest ] = useState( null );
	const action = useAction();
	const set = ( key ) => ( value ) => setForm( { ...form, [ key ]: value } );

	const save = async ( extra = {} ) => {
		const o = await action.run( () =>
			put( '/channex/settings', { ...form, ...extra } )
		);
		if ( o ) {
			setOverview( o );
			setForm( { ...form, api_key: '', webhook_secret: '' } );
			notify( __( 'Impostazioni salvate.', 'booking-manager' ) );
		}
	};

	const run = async ( path, message ) => {
		const o = await action.run( () => post( path ) );
		if ( o ) {
			if ( o.settings ) {
				setOverview( o );
			}
			notify( message );
		}
		return o;
	};

	return (
		<div className="bkng-stack">
			<Card title={ __( 'Account Channex', 'booking-manager' ) }>
				<ErrorNotice error={ action.error } />
				{ s.suspended && (
					<p className="bkng-help is-error" role="alert">
						{ __(
							'Sincronizzazione sospesa: Channex ha rifiutato la chiave. Inseriscila di nuovo.',
							'booking-manager'
						) }{ ' ' }
						({ s.suspended })
					</p>
				) }
				<div className="bkng-grid is-2">
					<Select
						label={ __( 'Ambiente', 'booking-manager' ) }
						value={ form.environment }
						options={ [
							{
								value: 'staging',
								label: __(
									'Prova (staging.channex.io)',
									'booking-manager'
								),
							},
							{
								value: 'production',
								label: __( 'Produzione', 'booking-manager' ),
							},
						] }
						onChange={ set( 'environment' ) }
						disabled={ s.base_url_constant }
					/>
					{ form.environment === 'production' && (
						<Text
							label={ __(
								'Indirizzo API di produzione',
								'booking-manager'
							) }
							help={ __(
								'Te lo dà Channex dopo la certificazione, ad esempio https://app.channex.io/api/v1',
								'booking-manager'
							) }
							value={ form.base_url }
							onChange={ set( 'base_url' ) }
							disabled={ s.base_url_constant }
						/>
					) }
				</div>
				<p className="bkng-help">
					{ sprintf(
						/* translators: %s: address of the API */
						__( 'Le chiamate vanno a %s', 'booking-manager' ),
						s.effective_url
					) }
				</p>
				<div className="bkng-grid is-2">
					<Text
						label={ __( 'Chiave API', 'booking-manager' ) }
						type="password"
						autoComplete="off"
						value={ form.api_key }
						onChange={ set( 'api_key' ) }
						disabled={ s.api_key_constant }
						help={ keyHelp( s ) }
					/>
					<Text
						label={ __(
							'Chiave di firma dei webhook',
							'booking-manager'
						) }
						type="password"
						autoComplete="off"
						value={ form.webhook_secret }
						onChange={ set( 'webhook_secret' ) }
						disabled={ s.webhook_secret_constant }
						help={ secretHelp( s ) }
					/>
				</div>
				<Check
					label={ __(
						'Risincronizza tutto ogni notte (alle 3, una struttura dopo l’altra)',
						'booking-manager'
					) }
					checked={ form.nightly }
					onChange={ set( 'nightly' ) }
				/>
				<div className="bkng-row">
					<Button
						variant="primary"
						isBusy={ action.busy }
						disabled={ action.busy }
						onClick={ () => save() }
					>
						{ __( 'Salva', 'booking-manager' ) }
					</Button>
					<Button
						variant="secondary"
						disabled={ action.busy || ! s.has_api_key }
						onClick={ async () => {
							setTest( null );
							const r = await action.run( () =>
								post( '/channex/test' )
							);
							if ( r ) {
								setTest( r.properties );
							}
						} }
					>
						{ __( 'Prova la chiave', 'booking-manager' ) }
					</Button>
				</div>
				{ test && (
					<p className="bkng-help is-ok" aria-live="polite">
						{ test.length
							? sprintf(
									/* translators: %s: names of the properties */
									__(
										'Chiave valida. Strutture viste da Channex: %s',
										'booking-manager'
									),
									test.map( ( p ) => p.title ).join( ', ' )
							  )
							: __(
									'Chiave valida, ma su Channex non c’è ancora nessuna struttura.',
									'booking-manager'
							  ) }
					</p>
				) }
			</Card>

			<Card title={ __( 'Sincronizzazione', 'booking-manager' ) }>
				<Toggle
					label={ __(
						'Invia disponibilità e prezzi e ricevi le prenotazioni',
						'booking-manager'
					) }
					help={ __(
						'Quando la accendi, ogni struttura collegata viene inviata tutta una volta; poi partono solo le modifiche.',
						'booking-manager'
					) }
					checked={ s.active }
					disabled={ action.busy || ! s.has_api_key }
					onChange={ ( active ) => save( { active } ) }
				/>
			</Card>

			<Card title={ __( 'Webhook', 'booking-manager' ) }>
				<p className="bkng-help">
					{ __(
						'Channex avvisa il sito a ogni prenotazione: il sito poi legge le prenotazioni e le conferma. Se un avviso si perde, il sito controlla comunque ogni 2 minuti.',
						'booking-manager'
					) }
				</p>
				<div className="bkng-cx__url">
					<code>{ overview.webhook_url }</code>
					<CopyButton
						text={ overview.webhook_url }
						label={ __( 'Copia', 'booking-manager' ) }
					/>
				</div>
				<div className="bkng-row">
					<Button
						variant="secondary"
						disabled={ action.busy || ! s.has_api_key }
						onClick={ () =>
							run(
								'/channex/signing-key',
								__(
									'Chiave di firma salvata.',
									'booking-manager'
								)
							)
						}
					>
						{ __( 'Genera chiave di firma', 'booking-manager' ) }
					</Button>
					<Button
						variant="secondary"
						disabled={
							action.busy ||
							! s.has_api_key ||
							! s.has_webhook_secret
						}
						onClick={ () =>
							run(
								'/channex/webhook',
								__(
									'Webhook creato su Channex.',
									'booking-manager'
								)
							)
						}
					>
						{ s.webhook_id
							? __(
									'Crea di nuovo il webhook',
									'booking-manager'
							  )
							: __(
									'Crea il webhook su Channex',
									'booking-manager'
							  ) }
					</Button>
					<Button
						variant="tertiary"
						disabled={ action.busy || ! s.webhook_id }
						onClick={ () =>
							run(
								'/channex/webhook/test',
								__(
									'Channex ha inviato la prova: controlla il registro in «Prenotazioni ricevute».',
									'booking-manager'
								)
							)
						}
					>
						{ __( 'Prova il webhook', 'booking-manager' ) }
					</Button>
				</div>
				{ s.webhook_id && (
					<p className="bkng-help">
						{ sprintf(
							/* translators: %s: id of the webhook */
							__( 'Webhook attivo: %s', 'booking-manager' ),
							s.webhook_id
						) }
					</p>
				) }
				{ ! overview.action_scheduler && (
					<p className="bkng-help">
						{ __(
							'Consiglio: fai chiamare wp-cron.php ogni minuto da un cron del server (o installa Action Scheduler). WP-Cron da solo parte solo quando qualcuno visita il sito.',
							'booking-manager'
						) }
					</p>
				) }
			</Card>
		</div>
	);
}

/* ------------------------------------------------------------------ Mappatura */

function MappingTab( { data, setData, notify } ) {
	const action = useAction();
	const { local, remote, mapping } = data;
	const [ propertyUuid, setPropertyUuid ] = useState(
		mapping.property || ''
	);
	const [ types, setTypes ] = useState( { ...mapping.room_types } );
	const [ plans, setPlans ] = useState( () => {
		const out = {};
		mapping.rate_plans.forEach( ( p ) => {
			out[ p.room_type_id + ':' + p.rate_plan_id ] = {
				channex_rate_plan_id: p.channex_id,
				per_person: p.per_person,
			};
		} );
		return out;
	} );

	const remoteOptions = ( list, filter ) => [
		{ value: '', label: __( '— non collegata —', 'booking-manager' ) },
		...list
			.filter( filter || ( () => true ) )
			.map( ( o ) => ( { value: o.id, label: o.title } ) ),
	];
	const changedProperty = propertyUuid !== ( mapping.property || '' );

	const save = async () => {
		const result = await action.run( () =>
			put( '/properties/' + data.property_id + '/channex/mapping', {
				channex_property_id: propertyUuid,
				room_types: changedProperty ? {} : types,
				rate_plans: changedProperty
					? []
					: Object.entries( plans ).map( ( [ key, value ] ) => {
							const [ roomTypeId, ratePlanId ] = key.split( ':' );
							return {
								room_type_id: parseInt( roomTypeId, 10 ),
								rate_plan_id: parseInt( ratePlanId, 10 ),
								...value,
							};
					  } ),
			} )
		);
		if ( result ) {
			setData( result );
			notify(
				result.linked
					? __(
							'Mappatura salvata: la struttura viene inviata tutta a Channex tra pochi secondi.',
							'booking-manager'
					  )
					: __( 'Struttura scollegata.', 'booking-manager' )
			);
		}
	};

	if ( remote.error && ! remote.properties.length ) {
		return (
			<Card>
				<p className="bkng-help is-error">{ remote.error }</p>
			</Card>
		);
	}

	return (
		<div className="bkng-stack">
			<ErrorNotice error={ action.error } />
			<Card title={ __( 'Struttura su Channex', 'booking-manager' ) }>
				<Select
					label={ __(
						'Struttura di Channex collegata a questa struttura',
						'booking-manager'
					) }
					value={ propertyUuid }
					options={ remoteOptions( remote.properties ) }
					onChange={ setPropertyUuid }
				/>
				{ changedProperty && propertyUuid && (
					<p className="bkng-help">
						{ __(
							'Salva per leggere le camere e le tariffe di questa struttura di Channex.',
							'booking-manager'
						) }
					</p>
				) }
			</Card>

			{ mapping.property && ! changedProperty && (
				<>
					<Card
						title={ __( 'Tipologie di camera', 'booking-manager' ) }
					>
						<p className="bkng-help">
							{ __(
								'A ogni tipologia del gestionale la sua su Channex: la disponibilità inviata è il numero di camere libere.',
								'booking-manager'
							) }
						</p>
						<div className="bkng-table-wrap">
							<table className="bkng-table">
								<thead>
									<tr>
										<th>
											{ __(
												'Nel gestionale',
												'booking-manager'
											) }
										</th>
										<th>
											{ __(
												'Su Channex',
												'booking-manager'
											) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ local.room_types.map( ( t ) => (
										<tr key={ t.id }>
											<td>{ t.name }</td>
											<td>
												<Select
													label={ t.name }
													hideLabelFromVision
													value={
														types[ t.id ] || ''
													}
													options={ remoteOptions(
														remote.room_types
													) }
													onChange={ ( v ) =>
														setTypes( {
															...types,
															[ t.id ]: v,
														} )
													}
												/>
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					</Card>

					<Card title={ __( 'Tariffe', 'booking-manager' ) }>
						<p className="bkng-help">
							{ __(
								'Su Channex ogni tariffa appartiene a una tipologia: collega ogni coppia tipologia + tariffa. Si inviano il prezzo per la capienza base, il soggiorno minimo, il massimo, arrivi e partenze chiusi e le chiusure.',
								'booking-manager'
							) }
						</p>
						<div className="bkng-table-wrap">
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
												'Tariffa',
												'booking-manager'
											) }
										</th>
										<th>
											{ __(
												'Su Channex',
												'booking-manager'
											) }
										</th>
										<th>
											{ __(
												'Prezzo per persona',
												'booking-manager'
											) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ local.rate_plans.map( ( p ) => {
										const key =
											p.room_type_id +
											':' +
											p.rate_plan_id;
										const row = plans[ key ] || {};
										const typeName =
											local.room_types.find(
												( t ) => t.id === p.room_type_id
											)?.name || '';
										const remoteType =
											types[ p.room_type_id ] || '';
										return (
											<tr key={ key }>
												<td>{ typeName }</td>
												<td>{ p.name }</td>
												<td>
													<Select
														label={
															typeName +
															' · ' +
															p.name
														}
														hideLabelFromVision
														disabled={
															! remoteType
														}
														value={
															row.channex_rate_plan_id ||
															''
														}
														options={ remoteOptions(
															remote.rate_plans,
															( o ) =>
																! o.room_type_id ||
																o.room_type_id ===
																	remoteType
														) }
														onChange={ ( v ) =>
															setPlans( {
																...plans,
																[ key ]: {
																	...row,
																	channex_rate_plan_id:
																		v,
																},
															} )
														}
													/>
												</td>
												<td>
													<Check
														label={ __(
															'Per occupazione',
															'booking-manager'
														) }
														checked={
															!! row.per_person
														}
														disabled={
															! row.channex_rate_plan_id
														}
														onChange={ ( v ) =>
															setPlans( {
																...plans,
																[ key ]: {
																	...row,
																	per_person:
																		v,
																},
															} )
														}
													/>
												</td>
											</tr>
										);
									} ) }
								</tbody>
							</table>
						</div>
						{ remote.error && (
							<p className="bkng-help is-error">
								{ remote.error }
							</p>
						) }
					</Card>
				</>
			) }
			<div className="bkng-row">
				<Button
					variant="primary"
					isBusy={ action.busy }
					disabled={ action.busy }
					onClick={ save }
				>
					{ __( 'Salva la mappatura', 'booking-manager' ) }
				</Button>
			</div>
		</div>
	);
}

/* ------------------------------------------------------------------ Sincronizzazione */

function SyncTab( { data, setData, notify, active } ) {
	const action = useAction();
	const [ confirm, setConfirm ] = useState( false );
	const st = data.status;
	const run = async ( path, message ) => {
		const result = await action.run( () =>
			post( '/properties/' + data.property_id + '/channex/' + path )
		);
		if ( result ) {
			setData( result );
			notify( message );
		}
		return result;
	};
	const queued = st.queue.availability + st.queue.restrictions;
	const paused =
		st.paused_until && new Date( st.paused_until ) > new Date()
			? st.paused_until
			: null;

	return (
		<div className="bkng-stack">
			<ErrorNotice error={ action.error } />
			<div className="bkng-kpis">
				<Kpi
					label={ __( 'Notti da inviare', 'booking-manager' ) }
					value={ queued }
					hint={ sprintf(
						/* translators: 1: nights of availability, 2: nights of rates */
						__(
							'%1$d disponibilità · %2$d prezzi e regole',
							'booking-manager'
						),
						st.queue.availability,
						st.queue.restrictions
					) }
				/>
				<Kpi
					label={ __( 'Ultimo invio completo', 'booking-manager' ) }
					value={
						st.last_full_sync
							? dateTime( st.last_full_sync )
							: __( 'mai', 'booking-manager' )
					}
				/>
				<Kpi
					label={ __(
						'Ultima lettura prenotazioni',
						'booking-manager'
					) }
					value={
						st.last_feed_poll
							? dateTime( st.last_feed_poll )
							: __( 'mai', 'booking-manager' )
					}
				/>
				<Kpi
					label={ __(
						'Avvisi di Channex (24 ore)',
						'booking-manager'
					) }
					value={ st.warnings_24h }
					tone={ st.warnings_24h ? 'warn' : '' }
				/>
			</div>
			{ paused && (
				<p className="bkng-help is-error" role="status">
					{ sprintf(
						/* translators: 1: time, 2: number of failures */
						__(
							'Invii in pausa fino alle %1$s dopo %2$d errori di fila: ripartono da soli.',
							'booking-manager'
						),
						dateTime( paused ),
						st.failures
					) }
				</p>
			) }
			{ st.last_error && (
				<p className="bkng-help is-error">{ st.last_error }</p>
			) }
			<div className="bkng-row">
				<Button
					variant="secondary"
					disabled={ action.busy || ! active }
					onClick={ () =>
						run(
							'push',
							__( 'Modifiche inviate.', 'booking-manager' )
						)
					}
				>
					{ __( 'Invia ora le modifiche', 'booking-manager' ) }
				</Button>
				<Button
					variant="secondary"
					disabled={ action.busy || ! active }
					onClick={ () =>
						run(
							'pull',
							__( 'Prenotazioni lette.', 'booking-manager' )
						)
					}
				>
					{ __( 'Leggi ora le prenotazioni', 'booking-manager' ) }
				</Button>
				<Button
					variant="tertiary"
					disabled={ action.busy || ! active }
					onClick={ () => setConfirm( true ) }
				>
					{ __( 'Risincronizza tutto', 'booking-manager' ) }
				</Button>
			</div>
			{ confirm && (
				<Confirm
					title={ __( 'Risincronizza tutto', 'booking-manager' ) }
					text={ __(
						'Invia a Channex 500 giorni di disponibilità, prezzi e regole di questa struttura, in due chiamate. Serve solo dopo un’interruzione lunga o un dubbio: di solito partono da sole solo le modifiche.',
						'booking-manager'
					) }
					confirmLabel={ __( 'Invia tutto', 'booking-manager' ) }
					busy={ action.busy }
					error={ action.error }
					onCancel={ () => setConfirm( false ) }
					onConfirm={ async () => {
						const r = await run(
							'full-sync',
							__(
								'Struttura inviata tutta a Channex.',
								'booking-manager'
							)
						);
						if ( r ) {
							setConfirm( false );
						}
					} }
				/>
			) }

			<Card title={ __( 'Registro degli invii', 'booking-manager' ) }>
				{ st.tasks.length === 0 ? (
					<Empty
						title={ __( 'Nessun invio ancora', 'booking-manager' ) }
					/>
				) : (
					<div className="bkng-table-wrap">
						<table className="bkng-table">
							<thead>
								<tr>
									<th>
										{ __( 'Quando', 'booking-manager' ) }
									</th>
									<th>{ __( 'Cosa', 'booking-manager' ) }</th>
									<th className="is-num">
										{ __( 'Valori', 'booking-manager' ) }
									</th>
									<th>
										{ __( 'Esito', 'booking-manager' ) }
									</th>
									<th>
										{ __(
											'Task Channex',
											'booking-manager'
										) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ st.tasks.map( ( t ) => (
									<tr key={ t.id }>
										<td className="bkng-nowrap">
											{ dateTime( t.at ) }
										</td>
										<td>{ KINDS[ t.kind ] || t.kind }</td>
										<td className="is-num">{ t.values }</td>
										<td>
											{ t.error ? (
												<span className="bkng-help is-error">
													{ t.error }
												</span>
											) : (
												<Tone
													tone={
														t.warnings.length
															? 'warn'
															: 'ok'
													}
												>
													{ t.warnings.length
														? sprintf(
																/* translators: %d: values refused */
																__(
																	'%d scartati',
																	'booking-manager'
																),
																t.warnings
																	.length
														  )
														: __(
																'OK',
																'booking-manager'
														  ) }
												</Tone>
											) }
											{ t.warnings.length > 0 && (
												<details className="bkng-small">
													<summary>
														{ __(
															'Dettagli',
															'booking-manager'
														) }
													</summary>
													<pre className="bkng-cx__pre">
														{ JSON.stringify(
															t.warnings,
															null,
															1
														) }
													</pre>
												</details>
											) }
										</td>
										<td>
											<code className="bkng-small">
												{ t.task_id || '—' }
											</code>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
			</Card>
		</div>
	);
}

/* ------------------------------------------------------------------ Prenotazioni */

function BookingsTab( { data, setData, notify } ) {
	const action = useAction();
	const act = async ( revision, what, message ) => {
		const result = await action.run( () =>
			post( '/channex/revisions/' + revision + '/' + what )
		);
		if ( result ) {
			setData( result );
			notify( message );
		}
	};
	return (
		<div className="bkng-stack">
			<ErrorNotice error={ action.error } />
			<Card title={ __( 'Prenotazioni dai portali', 'booking-manager' ) }>
				{ data.revisions.length === 0 ? (
					<Empty
						title={ __(
							'Nessuna prenotazione ricevuta',
							'booking-manager'
						) }
						text={ __(
							'Le prenotazioni di Booking.com arrivano qui da sole e diventano prenotazioni del gestionale, senza email all’ospite (la manda il portale).',
							'booking-manager'
						) }
					/>
				) : (
					<div className="bkng-table-wrap">
						<table className="bkng-table">
							<thead>
								<tr>
									<th>
										{ __( 'Ricevuta', 'booking-manager' ) }
									</th>
									<th>
										{ __( 'Portale', 'booking-manager' ) }
									</th>
									<th>
										{ __( 'Soggiorno', 'booking-manager' ) }
									</th>
									<th>
										{ __( 'Esito', 'booking-manager' ) }
									</th>
									<th />
								</tr>
							</thead>
							<tbody>
								{ data.revisions.map( ( r ) => {
									const [ tone, label ] = OUTCOMES[
										r.outcome
									] || [
										r.error ? 'bad' : 'warn',
										r.error
											? __(
													'Non salvata',
													'booking-manager'
											  )
											: __(
													'In lavorazione',
													'booking-manager'
											  ),
									];
									return (
										<tr key={ r.revision_id }>
											<td className="bkng-nowrap">
												{ dateTime( r.at ) }
											</td>
											<td>
												{ r.ota_name || 'Channex' }{ ' ' }
												<span className="bkng-muted">
													{ r.ota_code }
												</span>
												<div className="bkng-small bkng-muted">
													{ r.status }
												</div>
											</td>
											<td className="bkng-nowrap">
												{ r.arrival
													? date( r.arrival ) +
													  ' → ' +
													  date( r.departure )
													: '—' }
											</td>
											<td>
												<Tone tone={ tone }>
													{ label }
												</Tone>{ ' ' }
												{ r.overbooked && (
													<Tone tone="bad">
														{ __(
															'Overbooking',
															'booking-manager'
														) }
													</Tone>
												) }{ ' ' }
												{ ! r.acked_at &&
													r.processed_at && (
														<Tone tone="warn">
															{ __(
																'Conferma a Channex da ripetere',
																'booking-manager'
															) }
														</Tone>
													) }
												{ r.error && (
													<div className="bkng-help is-error">
														{ r.error }
													</div>
												) }
											</td>
											<td className="bkng-right bkng-nowrap">
												{ r.local_booking_id && (
													<a
														href={
															'#/bookings/' +
															r.local_booking_id
														}
													>
														{ __(
															'Apri',
															'booking-manager'
														) }
													</a>
												) }{ ' ' }
												{ r.outcome === 'unmapped' && (
													<Button
														variant="secondary"
														size="compact"
														disabled={ action.busy }
														onClick={ () =>
															act(
																r.revision_id,
																'retry',
																__(
																	'Importata di nuovo.',
																	'booking-manager'
																)
															)
														}
													>
														{ __(
															'Importa di nuovo',
															'booking-manager'
														) }
													</Button>
												) }{ ' ' }
												{ r.needs_attention && (
													<Button
														variant="tertiary"
														size="compact"
														disabled={ action.busy }
														onClick={ () =>
															act(
																r.revision_id,
																'resolve',
																__(
																	'Segnata come risolta.',
																	'booking-manager'
																)
															)
														}
													>
														{ __(
															'Risolta',
															'booking-manager'
														) }
													</Button>
												) }
											</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>
					</div>
				) }
			</Card>
			<Card
				title={ __( 'Avvisi ricevuti da Channex', 'booking-manager' ) }
			>
				{ data.webhooks.length === 0 ? (
					<p className="bkng-help">
						{ __( 'Nessun webhook ricevuto.', 'booking-manager' ) }
					</p>
				) : (
					<ul className="bkng-cx__alert-list">
						{ data.webhooks.map( ( w, i ) => (
							<li key={ i }>
								<span className="bkng-muted bkng-small">
									{ dateTime( w.at ) }
								</span>{ ' ' }
								<code>{ w.event }</code>{ ' ' }
								{ w.valid ? (
									<Tone tone="ok">
										{ __(
											'firma valida',
											'booking-manager'
										) }
									</Tone>
								) : (
									<Tone tone="bad">
										{ __(
											'firma non valida: rifiutato',
											'booking-manager'
										) }
									</Tone>
								) }
							</li>
						) ) }
					</ul>
				) }
			</Card>
		</div>
	);
}

function keyHelp( s ) {
	if ( s.api_key_constant ) {
		return __(
			'Impostata in wp-config.php (BKNG_CHANNEX_API_KEY).',
			'booking-manager'
		);
	}
	if ( s.has_api_key ) {
		return sprintf(
			/* translators: %s: last characters of the key */
			__(
				'Salvata (finisce con %s). Lascia vuoto per non cambiarla.',
				'booking-manager'
			),
			s.api_key_hint
		);
	}
	return __(
		'Dal pannello di Channex: Impostazioni utente › Chiavi API.',
		'booking-manager'
	);
}

function secretHelp( s ) {
	if ( s.webhook_secret_constant ) {
		return __(
			'Impostata in wp-config.php (BKNG_CHANNEX_WEBHOOK_SECRET).',
			'booking-manager'
		);
	}
	if ( s.has_webhook_secret ) {
		return __(
			'Salvata. Lascia vuoto per non cambiarla.',
			'booking-manager'
		);
	}
	return __( 'Generala qui sotto o copiala da Channex.', 'booking-manager' );
}

function connectionStatus( s ) {
	if ( ! s.has_api_key ) {
		return [ 'muted', __( 'Non collegato', 'booking-manager' ) ];
	}
	if ( s.suspended ) {
		return [ 'bad', __( 'Sospeso', 'booking-manager' ) ];
	}
	if ( s.active ) {
		return [ 'ok', __( 'Attivo', 'booking-manager' ) ];
	}
	return [ 'warn', __( 'Spento', 'booking-manager' ) ];
}

/* ------------------------------------------------------------------ Screen */

export default function Channex() {
	const { property } = useProperty();
	const [ tab, setTab ] = useState( 'connection' );
	const [ notice, setNotice ] = useState( null );
	const overview = useLoad( () => get( '/channex' ), [] );
	const detail = useLoad(
		() =>
			property
				? get( '/properties/' + property.id + '/channex' )
				: Promise.resolve( null ),
		[ property?.id ]
	);

	// Opens on the property's work once it is set up.
	useEffect( () => {
		if ( overview.data?.settings.active && detail.data?.linked ) {
			setTab( ( t ) => ( t === 'connection' ? 'sync' : t ) );
		}
	}, [ overview.data?.settings.active, detail.data?.linked ] );

	if ( overview.loading && ! overview.data ) {
		return <Loading />;
	}
	if ( overview.error ) {
		return (
			<Page title="Channex">
				<ErrorNotice
					error={ overview.error }
					onRetry={ overview.reload }
				/>
			</Page>
		);
	}
	const o = overview.data;
	const notify = ( text ) => {
		setNotice( text );
		overview.reload();
	};
	const status = connectionStatus( o.settings );

	return (
		<Page
			title="Channex"
			subtitle={ __(
				'Disponibilità, prezzi e prenotazioni con Booking.com e gli altri portali, tramite Channex.',
				'booking-manager'
			) }
			actions={ <Tone tone={ status[ 0 ] }>{ status[ 1 ] }</Tone> }
		>
			{ notice && (
				<Success onDismiss={ () => setNotice( null ) }>
					{ notice }
				</Success>
			) }
			<Alerts overview={ o } setOverview={ overview.setData } />
			{ detail.data?.attention > 0 && (
				<p className="bkng-help is-error" role="status">
					{ sprintf(
						/* translators: %d: number of reservations */
						__(
							'%d prenotazioni dai portali da controllare: vedi «Prenotazioni ricevute».',
							'booking-manager'
						),
						detail.data.attention
					) }
				</p>
			) }
			<Tabs tabs={ TABS } current={ tab } onChange={ setTab } />
			{ tab === 'connection' && (
				<ConnectionTab
					overview={ o }
					setOverview={ overview.setData }
					notify={ ( text ) => {
						setNotice( text );
						detail.reload();
					} }
				/>
			) }
			{ tab !== 'connection' && ! property && (
				<Empty
					title={ __( 'Scegli una struttura', 'booking-manager' ) }
				/>
			) }
			{ tab !== 'connection' &&
				property &&
				detail.loading &&
				! detail.data && <Loading /> }
			{ tab !== 'connection' && detail.error && (
				<ErrorNotice error={ detail.error } onRetry={ detail.reload } />
			) }
			{ tab !== 'connection' && detail.data && (
				<>
					{ tab === 'mapping' && (
						<MappingTab
							key={
								detail.data.property_id +
								':' +
								( detail.data.mapping.property || '' )
							}
							data={ detail.data }
							setData={ detail.setData }
							notify={ notify }
						/>
					) }
					{ tab !== 'mapping' && ! detail.data.linked && (
						<Empty
							title={ __(
								'Struttura non collegata a Channex',
								'booking-manager'
							) }
							text={ __(
								'Collegala in «Mappatura».',
								'booking-manager'
							) }
						/>
					) }
					{ tab === 'sync' && detail.data.linked && (
						<SyncTab
							data={ detail.data }
							setData={ detail.setData }
							notify={ notify }
							active={ o.settings.active }
						/>
					) }
					{ tab === 'bookings' && detail.data.linked && (
						<BookingsTab
							data={ detail.data }
							setData={ detail.setData }
							notify={ notify }
						/>
					) }
				</>
			) }
		</Page>
	);
}
