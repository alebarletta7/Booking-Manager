/**
 * Staff: who works in Booking Manager, with which role and in which properties; what each
 * role may do (for who manages roles).
 */
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { del, get, post, put } from '../lib/api';
import { boot, can } from '../lib/boot';
import { useAction, useLoad } from '../lib/hooks';
import { useProperty } from '../lib/property';
import {
	Card,
	Check,
	Confirm,
	Drawer,
	ErrorNotice,
	Grid,
	Loading,
	Page,
	Select,
	Success,
	Tabs,
	Text,
} from '../components/ui';

function PropertyChecks( { properties, value, onChange } ) {
	return (
		<fieldset>
			<legend className="bkng-field__label">
				{ __( 'Strutture', 'booking-manager' ) }
			</legend>
			<div className="bkng-checks">
				{ properties.map( ( p ) => (
					<Check
						key={ p.id }
						label={ p.name }
						checked={ value.includes( p.id ) }
						onChange={ ( on ) =>
							onChange(
								on
									? [ ...value, p.id ]
									: value.filter( ( x ) => x !== p.id )
							)
						}
					/>
				) ) }
			</div>
			<p className="bkng-note">
				{ __(
					"L'operatore vede solo le strutture selezionate. Puoi assegnare solo quelle in cui lavori anche tu.",
					'booking-manager'
				) }
			</p>
		</fieldset>
	);
}

function initialProperties( member, properties ) {
	if ( member ) {
		return member.properties || [];
	}
	return properties.length === 1 ? [ properties[ 0 ].id ] : [];
}

function memberBody( member, mode, form ) {
	if ( member ) {
		return { role: form.role, properties: form.properties };
	}
	if ( mode === 'existing' ) {
		return {
			user_id: form.user_id,
			role: form.role,
			properties: form.properties,
		};
	}
	return {
		email: form.email,
		first_name: form.first_name,
		last_name: form.last_name,
		role: form.role,
		properties: form.properties,
	};
}

function MemberDrawer( {
	member,
	roles,
	properties,
	onClose,
	onDone,
	onRemove,
} ) {
	const action = useAction();
	// Searching the site's users is for the platform: a customer adds new people (0.2.1).
	const searchable = ! boot.me || boot.me.is_platform !== false;
	const [ mode, setMode ] = useState( searchable ? 'existing' : 'new' );
	const [ search, setSearch ] = useState( '' );
	const [ found, setFound ] = useState( null );
	const [ form, setForm ] = useState( {
		user_id: null,
		email: '',
		first_name: '',
		last_name: '',
		role: member ? member.role : roles[ roles.length - 1 ]?.slug,
		properties: initialProperties( member, properties ),
	} );
	const set = ( k ) => ( v ) => setForm( ( f ) => ( { ...f, [ k ]: v } ) );
	const roleOptions = roles.map( ( r ) => ( {
		value: r.slug,
		label: r.label,
	} ) );

	return (
		<Drawer
			title={
				member
					? member.display_name
					: __( 'Aggiungi operatore', 'booking-manager' )
			}
			onClose={ onClose }
			busy={ action.busy }
			error={ action.error }
			submitLabel={
				member ? undefined : __( 'Aggiungi', 'booking-manager' )
			}
			danger={
				member && onRemove
					? {
							label: __(
								'Rimuovi dallo staff',
								'booking-manager'
							),
							onClick: onRemove,
					  }
					: null
			}
			onSubmit={ async () => {
				const body = memberBody( member, mode, form );
				const res = await action.run( () =>
					member
						? put( '/staff/' + member.id, body )
						: post( '/staff', body )
				);
				if ( res ) {
					onDone(
						member
							? __( 'Staff aggiornato.', 'booking-manager' )
							: __(
									'Persona aggiunta allo staff.',
									'booking-manager'
							  )
					);
				}
			} }
		>
			{ member && (
				<p className="bkng-small bkng-muted">{ member.email }</p>
			) }
			{ ! member && searchable && (
				<Tabs
					current={ mode }
					onChange={ setMode }
					tabs={ [
						[
							'existing',
							__( 'Utente già registrato', 'booking-manager' ),
						],
						[ 'new', __( 'Nuovo utente', 'booking-manager' ) ],
					] }
				/>
			) }
			{ ! member && mode === 'existing' && (
				<>
					<div className="bkng-row">
						<div style={ { flex: 1 } }>
							<Text
								label={ __(
									'Cerca per nome o email',
									'booking-manager'
								) }
								value={ search }
								onChange={ setSearch }
							/>
						</div>
						<Button
							variant="secondary"
							onClick={ async () =>
								setFound(
									await action.run( () =>
										get( '/staff/candidates', { search } )
									)
								)
							}
						>
							{ __( 'Cerca', 'booking-manager' ) }
						</Button>
					</div>
					{ found && ! found.length && (
						<p className="bkng-help">
							{ __(
								'Nessun utente trovato: usa «Nuovo utente».',
								'booking-manager'
							) }
						</p>
					) }
					{ found && found.length > 0 && (
						<Select
							label={ __( 'Utente', 'booking-manager' ) }
							value={ String( form.user_id || '' ) }
							options={ [
								{
									value: '',
									label: __( 'Scegli…', 'booking-manager' ),
								},
								...found.map( ( u ) => ( {
									value: String( u.id ),
									label: u.display_name + ' · ' + u.email,
								} ) ),
							] }
							onChange={ ( v ) =>
								set( 'user_id' )( v ? Number( v ) : null )
							}
						/>
					) }
				</>
			) }
			{ ! member && mode === 'new' && (
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
					/>
					<Text
						label={ __( 'Email', 'booking-manager' ) }
						type="email"
						value={ form.email }
						onChange={ set( 'email' ) }
						required
					/>
					<p className="bkng-help">
						{ __(
							"Riceverà da WordPress l'email per scegliere la password.",
							'booking-manager'
						) }
					</p>
				</Grid>
			) }
			<div className="bkng-stack" style={ { marginTop: 12 } }>
				<Select
					label={ __( 'Ruolo', 'booking-manager' ) }
					value={ form.role }
					options={ roleOptions }
					onChange={ set( 'role' ) }
					help={ __(
						'Puoi assegnare solo ruoli con permessi che hai anche tu.',
						'booking-manager'
					) }
				/>
				{ ! member?.all_properties && (
					<PropertyChecks
						properties={ properties }
						value={ form.properties }
						onChange={ set( 'properties' ) }
					/>
				) }
			</div>
		</Drawer>
	);
}

function RolesTab( { roles, onSaved } ) {
	const caps = useLoad( () => get( '/capabilities' ), [] );
	const [ slug, setSlug ] = useState( roles[ 0 ]?.slug );
	const role = roles.find( ( r ) => r.slug === slug );
	const [ chosen, setChosen ] = useState( role ? role.caps : [] );
	const action = useAction();
	if ( caps.loading ) {
		return <Loading />;
	}
	if ( caps.error ) {
		return <ErrorNotice error={ caps.error } />;
	}
	return (
		<Card title={ __( 'Permessi dei ruoli', 'booking-manager' ) }>
			<ErrorNotice
				error={ action.error }
				onDismiss={ () => action.setError( null ) }
			/>
			<Select
				label={ __( 'Ruolo', 'booking-manager' ) }
				value={ slug }
				options={ roles.map( ( r ) => ( {
					value: r.slug,
					label: r.label,
				} ) ) }
				onChange={ ( v ) => {
					setSlug( v );
					setChosen(
						roles.find( ( r ) => r.slug === v )?.caps || []
					);
				} }
			/>
			{ Object.entries( caps.data.groups ).map( ( [ group, label ] ) => (
				<fieldset key={ group } style={ { marginTop: 14 } }>
					<legend className="bkng-strong">{ label }</legend>
					<div className="bkng-checks">
						{ caps.data.caps
							.filter( ( c ) => c.group === group )
							.map( ( c ) => (
								<Check
									key={ c.cap }
									label={ c.label }
									checked={ chosen.includes( c.cap ) }
									onChange={ ( on ) =>
										setChosen(
											on
												? [ ...chosen, c.cap ]
												: chosen.filter(
														( x ) => x !== c.cap
												  )
										)
									}
								/>
							) ) }
					</div>
				</fieldset>
			) ) }
			<div className="bkng-page__actions">
				<Button
					variant="primary"
					isBusy={ action.busy }
					onClick={ async () =>
						( await action.run( () =>
							put( '/roles/' + slug, { caps: chosen } )
						) ) &&
						onSaved( __( 'Permessi salvati.', 'booking-manager' ) )
					}
				>
					{ __( 'Salva', 'booking-manager' ) }
				</Button>
				<Button
					variant="tertiary"
					onClick={ async () => {
						const res = await action.run( () =>
							put( '/roles/' + slug, { reset: true } )
						);
						if ( res ) {
							setChosen( res.caps || chosen );
							onSaved(
								__(
									'Permessi predefiniti ripristinati.',
									'booking-manager'
								)
							);
						}
					} }
				>
					{ __( 'Ripristina i predefiniti', 'booking-manager' ) }
				</Button>
			</div>
		</Card>
	);
}

export default function Staff() {
	const { properties } = useProperty();
	const data = useLoad( () => get( '/staff' ), [] );
	const [ tab, setTab ] = useState( 'people' );
	const [ modal, setModal ] = useState( null );
	const [ notice, setNotice ] = useState( '' );
	const action = useAction();
	const done = ( m ) => {
		setModal( null );
		setNotice( m );
		data.reload();
	};
	if ( data.loading && ! data.data ) {
		return <Loading />;
	}
	if ( data.error ) {
		return <ErrorNotice error={ data.error } onRetry={ data.reload } />;
	}
	const names = Object.fromEntries(
		properties.map( ( p ) => [ p.id, p.name ] )
	);
	return (
		<Page
			title={ __( 'Staff e permessi', 'booking-manager' ) }
			actions={
				tab === 'people' && (
					<Button
						variant="primary"
						onClick={ () => setModal( { kind: 'member' } ) }
					>
						{ __( 'Aggiungi operatore', 'booking-manager' ) }
					</Button>
				)
			}
		>
			<Success onDismiss={ () => setNotice( '' ) }>{ notice }</Success>
			<ErrorNotice
				error={ action.error }
				onDismiss={ () => action.setError( null ) }
			/>
			{ can( 'bkng_manage_roles' ) && (
				<Tabs
					current={ tab }
					onChange={ setTab }
					tabs={ [
						[ 'people', __( 'Operatori', 'booking-manager' ) ],
						[
							'roles',
							__( 'Ruoli e permessi', 'booking-manager' ),
						],
					] }
				/>
			) }
			{ tab === 'people' && (
				<section className="bkng-staff">
					<div className="bkng-staff__intro">
						<h2 className="bkng-section-title">
							{ __( 'Operatori', 'booking-manager' ) }
						</h2>
						<p className="bkng-muted">
							{ __(
								'Chi lavora con Booking Manager, con quale ruolo e in quali strutture. Gli amministratori di WordPress hanno sempre accesso completo e il tuo account non si modifica da qui.',
								'booking-manager'
							) }
						</p>
					</div>
					<Card className="is-flush">
						<div className="bkng-table-wrap">
							<table className="bkng-table bkng-staff__table">
								<thead>
									<tr>
										<th>
											{ __( 'Nome', 'booking-manager' ) }
										</th>
										<th>
											{ __( 'Email', 'booking-manager' ) }
										</th>
										<th>
											{ __( 'Ruolo', 'booking-manager' ) }
										</th>
										<th>
											{ __(
												'Strutture',
												'booking-manager'
											) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ data.data.staff.map( ( m ) => {
										const open = () =>
											setModal( {
												kind: 'member',
												member: m,
											} );
										return (
											<tr
												key={ m.id }
												className={
													m.editable
														? 'is-clickable'
														: ''
												}
												onClick={
													m.editable
														? open
														: undefined
												}
											>
												<td>
													{ m.editable ? (
														<button
															type="button"
															className="bkng-staff__name"
															onClick={ ( e ) => {
																e.stopPropagation();
																open();
															} }
														>
															{ m.display_name }
														</button>
													) : (
														<span className="bkng-staff__name">
															{ m.display_name }
														</span>
													) }
													<div className="bkng-small bkng-muted bkng-staff__mail">
														{ m.email }
													</div>
													{ m.is_me && (
														<div className="bkng-small bkng-muted">
															{ __(
																'Sei tu: chiedi a un altro amministratore',
																'booking-manager'
															) }
														</div>
													) }
												</td>
												<td className="bkng-muted">
													{ m.email }
												</td>
												<td>
													<span className="bkng-role-badge">
														{ m.role_label || '—' }
													</span>
												</td>
												<td className="bkng-small">
													{ m.all_properties
														? __(
																'Tutte',
																'booking-manager'
														  )
														: ( m.properties || [] )
																.map(
																	( id ) =>
																		names[
																			id
																		] ||
																		'#' + id
																)
																.join( ', ' ) ||
														  '—' }
												</td>
											</tr>
										);
									} ) }
								</tbody>
							</table>
						</div>
					</Card>
				</section>
			) }
			{ tab === 'roles' && (
				<RolesTab roles={ data.data.roles } onSaved={ done } />
			) }
			{ modal?.kind === 'member' && (
				<MemberDrawer
					member={ modal.member }
					roles={ data.data.roles }
					properties={ properties }
					onClose={ () => setModal( null ) }
					onDone={ done }
					onRemove={ () =>
						setModal( { kind: 'remove', member: modal.member } )
					}
				/>
			) }
			{ modal?.kind === 'remove' && (
				<Confirm
					destructive
					title={ sprintf(
						/* translators: %s: person. */ __(
							'Rimuovi %s dallo staff',
							'booking-manager'
						),
						modal.member.display_name
					) }
					text={ __(
						"L'account WordPress resta, ma non potrà più usare Booking Manager.",
						'booking-manager'
					) }
					confirmLabel={ __( 'Rimuovi', 'booking-manager' ) }
					busy={ action.busy }
					onCancel={ () => setModal( null ) }
					onConfirm={ async () =>
						( await action.run( () =>
							del( '/staff/' + modal.member.id )
						) )
							? done(
									__(
										'Rimosso dallo staff.',
										'booking-manager'
									)
							  )
							: setModal( null )
					}
				/>
			) }
		</Page>
	);
}
