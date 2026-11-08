/**
 * Attività (0.2.4): the customer's contacts, with «Mostra al pubblico» on each, the
 * WhatsApp click-to-chat, logo and font of the booking form, and the page of its own
 * site where the guest lands after paying, and (0.2.5) how its e-mails leave. The customer's administrator edits its own;
 * the platform reaches every customer from Piattaforma (#/platform/tenants/:id/business).
 */
import { Button } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { get, post, put } from '../lib/api';
import { useAction, useLoad } from '../lib/hooks';
import {
	Badge,
	Card,
	Check,
	ErrorNotice,
	Grid,
	ImageField,
	Loading,
	Page,
	Select,
	Success,
	Text,
	Textarea,
	Toggle,
} from '../components/ui';

const CONTACTS = [
	[ 'trade_name', __( 'Nome commerciale', 'booking-manager' ) ],
	[ 'contact_name', __( 'Referente', 'booking-manager' ) ],
	[ 'email', __( 'Email principale', 'booking-manager' ) ],
	[ 'booking_email', __( 'Email prenotazioni', 'booking-manager' ) ],
	[ 'info_email', __( 'Email informazioni', 'booking-manager' ) ],
	[ 'phone', __( 'Telefono', 'booking-manager' ) ],
	[ 'whatsapp', __( 'WhatsApp', 'booking-manager' ) ],
	[ 'website', __( 'Sito web', 'booking-manager' ) ],
];

const FONTS = {
	'': __( 'Quello della pagina (come oggi)', 'booking-manager' ),
	sans: __( 'Moderno (senza grazie)', 'booking-manager' ),
	serif: __( 'Classico (con grazie)', 'booking-manager' ),
	humanist: __( 'Elegante', 'booking-manager' ),
	rounded: __( 'Arrotondato', 'booking-manager' ),
};

/** Same stacks as TenantProfile::FONTS, for the preview. */
const STACKS = {
	sans: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
	serif: 'Georgia, "Iowan Old Style", "Palatino Linotype", Palatino, "Times New Roman", serif',
	humanist:
		'Optima, Candara, "Gill Sans", "Noto Sans", "Trebuchet MS", sans-serif',
	rounded:
		'ui-rounded, "SF Pro Rounded", "Arial Rounded MT Bold", "Varela Round", "Segoe UI", sans-serif',
};

export default function Business( { params = {} } ) {
	const tenantId = params.id ? Number( params.id ) : 0;
	const path = '/tenant/profile' + ( tenantId ? '?tenant=' + tenantId : '' );
	const data = useLoad( () => get( path ), [ path ] );
	const action = useAction();
	const [ form, setForm ] = useState( null );
	const [ done, setDone ] = useState( '' );

	useEffect( () => {
		if ( data.data ) {
			const profile = data.data.profile;
			setForm( {
				...profile,
				// Stored as digits for wa.me; shown with its "+".
				contacts: {
					...profile.contacts,
					whatsapp: profile.contacts.whatsapp
						? '+' + profile.contacts.whatsapp
						: '',
				},
				version: data.data.tenant.version,
			} );
		}
	}, [ data.data ] );

	if ( data.loading && ! form ) {
		return <Loading />;
	}
	if ( data.error ) {
		return <ErrorNotice error={ data.error } onRetry={ data.reload } />;
	}
	if ( ! form ) {
		return <Loading />;
	}
	const d = data.data;
	const fields = action.error?.fields || {};
	const setContact = ( key ) => ( value ) =>
		setForm( ( f ) => ( {
			...f,
			contacts: { ...f.contacts, [ key ]: value },
		} ) );
	const setPublic = ( key ) => ( value ) =>
		setForm( ( f ) => ( {
			...f,
			public: { ...f.public, [ key ]: value },
		} ) );
	const set = ( key ) => ( value ) =>
		setForm( ( f ) => ( { ...f, [ key ]: value } ) );

	const save = async () => {
		setDone( '' );
		const saved = await action.run( () => put( path, form ) );
		if ( saved ) {
			data.setData( saved );
			setDone( __( 'Dati dell’attività salvati.', 'booking-manager' ) );
		}
	};

	const hidden = ( key ) =>
		form.same_email && [ 'booking_email', 'info_email' ].includes( key );
	const help = {
		whatsapp: __(
			'Con il prefisso internazionale, ad esempio +39 333 1234567. Serve per il pulsante «Scrivici su WhatsApp».',
			'booking-manager'
		),
		website: __( 'Ad esempio https://www.miohotel.it', 'booking-manager' ),
	};

	return (
		<Page
			title={ __( 'Attività', 'booking-manager' ) }
			subtitle={
				tenantId ? (
					<a href={ '#/platform/tenants/' + tenantId }>
						{ sprintf(
							/* translators: %s: customer name */
							__( '← %s', 'booking-manager' ),
							d.tenant.name
						) }
					</a>
				) : (
					d.tenant.name
				)
			}
			actions={
				<Button
					variant="primary"
					onClick={ save }
					isBusy={ action.busy }
					disabled={ action.busy }
				>
					{ __( 'Salva', 'booking-manager' ) }
				</Button>
			}
		>
			<Success onDismiss={ () => setDone( '' ) }>{ done }</Success>
			<ErrorNotice
				error={ action.error }
				onDismiss={ () => action.setError( null ) }
			/>
			<Card title={ __( 'Contatti', 'booking-manager' ) }>
				<p className="bkng-help">
					{ __(
						'Gli ospiti vedono solo i contatti con «Mostra al pubblico», nel modulo di prenotazione e nella pagina della prenotazione. Telefono, email e sito scritti nella scheda di una struttura hanno la precedenza per quella struttura.',
						'booking-manager'
					) }
				</p>
				<Toggle
					label={ __(
						'Stessa email per tutto (principale, prenotazioni, informazioni)',
						'booking-manager'
					) }
					checked={ !! form.same_email }
					onChange={ set( 'same_email' ) }
				/>
				<Grid>
					{ CONTACTS.filter( ( [ key ] ) => ! hidden( key ) ).map(
						( [ key, label ] ) => (
							<div key={ key } className="bkng-business-field">
								<Text
									label={ label }
									value={ form.contacts[ key ] || '' }
									onChange={ setContact( key ) }
									help={
										fields[ 'contacts.' + key ] ||
										help[ key ]
									}
									className={
										fields[ 'contacts.' + key ]
											? 'has-error'
											: ''
									}
									type={
										key.endsWith( 'email' )
											? 'email'
											: 'text'
									}
								/>
								<Check
									label={ __(
										'Mostra al pubblico',
										'booking-manager'
									) }
									checked={ !! form.public[ key ] }
									onChange={ setPublic( key ) }
								/>
							</div>
						)
					) }
				</Grid>
			</Card>
			<Card title={ __( 'Pulsante WhatsApp', 'booking-manager' ) }>
				<p className="bkng-help">
					{ form.contacts.whatsapp && form.public.whatsapp
						? __(
								'Il pulsante «Scrivici su WhatsApp» compare nel modulo e nella pagina della prenotazione: apre la chat con il numero dell’attività, senza nessun servizio a pagamento.',
								'booking-manager'
						  )
						: __(
								'Per mostrare il pulsante scrivi il numero WhatsApp qui sopra e spunta «Mostra al pubblico».',
								'booking-manager'
						  ) }
				</p>
				<Textarea
					label={ __(
						'Testo già scritto nel messaggio',
						'booking-manager'
					) }
					value={ form.whatsapp_text || '' }
					onChange={ set( 'whatsapp_text' ) }
					rows={ 2 }
					help={
						fields.whatsapp_text ||
						__(
							'Puoi usare {struttura} e {codice_prenotazione}. Vuoto: «Ciao, vorrei informazioni su {struttura}.» nel modulo e «Ciao, ho una domanda sulla prenotazione {codice_prenotazione}.» nella pagina della prenotazione.',
							'booking-manager'
						)
					}
				/>
			</Card>
			<Card title={ __( 'Aspetto del modulo', 'booking-manager' ) }>
				<Grid>
					<ImageField
						label={ __( 'Logo', 'booking-manager' ) }
						id={ form.logo_id }
						onChange={ set( 'logo_id' ) }
						preview={
							form.logo_id === d.profile.logo_id
								? d.logo || ''
								: ''
						}
						empty={ __( 'Nessun logo', 'booking-manager' ) }
					/>
					<div>
						<Select
							label={ __( 'Carattere', 'booking-manager' ) }
							value={ form.font }
							options={ d.fonts.map( ( key ) => ( {
								value: key,
								label: FONTS[ key ] || key,
							} ) ) }
							onChange={ set( 'font' ) }
							help={ fields.font }
						/>
						<p
							className="bkng-business-font"
							style={ {
								fontFamily: STACKS[ form.font ] || 'inherit',
							} }
						>
							{ __(
								'Prenota il tuo soggiorno – 1 camera, 2 adulti',
								'booking-manager'
							) }
						</p>
					</div>
				</Grid>
				{ fields.logo_id && (
					<p className="bkng-help is-error" role="alert">
						{ fields.logo_id }
					</p>
				) }
				<p className="bkng-help">
					{ __(
						'Logo e nome commerciale compaiono in fondo al modulo con i contatti pubblici. Il colore del modulo si sceglie nella scheda di ogni struttura.',
						'booking-manager'
					) }
				</p>
			</Card>
			<Card title={ __( 'Dopo il pagamento online', 'booking-manager' ) }>
				<Text
					label={ __(
						'Pagina del tuo sito dove torna l’ospite',
						'booking-manager'
					) }
					value={ form.return_url || '' }
					onChange={ set( 'return_url' ) }
					placeholder="https://"
					help={
						fields.return_url ||
						__(
							'Facoltativa. Deve essere la pagina che contiene il modulo: lì l’ospite legge che il pagamento è stato ricevuto. Vuota: torna alla pagina della prenotazione sulla piattaforma.',
							'booking-manager'
						)
					}
				/>
				<p className="bkng-help">
					{ d.domains.length
						? sprintf(
								/* translators: %s: list of domains */
								__(
									'Domini autorizzati per il modulo: %s. Li gestisce l’amministratore della piattaforma.',
									'booking-manager'
								),
								d.domains.join( ', ' )
						  )
						: __(
								'Nessun dominio autorizzato: chiedi all’amministratore della piattaforma di aggiungere quello del tuo sito.',
								'booking-manager'
						  ) }
				</p>
			</Card>
			<MailCard tenantId={ tenantId } />
		</Page>
	);
}

const SECURE = {
	tls: __( 'STARTTLS (di solito porta 587)', 'booking-manager' ),
	ssl: __( 'SSL/TLS (di solito porta 465)', 'booking-manager' ),
	none: __( 'Nessuna (sconsigliato)', 'booking-manager' ),
};

/**
 * Email (0.2.5): how the customer's e-mails leave. With the platform's service the
 * sender is the platform and the replies go to the business; with its own SMTP server
 * (if the plan has it) the e-mails leave from there, only after a test e-mail went out.
 * The password is never sent back: empty keeps the saved one.
 */
function MailCard( { tenantId } ) {
	const q = tenantId ? '?tenant=' + tenantId : '';
	const data = useLoad( () => get( '/tenant/mail' + q ), [ q ] );
	const action = useAction();
	const [ form, setForm ] = useState( null );
	const [ to, setTo ] = useState( '' );
	const [ done, setDone ] = useState( '' );

	useEffect( () => {
		if ( data.data ) {
			setForm( { ...data.data, password: '' } );
		}
	}, [ data.data ] );

	if ( data.error ) {
		return <ErrorNotice error={ data.error } onRetry={ data.reload } />;
	}
	if ( ! form ) {
		return <Loading />;
	}
	const d = data.data;
	const fields = action.error?.fields || {};
	const set = ( key ) => ( value ) =>
		setForm( ( f ) => ( { ...f, [ key ]: value } ) );
	const smtp = form.mode === 'smtp';
	const field = ( key, label, extra = {} ) => (
		<Text
			label={ label }
			value={ form[ key ] === null ? '' : String( form[ key ] ) }
			onChange={ set( key ) }
			help={ fields[ key ] || extra.help }
			className={ fields[ key ] ? 'has-error' : '' }
			type={ extra.type || 'text' }
			placeholder={ extra.placeholder }
			autoComplete={ extra.autoComplete }
		/>
	);

	const save = async () => {
		setDone( '' );
		const saved = await action.run( () =>
			put( '/tenant/mail' + q, {
				mode: form.mode,
				host: form.host,
				port: Number( form.port ) || 0,
				secure: form.secure,
				user: form.user,
				password: form.password,
				from_email: form.from_email,
				from_name: form.from_name,
				version: form.version,
			} )
		);
		if ( saved ) {
			data.setData( saved );
			setDone(
				saved.mode === 'smtp' && ! saved.verified
					? __(
							'Salvato. Ora invia l’email di prova: finché non arriva, le email partono dal servizio della piattaforma.',
							'booking-manager'
					  )
					: __( 'Impostazioni email salvate.', 'booking-manager' )
			);
		}
	};
	const test = async () => {
		setDone( '' );
		const res = await action.run( () =>
			post( '/tenant/mail/test' + q, { to } )
		);
		if ( res ) {
			data.setData( res.settings );
			if ( res.ok ) {
				setDone(
					__(
						'Email di prova inviata: da ora le email ai tuoi ospiti partono dal tuo server.',
						'booking-manager'
					)
				);
			} else {
				action.setError( {
					message: sprintf(
						/* translators: %s: error of the mail server. */
						__(
							'L’email di prova non è partita: %s',
							'booking-manager'
						),
						res.error ||
							__( 'errore sconosciuto', 'booking-manager' )
					),
				} );
			}
		}
	};

	let state;
	if ( ! smtp || d.mode !== 'smtp' ) {
		state = null;
	} else if ( d.active ) {
		state = (
			<Badge status="sent">
				{ __( 'Verificato e in uso', 'booking-manager' ) }
			</Badge>
		);
	} else {
		state = (
			<Badge status="pending">
				{ __( 'Da verificare: non ancora in uso', 'booking-manager' ) }
			</Badge>
		);
	}

	return (
		<Card title={ __( 'Email', 'booking-manager' ) }>
			<Success onDismiss={ () => setDone( '' ) }>{ done }</Success>
			<ErrorNotice
				error={ action.error }
				onDismiss={ () => action.setError( null ) }
			/>
			<Select
				label={ __(
					'Le email agli ospiti partono da',
					'booking-manager'
				) }
				value={ form.mode }
				options={ [
					{
						value: 'platform',
						label: __(
							'Servizio della piattaforma (consigliato)',
							'booking-manager'
						),
					},
					{
						value: 'smtp',
						label: __(
							'Il server SMTP della mia attività',
							'booking-manager'
						),
						disabled: ! d.allowed,
					},
				] }
				onChange={ set( 'mode' ) }
				help={ fields.mode }
			/>
			{ ! smtp && (
				<p className="bkng-help">
					{ sprintf(
						/* translators: 1: sender address, 2: reply address. */
						__(
							'Mittente: %1$s, con il nome della struttura. Le risposte degli ospiti arrivano a %2$s (email prenotazioni dell’attività, o quella della struttura se l’ha).',
							'booking-manager'
						),
						d.platform.from || '—',
						d.platform.reply_to ||
							__( 'nessun indirizzo', 'booking-manager' )
					) }
				</p>
			) }
			{ ! d.allowed && (
				<p className="bkng-help">
					{ __(
						'Il piano attuale non comprende un server email proprio: contatta la piattaforma per attivarlo.',
						'booking-manager'
					) }
				</p>
			) }
			{ smtp && (
				<>
					{ state && <p>{ state }</p> }
					<Grid>
						{ field(
							'host',
							__( 'Server SMTP', 'booking-manager' ),
							{
								placeholder: 'smtp.miodominio.it',
							}
						) }
						{ field( 'port', __( 'Porta', 'booking-manager' ), {
							type: 'number',
						} ) }
						<Select
							label={ __( 'Cifratura', 'booking-manager' ) }
							value={ form.secure }
							options={ Object.entries( SECURE ).map(
								( [ value, label ] ) => ( { value, label } )
							) }
							onChange={ set( 'secure' ) }
							help={ fields.secure }
						/>
						{ field( 'user', __( 'Utente', 'booking-manager' ), {
							autoComplete: 'off',
						} ) }
						{ field(
							'password',
							__( 'Password', 'booking-manager' ),
							{
								type: 'password',
								autoComplete: 'new-password',
								help: d.password_set
									? __(
											'Salvata e cifrata. Lascia vuoto per non cambiarla.',
											'booking-manager'
									  )
									: __(
											'Viene salvata cifrata e non si legge più da qui.',
											'booking-manager'
									  ),
							}
						) }
						{ field(
							'from_email',
							__( 'Indirizzo del mittente', 'booking-manager' ),
							{
								type: 'email',
								help: __(
									'Lo stesso dell’utente SMTP o dello stesso dominio delle email dell’attività.',
									'booking-manager'
								),
							}
						) }
						{ field(
							'from_name',
							__( 'Nome del mittente', 'booking-manager' ),
							{
								help: __(
									'Vuoto: il nome della struttura.',
									'booking-manager'
								),
							}
						) }
					</Grid>
					<p className="bkng-help">
						{ __(
							'Il server si usa solo dopo un’email di prova riuscita; se cambi un dato va provato di nuovo. Se un invio non riesce, l’email resta in coda e viene ritentata: non parte mai da un altro server.',
							'booking-manager'
						) }
					</p>
				</>
			) }
			<div className="bkng-page__actions" style={ { marginTop: 12 } }>
				<Button
					variant="primary"
					onClick={ save }
					isBusy={ action.busy }
					disabled={ action.busy }
				>
					{ __( 'Salva le impostazioni email', 'booking-manager' ) }
				</Button>
			</div>
			{ smtp && d.mode === 'smtp' && (
				<div className="bkng-row" style={ { marginTop: 12 } }>
					<Text
						label={ __( 'Email di prova a', 'booking-manager' ) }
						type="email"
						value={ to }
						onChange={ setTo }
						help={ fields.to }
					/>
					<Button
						variant="secondary"
						onClick={ test }
						isBusy={ action.busy }
						disabled={ action.busy || ! to }
					>
						{ __( 'Invia la prova', 'booking-manager' ) }
					</Button>
				</div>
			) }
		</Card>
	);
}
