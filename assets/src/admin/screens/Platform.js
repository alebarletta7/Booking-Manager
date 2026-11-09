/**
 * Piattaforma (bkng_platform_manage only): the customers of the platform, their
 * domains, people and plan, the plans and the platform's numbers.
 */
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { del, get, post, put } from '../lib/api';
import { useAction, useLoad } from '../lib/hooks';
import { navigate } from '../lib/router';
import { date, money } from '../lib/format';
import {
	Badge,
	Card,
	Check,
	Confirm,
	DateField,
	Details,
	Empty,
	ErrorNotice,
	FormModal,
	Grid,
	Kpi,
	Loading,
	NumberField,
	Page,
	Select,
	Success,
	Tabs,
	Text,
} from '../components/ui';

const today = () => new Date().toISOString().slice( 0, 10 );
const monthStart = () => today().slice( 0, 8 ) + '01';
const day = ( utc ) => ( utc ? date( utc.slice( 0, 10 ) ) : '' );
const limitText = ( n ) =>
	n > 0 ? String( n ) : __( 'nessun limite', 'booking-manager' );

function usePlans() {
	return useLoad( () => get( '/platform/plans' ), [] );
}

/* ------------------------------------------------------------ customers */

function NewTenant( { meta, onClose, onDone } ) {
	const action = useAction();
	const [ form, setForm ] = useState( {
		name: '',
		business_type: 'hotel',
		plan: meta.plans[ 0 ]?.key || 'unlimited',
		status: 'active',
		expires_at: '',
		email: '',
		first_name: '',
		last_name: '',
	} );
	const set = ( k ) => ( v ) => setForm( ( f ) => ( { ...f, [ k ]: v } ) );
	return (
		<FormModal
			title={ __( 'Nuovo cliente', 'booking-manager' ) }
			onClose={ onClose }
			busy={ action.busy }
			error={ action.error }
			submitLabel={ __( 'Crea il cliente', 'booking-manager' ) }
			onSubmit={ async () => {
				const created = await action.run( () =>
					post( '/platform/tenants', {
						name: form.name,
						business_type: form.business_type,
						plan: form.plan,
						status: form.status,
						expires_at: form.expires_at || null,
						admin: form.email
							? {
									email: form.email,
									first_name: form.first_name,
									last_name: form.last_name,
							  }
							: undefined,
					} )
				);
				if ( created ) {
					onDone( created );
				}
			} }
		>
			<Text
				label={ __( 'Nome (attività o azienda)', 'booking-manager' ) }
				value={ form.name }
				onChange={ set( 'name' ) }
				required
			/>
			<Grid>
				<Select
					label={ __( 'Tipo di attività', 'booking-manager' ) }
					value={ form.business_type }
					options={ Object.entries( meta.business_types ).map(
						( [ value, label ] ) => ( { value, label } )
					) }
					onChange={ set( 'business_type' ) }
				/>
				<Select
					label={ __( 'Piano', 'booking-manager' ) }
					value={ form.plan }
					options={ meta.plans.map( ( p ) => ( {
						value: p.key,
						label: p.name,
					} ) ) }
					onChange={ set( 'plan' ) }
				/>
				<Select
					label={ __( 'Stato', 'booking-manager' ) }
					value={ form.status }
					options={ [
						{
							value: 'active',
							label: meta.statuses.active,
						},
						{ value: 'trial', label: meta.statuses.trial },
					] }
					onChange={ set( 'status' ) }
				/>
				<DateField
					label={ __(
						'Scadenza (vuota = nessuna)',
						'booking-manager'
					) }
					value={ form.expires_at }
					min={ today() }
					onChange={ set( 'expires_at' ) }
				/>
			</Grid>
			<h3 className="bkng-subtitle">
				{ __(
					'Primo amministratore (facoltativo)',
					'booking-manager'
				) }
			</h3>
			<p className="bkng-help">
				{ __(
					"Riceve l'email di WordPress per scegliere la password; entra nel gestionale e crea le strutture del cliente.",
					'booking-manager'
				) }
			</p>
			<Grid>
				<Text
					label={ __( 'Nome', 'booking-manager' ) }
					value={ form.first_name }
					onChange={ set( 'first_name' ) }
				/>
				<Text
					label={ __( 'Cognome', 'booking-manager' ) }
					value={ form.last_name }
					onChange={ set( 'last_name' ) }
				/>
				<Text
					label={ __( 'Email', 'booking-manager' ) }
					type="email"
					value={ form.email }
					onChange={ set( 'email' ) }
				/>
			</Grid>
		</FormModal>
	);
}

function TenantList( { meta } ) {
	const [ search, setSearch ] = useState( '' );
	const [ filters, setFilters ] = useState( { search: '', status: '' } );
	const [ creating, setCreating ] = useState( false );
	const list = useLoad(
		() => get( '/platform/tenants', filters ),
		[ filters.search, filters.status ]
	);
	return (
		<>
			<form
				className="bkng-filters"
				onSubmit={ ( e ) => {
					e.preventDefault();
					setFilters( ( f ) => ( { ...f, search: search.trim() } ) );
				} }
			>
				<div className="bkng-filters__search">
					<Text
						label={ __( 'Cerca', 'booking-manager' ) }
						placeholder={ __(
							'Nome del cliente',
							'booking-manager'
						) }
						value={ search }
						onChange={ setSearch }
						onBlur={ () =>
							setFilters( ( f ) => ( {
								...f,
								search: search.trim(),
							} ) )
						}
					/>
				</div>
				<Select
					label={ __( 'Stato', 'booking-manager' ) }
					value={ filters.status }
					options={ [
						{ value: '', label: __( 'Tutti', 'booking-manager' ) },
						...Object.entries( meta.statuses ).map(
							( [ value, label ] ) => ( { value, label } )
						),
					] }
					onChange={ ( v ) =>
						setFilters( ( f ) => ( { ...f, status: v } ) )
					}
				/>
				<Button variant="primary" onClick={ () => setCreating( true ) }>
					{ __( 'Nuovo cliente', 'booking-manager' ) }
				</Button>
			</form>
			{ list.loading && <Loading /> }
			<ErrorNotice error={ list.error } onRetry={ list.reload } />
			{ list.data && ! list.data.length && (
				<Empty
					title={ __( 'Nessun cliente', 'booking-manager' ) }
					text={ __(
						'Nessun cliente corrisponde alla ricerca.',
						'booking-manager'
					) }
				/>
			) }
			{ list.data && list.data.length > 0 && (
				<div className="bkng-table-wrap">
					<table className="bkng-table">
						<thead>
							<tr>
								<th>{ __( 'Cliente', 'booking-manager' ) }</th>
								<th>{ __( 'Stato', 'booking-manager' ) }</th>
								<th>{ __( 'Piano', 'booking-manager' ) }</th>
								<th>{ __( 'Scadenza', 'booking-manager' ) }</th>
								<th className="is-num">
									{ __( 'Strutture', 'booking-manager' ) }
								</th>
								<th className="is-num">
									{ __( 'Persone', 'booking-manager' ) }
								</th>
								<th className="is-num">
									{ __( 'Domini', 'booking-manager' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ list.data.map( ( t ) => (
								<tr
									key={ t.id }
									className="is-link"
									onClick={ () =>
										navigate( '/platform/tenants/' + t.id )
									}
								>
									<td>
										<a
											href={
												'#/platform/tenants/' + t.id
											}
											onClick={ ( e ) =>
												e.stopPropagation()
											}
										>
											{ t.name }
										</a>
										{ t.is_default && (
											<div className="bkng-muted bkng-small">
												{ __(
													'Cliente principale',
													'booking-manager'
												) }
											</div>
										) }
									</td>
									<td>
										<Badge
											kind="tenant"
											status={ t.status }
										>
											{ t.status_label }
										</Badge>
									</td>
									<td>{ t.plan_name }</td>
									<td className="bkng-nowrap">
										{ day( t.expires_at ) || '—' }
									</td>
									<td className="is-num">
										{ t.properties_count }
									</td>
									<td className="is-num">
										{ t.users_count }
									</td>
									<td className="is-num">
										{ t.domains_count }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
			{ creating && (
				<NewTenant
					meta={ meta }
					onClose={ () => setCreating( false ) }
					onDone={ ( t ) => navigate( '/platform/tenants/' + t.id ) }
				/>
			) }
		</>
	);
}

/* ------------------------------------------------------------ one customer */

function EditTenant( { tenant, meta, onClose, onDone } ) {
	const action = useAction();
	const [ form, setForm ] = useState( {
		name: tenant.name,
		business_type: tenant.business_type,
		plan: tenant.plan,
		expires_at: tenant.expires_at ? tenant.expires_at.slice( 0, 10 ) : '',
		limits: { ...tenant.limits_override },
		features: { ...tenant.features_override },
	} );
	const set = ( k ) => ( v ) => setForm( ( f ) => ( { ...f, [ k ]: v } ) );
	const plan = meta.plans.find( ( p ) => p.key === form.plan );
	return (
		<FormModal
			title={ tenant.name }
			onClose={ onClose }
			busy={ action.busy }
			error={ action.error }
			wide
			onSubmit={ async () => {
				const saved = await action.run( () =>
					put( '/platform/tenants/' + tenant.id, {
						...form,
						expires_at: form.expires_at || null,
						version: tenant.version,
					} )
				);
				if ( saved ) {
					onDone( saved );
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
					label={ __( 'Tipo di attività', 'booking-manager' ) }
					value={ form.business_type }
					options={ Object.entries( meta.business_types ).map(
						( [ value, label ] ) => ( { value, label } )
					) }
					onChange={ set( 'business_type' ) }
				/>
				<Select
					label={ __( 'Piano', 'booking-manager' ) }
					value={ form.plan }
					options={ meta.plans.map( ( p ) => ( {
						value: p.key,
						label: p.name,
					} ) ) }
					onChange={ set( 'plan' ) }
				/>
				<DateField
					label={ __(
						'Scadenza (vuota = nessuna)',
						'booking-manager'
					) }
					value={ form.expires_at }
					onChange={ set( 'expires_at' ) }
				/>
			</Grid>
			<h3 className="bkng-subtitle">
				{ __( 'Eccezioni al piano', 'booking-manager' ) }
			</h3>
			<p className="bkng-help">
				{ __(
					'Lascia vuoto per usare il valore del piano. 0 = nessun limite.',
					'booking-manager'
				) }
			</p>
			<Grid>
				{ Object.entries( meta.limits ).map( ( [ key, label ] ) => (
					<NumberField
						key={ key }
						label={ label }
						min={ 0 }
						value={ form.limits[ key ] ?? null }
						help={ sprintf(
							/* translators: %s: limit of the plan */
							__( 'Piano: %s', 'booking-manager' ),
							limitText( plan?.limits[ key ] ?? 0 )
						) }
						onChange={ ( v ) =>
							set( 'limits' )( { ...form.limits, [ key ]: v } )
						}
					/>
				) ) }
			</Grid>
			<Grid>
				{ Object.entries( meta.features ).map( ( [ key, label ] ) => (
					<Select
						key={ key }
						label={ label }
						value={
							form.features[ key ] === undefined
								? ''
								: String( !! form.features[ key ] )
						}
						options={ [
							{
								value: '',
								label: sprintf(
									/* translators: %s: yes or no */
									__(
										'Come il piano (%s)',
										'booking-manager'
									),
									plan?.features[ key ]
										? __( 'sì', 'booking-manager' )
										: __( 'no', 'booking-manager' )
								),
							},
							{
								value: 'true',
								label: __( 'Sì', 'booking-manager' ),
							},
							{
								value: 'false',
								label: __( 'No', 'booking-manager' ),
							},
						] }
						onChange={ ( v ) => {
							const next = { ...form.features };
							if ( v === '' ) {
								delete next[ key ];
							} else {
								next[ key ] = v === 'true';
							}
							set( 'features' )( next );
						} }
					/>
				) ) }
			</Grid>
		</FormModal>
	);
}

function Domains( { tenant, onChange } ) {
	const action = useAction();
	const [ host, setHost ] = useState( '' );
	return (
		<Card title={ __( 'Domini autorizzati', 'booking-manager' ) }>
			<p className="bkng-help">
				{ __(
					'I siti che potranno mostrare il modulo di prenotazione del cliente in un iframe. Solo il nome del dominio, per esempio www.hotel-esempio.it. Il dominio non dà alcun accesso ai dati.',
					'booking-manager'
				) }
			</p>
			<ErrorNotice error={ action.error } />
			{ tenant.domains.length > 0 ? (
				<ul className="bkng-list">
					{ tenant.domains.map( ( d ) => (
						<li key={ d } className="bkng-row">
							<span style={ { flex: 1 } }>{ d }</span>
							<Button
								variant="tertiary"
								isDestructive
								disabled={ action.busy }
								onClick={ async () => {
									const left = await action.run( () =>
										del(
											'/platform/tenants/' +
												tenant.id +
												'/domains',
											{ host: d }
										)
									);
									if ( left ) {
										onChange( left );
									}
								} }
							>
								{ __( 'Togli', 'booking-manager' ) }
							</Button>
						</li>
					) ) }
				</ul>
			) : (
				<p className="bkng-muted">
					{ __( 'Nessun dominio.', 'booking-manager' ) }
				</p>
			) }
			<form
				className="bkng-row"
				onSubmit={ async ( e ) => {
					e.preventDefault();
					const list = await action.run( () =>
						post( '/platform/tenants/' + tenant.id + '/domains', {
							host,
						} )
					);
					if ( list ) {
						setHost( '' );
						onChange( list );
					}
				} }
			>
				<div style={ { flex: 1 } }>
					<Text
						label={ __( 'Nuovo dominio', 'booking-manager' ) }
						value={ host }
						onChange={ setHost }
					/>
				</div>
				<Button
					variant="secondary"
					type="submit"
					isBusy={ action.busy }
					disabled={ action.busy || ! host.trim() }
				>
					{ __( 'Aggiungi', 'booking-manager' ) }
				</Button>
			</form>
		</Card>
	);
}

function NewPerson( { tenant, roles, onClose, onDone } ) {
	const action = useAction();
	const [ form, setForm ] = useState( {
		email: '',
		first_name: '',
		last_name: '',
		role: 'bkng_admin',
	} );
	const set = ( k ) => ( v ) => setForm( ( f ) => ( { ...f, [ k ]: v } ) );
	return (
		<FormModal
			title={ __( 'Aggiungi una persona', 'booking-manager' ) }
			onClose={ onClose }
			busy={ action.busy }
			error={ action.error }
			onSubmit={ async () => {
				const added = await action.run( () =>
					post( '/platform/tenants/' + tenant.id + '/users', form )
				);
				if ( added ) {
					onDone();
				}
			} }
		>
			<p className="bkng-help">
				{ __(
					"Un nuovo utente di WordPress, che riceve l'email per scegliere la password. Strutture e ruolo si cambiano poi da «Staff e permessi».",
					'booking-manager'
				) }
			</p>
			<Grid>
				<Text
					label={ __( 'Email', 'booking-manager' ) }
					type="email"
					value={ form.email }
					onChange={ set( 'email' ) }
					required
				/>
				<Select
					label={ __( 'Ruolo', 'booking-manager' ) }
					value={ form.role }
					options={ roles.map( ( r ) => ( {
						value: r.slug,
						label: r.label,
					} ) ) }
					onChange={ set( 'role' ) }
				/>
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
				/>
			</Grid>
		</FormModal>
	);
}

function People( { tenant } ) {
	const people = useLoad(
		() =>
			Promise.all( [
				get( '/platform/tenants/' + tenant.id + '/users' ),
				get( '/staff' ),
			] ).then( ( [ users, staff ] ) => ( {
				users,
				roles: staff.roles,
			} ) ),
		[ tenant.id ]
	);
	const [ adding, setAdding ] = useState( false );
	return (
		<Card
			title={ __( 'Persone', 'booking-manager' ) }
			actions={
				people.data && (
					<Button
						variant="secondary"
						onClick={ () => setAdding( true ) }
					>
						{ __( 'Aggiungi', 'booking-manager' ) }
					</Button>
				)
			}
		>
			{ people.loading && <Loading /> }
			<ErrorNotice error={ people.error } onRetry={ people.reload } />
			{ people.data && ! people.data.users.length && (
				<p className="bkng-muted">
					{ __( 'Nessuna persona.', 'booking-manager' ) }
				</p>
			) }
			{ people.data && people.data.users.length > 0 && (
				<ul className="bkng-list">
					{ people.data.users.map( ( u ) => (
						<li key={ u.id } className="bkng-row">
							<span style={ { flex: 1 } }>
								<strong>{ u.display_name }</strong>
								<div className="bkng-muted bkng-small">
									{ u.email }
								</div>
							</span>
							<span>
								{ u.role_label ||
									__( 'Nessun ruolo', 'booking-manager' ) }
							</span>
						</li>
					) ) }
				</ul>
			) }
			{ adding && (
				<NewPerson
					tenant={ tenant }
					roles={ people.data.roles }
					onClose={ () => setAdding( false ) }
					onDone={ () => {
						setAdding( false );
						people.reload();
					} }
				/>
			) }
		</Card>
	);
}

const STATUS_ACTIONS = {
	suspend: {
		label: __( 'Sospendi', 'booking-manager' ),
		title: __( 'Sospendere il cliente?', 'booking-manager' ),
		text: __(
			'Il cliente non potrà registrare nuove prenotazioni (né dal gestionale né dal modulo online) né aggiungere strutture e camere. Le prenotazioni esistenti, i check-in, i pagamenti e le cancellazioni continuano a funzionare. Le prenotazioni dai portali vengono salvate con un avviso nel registro.',
			'booking-manager'
		),
		reason: __( 'Motivo (visibile al cliente)', 'booking-manager' ),
		destructive: true,
	},
	activate: {
		label: __( 'Riattiva', 'booking-manager' ),
		title: __( 'Riattivare il cliente?', 'booking-manager' ),
		text: __(
			'Il cliente torna a registrare nuove prenotazioni.',
			'booking-manager'
		),
	},
	archive: {
		label: __( 'Archivia', 'booking-manager' ),
		title: __( 'Archiviare il cliente?', 'booking-manager' ),
		text: __(
			'Le persone del cliente non vedranno più nulla e le pagine di prenotazione online spariscono. I dati restano conservati e visibili alla piattaforma; il cliente si può riattivare.',
			'booking-manager'
		),
		reason: __( 'Motivo', 'booking-manager' ),
		destructive: true,
	},
};

export function TenantDetail( { params } ) {
	const tenant = useLoad(
		() => get( '/platform/tenants/' + params.id ),
		[ params.id ]
	);
	const meta = usePlans();
	const action = useAction();
	const [ editing, setEditing ] = useState( false );
	const [ confirm, setConfirm ] = useState( null );
	const [ done, setDone ] = useState( '' );

	if ( tenant.loading || meta.loading ) {
		return <Loading />;
	}
	if ( tenant.error || meta.error ) {
		return (
			<ErrorNotice
				error={ tenant.error || meta.error }
				onRetry={ tenant.error ? tenant.reload : meta.reload }
			/>
		);
	}
	const t = tenant.data;
	const actions = [];
	if ( [ 'active', 'trial' ].includes( t.status ) ) {
		actions.push( 'suspend' );
	} else {
		actions.push( 'activate' );
	}
	if ( t.status !== 'archived' && ! t.is_default ) {
		actions.push( 'archive' );
	}
	const step = confirm ? STATUS_ACTIONS[ confirm ] : null;

	return (
		<Page
			title={ t.name }
			subtitle={
				<a href="#/platform">
					{ __( '← Tutti i clienti', 'booking-manager' ) }
				</a>
			}
			actions={
				<>
					<Button
						variant="secondary"
						onClick={ () => setEditing( true ) }
					>
						{ __( 'Modifica', 'booking-manager' ) }
					</Button>
					<Button
						variant="secondary"
						href={ '#/platform/tenants/' + t.id + '/business' }
					>
						{ __( 'Contatti e aspetto', 'booking-manager' ) }
					</Button>
					{ actions.map( ( key ) => (
						<Button
							key={ key }
							variant="secondary"
							isDestructive={ STATUS_ACTIONS[ key ].destructive }
							onClick={ () => setConfirm( key ) }
						>
							{ STATUS_ACTIONS[ key ].label }
						</Button>
					) ) }
				</>
			}
		>
			<Success onDismiss={ () => setDone( '' ) }>{ done }</Success>
			<Card title={ __( 'Cliente', 'booking-manager' ) }>
				<Details
					rows={ [
						[
							__( 'Stato', 'booking-manager' ),
							<Badge key="s" kind="tenant" status={ t.status }>
								{ t.status_label }
							</Badge>,
						],
						t.status === 'suspended' && [
							__( 'Motivo', 'booking-manager' ),
							t.suspend_reason,
						],
						[
							__( 'Tipo di attività', 'booking-manager' ),
							meta.data.business_types[ t.business_type ] ||
								t.business_type,
						],
						[ __( 'Piano', 'booking-manager' ), t.plan_name ],
						[
							__( 'Scadenza', 'booking-manager' ),
							day( t.expires_at ) ||
								__( 'Nessuna', 'booking-manager' ),
						],
						[
							__( 'Attivo dal', 'booking-manager' ),
							day( t.activated_at || t.created_at ),
						],
						[ __( 'Codice', 'booking-manager' ), t.uuid ],
					] }
				/>
			</Card>
			<Card title={ __( 'Uso del piano', 'booking-manager' ) }>
				<div className="bkng-kpis">
					{ Object.entries( meta.data.limits ).map(
						( [ key, label ] ) => (
							<Kpi
								key={ key }
								label={ label }
								value={ t.usage[ key ] }
								hint={ sprintf(
									/* translators: %s: limit */
									__( 'Limite: %s', 'booking-manager' ),
									limitText( t.limits[ key ] )
								) }
								tone={
									t.limits[ key ] > 0 &&
									t.usage[ key ] >= t.limits[ key ]
										? 'warn'
										: ''
								}
							/>
						)
					) }
				</div>
				<p className="bkng-help">
					{ Object.entries( meta.data.features )
						.map(
							( [ key, label ] ) =>
								label +
								': ' +
								( t.features[ key ]
									? __( 'sì', 'booking-manager' )
									: __( 'no', 'booking-manager' ) )
						)
						.join( ' · ' ) }
				</p>
			</Card>
			<Domains
				tenant={ t }
				onChange={ ( domains ) => tenant.setData( { ...t, domains } ) }
			/>
			<People tenant={ t } />
			{ editing && (
				<EditTenant
					tenant={ t }
					meta={ meta.data }
					onClose={ () => setEditing( false ) }
					onDone={ ( saved ) => {
						setEditing( false );
						tenant.setData( saved );
						setDone(
							__( 'Cliente aggiornato.', 'booking-manager' )
						);
					} }
				/>
			) }
			{ step && (
				<Confirm
					title={ step.title }
					text={ step.text }
					confirmLabel={ step.label }
					destructive={ step.destructive }
					reasonLabel={ step.reason }
					busy={ action.busy }
					error={ action.error }
					onCancel={ () => {
						action.setError( null );
						setConfirm( null );
					} }
					onConfirm={ async ( reason ) => {
						const saved = await action.run( () =>
							post( '/platform/tenants/' + t.id + '/' + confirm, {
								reason,
							} )
						);
						if ( saved ) {
							setConfirm( null );
							tenant.setData( { ...t, ...saved } );
							setDone( saved.status_label );
						}
					} }
				/>
			) }
		</Page>
	);
}

/* ------------------------------------------------------------ plans */

function PlansEditor( { meta, onSaved } ) {
	const action = useAction();
	const [ plans, setPlans ] = useState( meta.plans );
	const [ done, setDone ] = useState( '' );
	const change = ( i, patch ) =>
		setPlans( ( list ) =>
			list.map( ( p, j ) => ( j === i ? { ...p, ...patch } : p ) )
		);
	return (
		<>
			<p className="bkng-help">
				{ __(
					'I limiti valgono da subito (strutture e camere). Le funzioni si applicheranno quando ciascuna diventerà per cliente (SMTP, WhatsApp e Channex nei prossimi passi). Nessun abbonamento viene incassato da qui.',
					'booking-manager'
				) }
			</p>
			<Success onDismiss={ () => setDone( '' ) }>{ done }</Success>
			<ErrorNotice error={ action.error } />
			{ plans.map( ( plan, i ) => (
				<Card
					key={ i }
					title={
						plan.name || __( 'Nuovo piano', 'booking-manager' )
					}
					actions={
						! meta.in_use.includes( plan.key ) &&
						plan.key !== 'unlimited' && (
							<Button
								variant="tertiary"
								isDestructive
								onClick={ () =>
									setPlans( ( list ) =>
										list.filter( ( x, j ) => j !== i )
									)
								}
							>
								{ __( 'Togli', 'booking-manager' ) }
							</Button>
						)
					}
				>
					<Grid>
						<Text
							label={ __( 'Nome', 'booking-manager' ) }
							value={ plan.name }
							onChange={ ( v ) => change( i, { name: v } ) }
						/>
						<Text
							label={ __( 'Codice', 'booking-manager' ) }
							help={ __(
								'Lettere minuscole, numeri, - e _. Non cambia dopo il salvataggio.',
								'booking-manager'
							) }
							value={ plan.key }
							disabled={ meta.in_use.includes( plan.key ) }
							onChange={ ( v ) => change( i, { key: v } ) }
						/>
						{ Object.entries( meta.limits ).map(
							( [ key, label ] ) => (
								<NumberField
									key={ key }
									label={ label }
									help={ __(
										'0 = nessun limite',
										'booking-manager'
									) }
									min={ 0 }
									value={ plan.limits[ key ] }
									onChange={ ( v ) =>
										change( i, {
											limits: {
												...plan.limits,
												[ key ]: v || 0,
											},
										} )
									}
								/>
							)
						) }
					</Grid>
					<div className="bkng-checks">
						{ Object.entries( meta.features ).map(
							( [ key, label ] ) => (
								<Check
									key={ key }
									label={ label }
									checked={ !! plan.features[ key ] }
									onChange={ ( on ) =>
										change( i, {
											features: {
												...plan.features,
												[ key ]: on,
											},
										} )
									}
								/>
							)
						) }
					</div>
				</Card>
			) ) }
			<div className="bkng-row">
				<Button
					variant="secondary"
					onClick={ () =>
						setPlans( ( list ) => [
							...list,
							{
								key: '',
								name: '',
								limits: {
									max_properties: 0,
									max_units: 0,
									max_bookings_hour: 0,
								},
								features: {},
							},
						] )
					}
				>
					{ __( 'Aggiungi un piano', 'booking-manager' ) }
				</Button>
				<Button
					variant="primary"
					isBusy={ action.busy }
					disabled={ action.busy }
					onClick={ async () => {
						const saved = await action.run( () =>
							put( '/platform/plans', { plans } )
						);
						if ( saved ) {
							setPlans( saved.plans );
							setDone(
								__( 'Piani salvati.', 'booking-manager' )
							);
							onSaved( saved );
						}
					} }
				>
					{ __( 'Salva i piani', 'booking-manager' ) }
				</Button>
			</div>
		</>
	);
}

/* ------------------------------------------------------------ numbers */

function Numbers( { meta } ) {
	const [ range, setRange ] = useState( { from: monthStart(), to: today() } );
	const stats = useLoad(
		() => get( '/platform/stats', range ),
		[ range.from, range.to ]
	);
	return (
		<>
			<div className="bkng-filters">
				<DateField
					label={ __( 'Dal', 'booking-manager' ) }
					value={ range.from }
					onChange={ ( v ) =>
						setRange( ( r ) => ( { ...r, from: v } ) )
					}
				/>
				<DateField
					label={ __( 'Al', 'booking-manager' ) }
					value={ range.to }
					onChange={ ( v ) =>
						setRange( ( r ) => ( { ...r, to: v } ) )
					}
				/>
			</div>
			{ stats.loading && <Loading /> }
			<ErrorNotice error={ stats.error } onRetry={ stats.reload } />
			{ stats.data && (
				<>
					<div className="bkng-kpis">
						{ Object.entries( meta.statuses ).map(
							( [ key, label ] ) => (
								<Kpi
									key={ key }
									label={ label }
									value={ stats.data.by_status[ key ] || 0 }
								/>
							)
						) }
					</div>
					{ stats.data.expiring.length > 0 && (
						<Card
							title={ __(
								'In scadenza nei prossimi 30 giorni',
								'booking-manager'
							) }
						>
							<ul className="bkng-list">
								{ stats.data.expiring.map( ( t ) => (
									<li key={ t.id } className="bkng-row">
										<a
											href={
												'#/platform/tenants/' + t.id
											}
											style={ { flex: 1 } }
										>
											{ t.name }
										</a>
										<span>{ day( t.expires_at ) }</span>
									</li>
								) ) }
							</ul>
						</Card>
					) }
					<Card
						title={ __(
							'Prenotazioni per cliente',
							'booking-manager'
						) }
					>
						<p className="bkng-help">
							{ __(
								'Prenotazioni create nel periodo (escluse le cancellate) e incassi ricevuti nel periodo, al netto dei rimborsi. Solo numeri, nessun dato degli ospiti.',
								'booking-manager'
							) }
						</p>
						{ ! stats.data.per_tenant.length ? (
							<p className="bkng-muted">
								{ __(
									'Nessuna prenotazione nel periodo.',
									'booking-manager'
								) }
							</p>
						) : (
							<div className="bkng-table-wrap">
								<table className="bkng-table">
									<thead>
										<tr>
											<th>
												{ __(
													'Cliente',
													'booking-manager'
												) }
											</th>
											<th className="is-num">
												{ __(
													'Prenotazioni',
													'booking-manager'
												) }
											</th>
											<th className="is-num">
												{ __(
													'Valore',
													'booking-manager'
												) }
											</th>
											<th className="is-num">
												{ __(
													'Incassato',
													'booking-manager'
												) }
											</th>
										</tr>
									</thead>
									<tbody>
										{ stats.data.per_tenant.map( ( r ) => (
											<tr key={ r.id + r.currency }>
												<td>
													<a
														href={
															'#/platform/tenants/' +
															r.id
														}
													>
														{ r.name }
													</a>
												</td>
												<td className="is-num">
													{ r.bookings }
												</td>
												<td className="is-num">
													{ money(
														r.booked,
														r.currency
													) }
												</td>
												<td className="is-num">
													{ money(
														r.cashed,
														r.currency
													) }
												</td>
											</tr>
										) ) }
									</tbody>
								</table>
							</div>
						) }
					</Card>
				</>
			) }
		</>
	);
}

export default function Platform( { query } ) {
	const meta = usePlans();
	const [ tab, setTab ] = useState( query?.tab || 'tenants' );
	return (
		<Page
			title={ __( 'Piattaforma', 'booking-manager' ) }
			subtitle={ __(
				'I clienti della piattaforma, i loro piani e i numeri complessivi.',
				'booking-manager'
			) }
			wide
		>
			<Tabs
				current={ tab }
				onChange={ setTab }
				tabs={ [
					[ 'tenants', __( 'Clienti', 'booking-manager' ) ],
					[ 'numbers', __( 'Riepilogo', 'booking-manager' ) ],
					[ 'plans', __( 'Piani', 'booking-manager' ) ],
				] }
			/>
			{ meta.loading && <Loading /> }
			<ErrorNotice error={ meta.error } onRetry={ meta.reload } />
			{ meta.data && tab === 'tenants' && (
				<TenantList meta={ meta.data } />
			) }
			{ meta.data && tab === 'numbers' && <Numbers meta={ meta.data } /> }
			{ meta.data && tab === 'plans' && (
				<PlansEditor
					meta={ meta.data }
					onSaved={ ( saved ) => meta.setData( saved ) }
				/>
			) }
		</Page>
	);
}
