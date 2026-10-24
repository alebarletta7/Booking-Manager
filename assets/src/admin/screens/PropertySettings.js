/**
 * The property: details, booking rules and online form, payments (Stripe, Nexi, at the
 * desk), e-mails, housekeeping, sales channels. A new property can be added here.
 */
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { del, get, post, put } from '../lib/api';
import { boot, can } from '../lib/boot';
import { dateTime } from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';
import { useProperty } from '../lib/property';
import {
	Card,
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
	Tabs,
	Text,
	Textarea,
	Toggle,
} from '../components/ui';

const KINDS = {
	bnb: __( 'B&B', 'booking-manager' ),
	rooms: __( 'Affittacamere', 'booking-manager' ),
	holiday_home: __( 'Casa vacanze', 'booking-manager' ),
	apartments: __( 'Appartamenti', 'booking-manager' ),
	residence: __( 'Residence', 'booking-manager' ),
	guesthouse: __( 'Guest house', 'booking-manager' ),
	hotel: __( 'Hotel', 'booking-manager' ),
};

const LOG_TONES = { ok: 'ok', error: 'bad' };

const kindOptions = Object.entries( KINDS ).map( ( [ value, label ] ) => ( {
	value,
	label,
} ) );

function NewPropertyModal( { onClose, onDone } ) {
	const action = useAction();
	const [ name, setName ] = useState( '' );
	const [ kind, setKind ] = useState( 'bnb' );
	return (
		<FormModal
			title={ __( 'Nuova struttura', 'booking-manager' ) }
			onClose={ onClose }
			busy={ action.busy }
			error={ action.error }
			submitLabel={ __( 'Crea', 'booking-manager' ) }
			onSubmit={ async () => {
				const res = await action.run( () =>
					post( '/properties', { name, kind } )
				);
				if ( res ) {
					onDone( res );
				}
			} }
		>
			<div className="bkng-stack">
				<Text
					label={ __( 'Nome', 'booking-manager' ) }
					value={ name }
					onChange={ setName }
					required
				/>
				<Select
					label={ __( 'Tipo di struttura', 'booking-manager' ) }
					value={ kind }
					options={ kindOptions }
					onChange={ setKind }
				/>
			</div>
		</FormModal>
	);
}

/* ------------------------------------------------------------------ Details */

function DetailsTab( { data, onSaved } ) {
	const action = useAction();
	const [ form, setForm ] = useState( data );
	const set = ( k ) => ( v ) => setForm( ( f ) => ( { ...f, [ k ]: v } ) );
	const fields = [
		'name',
		'kind',
		'timezone',
		'currency',
		'booking_mode',
		'checkin_from',
		'checkin_to',
		'checkout_until',
		'address',
		'city',
		'postcode',
		'province',
		'country',
		'phone',
		'email',
		'website',
		'company_name',
		'vat_number',
	];
	return (
		<form
			onSubmit={ async ( e ) => {
				e.preventDefault();
				const body = { version: data.version };
				fields.forEach( ( k ) => ( body[ k ] = form[ k ] ?? '' ) );
				const res = await action.run( () =>
					put( '/properties/' + data.id, body )
				);
				if ( res ) {
					onSaved(
						res,
						__( 'Dati della struttura salvati.', 'booking-manager' )
					);
				}
			} }
		>
			<ErrorNotice error={ action.error } />
			<Card title={ __( 'Struttura', 'booking-manager' ) }>
				<Grid columns={ 3 }>
					<Text
						label={ __( 'Nome', 'booking-manager' ) }
						value={ form.name }
						onChange={ set( 'name' ) }
						required
					/>
					<Select
						label={ __( 'Tipo', 'booking-manager' ) }
						value={ form.kind }
						options={ kindOptions }
						onChange={ set( 'kind' ) }
					/>
					<Select
						label={ __( 'Prenotazioni online', 'booking-manager' ) }
						value={ form.booking_mode }
						options={ [
							{
								value: 'request',
								label: __(
									'Su richiesta (le confermi tu)',
									'booking-manager'
								),
							},
							{
								value: 'direct',
								label: __(
									'Conferma immediata',
									'booking-manager'
								),
							},
						] }
						onChange={ set( 'booking_mode' ) }
					/>
					<Text
						label={ __( 'Check-in dalle', 'booking-manager' ) }
						type="time"
						value={ form.checkin_from || '' }
						onChange={ set( 'checkin_from' ) }
					/>
					<Text
						label={ __( 'Check-in fino alle', 'booking-manager' ) }
						type="time"
						value={ form.checkin_to || '' }
						onChange={ set( 'checkin_to' ) }
					/>
					<Text
						label={ __( 'Check-out entro le', 'booking-manager' ) }
						type="time"
						value={ form.checkout_until || '' }
						onChange={ set( 'checkout_until' ) }
					/>
					<Text
						label={ __( 'Fuso orario', 'booking-manager' ) }
						value={ form.timezone }
						onChange={ set( 'timezone' ) }
						help={ __(
							'Nel formato Europe/Rome',
							'booking-manager'
						) }
					/>
					<Text
						label={ __( 'Valuta', 'booking-manager' ) }
						value={ form.currency }
						onChange={ set( 'currency' ) }
						maxLength={ 3 }
					/>
				</Grid>
			</Card>
			<Card title={ __( 'Indirizzo e contatti', 'booking-manager' ) }>
				<Grid columns={ 3 }>
					<Text
						label={ __( 'Indirizzo', 'booking-manager' ) }
						value={ form.address || '' }
						onChange={ set( 'address' ) }
					/>
					<Text
						label={ __( 'Città', 'booking-manager' ) }
						value={ form.city || '' }
						onChange={ set( 'city' ) }
					/>
					<Text
						label={ __( 'CAP', 'booking-manager' ) }
						value={ form.postcode || '' }
						onChange={ set( 'postcode' ) }
					/>
					<Text
						label={ __( 'Provincia', 'booking-manager' ) }
						value={ form.province || '' }
						onChange={ set( 'province' ) }
					/>
					<Text
						label={ __( 'Paese (sigla)', 'booking-manager' ) }
						value={ form.country || '' }
						onChange={ set( 'country' ) }
						maxLength={ 2 }
					/>
					<Text
						label={ __( 'Telefono', 'booking-manager' ) }
						value={ form.phone || '' }
						onChange={ set( 'phone' ) }
					/>
					<Text
						label={ __( 'Email', 'booking-manager' ) }
						type="email"
						value={ form.email || '' }
						onChange={ set( 'email' ) }
					/>
					<Text
						label={ __( 'Sito web', 'booking-manager' ) }
						value={ form.website || '' }
						onChange={ set( 'website' ) }
					/>
				</Grid>
				<Grid>
					<Text
						label={ __( 'Ragione sociale', 'booking-manager' ) }
						value={ form.company_name || '' }
						onChange={ set( 'company_name' ) }
					/>
					<Text
						label={ __( 'Partita IVA', 'booking-manager' ) }
						value={ form.vat_number || '' }
						onChange={ set( 'vat_number' ) }
					/>
				</Grid>
			</Card>
			<Button variant="primary" type="submit" isBusy={ action.busy }>
				{ __( 'Salva', 'booking-manager' ) }
			</Button>
		</form>
	);
}

/* ------------------------------------------------------------------ Settings (shared form) */

function SettingsForm( { data, onSaved, children } ) {
	const action = useAction();
	const [ s, setS ] = useState( data.settings );
	const set = ( k ) => ( v ) => setS( ( x ) => ( { ...x, [ k ]: v } ) );
	return (
		<form
			onSubmit={ async ( e ) => {
				e.preventDefault();
				const res = await action.run( () =>
					put( '/properties/' + data.id + '/settings', s )
				);
				if ( res ) {
					onSaved(
						res,
						__( 'Impostazioni salvate.', 'booking-manager' )
					);
				}
			} }
		>
			<ErrorNotice error={ action.error } />
			{ children( s, set ) }
			<Button variant="primary" type="submit" isBusy={ action.busy }>
				{ __( 'Salva', 'booking-manager' ) }
			</Button>
		</form>
	);
}

function BookingTab( { data, onSaved } ) {
	return (
		<SettingsForm data={ data } onSaved={ onSaved }>
			{ ( s, set ) => (
				<>
					<Card title={ __( 'Regole generali', 'booking-manager' ) }>
						<Grid columns={ 4 }>
							<NumberField
								label={ __(
									'Soggiorno minimo',
									'booking-manager'
								) }
								min={ 1 }
								value={ s.min_stay }
								onChange={ set( 'min_stay' ) }
							/>
							<NumberField
								label={ __(
									'Soggiorno massimo',
									'booking-manager'
								) }
								min={ 1 }
								value={ s.max_stay }
								onChange={ set( 'max_stay' ) }
							/>
							<NumberField
								label={ __(
									'Anticipo minimo (giorni)',
									'booking-manager'
								) }
								min={ 0 }
								value={ s.min_advance_days }
								onChange={ set( 'min_advance_days' ) }
							/>
							<NumberField
								label={ __(
									'Prenotabile fino a (giorni)',
									'booking-manager'
								) }
								min={ 1 }
								value={ s.max_advance_days }
								onChange={ set( 'max_advance_days' ) }
							/>
							<Text
								label={ __(
									'Arrivo in giornata fino alle',
									'booking-manager'
								) }
								type="time"
								value={ s.same_day_until || '' }
								onChange={ set( 'same_day_until' ) }
							/>
							<NumberField
								label={ __(
									'Camere max per prenotazione',
									'booking-manager'
								) }
								min={ 1 }
								value={ s.max_rooms_per_booking }
								onChange={ set( 'max_rooms_per_booking' ) }
							/>
							<NumberField
								label={ __(
									'Età massima bambini',
									'booking-manager'
								) }
								min={ 0 }
								value={ s.children_max_age }
								onChange={ set( 'children_max_age' ) }
							/>
							<NumberField
								label={ __(
									'Durata opzione (ore)',
									'booking-manager'
								) }
								min={ 1 }
								value={ s.option_hours }
								onChange={ set( 'option_hours' ) }
							/>
						</Grid>
						<Toggle
							label={ __(
								'Assegna la camera in automatico',
								'booking-manager'
							) }
							checked={ !! s.auto_assign_unit }
							onChange={ set( 'auto_assign_unit' ) }
						/>
					</Card>
					<Card title={ __( 'Modulo online', 'booking-manager' ) }>
						<p className="bkng-help">
							{ __(
								'Inserisci il modulo in una pagina con questo shortcode:',
								'booking-manager'
							) }{ ' ' }
							<code>
								[booking_manager_prenota struttura=&quot;
								{ data.slug }&quot;]
							</code>
						</p>
						<div className="bkng-stack">
							<Toggle
								label={ __(
									'Prenotazioni online attive',
									'booking-manager'
								) }
								checked={ !! s.online_enabled }
								onChange={ set( 'online_enabled' ) }
							/>
							<Toggle
								label={ __(
									'Mostra i prezzi',
									'booking-manager'
								) }
								checked={ !! s.show_prices }
								onChange={ set( 'show_prices' ) }
							/>
							<Toggle
								label={ __(
									"L'ospite può annullare dal link nell'email (secondo le condizioni della tariffa)",
									'booking-manager'
								) }
								checked={ !! s.guest_cancel_online }
								onChange={ set( 'guest_cancel_online' ) }
							/>
							<Toggle
								label={ __(
									'Chiedi il consenso alle comunicazioni promozionali',
									'booking-manager'
								) }
								checked={ !! s.marketing_consent }
								onChange={ set( 'marketing_consent' ) }
							/>
							<NumberField
								label={ __(
									'Minuti di blocco della camera durante il pagamento',
									'booking-manager'
								) }
								min={ 5 }
								value={ s.hold_minutes }
								onChange={ set( 'hold_minutes' ) }
							/>
							<Text
								label={ __(
									"Indirizzo dell'informativa privacy",
									'booking-manager'
								) }
								value={ s.privacy_url || '' }
								onChange={ set( 'privacy_url' ) }
							/>
							<Textarea
								label={ __(
									'Condizioni di prenotazione',
									'booking-manager'
								) }
								value={ s.terms_text || '' }
								onChange={ set( 'terms_text' ) }
							/>
						</div>
					</Card>
					<Card
						title={ __(
							'Imposta di soggiorno',
							'booking-manager'
						) }
					>
						<p className="bkng-help">
							{ __(
								'Un importo a persona a notte aggiunto al totale. Verifica le regole del tuo Comune: non è un calcolo fiscale.',
								'booking-manager'
							) }
						</p>
						<Toggle
							label={ __(
								"Applica l'imposta di soggiorno",
								'booking-manager'
							) }
							checked={ !! s.tourist_tax_enabled }
							onChange={ set( 'tourist_tax_enabled' ) }
						/>
						{ s.tourist_tax_enabled && (
							<Grid columns={ 3 }>
								<MoneyField
									label={ __(
										'A persona a notte (€)',
										'booking-manager'
									) }
									value={ s.tourist_tax_amount }
									onChange={ ( v ) =>
										set( 'tourist_tax_amount' )( v ?? 0 )
									}
								/>
								<NumberField
									label={ __(
										'Fino a notti (0 = tutte)',
										'booking-manager'
									) }
									min={ 0 }
									value={ s.tourist_tax_max_nights }
									onChange={ set( 'tourist_tax_max_nights' ) }
								/>
								<NumberField
									label={ __(
										'Esenti sotto i … anni',
										'booking-manager'
									) }
									min={ 0 }
									value={ s.tourist_tax_min_age }
									onChange={ set( 'tourist_tax_min_age' ) }
								/>
							</Grid>
						) }
					</Card>
				</>
			) }
		</SettingsForm>
	);
}

function MessagesTab( { data, onSaved } ) {
	return (
		<SettingsForm data={ data } onSaved={ onSaved }>
			{ ( s, set ) => (
				<Card title={ __( 'Email', 'booking-manager' ) }>
					<div className="bkng-stack">
						<Toggle
							label={ __(
								'Invia email agli ospiti',
								'booking-manager'
							) }
							checked={ !! s.email_enabled }
							onChange={ set( 'email_enabled' ) }
						/>
						<Toggle
							label={ __(
								'Avvisa lo staff delle prenotazioni online e degli annullamenti',
								'booking-manager'
							) }
							checked={ !! s.notify_staff }
							onChange={ set( 'notify_staff' ) }
						/>
						<Text
							label={ __(
								'Email dello staff (vuoto = email della struttura)',
								'booking-manager'
							) }
							type="email"
							value={ s.notify_email || '' }
							onChange={ set( 'notify_email' ) }
						/>
						<Text
							label={ __(
								'Nome del mittente',
								'booking-manager'
							) }
							value={ s.sender_name || '' }
							onChange={ set( 'sender_name' ) }
						/>
						<Toggle
							label={ __(
								'QR di check-in nella conferma',
								'booking-manager'
							) }
							checked={ !! s.qr_in_email }
							onChange={ set( 'qr_in_email' ) }
						/>
						<Toggle
							label={ __(
								"Promemoria prima dell'arrivo",
								'booking-manager'
							) }
							checked={ !! s.send_pre_arrival }
							onChange={ set( 'send_pre_arrival' ) }
						/>
						{ s.send_pre_arrival && (
							<NumberField
								label={ __(
									"Giorni prima dell'arrivo",
									'booking-manager'
								) }
								min={ 0 }
								value={ s.reminder_days }
								onChange={ set( 'reminder_days' ) }
							/>
						) }
						<Toggle
							label={ __(
								'Email di benvenuto al check-in',
								'booking-manager'
							) }
							checked={ !! s.send_checkin_confirmation }
							onChange={ set( 'send_checkin_confirmation' ) }
						/>
						<Toggle
							label={ __(
								'Messaggi WhatsApp (richiede un servizio collegato)',
								'booking-manager'
							) }
							help={ __(
								'Predisposto: senza un servizio WhatsApp collegato da un componente aggiuntivo i messaggi non partono e il registro lo indica.',
								'booking-manager'
							) }
							checked={ !! s.whatsapp_enabled }
							onChange={ set( 'whatsapp_enabled' ) }
						/>
					</div>
				</Card>
			) }
		</SettingsForm>
	);
}

function HousekeepingTab( { data, onSaved } ) {
	return (
		<SettingsForm data={ data } onSaved={ onSaved }>
			{ ( s, set ) => (
				<Card title={ __( 'Housekeeping', 'booking-manager' ) }>
					<div className="bkng-stack">
						<Toggle
							label={ __(
								'Gestisci le pulizie delle camere',
								'booking-manager'
							) }
							checked={ !! s.housekeeping_enabled }
							onChange={ set( 'housekeeping_enabled' ) }
						/>
						<Toggle
							label={ __(
								'Alla partenza la camera diventa «Da pulire»',
								'booking-manager'
							) }
							checked={ !! s.dirty_on_checkout }
							onChange={ set( 'dirty_on_checkout' ) }
						/>
						<Toggle
							label={ __(
								'Impedisci il check-in in una camera non pronta',
								'booking-manager'
							) }
							checked={ !! s.block_dirty_checkin }
							onChange={ set( 'block_dirty_checkin' ) }
						/>
					</div>
				</Card>
			) }
		</SettingsForm>
	);
}

/* ------------------------------------------------------------------ Payments */

function AccountModal( {
	property,
	provider,
	mode,
	account,
	onClose,
	onDone,
} ) {
	const action = useAction();
	const [ values, setValues ] = useState( {} );
	return (
		<FormModal
			title={ sprintf(
				/* translators: 1: provider, 2: test or live. */ __(
					'%1$s · %2$s',
					'booking-manager'
				),
				provider.label,
				mode === 'live'
					? __( 'reale', 'booking-manager' )
					: __( 'prova', 'booking-manager' )
			) }
			onClose={ onClose }
			busy={ action.busy }
			error={ action.error }
			submitLabel={ __( 'Collega e verifica', 'booking-manager' ) }
			onSubmit={ async () => {
				const res = await action.run( () =>
					put(
						'/properties/' +
							property.id +
							'/payment-accounts/' +
							provider.id +
							'/' +
							mode,
						values
					)
				);
				if ( res ) {
					onDone(
						__(
							'Account collegato e verificato.',
							'booking-manager'
						)
					);
				}
			} }
		>
			<p className="bkng-help">
				{ __(
					'Le chiavi sono cifrate nel database e non vengono più mostrate. I dati delle carte restano sulla pagina del gestore: il sito non li vede mai.',
					'booking-manager'
				) }
			</p>
			<div className="bkng-stack">
				{ provider.fields.map( ( f ) =>
					f.type === 'select' ? (
						<Select
							key={ f.name }
							label={ f.label }
							help={ f.help }
							value={ values[ f.name ] ?? '' }
							options={ ( f.options || [] ).map( ( o ) =>
								typeof o === 'object'
									? o
									: { value: o, label: o }
							) }
							onChange={ ( v ) =>
								setValues( { ...values, [ f.name ]: v } )
							}
						/>
					) : (
						<Text
							key={ f.name }
							label={
								f.label +
								( f.required
									? ''
									: ' (' +
									  __( 'facoltativo', 'booking-manager' ) +
									  ')' )
							}
							help={ f.help }
							type={ f.type === 'password' ? 'password' : 'text' }
							autoComplete="off"
							placeholder={
								account?.configured && f.secret
									? __(
											'Lascia vuoto per non cambiarla',
											'booking-manager'
									  )
									: f.placeholder
							}
							value={ values[ f.name ] ?? '' }
							onChange={ ( v ) =>
								setValues( { ...values, [ f.name ]: v } )
							}
						/>
					)
				) }
			</div>
		</FormModal>
	);
}

function PaymentsTab( { data, onSaved } ) {
	const overview = useLoad(
		() => get( '/properties/' + data.id + '/payment-accounts' ),
		[ data.id ]
	);
	const [ modal, setModal ] = useState( null );
	const [ notice, setNotice ] = useState( '' );
	const action = useAction();
	const reload = ( m ) => {
		setModal( null );
		setNotice( m );
		overview.reload();
	};
	if ( overview.loading && ! overview.data ) {
		return <Loading />;
	}
	if ( overview.error ) {
		return (
			<ErrorNotice error={ overview.error } onRetry={ overview.reload } />
		);
	}
	const o = overview.data;
	return (
		<>
			<Success onDismiss={ () => setNotice( '' ) }>{ notice }</Success>
			<ErrorNotice
				error={ action.error }
				onDismiss={ () => action.setError( null ) }
			/>
			{ o.last_checkout_error && (
				<ErrorNotice error={ { message: o.last_checkout_error } } />
			) }
			<Card title={ __( 'Pagamento online', 'booking-manager' ) }>
				<p className="bkng-help">
					{ o.online_ready
						? sprintf(
								/* translators: 1: provider, 2: mode. */ __(
									'Attivo: %1$s in modalità %2$s.',
									'booking-manager'
								),
								o.providers.find( ( p ) => p.id === o.provider )
									?.label || o.provider,
								o.mode === 'live'
									? __( 'reale', 'booking-manager' )
									: __( 'prova', 'booking-manager' )
						  )
						: __(
								'Nessun pagamento online: gli ospiti pagano in struttura. Collega Stripe o Nexi qui sotto e poi sceglilo.',
								'booking-manager'
						  ) }
				</p>
				{ ! o.dedicated_key && (
					<p className="bkng-help">
						{ __(
							'Consiglio: definisci BKNG_SECRET_KEY in wp-config.php per cifrare le chiavi con una chiave dedicata.',
							'booking-manager'
						) }
					</p>
				) }
				<SettingsForm data={ data } onSaved={ onSaved }>
					{ ( s, set ) => (
						<Grid columns={ 3 }>
							<Select
								label={ __(
									'Cosa chiedere online',
									'booking-manager'
								) }
								value={ s.online_payment }
								options={ [
									{
										value: 'none',
										label: __( 'Nulla', 'booking-manager' ),
									},
									{
										value: 'deposit',
										label: __(
											'La caparra della tariffa',
											'booking-manager'
										),
									},
									{
										value: 'full',
										label: __(
											"L'intero importo",
											'booking-manager'
										),
									},
								] }
								onChange={ set( 'online_payment' ) }
							/>
							<NumberField
								label={ __(
									"Saldo dovuto giorni prima dell'arrivo (0 = in struttura)",
									'booking-manager'
								) }
								min={ 0 }
								value={ s.balance_due_days }
								onChange={ set( 'balance_due_days' ) }
							/>
						</Grid>
					) }
				</SettingsForm>
			</Card>
			{ o.providers.map( ( p ) => (
				<Card key={ p.id } title={ p.label }>
					{ ! p.available && (
						<p className="bkng-muted">
							{ __(
								'Non disponibile in questa versione.',
								'booking-manager'
							) }
						</p>
					) }
					{ p.available &&
						[ 'test', 'live' ].map( ( mode ) => {
							const acc = p.accounts[ mode ];
							const active =
								o.provider === p.id &&
								o.mode === mode &&
								o.online_ready;
							return (
								<div
									key={ mode }
									className="bkng-list"
									style={ {
										borderBottom: '1px solid #d6e0da',
										padding: '10px 0',
									} }
								>
									<div
										className="bkng-row"
										style={ {
											marginBottom: 0,
											alignItems: 'center',
										} }
									>
										<div className="bkng-list__main">
											<span className="bkng-strong">
												{ mode === 'live'
													? __(
															'Account reale',
															'booking-manager'
													  )
													: __(
															'Account di prova',
															'booking-manager'
													  ) }
											</span>{ ' ' }
											{ active && (
												<span className="bkng-badge is-ok">
													{ __(
														'in uso',
														'booking-manager'
													) }
												</span>
											) }
											{ acc &&
												acc.status === 'active' &&
												! active && (
													<span className="bkng-badge is-info">
														{ __(
															'collegato',
															'booking-manager'
														) }
													</span>
												) }
											{ acc &&
												acc.status !== 'active' && (
													<span className="bkng-badge is-warn">
														{ acc.status }
													</span>
												) }
											<div className="bkng-small bkng-muted">
												{ acc?.key_hint
													? acc.key_hint + ' · '
													: '' }
												{ acc?.last_verified_at
													? sprintf(
															/* translators: %s: date. */ __(
																'verificato il %s',
																'booking-manager'
															),
															dateTime(
																acc.last_verified_at
															)
													  )
													: '' }
												{ acc?.webhook === 'manual'
													? ' · ' +
													  __(
															'webhook manuale',
															'booking-manager'
													  )
													: '' }
											</div>
											{ acc?.last_error && (
												<div
													className="bkng-small"
													style={ {
														color: '#b42318',
													} }
												>
													{ acc.last_error }
												</div>
											) }
											{ acc?.webhook_url && (
												<div className="bkng-small bkng-muted">
													{ __(
														'Webhook:',
														'booking-manager'
													) }{ ' ' }
													<code>
														{ acc.webhook_url }
													</code>
												</div>
											) }
										</div>
										<Button
											variant="secondary"
											onClick={ () =>
												setModal( {
													provider: p,
													mode,
													account: acc,
												} )
											}
										>
											{ acc
												? __(
														'Aggiorna chiavi',
														'booking-manager'
												  )
												: __(
														'Collega',
														'booking-manager'
												  ) }
										</Button>
										{ acc && (
											<Button
												variant="tertiary"
												onClick={ () =>
													action
														.run( () =>
															post(
																'/properties/' +
																	data.id +
																	'/payment-accounts/' +
																	p.id +
																	'/' +
																	mode +
																	'/verify'
															)
														)
														.then(
															( r ) =>
																r &&
																reload(
																	__(
																		'Verifica completata.',
																		'booking-manager'
																	)
																)
														)
												}
											>
												{ __(
													'Verifica',
													'booking-manager'
												) }
											</Button>
										) }
										{ acc &&
											acc.status === 'active' &&
											! active && (
												<Button
													variant="primary"
													onClick={ () =>
														action
															.run( () =>
																post(
																	'/properties/' +
																		data.id +
																		'/payment-accounts/select',
																	{
																		provider:
																			p.id,
																		mode,
																	}
																)
															)
															.then(
																( r ) =>
																	r &&
																	reload(
																		__(
																			'Pagamento online attivato.',
																			'booking-manager'
																		)
																	)
															)
													}
												>
													{ __(
														'Usa questo',
														'booking-manager'
													) }
												</Button>
											) }
										{ acc && (
											<Button
												variant="tertiary"
												isDestructive
												onClick={ () =>
													setModal( {
														disconnect: p,
														mode,
													} )
												}
											>
												{ __(
													'Scollega',
													'booking-manager'
												) }
											</Button>
										) }
									</div>
								</div>
							);
						} ) }
				</Card>
			) ) }
			{ o.online_ready && (
				<Button
					variant="secondary"
					isDestructive
					onClick={ () =>
						action
							.run( () =>
								post(
									'/properties/' +
										data.id +
										'/payment-accounts/select',
									{ provider: 'none', mode: o.mode }
								)
							)
							.then(
								( r ) =>
									r &&
									reload(
										__(
											'Pagamento online disattivato.',
											'booking-manager'
										)
									)
							)
					}
				>
					{ __( 'Disattiva il pagamento online', 'booking-manager' ) }
				</Button>
			) }
			{ modal?.provider && (
				<AccountModal
					property={ data }
					provider={ modal.provider }
					mode={ modal.mode }
					account={ modal.account }
					onClose={ () => setModal( null ) }
					onDone={ reload }
				/>
			) }
			{ modal?.disconnect && (
				<Confirm
					destructive
					title={ __( "Scollega l'account", 'booking-manager' ) }
					text={ __(
						'Le chiavi vengono cancellate. I pagamenti già fatti restano registrati.',
						'booking-manager'
					) }
					confirmLabel={ __( 'Scollega', 'booking-manager' ) }
					busy={ action.busy }
					onCancel={ () => setModal( null ) }
					onConfirm={ async () =>
						( await action.run( () =>
							del(
								'/properties/' +
									data.id +
									'/payment-accounts/' +
									modal.disconnect.id +
									'/' +
									modal.mode
							)
						) )
							? reload(
									__(
										'Account scollegato.',
										'booking-manager'
									)
							  )
							: setModal( null )
					}
				/>
			) }
		</>
	);
}

/* ------------------------------------------------------------------ Channels */

function ChannelsTab( { data } ) {
	const overview = useLoad(
		() => get( '/properties/' + data.id + '/channels' ),
		[ data.id ]
	);
	if ( overview.loading ) {
		return <Loading />;
	}
	if ( overview.error ) {
		return (
			<ErrorNotice error={ overview.error } onRetry={ overview.reload } />
		);
	}
	const o = overview.data;
	return (
		<>
			<Card title={ __( 'Canali di vendita (OTA)', 'booking-manager' ) }>
				<p className="bkng-help">
					{ __(
						'Booking Manager è predisposto per ricevere prenotazioni dai portali e inviare la disponibilità tramite un channel manager. Il collegamento richiede un connettore dedicato (componente aggiuntivo) con le credenziali ufficiali del canale: nessun canale è collegato in automatico.',
						'booking-manager'
					) }
				</p>
				{ ! o.connectors.length && (
					<Empty
						title={ __(
							'Nessun connettore installato',
							'booking-manager'
						) }
						text={ __(
							'Quando un connettore è attivo, qui compaiono stato e abbinamenti delle tipologie.',
							'booking-manager'
						) }
					/>
				) }
				<ul className="bkng-list">
					{ o.connectors.map( ( c ) => (
						<li key={ c.key }>
							<div className="bkng-list__main">
								<span className="bkng-strong">{ c.label }</span>
								<div className="bkng-small bkng-muted">
									{ c.ready
										? __( 'Pronto', 'booking-manager' )
										: c.reason }
								</div>
							</div>
							<span className="bkng-small">
								{ sprintf(
									/* translators: %d: mapped room types. */ __(
										'%d tipologie abbinate',
										'booking-manager'
									),
									c.room_types.length
								) }
							</span>
						</li>
					) ) }
				</ul>
			</Card>
			{ o.log.length > 0 && (
				<Card title={ __( 'Ultimi scambi', 'booking-manager' ) }>
					<ul className="bkng-list">
						{ o.log.map( ( l, i ) => (
							<li key={ i }>
								<span className="bkng-small bkng-muted bkng-nowrap">
									{ dateTime( l.at ) }
								</span>
								<div className="bkng-list__main">
									{ l.channel } ·{ ' ' }
									{ l.direction === 'in'
										? __( 'ricevuta', 'booking-manager' )
										: __(
												'inviata',
												'booking-manager'
										  ) }{ ' ' }
									{ l.external_id
										? '· ' + l.external_id
										: '' }
									{ l.message && (
										<div className="bkng-small">
											{ l.message }
										</div>
									) }
								</div>
								<span
									className={
										'bkng-badge is-' +
										( LOG_TONES[ l.status ] || 'warn' )
									}
								>
									{ l.status }
								</span>
							</li>
						) ) }
					</ul>
				</Card>
			) }
		</>
	);
}

export default function PropertySettings() {
	const { property, refreshMe, select } = useProperty();
	const [ tab, setTab ] = useState( 'details' );
	const [ notice, setNotice ] = useState( '' );
	const [ creating, setCreating ] = useState( false );
	const current = useLoad(
		() => ( property ? get( '/properties/' + property.id ) : null ),
		[ property?.id ]
	);

	const saved = async ( fresh, message ) => {
		current.setData( fresh );
		setNotice( message );
		await refreshMe();
	};
	const newProperty = can( 'bkng_manage_properties' ) && (
		<Button variant="secondary" onClick={ () => setCreating( true ) }>
			{ __( 'Nuova struttura', 'booking-manager' ) }
		</Button>
	);
	const modal = creating && (
		<NewPropertyModal
			onClose={ () => setCreating( false ) }
			onDone={ async ( p ) => {
				setCreating( false );
				await refreshMe();
				select( p.id );
			} }
		/>
	);

	if ( ! property ) {
		return (
			<Page
				title={ __( 'Struttura', 'booking-manager' ) }
				actions={ newProperty }
			>
				<Empty
					title={ __( 'Nessuna struttura', 'booking-manager' ) }
					text={ __(
						'Crea la prima struttura per iniziare.',
						'booking-manager'
					) }
				/>
				{ modal }
			</Page>
		);
	}
	if ( current.loading && ! current.data ) {
		return <Loading />;
	}
	if ( current.error ) {
		return (
			<ErrorNotice error={ current.error } onRetry={ current.reload } />
		);
	}
	const data = current.data;
	const tabs = [
		[ 'details', __( 'Dati', 'booking-manager' ) ],
		[ 'booking', __( 'Prenotazioni e modulo online', 'booking-manager' ) ],
		can( 'bkng_manage_payment_accounts' ) && [
			'payments',
			__( 'Pagamenti', 'booking-manager' ),
		],
		[ 'messages', __( 'Email', 'booking-manager' ) ],
		[ 'housekeeping', __( 'Housekeeping', 'booking-manager' ) ],
		can( 'bkng_manage_integrations' ) && [
			'channels',
			__( 'Canali', 'booking-manager' ),
		],
	].filter( Boolean );

	return (
		<Page
			title={ data.name }
			subtitle={ KINDS[ data.kind ] }
			actions={ newProperty }
		>
			<Success onDismiss={ () => setNotice( '' ) }>{ notice }</Success>
			<Tabs tabs={ tabs } current={ tab } onChange={ setTab } />
			{ tab === 'details' && (
				<DetailsTab
					key={ data.version }
					data={ data }
					onSaved={ saved }
				/>
			) }
			{ tab === 'booking' && (
				<BookingTab
					key={ data.version }
					data={ data }
					onSaved={ saved }
				/>
			) }
			{ tab === 'payments' && (
				<PaymentsTab data={ data } onSaved={ saved } />
			) }
			{ tab === 'messages' && (
				<MessagesTab
					key={ data.version }
					data={ data }
					onSaved={ saved }
				/>
			) }
			{ tab === 'housekeeping' && (
				<HousekeepingTab
					key={ data.version }
					data={ data }
					onSaved={ saved }
				/>
			) }
			{ tab === 'channels' && <ChannelsTab data={ data } /> }
			{ boot.me?.is_wp_admin && (
				<p className="bkng-help">
					{ sprintf(
						/* translators: %s: slug. */ __(
							'Identificativo per lo shortcode: %s',
							'booking-manager'
						),
						data.slug
					) }
				</p>
			) }
			{ modal }
		</Page>
	);
}
