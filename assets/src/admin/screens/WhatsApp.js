/**
 * WhatsApp (bkng_manage_settings), like Night Manager's section: the switch on top, then
 * three tabs. Messaggi: the texts with their approval by WhatsApp. Collegamento: the
 * provider's credentials, the delivery webhook and the country code. Registro: every
 * message, with «Invia di nuovo» / «Riprova».
 *
 * One WhatsApp Business number for the whole site. Messages go to every guest with a
 * mobile number, unless they ticked «Non voglio ricevere messaggi WhatsApp».
 */
import { Button, Modal } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { del, get, post, put, readableError } from '../lib/api';
import { dateTime } from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';
import {
	LOG_FILTERS,
	approvalBadge,
	connectionErrors,
	connectionForm,
	deliveryBadge,
	insertTag,
	messageNote,
	messageStatus,
	resendLabel,
	textProblems,
} from '../lib/whatsapp';
import {
	Card,
	Check,
	ErrorNotice,
	Loading,
	Page,
	Select,
	Success,
	Tabs,
	Text,
	Toggle,
} from '../components/ui';

const TABS = [
	[ 'messages', __( 'Messaggi', 'booking-manager' ) ],
	[ 'connection', __( 'Collegamento', 'booking-manager' ) ],
	[ 'log', __( 'Registro', 'booking-manager' ) ],
];

const PER_PAGE = 30;

function Tone( { badge } ) {
	return (
		<span className={ 'bkng-badge is-' + badge.tone }>{ badge.label }</span>
	);
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

/**
 * The message as WhatsApp shows it: the text, and the button under it.
 *
 * @param {Object}  props
 * @param {string}  props.text
 * @param {?string} props.button button text, nothing when the message has none
 * @param {boolean} props.old    the version still in use
 */
function Bubble( { text, button, old = false } ) {
	return (
		<div className={ 'bkng-wa__preview' + ( old ? ' is-old' : '' ) }>
			<div className="bkng-wa__bubble">{ text }</div>
			{ button && (
				<div className="bkng-wa__cta">
					<span aria-hidden="true">↗</span> { button }
				</div>
			) }
		</div>
	);
}

/* ------------------------------------------------------------------ Messaggi */

function TemplateEditor( { template, data, onSaved, onClose } ) {
	const [ body, setBody ] = useState( template.body );
	const [ button, setButton ] = useState( !! template.button );
	const [ preview, setPreview ] = useState( null );
	const [ to, setTo ] = useState( '' );
	const [ test, setTest ] = useState( null );
	const action = useAction();
	const area = useRef( null );
	const known = data.variables.map( ( v ) => v.tag.slice( 1, -1 ) );
	const problems = textProblems( body, known, data.max_length );
	const changed =
		body.trim() !== template.body || button !== !! template.button;
	const badge = approvalBadge(
		template.approval.status,
		data.settings.connected
	);

	// The preview follows the text, a moment after typing stops.
	useEffect( () => {
		let live = true;
		const timer = setTimeout( () => {
			post( '/whatsapp/templates/' + template.key + '/preview', {
				body,
				button,
			} )
				.then( ( p ) => live && setPreview( p ) )
				.catch( () => {} );
		}, 400 );
		return () => {
			live = false;
			clearTimeout( timer );
		};
	}, [ body, button, template.key ] );

	const insert = ( tag ) => {
		const el = area.current;
		const next = insertTag(
			body,
			tag,
			el?.selectionStart ?? undefined,
			el?.selectionEnd ?? undefined
		);
		setBody( next.body );
		window.requestAnimationFrame( () => {
			if ( el ) {
				el.focus();
				el.setSelectionRange( next.cursor, next.cursor );
			}
		} );
	};

	const save = async ( payload ) => {
		const t = await action.run( () =>
			put( '/whatsapp/templates/' + template.key, payload )
		);
		if ( t ) {
			let note = t.warning;
			if ( ! note && t.approval.status === 'approved' ) {
				note = __( 'Testo salvato e approvato.', 'booking-manager' );
			}
			onSaved( t, note );
		}
	};

	const sendTest = async () => {
		setTest( null );
		try {
			const r = await post(
				'/whatsapp/templates/' + template.key + '/test',
				{ to }
			);
			setTest( {
				ok: true,
				text: sprintf(
					/* translators: %s: phone number */
					__(
						'WhatsApp ha accettato il messaggio per %s: controlla il telefono.',
						'booking-manager'
					),
					r.sent_to
				),
			} );
		} catch ( e ) {
			const err = readableError( e );
			setTest( { ok: false, text: err.fields?.to || err.message } );
		}
	};

	return (
		<Modal
			title={ template.label }
			onRequestClose={ onClose }
			className="bkng-modal is-wide bkng-wa__editor"
		>
			<p className="bkng-help">{ template.when }</p>
			<p>
				<Tone badge={ badge } />
			</p>
			<ErrorNotice error={ action.error } />
			<div className="bkng-wa__columns">
				<div>
					<div
						className={
							'bkng-field' +
							( problems.length ? ' has-error' : '' )
						}
					>
						<label
							className="bkng-field__label"
							htmlFor="bkng-wa-body"
						>
							{ __( 'Testo del messaggio', 'booking-manager' ) }
						</label>
						<textarea
							id="bkng-wa-body"
							className="bkng-wa__textarea"
							ref={ area }
							rows={ 10 }
							value={ body }
							onChange={ ( e ) => setBody( e.target.value ) }
							disabled={ action.busy }
							aria-invalid={ problems.length ? true : undefined }
							aria-describedby="bkng-wa-body-help"
						/>
						<p className="bkng-help" id="bkng-wa-body-help">
							{ sprintf(
								/* translators: 1: characters used, 2: maximum */
								__(
									'%1$d di %2$d caratteri. Le variabili tra graffe vengono sostituite al momento dell’invio.',
									'booking-manager'
								),
								body.length,
								data.max_length
							) }
						</p>
						{ problems.length > 0 && (
							<p className="bkng-help is-error" role="alert">
								{ problems.join( ' ' ) }
							</p>
						) }
					</div>
					<Check
						label={ sprintf(
							/* translators: %s: text of the button, e.g. «Vedi la prenotazione» */
							__(
								'Pulsante «%s» sotto il messaggio',
								'booking-manager'
							),
							template.button_text
						) }
						help={ __(
							'Apre la pagina della prenotazione dell’ospite, senza mostrare il link lungo nel testo. WhatsApp non permette di nascondere un link dietro una parola: il pulsante è il modo per farlo.',
							'booking-manager'
						) }
						checked={ button }
						onChange={ setButton }
						disabled={ action.busy }
					/>
					<p className="bkng-wa__label">
						{ __(
							'Variabili: tocca per inserirle',
							'booking-manager'
						) }
					</p>
					<ul className="bkng-wa__vars">
						{ data.variables.map( ( v ) => (
							<li key={ v.tag }>
								<button
									type="button"
									className="bkng-wa__var"
									onClick={ () => insert( v.tag ) }
									disabled={ action.busy }
									title={ v.label }
								>
									<code>{ v.tag }</code>
									<span>{ v.label }</span>
								</button>
							</li>
						) ) }
					</ul>
				</div>
				<div>
					<p className="bkng-wa__label">
						{ __(
							'Anteprima, con una prenotazione di esempio',
							'booking-manager'
						) }
					</p>
					<div aria-live="polite">
						<Bubble
							text={ preview?.text ?? '…' }
							button={ preview ? preview.button : null }
						/>
					</div>
					{ template.approval.in_use &&
						template.approval.status !== 'approved' && (
							<>
								<p className="bkng-wa__label">
									{ __(
										'Versione in uso finché il nuovo testo non è approvato',
										'booking-manager'
									) }
								</p>
								<Bubble
									old
									text={ template.approval.in_use }
									button={
										template.approval.in_use_button
											? template.button_text
											: null
									}
								/>
							</>
						) }
					<h3 className="bkng-wa__title">
						{ __( 'Prova sul tuo telefono', 'booking-manager' ) }
					</h3>
					<div className="bkng-wa__test">
						<Text
							label={ __(
								'Il tuo cellulare',
								'booking-manager'
							) }
							type="tel"
							autoComplete="tel"
							value={ to }
							onChange={ setTo }
							help={ __(
								'Parte la versione approvata, con la prenotazione di esempio. Con il numero di prova di Meta il cellulare deve essere tra i destinatari autorizzati.',
								'booking-manager'
							) }
						/>
						<Button
							variant="secondary"
							onClick={ sendTest }
							disabled={
								action.busy ||
								! to.trim() ||
								! data.settings.connected ||
								! template.approval.in_use
							}
						>
							{ __( 'Invia prova', 'booking-manager' ) }
						</Button>
					</div>
					<div aria-live="polite">
						{ test && (
							<p
								className={
									'bkng-help' +
									( test.ok ? ' is-ok' : ' is-error' )
								}
							>
								{ test.text }
							</p>
						) }
					</div>
				</div>
			</div>
			<div className="bkng-modal__actions">
				{ template.customized && (
					<Button
						variant="tertiary"
						disabled={ action.busy }
						onClick={ () => save( { reset: true } ) }
					>
						{ __(
							'Ripristina il testo predefinito',
							'booking-manager'
						) }
					</Button>
				) }
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Annulla', 'booking-manager' ) }
				</Button>
				<Button
					variant="primary"
					isBusy={ action.busy }
					disabled={ action.busy || ! changed || problems.length > 0 }
					onClick={ () => save( { body, button } ) }
				>
					{ __(
						'Salva e invia per l’approvazione',
						'booking-manager'
					) }
				</Button>
			</div>
		</Modal>
	);
}

function MessagesTab( { data, setData, notify } ) {
	const [ open, setOpen ] = useState( null );
	const action = useAction();
	const { settings, templates } = data;

	const replace = ( template ) =>
		setData( {
			...data,
			templates: data.templates.map( ( t ) =>
				t.key === template.key ? template : t
			),
		} );

	const toggle = async ( template, active ) => {
		const t = await action.run( () =>
			put( '/whatsapp/templates/' + template.key, { is_active: active } )
		);
		if ( t ) {
			replace( t );
		}
	};

	const refresh = async () => {
		const list = await action.run( () =>
			post( '/whatsapp/templates/refresh' )
		);
		if ( list ) {
			setData( { ...data, templates: list } );
			notify(
				__( 'Stato delle approvazioni aggiornato.', 'booking-manager' )
			);
		}
	};

	return (
		<>
			<p className="bkng-help">
				{ __(
					'WhatsApp fa approvare ogni testo prima di poterlo inviare: quando salvi un testo nuovo parte la richiesta di approvazione e, finché non arriva, continua a partire la versione approvata prima. Di solito l’approvazione arriva in pochi minuti.',
					'booking-manager'
				) }
			</p>
			<div className="bkng-wa__actions">
				<Button
					variant="secondary"
					onClick={ refresh }
					isBusy={ action.busy }
					disabled={ action.busy || ! settings.connected }
				>
					{ __(
						'Aggiorna lo stato delle approvazioni',
						'booking-manager'
					) }
				</Button>
			</div>
			<ErrorNotice
				error={ action.error }
				onDismiss={ () => action.setError( null ) }
			/>
			<ul className="bkng-wa__messages">
				{ templates.map( ( t ) => (
					<li key={ t.key } className="bkng-card">
						<Toggle
							label={
								<span className="screen-reader-text">
									{ t.is_active
										? __( 'Attivo', 'booking-manager' )
										: __( 'Spento', 'booking-manager' ) }
								</span>
							}
							checked={ t.is_active }
							disabled={ action.busy }
							onChange={ ( v ) => toggle( t, v ) }
						/>
						<div className="bkng-wa__message">
							<div className="bkng-wa__message-head">
								<strong>{ t.label }</strong>
								<Tone
									badge={ approvalBadge(
										t.approval.status,
										settings.connected
									) }
								/>
								{ t.customized && (
									<span className="bkng-badge">
										{ __(
											'Testo personalizzato',
											'booking-manager'
										) }
									</span>
								) }
							</div>
							<p className="bkng-help">{ t.when }</p>
							{ t.approval.reason && (
								<p className="bkng-help is-error">
									{ t.approval.reason }
								</p>
							) }
							{ t.approval.status !== 'approved' &&
								t.approval.in_use && (
									<p className="bkng-wa__note">
										{ __(
											'Intanto parte la versione approvata prima.',
											'booking-manager'
										) }
									</p>
								) }
						</div>
						<Button
							variant="secondary"
							onClick={ () => setOpen( t.key ) }
						>
							{ __( 'Modifica', 'booking-manager' ) }
						</Button>
					</li>
				) ) }
			</ul>
			{ open && (
				<TemplateEditor
					template={ templates.find( ( t ) => t.key === open ) }
					data={ data }
					onSaved={ ( t, warning ) => {
						replace( t );
						setOpen( null );
						notify(
							warning ||
								__(
									'Testo salvato e inviato per l’approvazione.',
									'booking-manager'
								)
						);
					} }
					onClose={ () => setOpen( null ) }
				/>
			) }
		</>
	);
}

/* ------------------------------------------------------------------ Collegamento */

function ConnectionTab( { data, setData, notify } ) {
	const { settings } = data;
	const firstProvider =
		settings.provider || settings.providers[ 0 ]?.id || '';
	const [ form, setForm ] = useState( () =>
		connectionForm( settings, firstProvider )
	);
	const [ errors, setErrors ] = useState( {} );
	const [ country, setCountry ] = useState( settings.country_code );
	// «Scollega» asks once more in place before forgetting the credentials.
	const [ confirming, setConfirming ] = useState( false );
	const action = useAction();
	const countryAction = useAction();

	const provider = settings.providers.find( ( p ) => p.id === form.provider );
	const set = ( key ) => ( value ) => {
		setForm( ( f ) => ( { ...f, [ key ]: value } ) );
		setErrors( ( e ) => ( { ...e, [ key ]: undefined } ) );
	};

	const connect = async ( e ) => {
		e.preventDefault();
		const local = connectionErrors( settings, form );
		if ( Object.keys( local ).length ) {
			setErrors( local );
			return;
		}
		const next = await action.run( () =>
			put( '/whatsapp/connection', form )
		);
		if ( next ) {
			setData( next );
			setForm( connectionForm( next.settings, form.provider ) );
			setErrors( {} );
			notify(
				__(
					'WhatsApp collegato: i testi sono stati inviati per l’approvazione.',
					'booking-manager'
				)
			);
		}
	};

	const disconnect = async () => {
		setConfirming( false );
		const next = await action.run( () => del( '/whatsapp/connection' ) );
		if ( next ) {
			setData( next );
			setForm( connectionForm( next.settings, form.provider ) );
			notify( __( 'WhatsApp scollegato.', 'booking-manager' ) );
		}
	};

	const saveCountry = async ( e ) => {
		e.preventDefault();
		const next = await countryAction.run( () =>
			put( '/whatsapp/settings', { country_code: country } )
		);
		if ( next ) {
			setData( next );
			notify( __( 'Prefisso salvato.', 'booking-manager' ) );
		}
	};

	const fieldErrors = { ...( action.error?.fields || {} ), ...errors };

	return (
		<>
			<Card
				title={ __( 'Account WhatsApp Business', 'booking-manager' ) }
			>
				<form className="bkng-stack" onSubmit={ connect } noValidate>
					<ErrorNotice error={ action.error } />
					{ settings.unreadable && (
						<p className="bkng-help is-error" role="alert">
							{ __(
								'Le credenziali salvate non si possono più leggere (sono cambiate le chiavi di sicurezza del sito): inseriscile di nuovo.',
								'booking-manager'
							) }
						</p>
					) }
					<Select
						label={ __( 'Servizio WhatsApp', 'booking-manager' ) }
						value={ form.provider }
						options={ settings.providers.map( ( p ) => ( {
							value: p.id,
							label: p.label,
						} ) ) }
						onChange={ ( id ) => {
							setForm( connectionForm( settings, id ) );
							setErrors( {} );
						} }
						disabled={ action.busy }
					/>
					{ provider?.id === 'meta' && (
						<p className="bkng-help">
							{ __(
								'Serve un’app Business su developers.facebook.com con il prodotto WhatsApp e il numero della struttura collegato (un numero non già usato nell’app WhatsApp del telefono).',
								'booking-manager'
							) }
						</p>
					) }
					{ ( provider?.fields || [] ).map( ( f ) => {
						const saved =
							f.secret &&
							form.provider === settings.provider &&
							settings.credentials?.[ f.name ] === true;
						return (
							<div key={ f.name }>
								<Text
									type={ f.secret ? 'password' : 'text' }
									label={
										f.label + ( f.required ? ' *' : '' )
									}
									autoComplete={
										f.secret ? 'new-password' : 'off'
									}
									spellCheck={ false }
									placeholder={
										saved
											? __(
													'Salvato, lascia vuoto per non cambiarlo',
													'booking-manager'
											  )
											: ''
									}
									value={ form[ f.name ] }
									onChange={ set( f.name ) }
									disabled={ action.busy }
									help={ f.help }
								/>
								{ fieldErrors[ f.name ] && (
									<p className="bkng-help is-error">
										{ fieldErrors[ f.name ] }
									</p>
								) }
							</div>
						);
					} ) }
					<div className="bkng-wa__actions">
						<Button
							variant="primary"
							type="submit"
							isBusy={ action.busy }
							disabled={ action.busy }
						>
							{ __( 'Salva e verifica', 'booking-manager' ) }
						</Button>
						{ settings.connected && ! confirming && (
							<Button
								variant="tertiary"
								isDestructive
								onClick={ () => setConfirming( true ) }
								disabled={ action.busy }
							>
								{ __( 'Scollega', 'booking-manager' ) }
							</Button>
						) }
						{ confirming && (
							<>
								<span className="bkng-help">
									{ __(
										'I messaggi smettono di partire finché non ricolleghi WhatsApp.',
										'booking-manager'
									) }
								</span>
								<Button
									variant="primary"
									isDestructive
									onClick={ disconnect }
									disabled={ action.busy }
								>
									{ __( 'Sì, scollega', 'booking-manager' ) }
								</Button>
								<Button
									variant="tertiary"
									onClick={ () => setConfirming( false ) }
									disabled={ action.busy }
								>
									{ __( 'Annulla', 'booking-manager' ) }
								</Button>
							</>
						) }
					</div>
				</form>
			</Card>

			{ settings.connected && settings.provider === 'meta' && (
				<Card
					title={ __(
						'Stati «consegnato» e «letto» (facoltativo)',
						'booking-manager'
					) }
				>
					<p className="bkng-help">
						{ __(
							'Nella tua app su developers.facebook.com apri WhatsApp → Configurazione, incolla questi due valori nel webhook e iscriviti al campo «messages». Serve anche la chiave segreta dell’app qui sopra, e il sito deve essere raggiungibile da internet.',
							'booking-manager'
						) }
					</p>
					<p className="bkng-wa__label">
						{ __( 'URL di callback', 'booking-manager' ) }
					</p>
					<div className="bkng-wa__value">
						<code>{ settings.webhook_url }</code>
						<CopyButton
							text={ settings.webhook_url }
							label={ __( 'Copia l’URL', 'booking-manager' ) }
						/>
					</div>
					<p className="bkng-wa__label">
						{ __( 'Token di verifica', 'booking-manager' ) }
					</p>
					<div className="bkng-wa__value">
						<code>{ settings.verify_token }</code>
						<CopyButton
							text={ settings.verify_token }
							label={ __( 'Copia il token', 'booking-manager' ) }
						/>
					</div>
				</Card>
			) }

			<Card title={ __( 'Numeri degli ospiti', 'booking-manager' ) }>
				<form
					className="bkng-stack"
					onSubmit={ saveCountry }
					noValidate
				>
					<Text
						label={ __(
							'Prefisso internazionale predefinito',
							'booking-manager'
						) }
						inputMode="numeric"
						autoComplete="off"
						value={ country }
						onChange={ setCountry }
						help={ __(
							'Per i numeri degli ospiti scritti senza prefisso: 39 per l’Italia.',
							'booking-manager'
						) }
					/>
					{ countryAction.error && (
						<p className="bkng-help is-error" role="alert">
							{ countryAction.error.fields?.country_code ||
								countryAction.error.message }
						</p>
					) }
					<div className="bkng-wa__actions">
						<Button
							variant="secondary"
							type="submit"
							isBusy={ countryAction.busy }
							disabled={
								countryAction.busy ||
								country === settings.country_code
							}
						>
							{ __( 'Salva il prefisso', 'booking-manager' ) }
						</Button>
					</div>
				</form>
			</Card>
		</>
	);
}

/* ------------------------------------------------------------------ Registro */

function LogRow( { m, busy, onResend } ) {
	const delivery = deliveryBadge( m );
	const note = messageNote( m );
	const label = resendLabel( m );
	const waiting =
		m.status === 'queued' &&
		m.send_after &&
		new Date( m.send_after ) > new Date();
	return (
		<li className="bkng-wa__row">
			<div className="bkng-wa__row-main">
				<div className="bkng-wa__message-head">
					<strong>{ m.label }</strong>
					<Tone badge={ messageStatus( m.status ) } />
					{ delivery && <Tone badge={ delivery } /> }
				</div>
				<p className="bkng-wa__meta">
					{ [ dateTime( m.sent_at || m.created_at ), m.to, m.guest ]
						.filter( Boolean )
						.join( ' · ' ) }
					{ m.booking_code && (
						<>
							{ ' · ' }
							<a href={ '#/bookings/' + m.booking_id }>
								{ m.booking_code }
							</a>
						</>
					) }
				</p>
				{ waiting && (
					<p className="bkng-wa__meta">
						{ sprintf(
							/* translators: %s: date and time */
							__(
								'Nuovo tentativo dopo le %s',
								'booking-manager'
							),
							dateTime( m.send_after )
						) }
					</p>
				) }
				{ m.status === 'sent' && m.provider_id && (
					<p className="bkng-wa__meta">
						{ sprintf(
							/* translators: %s: WhatsApp message id */
							__( 'ID WhatsApp: %s', 'booking-manager' ),
							m.provider_id
						) }
					</p>
				) }
				{ note && <p className="bkng-wa__note">{ note }</p> }
			</div>
			{ label && (
				<Button
					variant="secondary"
					size="compact"
					onClick={ () => onResend( m ) }
					disabled={ busy }
				>
					{ label }
				</Button>
			) }
		</li>
	);
}

function LogTab( { notify } ) {
	const [ status, setStatus ] = useState( '' );
	const [ pages, setPages ] = useState( 1 );
	const log = useLoad(
		() =>
			get( '/whatsapp/messages', {
				status,
				page: 1,
				per_page: PER_PAGE * pages,
			} ),
		[ status, pages ]
	);
	const action = useAction();

	const resend = async ( m ) => {
		const row = await action.run( () =>
			post( '/whatsapp/messages/' + m.id + '/resend' )
		);
		if ( row ) {
			notify(
				row.status === 'sent'
					? __( 'Messaggio inviato di nuovo.', 'booking-manager' )
					: __( 'Messaggio rimesso in coda.', 'booking-manager' )
			);
			log.reload();
		}
	};

	return (
		<Card
			className="is-flush"
			title={ __( 'Messaggi WhatsApp', 'booking-manager' ) }
			actions={
				<>
					<Select
						label={ __( 'Stato', 'booking-manager' ) }
						hideLabelFromVision
						value={ status }
						options={ LOG_FILTERS.map( ( [ value, label ] ) => ( {
							value,
							label,
						} ) ) }
						onChange={ ( v ) => {
							setStatus( v );
							setPages( 1 );
						} }
					/>
					<Button variant="tertiary" onClick={ log.reload }>
						{ __( 'Aggiorna', 'booking-manager' ) }
					</Button>
				</>
			}
		>
			<ErrorNotice
				error={ action.error || log.error }
				onDismiss={ () => action.setError( null ) }
			/>
			{ log.loading && ! log.data && <Loading /> }
			{ log.data && ! log.data.items.length && (
				<p className="bkng-help bkng-wa__pad">
					{ __( 'Nessun messaggio.', 'booking-manager' ) }
				</p>
			) }
			{ log.data && log.data.items.length > 0 && (
				<ul className="bkng-wa__log">
					{ log.data.items.map( ( m ) => (
						<LogRow
							key={ m.id }
							m={ m }
							busy={ action.busy }
							onResend={ resend }
						/>
					) ) }
				</ul>
			) }
			{ log.data && log.data.total > log.data.items.length && (
				<div className="bkng-wa__actions bkng-wa__pad">
					<Button
						variant="secondary"
						onClick={ () => setPages( ( p ) => p + 1 ) }
						disabled={ log.loading }
					>
						{ __( 'Mostra altri', 'booking-manager' ) }
					</Button>
				</div>
			) }
			<p className="bkng-help bkng-wa__pad">
				{ __(
					'«Inviato» vuol dire che WhatsApp ha accettato il messaggio; «Consegnato» e «Letto» arrivano se hai impostato il webhook nella scheda «Collegamento». I messaggi partono subito e, come rete di sicurezza, ogni minuto: se il sito ha poche visite imposta un cron di sistema che chiami wp-cron.php.',
					'booking-manager'
				) }
			</p>
		</Card>
	);
}

/* ------------------------------------------------------------------ Screen */

export default function WhatsApp() {
	const view = useLoad( () => get( '/whatsapp' ), [] );
	const [ tab, setTab ] = useState( null );
	const [ notice, setNotice ] = useState( '' );
	const action = useAction();

	if ( view.loading && ! view.data ) {
		return <Loading />;
	}
	if ( view.error ) {
		return <ErrorNotice error={ view.error } onRetry={ view.reload } />;
	}
	const data = view.data;
	const { settings } = data;
	// Nothing connected yet: the connection is the first thing to do.
	const current = tab || ( settings.connected ? 'messages' : 'connection' );

	const toggle = async ( active ) => {
		const next = await action.run( () =>
			put( '/whatsapp/settings', { active } )
		);
		if ( next ) {
			view.setData( next );
			setNotice(
				active
					? __( 'Messaggi WhatsApp attivi.', 'booking-manager' )
					: __( 'Messaggi WhatsApp spenti.', 'booking-manager' )
			);
		}
	};

	return (
		<Page
			title={ __( 'WhatsApp', 'booking-manager' ) }
			subtitle={ __(
				'I messaggi agli ospiti su WhatsApp, dal numero WhatsApp Business del sito.',
				'booking-manager'
			) }
		>
			<Success onDismiss={ () => setNotice( '' ) }>{ notice }</Success>
			<Card className="bkng-wa__head">
				<Toggle
					label={ __(
						'I messaggi WhatsApp partono da soli.',
						'booking-manager'
					) }
					checked={ settings.active }
					disabled={ action.busy || ! settings.connected }
					onChange={ toggle }
				/>
				<p className="bkng-wa__account">
					{ settings.connected
						? sprintf(
								/* translators: 1: service, 2: account (name and number) */
								__(
									'Collegato con %1$s: %2$s',
									'booking-manager'
								),
								settings.provider_label,
								settings.account
						  )
						: __(
								'WhatsApp non è ancora collegato: compila la scheda «Collegamento».',
								'booking-manager'
						  ) }
				</p>
				<p className="bkng-help">
					{ __(
						'Partono a tutti gli ospiti che hanno lasciato il cellulare, salvo chi ha spuntato «Non voglio ricevere messaggi WhatsApp» (nel modulo di prenotazione o nella scheda dell’ospite). Con i messaggi attivi il modulo di prenotazione chiede il cellulare. Valgono per tutte le strutture del sito.',
						'booking-manager'
					) }
				</p>
				<ErrorNotice
					error={ action.error }
					onDismiss={ () => action.setError( null ) }
				/>
			</Card>
			<Tabs tabs={ TABS } current={ current } onChange={ setTab } />
			{ current === 'messages' && (
				<MessagesTab
					data={ data }
					setData={ view.setData }
					notify={ setNotice }
				/>
			) }
			{ current === 'connection' && (
				<ConnectionTab
					data={ data }
					setData={ view.setData }
					notify={ setNotice }
				/>
			) }
			{ current === 'log' && <LogTab notify={ setNotice } /> }
		</Page>
	);
}
