/**
 * Messages: the log of e-mails (and WhatsApp, when connected) with retries, the texts of
 * the property in every language with a preview, their look (logo, photo, colours) and
 * a test e-mail.
 */
import { Button, ColorPalette } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { del, getPaged, get, post, put } from '../lib/api';
import { boot, can } from '../lib/boot';
import { dateTime } from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';
import { useProperty } from '../lib/property';
import { FORM_COLORS } from './PropertySettings';
import {
	Badge,
	Card,
	Empty,
	ErrorNotice,
	Loading,
	Page,
	Pager,
	Select,
	Success,
	Tabs,
	Text,
	Textarea,
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
			title={ __( 'Registro dei messaggi', 'booking-manager' ) }
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

function Templates( { property } ) {
	const data = useLoad(
		() => get( '/properties/' + property.id + '/templates' ),
		[ property.id ]
	);
	const [ key, setKey ] = useState( '' );
	const [ lang, setLang ] = useState( 'it' );
	const [ form, setForm ] = useState( { subject: '', body: '' } );
	const [ preview, setPreview ] = useState( null );
	const [ notice, setNotice ] = useState( '' );
	const action = useAction();
	const base = '/properties/' + property.id + '/templates/';

	const tpl =
		data.data?.templates.find( ( t ) => t.key === key ) ||
		data.data?.templates[ 0 ];
	useEffect( () => {
		if ( tpl ) {
			const own = tpl.own[ lang ];
			setForm(
				own
					? { subject: own.subject, body: own.body }
					: { subject: '', body: '' }
			);
			setPreview( null );
		}
	}, [ tpl?.key, lang, data.data ] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( data.loading && ! data.data ) {
		return <Loading />;
	}
	if ( data.error ) {
		return <ErrorNotice error={ data.error } onRetry={ data.reload } />;
	}
	const own = !! tpl.own[ lang ];
	return (
		<div className="bkng-columns">
			<Card title={ __( 'Testi delle email', 'booking-manager' ) }>
				<p className="bkng-help">
					{ __(
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
				<div className="bkng-row">
					<Select
						label={ __( 'Messaggio', 'booking-manager' ) }
						value={ tpl.key }
						options={ data.data.templates.map( ( t ) => ( {
							value: t.key,
							label: t.label,
						} ) ) }
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
				<div className="bkng-stack">
					<Text
						label={ __( 'Oggetto', 'booking-manager' ) }
						value={ form.subject }
						placeholder={ tpl.defaults.subject }
						onChange={ ( v ) => setForm( { ...form, subject: v } ) }
					/>
					<Textarea
						label={ __( 'Testo', 'booking-manager' ) }
						rows={ 8 }
						value={ form.body }
						placeholder={ tpl.defaults.body }
						onChange={ ( v ) => setForm( { ...form, body: v } ) }
					/>
					<p className="bkng-small bkng-muted">
						{ __( 'Segnaposto:', 'booking-manager' ) }{ ' ' }
						{ data.data.placeholders.join( ' ' ) }
					</p>
				</div>
				<div className="bkng-page__actions" style={ { marginTop: 12 } }>
					<Button
						variant="primary"
						isBusy={ action.busy }
						onClick={ async () => {
							const res = await action.run( () =>
								put( base + tpl.key, {
									...form,
									language: lang,
								} )
							);
							if ( res ) {
								data.setData( res );
								setNotice(
									__( 'Testo salvato.', 'booking-manager' )
								);
							}
						} }
					>
						{ __( 'Salva', 'booking-manager' ) }
					</Button>
					<Button
						variant="secondary"
						onClick={ async () => {
							const res = await action.run( () =>
								post( base + tpl.key + '/preview', {
									subject: form.subject || null,
									body: form.body || null,
								} )
							);
							if ( res ) {
								setPreview( res );
							}
						} }
					>
						{ __( 'Anteprima', 'booking-manager' ) }
					</Button>
					{ own && (
						<Button
							variant="tertiary"
							isDestructive
							onClick={ async () => {
								const res = await action.run( () =>
									del( base + tpl.key, { language: lang } )
								);
								if ( res ) {
									data.setData( res );
									setNotice(
										__(
											'Ripristinato il testo predefinito.',
											'booking-manager'
										)
									);
								}
							} }
						>
							{ __( 'Torna al predefinito', 'booking-manager' ) }
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

/** One image of the media library: its thumbnail, «Scegli» and «Rimuovi». */
function ImageField( { label, help, id, onChange, empty } ) {
	const [ url, setUrl ] = useState( '' );
	useEffect( () => {
		setUrl( '' );
		if ( id > 0 && window.wp?.media?.attachment ) {
			const a = window.wp.media.attachment( id );
			a.fetch().then( () =>
				setUrl(
					a.get( 'sizes' )?.thumbnail?.url || a.get( 'url' ) || ''
				)
			);
		}
	}, [ id ] );
	const pick = () => {
		if ( ! window.wp?.media ) {
			return;
		}
		const frame = window.wp.media( {
			title: label,
			library: { type: 'image' },
			multiple: false,
			button: { text: __( 'Usa questa immagine', 'booking-manager' ) },
		} );
		frame.on( 'select', () => {
			const a = frame.state().get( 'selection' ).first().toJSON();
			setUrl( a.sizes?.thumbnail?.url || a.url );
			onChange( a.id );
		} );
		frame.open();
	};
	return (
		<div className="bkng-image-field">
			<p className="bkng-strong">{ label }</p>
			<div className="bkng-image-field__row">
				<span className="bkng-image-field__thumb">
					{ id > 0 && url ? (
						<img src={ url } alt="" />
					) : (
						<span className="bkng-small bkng-muted">{ empty }</span>
					) }
				</span>
				<Button variant="secondary" onClick={ pick }>
					{ id > 0
						? __( 'Cambia', 'booking-manager' )
						: __( 'Scegli', 'booking-manager' ) }
				</Button>
				{ id > 0 && (
					<Button
						variant="tertiary"
						isDestructive
						onClick={ () => onChange( 0 ) }
					>
						{ __( 'Rimuovi', 'booking-manager' ) }
					</Button>
				) }
			</div>
			{ help && <p className="bkng-help">{ help }</p> }
		</div>
	);
}

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
		<Page title={ __( 'Messaggi', 'booking-manager' ) }>
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
