/**
 * Booking.com, Airbnb and the other portals (bkng_manage_integrations), through their
 * iCal calendars: for every room, the link to give the portal (our closed dates) and the
 * portal's calendars read every 15 minutes (their stays close the room here).
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

function roomTitle( room ) {
	const name = room.name ? room.name : room.code;
	return room.room_type ? name + ' · ' + room.room_type : name;
}

function syncBadge( feed ) {
	if ( ! feed.last_sync_at ) {
		return [ 'neutral', __( 'In attesa', 'booking-manager' ) ];
	}
	return 'ok' === feed.last_status
		? [ 'ok', __( 'Sincronizzato', 'booking-manager' ) ]
		: [ 'bad', __( 'Errore', 'booking-manager' ) ];
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
				<div className="bkng-help">
					{ feed.last_sync_at
						? sprintf(
								/* translators: 1: date and time, 2: number of closed periods */
								_n(
									'Ultima lettura %1$s · %2$d periodo chiuso',
									'Ultima lettura %1$s · %2$d periodi chiusi',
									feed.blocks,
									'booking-manager'
								),
								dateTime( feed.last_sync_at ),
								feed.blocks
						  )
						: __( 'Non ancora letto.', 'booking-manager' ) }
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
	const [ form, setForm ] = useState( { channel: 'booking', url: '' } );
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
				options={ channels }
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

function Room( { property, room, channels, onChanged } ) {
	const [ adding, setAdding ] = useState( false );
	const [ note, setNote ] = useState( null );
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
				</div>
			</div>
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
						'«Esporta calendario»: copia il link del portale e incollalo qui con «Collega un calendario». Così le prenotazioni del portale chiudono la camera qui, entro 15 minuti.',
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
					"Con i calendari passano solo le date: nome dell'ospite, prezzo e pagamento restano sul portale. I portali leggono il link di Booking Manager ogni qualche ora: per le prenotazioni dell'ultimo minuto resta un piccolo rischio di doppia prenotazione.",
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

export default function Portals() {
	const { property } = useProperty();
	const data = useLoad(
		() => get( '/properties/' + property.id + '/ical' ),
		[ property.id ]
	);
	const sync = useAction();
	const [ done, setDone ] = useState( '' );

	const actions = (
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
								'Calendari letti: %1$d (con errori: %2$d).',
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
		</Page>
	);
}
