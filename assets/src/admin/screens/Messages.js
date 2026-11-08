/**
 * Email: the log of e-mails with retries (WhatsApp has its own section), the texts of
 * the property in every language with a preview, their look (logo, photo, colours) and
 * a test e-mail.
 */
import { Button, ColorPalette } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { del, getPaged, get, post, put, query } from '../lib/api';
import { boot, can } from '../lib/boot';
import { dateTime } from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';
import { useProperty } from '../lib/property';
import { FORM_COLORS } from './PropertySettings';
import RichEditor, {
	editorAvailable,
	insertAtCursor,
} from '../components/RichEditor';
import {
	Badge,
	Card,
	Empty,
	ErrorNotice,
	ImageField,
	Loading,
	Page,
	Pager,
	Select,
	Success,
	Tabs,
	Text,
} from '../components/ui';

const PER_PAGE = 30;

const STATUSES = {
	queued: __( 'In coda', 'booking-manager' ),
	sending: __( 'In invio', 'booking-manager' ),
	sent: __( 'Inviato', 'booking-manager' ),
	failed: __( 'Non riuscito', 'booking-manager' ),
	skipped: __( 'Non inviato', 'booking-manager' ),
};

const LANGUAGES = {
	it: 'Italiano',
	en: 'English',
	de: 'Deutsch',
	fr: 'Français',
	es: 'Español',
};

function Log( { property } ) {
	const [ status, setStatus ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const list = useLoad(
		() =>
			getPaged( '/properties/' + property.id + '/notifications', {
				// WhatsApp messages have their own log (WhatsApp → Registro).
				channel: 'email',
				status,
				page,
				per_page: PER_PAGE,
			} ),
		[ property.id, status, page ]
	);
	const action = useAction();
	const [ notice, setNotice ] = useState( '' );
	return (
		<Card
			className="is-flush"
			title={ __( 'Registro delle email', 'booking-manager' ) }
			actions={
				<Select
					label={ __( 'Stato', 'booking-manager' ) }
					hideLabelFromVision
					value={ status }
					options={ [
						{ value: '', label: __( 'Tutti', 'booking-manager' ) },
						...Object.entries( STATUSES ).map(
							( [ value, label ] ) => ( { value, label } )
						),
					] }
					onChange={ ( v ) => {
						setStatus( v );
						setPage( 1 );
					} }
				/>
			}
		>
			<div style={ { padding: '0 18px' } }>
				<Success onDismiss={ () => setNotice( '' ) }>
					{ notice }
				</Success>
				<ErrorNotice
					error={ list.error || action.error }
					onRetry={ list.error ? list.reload : undefined }
				/>
			</div>
			{ list.loading && <Loading /> }
			{ list.data && ! list.data.items.length && (
				<Empty title={ __( 'Nessun messaggio', 'booking-manager' ) } />
			) }
			{ list.data && list.data.items.length > 0 && (
				<div className="bkng-table-wrap">
					<table className="bkng-table">
						<thead>
							<tr>
								<th>{ __( 'Data', 'booking-manager' ) }</th>
								<th>
									{ __( 'Messaggio', 'booking-manager' ) }
								</th>
								<th>
									{ __( 'Destinatario', 'booking-manager' ) }
								</th>
								<th>{ __( 'Stato', 'booking-manager' ) }</th>
								<th />
							</tr>
						</thead>
						<tbody>
							{ list.data.items.map( ( n ) => (
								<tr key={ n.id }>
									<td className="bkng-nowrap">
										{ dateTime(
											n.sent_at || n.created_at
										) }
									</td>
									<td>
										{ n.template_label }
										{ n.subject && (
											<div className="bkng-small">
												{ n.subject }
											</div>
										) }
										<div className="bkng-small bkng-muted">
											{ n.channel === 'whatsapp'
												? 'WhatsApp'
												: 'Email' }
											{ n.booking_id && (
												<>
													{ ' · ' }
													<a
														href={
															'#/bookings/' +
															n.booking_id
														}
													>
														{ n.booking_code }
													</a>
												</>
											) }
										</div>
									</td>
									<td>{ n.recipient }</td>
									<td>
										<Badge status={ n.status }>
											{ STATUSES[ n.status ] }
										</Badge>
										{ n.last_error && (
											<div className="bkng-small bkng-muted">
												{ n.last_error }
											</div>
										) }
										{ n.next_attempt && (
											<div className="bkng-small bkng-muted">
												{ sprintf(
													/* translators: %s: time. */ __(
														'Nuovo tentativo: %s',
														'booking-manager'
													),
													dateTime( n.next_attempt )
												) }
											</div>
										) }
									</td>
									<td className="bkng-right">
										{ [
											'failed',
											'skipped',
											'sent',
										].includes( n.status ) &&
											n.booking_id &&
											can( 'bkng_edit_booking' ) && (
												<Button
													variant="link"
													onClick={ async () => {
														const res =
															await action.run(
																() =>
																	post(
																		'/notifications/' +
																			n.id +
																			'/resend'
																	)
															);
														if ( res ) {
															setNotice(
																__(
																	'Messaggio inviato di nuovo.',
																	'booking-manager'
																)
															);
															list.reload();
														}
													} }
												>
													{ __(
														'Invia di nuovo',
														'booking-manager'
													) }
												</Button>
											) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
			{ list.data && (
				<Pager
					page={ page }
					total={ list.data.total }
					perPage={ PER_PAGE }
					onChange={ setPage }
				/>
			) }
		</Card>
	);
}

const NEW_CUSTOM = '__new';

/**
 * Texts of the e-mails (0.2.5): for this property, or for all the properties of the
 * customer (used where a property has none of its own), with the visual editor, the
 * placeholders and the custom messages of the property, sent by hand from a booking.
 */
function Templates( { property } ) {
	// The customer's level needs the right to manage the business (Attività).
	const business = can( 'bkng_manage_properties' ) && !! boot.me?.tenant;
	const [ level, setLevel ] = useState( 'property' );
	const tenantLevel = level === 'tenant';
	// The customer of this property (the platform may be looking at any customer's).
	const tq =
		tenantLevel && property.tenant_id ? { tenant: property.tenant_id } : {};
	const listPath = tenantLevel
		? '/tenant/templates' + query( tq )
		: '/properties/' + property.id + '/templates';
	const data = useLoad( () => get( listPath ), [ listPath ] );
	const [ key, setKey ] = useState( '' );
	const [ lang, setLang ] = useState( 'it' );
	const [ form, setForm ] = useState( { subject: '', body: '' } );
	const [ mount, setMount ] = useState( 0 );
	const [ preview, setPreview ] = useState( null );
	const [ notice, setNotice ] = useState( '' );
	const [ editor, setEditor ] = useState( null );
	const action = useAction();
	const base = tenantLevel
		? '/tenant/templates/'
		: '/properties/' + property.id + '/templates/';

	const custom = ( ( ! tenantLevel && data.data?.custom ) || [] ).map(
		( t ) => ( { ...t, defaults: { subject: '', body: '' } } )
	);
	const isNew = key === NEW_CUSTOM;
	const tpl = isNew
		? {
				key: NEW_CUSTOM,
				label: '',
				defaults: { subject: '', body: '' },
				own: {},
				custom: true,
		  }
		: custom.find( ( t ) => t.key === key ) ||
		  data.data?.templates.find( ( t ) => t.key === key ) ||
		  data.data?.templates[ 0 ];
	const isCustom = !! tpl && ( isNew || tpl.key.startsWith( 'custom_' ) );
	useEffect( () => {
		if ( tpl ) {
			const own = tpl.own[ lang ];
			setForm(
				own
					? { subject: own.subject, body: own.body }
					: { subject: '', body: '' }
			);
			setPreview( null );
			setMount( ( m ) => m + 1 );
		}
	}, [ tpl?.key, lang, data.data, level ] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( data.loading && ! data.data ) {
		return <Loading />;
	}
	if ( data.error ) {
		return <ErrorNotice error={ data.error } onRetry={ data.reload } />;
	}
	const own = !! tpl.own[ lang ];
	const inherited = ! tenantLevel && ! own && tpl.tenant?.[ lang ];
	const fields = action.error?.fields || {};
	const options = [
		...data.data.templates.map( ( t ) => ( {
			value: t.key,
			label: t.label,
		} ) ),
		...custom.map( ( t ) => ( {
			value: t.key,
			label: sprintf(
				/* translators: %s: name of a custom message. */
				__( 'Personalizzato: %s', 'booking-manager' ),
				t.label
			),
		} ) ),
		...( tenantLevel
			? []
			: [
					{
						value: NEW_CUSTOM,
						label: __(
							'+ Nuovo messaggio personalizzato',
							'booking-manager'
						),
					},
			  ] ),
	];

	const save = async () => {
		const res = await action.run( () =>
			isNew
				? post( base + 'custom', { ...form, language: lang } )
				: put( base + tpl.key, { ...form, language: lang, ...tq } )
		);
		if ( res ) {
			data.setData( res );
			if ( res.created ) {
				setKey( res.created );
			}
			setNotice( __( 'Testo salvato.', 'booking-manager' ) );
		}
	};

	return (
		<div className="bkng-columns">
			<Card title={ __( 'Testi delle email', 'booking-manager' ) }>
				<p className="bkng-help">
					{ isCustom
						? __(
								'Un messaggio personalizzato si invia a mano dalla scheda della prenotazione («Invia un’email»). L’oggetto è anche il titolo dell’email.',
								'booking-manager'
						  )
						: __(
								'Lascia vuoto per usare il testo predefinito. Dettagli della prenotazione, QR e link per gestirla vengono aggiunti da soli.',
								'booking-manager'
						  ) }
				</p>
				<Success onDismiss={ () => setNotice( '' ) }>
					{ notice }
				</Success>
				<ErrorNotice
					error={ action.error }
					onDismiss={ () => action.setError( null ) }
				/>
				{ business && (
					<Select
						label={ __( 'Testi per', 'booking-manager' ) }
						value={ level }
						options={ [
							{
								value: 'property',
								label: sprintf(
									/* translators: %s: property name. */
									__(
										'Questa struttura (%s)',
										'booking-manager'
									),
									property.name
								),
							},
							{
								value: 'tenant',
								label: __(
									'Tutte le strutture dell’attività',
									'booking-manager'
								),
							},
						] }
						onChange={ ( v ) => {
							// The same message on the other level (custom ones are per property).
							if (
								key.startsWith( 'custom_' ) ||
								key === NEW_CUSTOM
							) {
								setKey( '' );
							}
							setLevel( v );
						} }
						help={
							tenantLevel
								? __(
										'Questi testi valgono per ogni struttura che non ha il proprio.',
										'booking-manager'
								  )
								: ''
						}
					/>
				) }
				<div className="bkng-row">
					<Select
						label={ __( 'Messaggio', 'booking-manager' ) }
						value={ tpl.key }
						options={ options }
						onChange={ setKey }
					/>
					<Select
						label={ __( 'Lingua', 'booking-manager' ) }
						value={ lang }
						options={ data.data.languages.map( ( l ) => ( {
							value: l,
							label: LANGUAGES[ l ] || l,
						} ) ) }
						onChange={ setLang }
					/>
				</div>
				{ inherited && (
					<p className="bkng-help">
						{ __(
							'Ora questa struttura usa il testo scritto per tutte le strutture dell’attività. Se salvi qui, questa struttura avrà il suo.',
							'booking-manager'
						) }
					</p>
				) }
				<div className="bkng-stack">
					<Text
						label={ __( 'Oggetto', 'booking-manager' ) }
						value={ form.subject }
						placeholder={
							inherited ? inherited.subject : tpl.defaults.subject
						}
						onChange={ ( v ) => setForm( { ...form, subject: v } ) }
						help={ fields.subject }
					/>
					<RichEditor
						key={ level + tpl.key + lang + mount }
						label={ __( 'Testo', 'booking-manager' ) }
						value={ form.body }
						onChange={ ( v ) =>
							setForm( ( f ) => ( { ...f, body: v } ) )
						}
						onReady={ setEditor }
						help={
							fields.body ||
							( ! isCustom && ! form.body
								? __(
										'Vuoto: si usa il testo predefinito (vedi l’anteprima).',
										'booking-manager'
								  )
								: '' )
						}
					/>
					<div className="bkng-small bkng-muted">
						{ __( 'Segnaposto:', 'booking-manager' ) }{ ' ' }
						{ data.data.placeholders.map( ( p ) =>
							editor && editorAvailable() ? (
								<button
									key={ p }
									type="button"
									className="bkng-chip"
									onClick={ () =>
										insertAtCursor( editor, p )
									}
								>
									{ p }
								</button>
							) : (
								<span key={ p }>{ p } </span>
							)
						) }
					</div>
				</div>
				<div className="bkng-page__actions" style={ { marginTop: 12 } }>
					<Button
						variant="primary"
						isBusy={ action.busy }
						onClick={ save }
					>
						{ isNew
							? __( 'Crea il messaggio', 'booking-manager' )
							: __( 'Salva', 'booking-manager' ) }
					</Button>
					{ ! isNew && (
						<Button
							variant="secondary"
							onClick={ async () => {
								const res = await action.run( () =>
									post( base + tpl.key + '/preview', {
										subject: form.subject || null,
										body: form.body || null,
										...tq,
									} )
								);
								if ( res ) {
									setPreview( res );
								}
							} }
						>
							{ __( 'Anteprima', 'booking-manager' ) }
						</Button>
					) }
					{ own && (
						<Button
							variant="tertiary"
							isDestructive
							onClick={ async () => {
								const res = await action.run( () =>
									del(
										base + tpl.key,
										isCustom
											? {}
											: { language: lang, ...tq }
									)
								);
								if ( res ) {
									data.setData( res );
									if ( isCustom ) {
										setKey( '' );
									}
									setNotice(
										isCustom
											? __(
													'Messaggio eliminato.',
													'booking-manager'
											  )
											: __(
													'Ripristinato il testo predefinito.',
													'booking-manager'
											  )
									);
								}
							} }
						>
							{ isCustom
								? __(
										'Elimina il messaggio',
										'booking-manager'
								  )
								: __(
										'Torna al predefinito',
										'booking-manager'
								  ) }
						</Button>
					) }
				</div>
			</Card>
			<Card title={ __( 'Anteprima', 'booking-manager' ) }>
				{ ! preview && (
					<p className="bkng-muted">
						{ __(
							"Premi «Anteprima» per vedere l'email con i dati di una prenotazione.",
							'booking-manager'
						) }
					</p>
				) }
				{ preview && (
					<>
						<p className="bkng-strong">{ preview.subject }</p>
						{ preview.example && (
							<p className="bkng-small bkng-muted">
								{ __(
									'Dati di esempio: non ci sono ancora prenotazioni.',
									'booking-manager'
								) }
							</p>
						) }
						<iframe
							title={ __( 'Anteprima email', 'booking-manager' ) }
							sandbox=""
							srcDoc={ preview.html }
							style={ {
								width: '100%',
								height: 520,
								border: '1px solid #d6e0da',
								borderRadius: 8,
								background: '#fff',
							} }
						/>
					</>
				) }
			</Card>
		</div>
	);
}

/* ------------------------------------------------------------------ Look of the e-mails */

const DESIGN_KEYS = [
	'email_logo_id',
	'email_banner_id',
	'email_subtitle',
	'email_tagline',
	'email_signoff',
	'email_color',
	'email_header_bg',
	'email_page_bg',
];

const BACKGROUNDS = [
	{ name: __( 'Crema', 'booking-manager' ), color: '#f6f1e9' },
	{ name: __( 'Bianco', 'booking-manager' ), color: '#ffffff' },
	{ name: __( 'Salvia', 'booking-manager' ), color: '#e8efe9' },
	{ name: __( 'Cielo', 'booking-manager' ), color: '#e8eff5' },
	{ name: __( 'Sabbia', 'booking-manager' ), color: '#efe6d8' },
	{ name: __( 'Grigio chiaro', 'booking-manager' ), color: '#f4f6f4' },
];

function ColorField( { label, help, value, colors, onChange } ) {
	return (
		<div className="bkng-color-field">
			<p className="bkng-strong">{ label }</p>
			<ColorPalette
				colors={ colors }
				value={ value || undefined }
				onChange={ ( c ) => onChange( c || '' ) }
				disableAlpha
				clearable
			/>
			{ help && <p className="bkng-help">{ help }</p> }
		</div>
	);
}

/** The e-mail as on a computer (scaled to the card) or on a phone. */
function MailFrame( { html } ) {
	const box = useRef( null );
	const [ phone, setPhone ] = useState( false );
	const [ width, setWidth ] = useState( 0 );
	useEffect( () => {
		const el = box.current;
		if ( ! el || ! window.ResizeObserver ) {
			return undefined;
		}
		const observer = new window.ResizeObserver( () =>
			setWidth( el.clientWidth )
		);
		observer.observe( el );
		return () => observer.disconnect();
	}, [] );
	const size = phone ? 390 : 700;
	const scale = width ? Math.min( 1, width / size ) : 1;
	const height = 760;
	return (
		<>
			<div className="bkng-segmented" style={ { marginBottom: 12 } }>
				<Button
					variant={ phone ? 'secondary' : 'primary' }
					onClick={ () => setPhone( false ) }
				>
					{ __( 'Computer', 'booking-manager' ) }
				</Button>
				<Button
					variant={ phone ? 'primary' : 'secondary' }
					onClick={ () => setPhone( true ) }
				>
					{ __( 'Telefono', 'booking-manager' ) }
				</Button>
			</div>
			<div ref={ box } className="bkng-mail-frame" style={ { height } }>
				<iframe
					title={ __( 'Anteprima email', 'booking-manager' ) }
					sandbox=""
					srcDoc={ html }
					style={ {
						width: size,
						height: height / scale,
						transform: 'scale(' + scale + ')',
						transformOrigin: '0 0',
						marginLeft:
							scale === 1 && width > size
								? ( width - size ) / 2
								: 0,
					} }
				/>
			</div>
		</>
	);
}

function Appearance( { property } ) {
	const data = useLoad(
		() => get( '/properties/' + property.id ),
		[ property.id ]
	);
	const [ form, setForm ] = useState( null );
	const [ key, setKey ] = useState( 'guest_received' );
	const [ preview, setPreview ] = useState( null );
	const [ notice, setNotice ] = useState( '' );
	const [ own, setOwn ] = useState( false );
	const action = useAction();
	useEffect( () => {
		if ( data.data ) {
			setForm(
				Object.fromEntries(
					DESIGN_KEYS.map( ( k ) => [ k, data.data.settings[ k ] ] )
				)
			);
		}
	}, [ data.data ] );
	// The preview follows what is typed, a moment after the last change.
	useEffect( () => {
		if ( ! form ) {
			return undefined;
		}
		let live = true;
		const timer = window.setTimeout( () => {
			post(
				'/properties/' + property.id + '/templates/' + key + '/preview',
				{ design: form }
			)
				.then( ( res ) => live && setPreview( res ) )
				.catch( () => {} );
		}, 400 );
		return () => {
			live = false;
			window.clearTimeout( timer );
		};
	}, [ form, key, property.id ] );

	if ( data.loading && ! data.data ) {
		return <Loading />;
	}
	if ( data.error ) {
		return <ErrorNotice error={ data.error } onRetry={ data.reload } />;
	}
	if ( ! form ) {
		return null;
	}
	const set = ( k ) => ( v ) => setForm( ( f ) => ( { ...f, [ k ]: v } ) );
	const banner = form.email_banner_id;
	return (
		<div className="bkng-columns bkng-columns--preview">
			<Card title={ __( 'Aspetto delle email', 'booking-manager' ) }>
				<p className="bkng-help">
					{ __(
						'Vale per tutte le email agli ospiti e alla struttura. Testi e dettagli della prenotazione restano quelli di «Testi».',
						'booking-manager'
					) }
				</p>
				<Success onDismiss={ () => setNotice( '' ) }>
					{ notice }
				</Success>
				<ErrorNotice
					error={ action.error }
					onDismiss={ () => action.setError( null ) }
				/>
				<div className="bkng-stack">
					<ImageField
						label={ __( 'Logo', 'booking-manager' ) }
						id={ form.email_logo_id }
						onChange={ set( 'email_logo_id' ) }
						empty={ __( 'Nessun logo', 'booking-manager' ) }
						help={ __(
							'Accanto al nome della struttura, in alto a sinistra.',
							'booking-manager'
						) }
					/>
					<Select
						label={ __( 'Foto in alto', 'booking-manager' ) }
						value={ own || banner > 0 ? 'own' : String( banner ) }
						options={ [
							{
								value: '0',
								label: __(
									'La prima foto delle camere',
									'booking-manager'
								),
							},
							{
								value: 'own',
								label: __(
									'Una foto scelta da me',
									'booking-manager'
								),
							},
							{
								value: '-1',
								label: __( 'Nessuna foto', 'booking-manager' ),
							},
						] }
						onChange={ ( v ) => {
							setOwn( v === 'own' );
							if ( v !== 'own' ) {
								set( 'email_banner_id' )( Number( v ) );
							}
						} }
					/>
					{ ( own || banner > 0 ) && (
						<ImageField
							label={ __( 'Foto', 'booking-manager' ) }
							id={ banner }
							onChange={ ( id ) =>
								set( 'email_banner_id' )( id || 0 )
							}
							empty={ __(
								'Nessuna foto scelta',
								'booking-manager'
							) }
						/>
					) }
					<Text
						label={ __(
							'Sottotitolo sotto il nome',
							'booking-manager'
						) }
						value={ form.email_subtitle }
						placeholder={ __(
							'es. B&B & Hotel',
							'booking-manager'
						) }
						onChange={ set( 'email_subtitle' ) }
					/>
					<Text
						label={ __( 'Frase in alto', 'booking-manager' ) }
						value={ form.email_tagline }
						placeholder={ __(
							'es. La tua ospitalità ci sta a cuore',
							'booking-manager'
						) }
						onChange={ set( 'email_tagline' ) }
					/>
					<Text
						label={ __( 'Saluto in fondo', 'booking-manager' ) }
						value={ form.email_signoff }
						placeholder={ __(
							'es. Grazie per averci scelto!',
							'booking-manager'
						) }
						onChange={ set( 'email_signoff' ) }
					/>
					<ColorField
						label={ __( 'Colore principale', 'booking-manager' ) }
						colors={ FORM_COLORS }
						value={ form.email_color }
						onChange={ set( 'email_color' ) }
						help={ __(
							'Titoli, icone, pulsante e barra in fondo. Senza scelta: il colore del modulo di prenotazione.',
							'booking-manager'
						) }
					/>
					<ColorField
						label={ __(
							"Sfondo dell'intestazione",
							'booking-manager'
						) }
						colors={ BACKGROUNDS }
						value={ form.email_header_bg }
						onChange={ set( 'email_header_bg' ) }
					/>
					<ColorField
						label={ __( 'Sfondo della pagina', 'booking-manager' ) }
						colors={ BACKGROUNDS }
						value={ form.email_page_bg }
						onChange={ set( 'email_page_bg' ) }
					/>
				</div>
				<div className="bkng-page__actions" style={ { marginTop: 16 } }>
					<Button
						variant="primary"
						isBusy={ action.busy }
						onClick={ async () => {
							const res = await action.run( () =>
								put(
									'/properties/' + property.id + '/settings',
									Object.fromEntries(
										DESIGN_KEYS.map( ( k ) => [
											k,
											form[ k ],
										] )
									)
								)
							);
							if ( res ) {
								data.reload();
								setNotice(
									__( 'Aspetto salvato.', 'booking-manager' )
								);
							}
						} }
					>
						{ __( 'Salva', 'booking-manager' ) }
					</Button>
				</div>
			</Card>
			<Card
				title={ __( 'Anteprima', 'booking-manager' ) }
				actions={
					<Select
						label={ __( 'Messaggio', 'booking-manager' ) }
						hideLabelFromVision
						value={ key }
						options={ [
							{
								value: 'guest_received',
								label: __(
									'Richiesta ricevuta',
									'booking-manager'
								),
							},
							{
								value: 'guest_confirmed',
								label: __(
									'Prenotazione confermata',
									'booking-manager'
								),
							},
							{
								value: 'guest_pre_arrival',
								label: __(
									"Promemoria prima dell'arrivo",
									'booking-manager'
								),
							},
						] }
						onChange={ setKey }
					/>
				}
			>
				{ preview ? <MailFrame html={ preview.html } /> : <Loading /> }
			</Card>
		</div>
	);
}

function TestEmail( { property } ) {
	const [ to, setTo ] = useState( boot.me?.email || '' );
	const action = useAction();
	const [ result, setResult ] = useState( null );
	return (
		<Card title={ __( 'Email di prova', 'booking-manager' ) }>
			<p className="bkng-help">
				{ __(
					'Controlla che le email del sito arrivino (e non finiscano nello spam).',
					'booking-manager'
				) }
			</p>
			<ErrorNotice error={ action.error } />
			{ result &&
				( result.ok ? (
					<Success>
						{ __(
							'Email inviata: controlla la casella.',
							'booking-manager'
						) }
					</Success>
				) : (
					<ErrorNotice
						error={ {
							message:
								result.error ||
								__(
									"Il server non ha accettato l'email.",
									'booking-manager'
								),
						} }
					/>
				) ) }
			<form
				className="bkng-row"
				onSubmit={ async ( e ) => {
					e.preventDefault();
					setResult(
						await action.run( () =>
							post(
								'/properties/' +
									property.id +
									'/notifications/test',
								{ to }
							)
						)
					);
				} }
			>
				<div style={ { flex: 1 } }>
					<Text
						label={ __( 'Indirizzo', 'booking-manager' ) }
						type="email"
						value={ to }
						onChange={ setTo }
						required
					/>
				</div>
				<Button
					variant="secondary"
					type="submit"
					isBusy={ action.busy }
				>
					{ __( 'Invia', 'booking-manager' ) }
				</Button>
			</form>
		</Card>
	);
}

export default function Messages() {
	const { property } = useProperty();
	const [ tab, setTab ] = useState( 'log' );
	const settings = can( 'bkng_manage_property_settings' );
	return (
		<Page title={ __( 'Email', 'booking-manager' ) }>
			{ settings && (
				<Tabs
					current={ tab }
					onChange={ setTab }
					tabs={ [
						[ 'log', __( 'Registro', 'booking-manager' ) ],
						[ 'templates', __( 'Testi', 'booking-manager' ) ],
						[ 'look', __( 'Aspetto', 'booking-manager' ) ],
						[ 'test', __( 'Prova', 'booking-manager' ) ],
					] }
				/>
			) }
			{ tab === 'log' && <Log property={ property } /> }
			{ tab === 'templates' && settings && (
				<Templates property={ property } />
			) }
			{ tab === 'look' && settings && (
				<Appearance property={ property } />
			) }
			{ tab === 'test' && settings && (
				<TestEmail property={ property } />
			) }
		</Page>
	);
}
