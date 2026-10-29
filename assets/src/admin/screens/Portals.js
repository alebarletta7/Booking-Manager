/**
 * Booking.com, Airbnb and the other portals (bkng_manage_integrations), through their
 * iCal calendars: for every room, the link to give the portals (our closed dates) and the
 * portals' calendars read every 15 minutes (their stays close the room here). On top: the
 * state of the automatic readings, «Collega i calendari di tutte le camere» (one form for
 * every room, the portal found from the address) and «Copia tutti i link».
 */
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { del, get, post } from '../lib/api';
import { dateTime } from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';
import { useProperty } from '../lib/property';
import {
	Card,
	Confirm,
	Empty,
	ErrorNotice,
	FormModal,
	Loading,
	Page,
	Select,
	Success,
	Text,
	Textarea,
} from '../components/ui';

/** Copies a value, saying so on the button for a moment. */
function CopyButton( { text } ) {
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
					// eslint-disable-next-line no-alert
					window.prompt(
						__( 'Copia il link', 'booking-manager' ),
						text
					);
				}
			} }
		>
			{ done
				? __( 'Copiato', 'booking-manager' )
				: __( 'Copia', 'booking-manager' ) }
		</Button>
	);
}

/** «Copia tutti i link»: every room's link to give the portals, one per line. */
function CopyAll( { rooms } ) {
	const [ done, setDone ] = useState( false );
	const text = allLinks( rooms );
	return (
		<Button
			variant="secondary"
			disabled={ ! rooms.length }
			onClick={ async () => {
				try {
					await window.navigator.clipboard.writeText( text );
					setDone( true );
					window.setTimeout( () => setDone( false ), 2000 );
				} catch ( e ) {
					// eslint-disable-next-line no-alert
					window.prompt(
						__( 'Copia i link', 'booking-manager' ),
						text
					);
				}
			} }
		>
			{ done
				? __( 'Copiati', 'booking-manager' )
				: __( 'Copia tutti i link', 'booking-manager' ) }
		</Button>
	);
}

function roomTitle( room ) {
	const name = room.name ? room.name : room.code;
	return room.room_type ? name + ' · ' + room.room_type : name;
}

const STATES = {
	pending: [
		'info',
		__( 'In attesa della prima lettura', 'booking-manager' ),
	],
	ok: [ 'ok', __( 'Collegato', 'booking-manager' ) ],
	late: [ 'warn', __( 'In ritardo', 'booking-manager' ) ],
	warning: [ 'warn', __( 'Da confermare', 'booking-manager' ) ],
	error: [ 'bad', __( 'Non raggiungibile', 'booking-manager' ) ],
};

function syncBadge( feed ) {
	return STATES[ feed.state ] || STATES.pending;
}

/** The rooms' links, one per line, to paste in a note or send to whoever manages the portals. */
export function allLinks( rooms ) {
	return rooms
		.map( ( room ) => roomTitle( room ) + '\t' + room.export_url )
		.join( '\n' );
}

/** Addresses pasted for a room (one per line, or separated by spaces), duplicates dropped. */
export function pastedUrls( text ) {
	const out = [];
	( text || '' )
		.split( /[\s,;]+/ )
		.map( ( line ) => line.trim() )
		.filter( Boolean )
		.forEach( ( url ) => {
			if ( ! out.includes( url ) ) {
				out.push( url );
			}
		} );
	return out;
}

/** Outcome of a reading, in words. */
export function resultText( r ) {
	if ( ! r ) {
		return '';
	}
	if ( 'ok' !== r.status ) {
		return r.error || __( 'Calendario non letto.', 'booking-manager' );
	}
	const parts = [
		sprintf(
			/* translators: %d: number of events */
			__( '%d periodi nel calendario del portale', 'booking-manager' ),
			r.events
		),
	];
	if ( r.created ) {
		parts.push(
			/* translators: %d: number of periods */
			sprintf( __( '%d chiusi qui', 'booking-manager' ), r.created )
		);
	}
	if ( r.removed ) {
		parts.push(
			/* translators: %d: number of periods */
			sprintf( __( '%d riaperti', 'booking-manager' ), r.removed )
		);
	}
	if ( r.echoes ) {
		parts.push(
			sprintf(
				/* translators: %d: number of periods */
				_n(
					'%d periodo era una nostra chiusura tornata dal portale (ignorato)',
					'%d periodi erano nostre chiusure tornate dal portale (ignorati)',
					r.echoes,
					'booking-manager'
				),
				r.echoes
			)
		);
	}
	if ( r.conflicts ) {
		parts.push(
			sprintf(
				/* translators: %d: number of reservations */
				__(
					'%d prenotazioni su notti già occupate: controlla il registro',
					'booking-manager'
				),
				r.conflicts
			)
		);
	}
	return parts.join( ' · ' ) + '.';
}

function Feed( { feed, onChanged } ) {
	const action = useAction();
	const [ confirm, setConfirm ] = useState( false );
	const [ note, setNote ] = useState( null );
	const [ tone, label ] = syncBadge( feed );
	return (
		<li className="bkng-portals__feed">
			<div className="bkng-portals__feed-main">
				<strong>{ feed.channel_name }</strong>{ ' ' }
				<span className={ 'bkng-badge is-' + tone }>{ label }</span>
				{ feed.url_hint && (
					<div className="bkng-help bkng-portals__hint">
						{ feed.url_hint }
					</div>
				) }
				<div className="bkng-help">
					{ feed.last_success_at
						? sprintf(
								/* translators: 1: date and time, 2: number of closed periods */
								_n(
									'Ultima sincronizzazione riuscita %1$s · %2$d periodo chiuso qui',
									'Ultima sincronizzazione riuscita %1$s · %2$d periodi chiusi qui',
									feed.blocks,
									'booking-manager'
								),
								dateTime( feed.last_success_at ),
								feed.blocks
						  )
						: __(
								'Nessuna sincronizzazione riuscita finora.',
								'booking-manager'
						  ) }
					{ feed.next_sync_at &&
						' · ' +
							sprintf(
								/* translators: %s: date and time */
								__( 'prossima lettura %s', 'booking-manager' ),
								dateTime( feed.next_sync_at )
							) }
				</div>
				{ 'ok' !== feed.last_status && feed.last_error && (
					<div className="bkng-help is-error">
						{ feed.last_error }
					</div>
				) }
				{ note && (
					<div className={ 'bkng-help is-' + note.tone }>
						{ note.text }
					</div>
				) }
				<ErrorNotice error={ action.error } />
			</div>
			<div className="bkng-portals__feed-actions">
				<Button
					variant="secondary"
					size="compact"
					isBusy={ action.busy }
					disabled={ action.busy }
					onClick={ async () => {
						const r = await action.run( () =>
							post( '/ical/feeds/' + feed.id + '/sync' )
						);
						if ( r ) {
							setNote( {
								tone: 'ok' === r.status ? 'ok' : 'error',
								text: resultText( r ),
							} );
							onChanged();
						}
					} }
				>
					{ __( 'Sincronizza', 'booking-manager' ) }
				</Button>
				<Button
					variant="tertiary"
					size="compact"
					isDestructive
					onClick={ () => setConfirm( true ) }
				>
					{ __( 'Scollega', 'booking-manager' ) }
				</Button>
			</div>
			{ confirm && (
				<Confirm
					title={ __(
						'Scollegare il calendario?',
						'booking-manager'
					) }
					text={ __(
						'Le date chiuse da questo calendario tornano libere qui. Ricordati di togliere anche il link di Booking Manager dal portale, se non lo usi più.',
						'booking-manager'
					) }
					confirmLabel={ __( 'Scollega', 'booking-manager' ) }
					destructive
					busy={ action.busy }
					error={ action.error }
					onCancel={ () => setConfirm( false ) }
					onConfirm={ async () => {
						const r = await action.run( () =>
							del( '/ical/feeds/' + feed.id )
						);
						if ( r ) {
							setConfirm( false );
							onChanged();
						}
					} }
				/>
			) }
		</li>
	);
}

function AddFeed( { property, room, channels, onClose, onAdded } ) {
	const action = useAction();
	const [ form, setForm ] = useState( { channel: 'auto', url: '' } );
	return (
		<FormModal
			title={ sprintf(
				/* translators: %s: room name */
				__( 'Collega un calendario a %s', 'booking-manager' ),
				roomTitle( room )
			) }
			submitLabel={ __( 'Collega e leggi', 'booking-manager' ) }
			busy={ action.busy }
			error={ action.error }
			onClose={ onClose }
			onSubmit={ async () => {
				const r = await action.run( () =>
					post( '/properties/' + property.id + '/ical/feeds', {
						unit_id: room.id,
						...form,
					} )
				);
				if ( r ) {
					onAdded( r.result );
				}
			} }
		>
			<Select
				label={ __( 'Portale', 'booking-manager' ) }
				value={ form.channel }
				options={ [
					{
						value: 'auto',
						label: __(
							"Riconosci dall'indirizzo",
							'booking-manager'
						),
					},
					...channels,
				] }
				onChange={ ( channel ) => setForm( { ...form, channel } ) }
			/>
			<Text
				label={ __(
					'Link del calendario del portale',
					'booking-manager'
				) }
				help={ __(
					'Sul portale si chiama «Esporta calendario»: inizia con https:// e finisce spesso con .ics.',
					'booking-manager'
				) }
				type="url"
				value={ form.url }
				onChange={ ( url ) => setForm( { ...form, url } ) }
				required
			/>
		</FormModal>
	);
}

function Sources( { room } ) {
	if ( ! room.sources.length ) {
		return (
			<p className="bkng-help">
				{ __( 'Nessuna occupazione in arrivo.', 'booking-manager' ) }
			</p>
		);
	}
	return (
		<div className="bkng-portals__sources">
			<span className="bkng-portals__label">
				{ __( 'Occupazioni in arrivo per fonte', 'booking-manager' ) }
			</span>
			<ul>
				{ room.sources.map( ( s ) => (
					<li
						key={ s.source }
						className={ 'bkng-portals__source is-' + s.source }
					>
						<strong>{ s.label }</strong>{ ' ' }
						{ sprintf(
							/* translators: 1: periods, 2: nights */
							_n(
								'%1$d periodo, %2$d notti',
								'%1$d periodi, %2$d notti',
								s.periods,
								'booking-manager'
							),
							s.periods,
							s.nights
						) }
					</li>
				) ) }
			</ul>
		</div>
	);
}

function Room( { property, room, channels, onChanged } ) {
	const [ adding, setAdding ] = useState( false );
	const [ note, setNote ] = useState( null );
	const [ renew, setRenew ] = useState( false );
	const rotate = useAction();
	return (
		<Card
			title={ roomTitle( room ) }
			className="bkng-portals__room"
			actions={
				<Button
					variant="secondary"
					size="compact"
					onClick={ () => setAdding( true ) }
				>
					{ __( 'Collega un calendario', 'booking-manager' ) }
				</Button>
			}
		>
			<div className="bkng-portals__export">
				<label
					className="bkng-portals__label"
					htmlFor={ 'bkng-ical-' + room.id }
				>
					{ __( 'Link da dare ai portali', 'booking-manager' ) }
				</label>
				<div className="bkng-portals__copy">
					<input
						id={ 'bkng-ical-' + room.id }
						type="text"
						readOnly
						value={ room.export_url }
						onFocus={ ( e ) => e.target.select() }
					/>
					<CopyButton text={ room.export_url } />
					<Button
						variant="tertiary"
						size="compact"
						onClick={ () => setRenew( true ) }
					>
						{ __( 'Nuovo link', 'booking-manager' ) }
					</Button>
				</div>
				<div className="bkng-help">
					{ room.last_export
						? sprintf(
								/* translators: %s: date and time */
								__(
									'Ultima lettura di questo link da parte di un portale: %s',
									'booking-manager'
								),
								dateTime( room.last_export )
						  )
						: __(
								'Nessun portale ha ancora letto questo link.',
								'booking-manager'
						  ) }
				</div>
			</div>
			<Sources room={ room } />
			{ renew && (
				<Confirm
					title={ __(
						'Creare un nuovo link per questa camera?',
						'booking-manager'
					) }
					text={ __(
						'Il link attuale smette subito di funzionare: incolla quello nuovo su tutti i portali di questa camera. Serve solo se il link è finito in mani sbagliate.',
						'booking-manager'
					) }
					confirmLabel={ __(
						'Crea un nuovo link',
						'booking-manager'
					) }
					destructive
					busy={ rotate.busy }
					error={ rotate.error }
					onCancel={ () => setRenew( false ) }
					onConfirm={ async () => {
						const r = await rotate.run( () =>
							post( '/ical/units/' + room.id + '/link' )
						);
						if ( r ) {
							setRenew( false );
							onChanged();
						}
					} }
				/>
			) }
			{ note && 'ok' === note.status && (
				<Success onDismiss={ () => setNote( null ) }>
					{ resultText( note ) }
				</Success>
			) }
			{ note && 'ok' !== note.status && (
				<ErrorNotice
					error={ { message: resultText( note ) } }
					onDismiss={ () => setNote( null ) }
				/>
			) }
			{ room.feeds.length ? (
				<ul className="bkng-portals__feeds">
					{ room.feeds.map( ( feed ) => (
						<Feed
							key={ feed.id }
							feed={ feed }
							onChanged={ onChanged }
						/>
					) ) }
				</ul>
			) : (
				<p className="bkng-help">
					{ __(
						'Nessun calendario dei portali collegato a questa camera.',
						'booking-manager'
					) }
				</p>
			) }
			{ adding && (
				<AddFeed
					property={ property }
					room={ room }
					channels={ channels }
					onClose={ () => setAdding( false ) }
					onAdded={ ( result ) => {
						setAdding( false );
						setNote( result );
						onChanged();
					} }
				/>
			) }
		</Card>
	);
}

function HowTo() {
	return (
		<Card title={ __( 'Come si collega', 'booking-manager' ) }>
			<p>
				{ __(
					'Booking.com e Airbnb permettono di sincronizzare il calendario di ogni camera con un link (iCal). Per ogni camera servono due passaggi, sul portale:',
					'booking-manager'
				) }
			</p>
			<ol className="bkng-portals__steps">
				<li>
					{ __(
						'«Importa calendario»: incolla il «Link da dare ai portali» della camera. Così il portale chiude le date già vendute qui.',
						'booking-manager'
					) }
				</li>
				<li>
					{ __(
						"«Esporta calendario»: copia il link del portale e incollalo qui con «Collega i calendari di tutte le camere» (il portale si riconosce dall'indirizzo). Così le prenotazioni del portale chiudono la camera qui, di solito entro 15 minuti.",
						'booking-manager'
					) }
				</li>
			</ol>
			<p className="bkng-help">
				{ __(
					'Booking.com: Extranet → Tariffe e disponibilità → Sincronizza calendari. Airbnb: Calendario → Disponibilità → Sincronizza calendari. Su un annuncio collega solo la camera corrispondente.',
					'booking-manager'
				) }
			</p>
			<p className="bkng-help">
				{ __(
					"Con i calendari passano solo le date: nome dell'ospite, prezzo e pagamento restano sul portale. iCal non è in tempo reale: i portali leggono il link di Booking Manager quando vogliono (di solito ogni poche ore), quindi per le prenotazioni dell'ultimo minuto resta un piccolo rischio di doppia prenotazione. Lo stesso link della camera vale per tutti i portali.",
					'booking-manager'
				) }
			</p>
		</Card>
	);
}

function Log( { rows } ) {
	return (
		<Card title={ __( 'Registro', 'booking-manager' ) }>
			{ rows.length ? (
				<ul className="bkng-portals__log">
					{ rows.map( ( row ) => (
						<li
							key={ row.id }
							className={
								'conflict' === row.status
									? 'is-warn'
									: 'is-error'
							}
						>
							<span className="bkng-help">
								{ dateTime( row.created_at ) }
							</span>{ ' ' }
							{ row.message }
						</li>
					) ) }
				</ul>
			) : (
				<p className="bkng-help">
					{ __(
						'Nessun problema: qui compaiono i calendari che non si riescono a leggere e le possibili doppie prenotazioni.',
						'booking-manager'
					) }
				</p>
			) }
		</Card>
	);
}

function CronCard( { cron, rooms } ) {
	const feeds = rooms.flatMap( ( room ) => room.feeds );
	const count = ( state ) =>
		feeds.filter( ( f ) => f.state === state ).length;
	const problems = count( 'error' ) + count( 'late' ) + count( 'warning' );
	return (
		<Card title={ __( 'Sincronizzazione automatica', 'booking-manager' ) }>
			<p>
				{ sprintf(
					/* translators: %d: minutes */
					__(
						'I calendari dei portali vengono letti ogni %d minuti. Se un portale non risponde le date restano chiuse e la lettura viene ripetuta più tardi.',
						'booking-manager'
					),
					cron.interval_minutes
				) }
			</p>
			<p className="bkng-help">
				{ cron.last_run
					? sprintf(
							/* translators: %s: date and time */
							__(
								'Ultimo controllo automatico: %s',
								'booking-manager'
							),
							dateTime( cron.last_run )
					  )
					: __(
							'Il controllo automatico non è ancora partito.',
							'booking-manager'
					  ) }
				{ ' · ' }
				{ sprintf(
					/* translators: 1: calendars, 2: connected, 3: with problems */
					__(
						'%1$d calendari collegati: %2$d a posto, %3$d da controllare.',
						'booking-manager'
					),
					feeds.length,
					count( 'ok' ),
					problems
				) }
			</p>
			{ feeds.length > 0 && ! cron.healthy && (
				<div className="bkng-help is-error">
					{ cron.wp_cron_disabled
						? __(
								'Il cron di WordPress è disattivato (DISABLE_WP_CRON) e il controllo automatico non sta girando: chiedi al tuo hosting un cron di sistema ogni 5 minuti con «wp cron event run --due-now» oppure con la chiamata a wp-cron.php.',
								'booking-manager'
						  )
						: __(
								'Il controllo automatico non è partito negli ultimi 45 minuti: WordPress esegue i lavori pianificati solo quando qualcuno visita il sito. Per un sito con poche visite chiedi al tuo hosting un cron di sistema ogni 5 minuti (istruzioni nel file readme).',
								'booking-manager'
						  ) }
				</div>
			) }
		</Card>
	);
}

/** One form for every room: paste the portals' links, the portal is found from the address. */
function LinkAll( { property, rooms, onClose, onDone } ) {
	const action = useAction();
	const [ texts, setTexts ] = useState( {} );
	const [ result, setResult ] = useState( null );
	const items = rooms.flatMap( ( room ) =>
		pastedUrls( texts[ room.id ] ).map( ( url ) => ( {
			unit_id: room.id,
			url,
		} ) )
	);
	const errors = result ? result.items.filter( ( i ) => ! i.ok ) : [];
	const roomName = ( id ) => {
		const room = rooms.find( ( r ) => r.id === id );
		return room ? roomTitle( room ) : '';
	};
	return (
		<FormModal
			wide
			title={ __(
				'Collega i calendari di tutte le camere',
				'booking-manager'
			) }
			submitLabel={ sprintf(
				/* translators: %d: number of calendars */
				__( 'Collega %d calendari', 'booking-manager' ),
				items.length
			) }
			busy={ action.busy }
			error={ action.error }
			onClose={ result ? onDone : onClose }
			onSubmit={ async () => {
				if ( ! items.length ) {
					action.setError( {
						message: __(
							'Incolla almeno un link «Esporta calendario».',
							'booking-manager'
						),
					} );
					return;
				}
				const r = await action.run( () =>
					post( '/properties/' + property.id + '/ical/feeds/bulk', {
						items,
					} )
				);
				if ( r ) {
					setResult( r );
					setTexts( {} );
					// The outcome is on top of the form: show it.
					const content = window.document.querySelector(
						'.bkng-modal .components-modal__content'
					);
					if ( content ) {
						content.scrollTop = 0;
					}
					if ( ! r.errors ) {
						onDone();
					}
				}
			} }
		>
			<p className="bkng-help">
				{ __(
					"Per ogni camera incolla il link «Esporta calendario» di Airbnb, di Booking.com o di altri portali, uno per riga. Il portale viene riconosciuto dall'indirizzo; i calendari già collegati vengono saltati.",
					'booking-manager'
				) }
			</p>
			{ result && (
				<div
					className={
						'bkng-help ' + ( errors.length ? 'is-error' : 'is-ok' )
					}
				>
					{ sprintf(
						/* translators: 1: added, 2: not added */
						__(
							'Collegati: %1$d. Non collegati: %2$d.',
							'booking-manager'
						),
						result.added,
						result.errors
					) }
					{ errors.length > 0 && (
						<ul>
							{ errors.map( ( e ) => (
								<li key={ e.index }>
									{ roomName( e.unit_id ) + ': ' + e.error }
								</li>
							) ) }
						</ul>
					) }
				</div>
			) }
			<div className="bkng-portals__bulk">
				{ rooms.map( ( room ) => (
					<Textarea
						key={ room.id }
						label={
							roomTitle( room ) +
							( room.feeds.length
								? ' · ' +
								  room.feeds
										.map( ( f ) => f.channel_name )
										.join( ', ' )
								: '' )
						}
						rows={ 2 }
						value={ texts[ room.id ] || '' }
						onChange={ ( v ) =>
							setTexts( { ...texts, [ room.id ]: v } )
						}
					/>
				) ) }
			</div>
		</FormModal>
	);
}

export default function Portals() {
	const { property } = useProperty();
	const data = useLoad(
		() => get( '/properties/' + property.id + '/ical' ),
		[ property.id ]
	);
	const sync = useAction();
	const [ done, setDone ] = useState( '' );
	const [ linking, setLinking ] = useState( false );
	const rooms = data.data ? data.data.rooms : [];

	const actions = (
		<div className="bkng-portals__actions">
			<Button
				variant="primary"
				disabled={ ! rooms.length }
				onClick={ () => setLinking( true ) }
			>
				{ __(
					'Collega i calendari di tutte le camere',
					'booking-manager'
				) }
			</Button>
			<CopyAll rooms={ rooms } />
			<Button
				variant="secondary"
				isBusy={ sync.busy }
				disabled={ sync.busy || ! data.data }
				onClick={ async () => {
					const r = await sync.run( () =>
						post( '/properties/' + property.id + '/ical/sync' )
					);
					if ( r ) {
						setDone(
							sprintf(
								/* translators: 1: calendars read, 2: with errors */
								__(
									'Calendari letti: %1$d (non raggiungibili: %2$d). Le date dei calendari non raggiungibili restano come erano.',
									'booking-manager'
								),
								r.feeds,
								r.errors
							)
						);
						data.reload();
					}
				} }
			>
				{ __( 'Sincronizza ora', 'booking-manager' ) }
			</Button>
		</div>
	);

	return (
		<Page
			title={ __( 'Booking.com e Airbnb', 'booking-manager' ) }
			subtitle={ __(
				'Calendari sincronizzati con i portali, camera per camera.',
				'booking-manager'
			) }
			actions={ actions }
		>
			<ErrorNotice error={ sync.error } />
			{ done && (
				<Success onDismiss={ () => setDone( '' ) }>{ done }</Success>
			) }
			{ data.loading && ! data.data && <Loading /> }
			<ErrorNotice error={ data.error } onRetry={ data.reload } />
			{ data.data && (
				<>
					<CronCard cron={ data.data.cron } rooms={ rooms } />
					<HowTo />
					{ data.data.rooms.length ? (
						data.data.rooms.map( ( room ) => (
							<Room
								key={ room.id }
								property={ property }
								room={ room }
								channels={ data.data.channels }
								onChanged={ data.reload }
							/>
						) )
					) : (
						<Empty
							title={ __( 'Nessuna camera', 'booking-manager' ) }
							text={ __(
								'Crea prima le camere: ognuna ha il suo calendario.',
								'booking-manager'
							) }
						/>
					) }
					<Log rows={ data.data.log } />
				</>
			) }
			{ linking && (
				<LinkAll
					property={ property }
					rooms={ rooms }
					onClose={ () => setLinking( false ) }
					onDone={ () => {
						setLinking( false );
						data.reload();
					} }
				/>
			) }
		</Page>
	);
}
