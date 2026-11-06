/**
 * Check-in and check-out: find the guest (name, code or room typed, or the QR of the
 * e-mail read with the camera), then one button per booking. Today's arrivals, guests in
 * house and departures are in tabs; a multi-room booking can still go room by room from
 * the card that opens with the QR or the code.
 */
import { Button } from '@wordpress/components';
import { Icon, search as searchIcon } from '@wordpress/icons';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import jsQR from 'jsqr';

import { get, post } from '../lib/api';
import { can } from '../lib/boot';
import { date, dateTime, guestsLabel } from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';
import { useProperty } from '../lib/property';
import {
	Badge,
	Card,
	Check,
	ErrorNotice,
	Loading,
	Page,
	Tabs,
} from '../components/ui';

const TONE = {
	ok: 'ok',
	ready: 'ok',
	already: 'warn',
	pending: 'warn',
	too_early: 'warn',
	not_ready: 'warn',
	completed: 'warn',
	past: 'bad',
	cancelled: 'bad',
	no_show: 'bad',
	wrong_property: 'bad',
	invalid: 'bad',
};

/** The camera with a QR reader; calls onCode once per new code. */
function Camera( { onCode, paused, start = false, onStop } ) {
	const video = useRef( null );
	const canvas = useRef( null );
	const last = useRef( { text: '', at: 0 } );
	const [ error, setError ] = useState( '' );
	const [ on, setOn ] = useState( start );

	useEffect( () => {
		if ( ! on ) {
			return undefined;
		}
		let stream = null;
		let timer = null;
		let stopped = false;
		const tick = () => {
			const v = video.current;
			const c = canvas.current;
			if ( ! stopped && v && c && v.readyState >= 2 && ! paused ) {
				const w = v.videoWidth;
				const h = v.videoHeight;
				c.width = w;
				c.height = h;
				const ctx = c.getContext( '2d', { willReadFrequently: true } );
				ctx.drawImage( v, 0, 0, w, h );
				const found = jsQR( ctx.getImageData( 0, 0, w, h ).data, w, h, {
					inversionAttempts: 'dontInvert',
				} );
				const now = Date.now();
				// The same code in front of the camera is read once every few seconds.
				if (
					found &&
					found.data &&
					( found.data !== last.current.text ||
						now - last.current.at > 4000 )
				) {
					last.current = { text: found.data, at: now };
					onCode( found.data );
				}
			}
			if ( ! stopped ) {
				timer = window.setTimeout( tick, 250 );
			}
		};
		if (
			! window.navigator.mediaDevices ||
			! window.navigator.mediaDevices.getUserMedia
		) {
			setError(
				__(
					'Questo browser non permette di usare la fotocamera. Inserisci il codice a mano.',
					'booking-manager'
				)
			);
			return undefined;
		}
		window.navigator.mediaDevices
			.getUserMedia( {
				video: { facingMode: 'environment' },
				audio: false,
			} )
			.then( ( s ) => {
				if ( stopped ) {
					s.getTracks().forEach( ( t ) => t.stop() );
					return;
				}
				stream = s;
				video.current.srcObject = s;
				video.current.play();
				tick();
			} )
			.catch( () =>
				setError(
					__(
						'Fotocamera non disponibile o permesso negato. Serve una connessione HTTPS.',
						'booking-manager'
					)
				)
			);
		return () => {
			stopped = true;
			window.clearTimeout( timer );
			if ( stream ) {
				stream.getTracks().forEach( ( t ) => t.stop() );
			}
		};
	}, [ on, paused, onCode ] );

	if ( ! on ) {
		return (
			<div
				className="bkng-camera"
				style={ {
					display: 'flex',
					alignItems: 'center',
					justifyContent: 'center',
				} }
			>
				<Button variant="primary" onClick={ () => setOn( true ) }>
					{ __( 'Attiva la fotocamera', 'booking-manager' ) }
				</Button>
			</div>
		);
	}
	return (
		<>
			{ error && <p className="bkng-help">{ error }</p> }
			<div className="bkng-camera">
				<video ref={ video } muted playsInline />
				<div className="bkng-camera__frame" />
				<canvas ref={ canvas } hidden />
			</div>
			<Button
				variant="link"
				onClick={ () => ( onStop ? onStop() : setOn( false ) ) }
			>
				{ __( 'Spegni la fotocamera', 'booking-manager' ) }
			</Button>
		</>
	);
}

function Result( { answer, busy, onCheckin, onCheckout, onClose } ) {
	const [ rooms, setRooms ] = useState( [] );
	const [ ignoreDirty, setIgnoreDirty ] = useState( false );
	const b = answer.booking;
	useEffect( () => setRooms( [] ), [ answer ] );
	const toggle = ( id, on ) =>
		setRooms( ( list ) =>
			on ? [ ...list, id ] : list.filter( ( x ) => x !== id )
		);
	return (
		<div
			className={
				'bkng-result is-' + ( TONE[ answer.result ] || 'warn' )
			}
			role="status"
		>
			<div className="bkng-result__head">
				<p className="bkng-result__label">{ answer.label }</p>
				{ onClose && (
					<Button
						variant="tertiary"
						size="small"
						onClick={ onClose }
						label={ __( 'Chiudi', 'booking-manager' ) }
					>
						×
					</Button>
				) }
			</div>
			<p>{ answer.message }</p>
			{ b && (
				<>
					<p>
						<a
							href={ '#/bookings/' + b.id }
							className="bkng-strong"
						>
							{ b.name }
						</a>{ ' ' }
						· { b.code } · { date( b.arrival ) } →{ ' ' }
						{ date( b.departure ) } ·{ ' ' }
						{ guestsLabel( b.adults, b.children ) }
					</p>
					{ b.balance > 0 && (
						<p className="bkng-strong">
							{ sprintf(
								/* translators: %s: amount. */ __(
									'Saldo da incassare: %s',
									'booking-manager'
								),
								b.balance_label
							) }
						</p>
					) }
					{ b.customer_notes && <p>{ b.customer_notes }</p> }
					{ b.internal_notes && (
						<p className="bkng-muted">{ b.internal_notes }</p>
					) }
					<ul className="bkng-list">
						{ b.rooms
							.filter( ( r ) => r.stay_status !== 'removed' )
							.map( ( r ) => (
								<li key={ r.id }>
									{ b.rooms.length > 1 &&
										( answer.can_checkin ||
											answer.can_checkout ) && (
											<Check
												label=""
												checked={ rooms.includes(
													r.id
												) }
												onChange={ ( v ) =>
													toggle( r.id, v )
												}
											/>
										) }
									<div className="bkng-list__main">
										<span className="bkng-strong">
											{ r.unit
												? r.unit.code
												: __(
														'Camera da assegnare',
														'booking-manager'
												  ) }
										</span>{ ' ' }
										· { r.room_type }
										<div className="bkng-small bkng-muted">
											{ r.stay_label }
											{ r.checked_in_at
												? ' · ' +
												  dateTime( r.checked_in_at ) +
												  ( r.checked_in_by
														? ' · ' +
														  r.checked_in_by
														: '' )
												: '' }
										</div>
									</div>
									{ r.unit && (
										<Badge
											status={ r.unit.op_status }
											kind="op"
										/>
									) }
								</li>
							) ) }
					</ul>
					{ b.rooms.length > 1 &&
						( answer.can_checkin || answer.can_checkout ) && (
							<p className="bkng-help">
								{ __(
									'Nessuna spunta: tutte le camere.',
									'booking-manager'
								) }
							</p>
						) }
					{ answer.result === 'not_ready' &&
						can( 'bkng_override_restrictions' ) && (
							<Check
								label={ __(
									'Consegna comunque le camere non ancora pronte',
									'booking-manager'
								) }
								checked={ ignoreDirty }
								onChange={ setIgnoreDirty }
							/>
						) }
					<div
						className="bkng-page__actions"
						style={ { marginTop: 12 } }
					>
						{ ( answer.can_checkin ||
							answer.result === 'not_ready' ) && (
							<Button
								variant="primary"
								isBusy={ busy }
								disabled={ busy }
								onClick={ () =>
									onCheckin( b, rooms, ignoreDirty )
								}
							>
								{ __( 'Fai il check-in', 'booking-manager' ) }
							</Button>
						) }
						{ answer.can_checkout && (
							<Button
								variant="secondary"
								isBusy={ busy }
								disabled={ busy }
								onClick={ () => onCheckout( b, rooms ) }
							>
								{ __( 'Fai il check-out', 'booking-manager' ) }
							</Button>
						) }
					</div>
				</>
			) }
		</div>
	);
}

/**
 * Today's rows (one per room) grouped by booking: the desk works by guest, not by room.
 *
 * @param {Array} rows
 */
function byBooking( rows ) {
	const groups = [];
	const at = {};
	rows.forEach( ( r ) => {
		if ( at[ r.booking_id ] === undefined ) {
			at[ r.booking_id ] = groups.length;
			groups.push( { ...r, rooms: [] } );
		}
		groups[ at[ r.booking_id ] ].rooms.push( r );
	} );
	return groups;
}

function matches( group, q ) {
	if ( ! q ) {
		return true;
	}
	const text = [
		group.name,
		group.code,
		...group.rooms.map( ( r ) => ( r.unit ? r.unit.code : '' ) ),
	]
		.join( ' ' )
		.toLowerCase();
	return q
		.toLowerCase()
		.split( /\s+/ )
		.every( ( w ) => text.includes( w ) );
}

const KIND = {
	arrivals: {
		todo: ( r ) => r.stay_status === 'expected',
		button: __( 'Check-in', 'booking-manager' ),
		done: __( 'Arrivato', 'booking-manager' ),
		empty: __( 'Nessun arrivo da fare oggi.', 'booking-manager' ),
	},
	in_house: {
		todo: () => false,
		empty: __( 'Nessun ospite in struttura.', 'booking-manager' ),
	},
	departures: {
		todo: ( r ) => r.stay_status === 'in_house',
		button: __( 'Check-out', 'booking-manager' ),
		done: __( 'Partito', 'booking-manager' ),
		empty: __( 'Nessuna partenza oggi.', 'booking-manager' ),
	},
};

/** One guest of the day: who, which rooms, what is still to collect, one button. */
function Guest( { group, kind, busy, onRun } ) {
	const k = KIND[ kind ];
	const todo = group.rooms.filter( k.todo );
	const notReady = todo.some(
		( r ) => kind === 'arrivals' && r.unit && r.unit.op_status !== 'ready'
	);
	const guests = group.rooms.reduce( ( n, r ) => n + r.guests, 0 );
	return (
		<li className="bkng-desk__guest">
			<div className="bkng-desk__who">
				<a
					href={ '#/bookings/' + group.booking_id }
					className="bkng-desk__name"
				>
					{ group.name }
				</a>
				<div className="bkng-desk__rooms">
					{ group.rooms.map( ( r ) => (
						<span key={ r.booking_room_id }>
							<strong>
								{ r.unit
									? r.unit.code
									: __( 'Da assegnare', 'booking-manager' ) }
							</strong>
							{ r.room_type ? ' · ' + r.room_type : '' }
						</span>
					) ) }
				</div>
				<div className="bkng-small bkng-muted">
					{ guestsLabel( guests, 0 ) } ·{ ' ' }
					{ sprintf(
						/* translators: %d: nights. */
						_n(
							'%d notte',
							'%d notti',
							group.nights,
							'booking-manager'
						),
						group.nights
					) }{ ' ' }
					· { date( group.arrival ) } → { date( group.departure ) } ·{ ' ' }
					{ group.code }
				</div>
				<div className="bkng-desk__tags">
					{ notReady && (
						<span className="bkng-badge is-warn">
							{ __( 'Camera non pronta', 'booking-manager' ) }
						</span>
					) }
					{ group.balance > 0 && (
						<span className="bkng-badge is-warn">
							{ sprintf(
								/* translators: %s: amount. */
								__( 'Da incassare %s', 'booking-manager' ),
								group.balance_label
							) }
						</span>
					) }
				</div>
			</div>
			{ k.button && todo.length > 0 && (
				<Button
					variant="primary"
					className="bkng-desk__go"
					disabled={ busy }
					onClick={ () => onRun( group, todo ) }
				>
					{ k.button }
				</Button>
			) }
			{ k.button && ! todo.length && (
				<span className="bkng-badge is-ok">✓ { k.done }</span>
			) }
		</li>
	);
}

export default function FrontDesk() {
	const { property } = useProperty();
	const desk = useLoad(
		() => get( '/properties/' + property.id + '/frontdesk' ),
		[ property.id ]
	);
	const [ query, setQuery ] = useState( '' );
	const [ camera, setCamera ] = useState( false );
	const [ tab, setTab ] = useState( 'arrivals' );
	const [ answer, setAnswer ] = useState( null );
	const action = useAction();
	const resultRef = useRef( null );
	const base = '/properties/' + property.id;

	const show = useCallback(
		( res ) => {
			if ( res ) {
				setAnswer( res );
				desk.reload();
				window.setTimeout( () => {
					if ( resultRef.current ) {
						resultRef.current.scrollIntoView( {
							behavior: 'smooth',
							block: 'start',
						} );
					}
				}, 50 );
			}
		},
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[]
	);

	const scan = useCallback(
		async ( text, method ) => {
			const res = await action.run( () =>
				post( base + '/checkin/scan', { text, method } )
			);
			show( res );
		},
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ base ]
	);
	const onCode = useCallback(
		( text ) => {
			setCamera( false );
			scan( text, 'qr' );
		},
		[ scan ]
	);

	const checkin = async ( b, rooms, ignoreDirty, method = 'manual' ) => {
		show(
			await action.run( () =>
				post( base + '/checkin', {
					booking_id: b.id || b.booking_id,
					version: b.version,
					room_ids: rooms,
					ignore_dirty: ignoreDirty,
					method,
				} )
			)
		);
	};
	const checkout = async ( b, rooms, method = 'manual' ) => {
		show(
			await action.run( () =>
				post( base + '/checkout', {
					booking_id: b.id || b.booking_id,
					version: b.version,
					room_ids: rooms,
					method,
				} )
			)
		);
	};

	const d = desk.data;
	const lists = d
		? {
				arrivals: byBooking( d.arrivals ),
				in_house: byBooking( d.in_house ),
				departures: byBooking( d.departures ),
		  }
		: null;
	const q = query.trim();
	const found = lists
		? Object.fromEntries(
				Object.entries( lists ).map( ( [ key, list ] ) => [
					key,
					list.filter( ( g ) => matches( g, q ) ),
				] )
		  )
		: null;
	const todo = ( key ) =>
		lists
			? lists[ key ].filter( ( g ) => g.rooms.some( KIND[ key ].todo ) )
					.length
			: 0;

	// Enter: one guest of the day found → its card; otherwise the code as typed.
	const submit = ( e ) => {
		e.preventDefault();
		if ( ! q ) {
			return;
		}
		const hits = found
			? [ ...found.arrivals, ...found.in_house, ...found.departures ]
			: [];
		const codes = [ ...new Set( hits.map( ( g ) => g.code ) ) ];
		scan( codes.length === 1 ? codes[ 0 ] : q, 'manual' );
	};

	return (
		<Page
			title={ __( 'Check-in e check-out', 'booking-manager' ) }
			subtitle={
				d
					? date( d.date, {
							weekday: 'long',
							day: 'numeric',
							month: 'long',
					  } )
					: ''
			}
		>
			<section className="bkng-desk__find">
				<form className="bkng-desk__search" onSubmit={ submit }>
					<label className="screen-reader-text" htmlFor="bkng-desk-q">
						{ __( 'Cerca l’ospite', 'booking-manager' ) }
					</label>
					<Icon icon={ searchIcon } />
					<input
						id="bkng-desk-q"
						type="search"
						value={ query }
						onChange={ ( e ) => setQuery( e.target.value ) }
						placeholder={ __(
							'Nome, codice o camera',
							'booking-manager'
						) }
						autoComplete="off"
					/>
					<Button
						variant="secondary"
						type="submit"
						isBusy={ action.busy }
						disabled={ ! q }
					>
						{ __( 'Apri', 'booking-manager' ) }
					</Button>
				</form>
				{ camera ? (
					<Camera
						start
						onCode={ onCode }
						paused={ action.busy }
						onStop={ () => setCamera( false ) }
					/>
				) : (
					<Button
						variant="primary"
						className="bkng-desk__qr"
						onClick={ () => setCamera( true ) }
					>
						{ __(
							'Scansiona il QR dell’ospite',
							'booking-manager'
						) }
					</Button>
				) }
				<p className="bkng-help">
					{ __(
						'Il QR è nell’email di conferma. Oppure cerca l’ospite qui sopra o premi «Check-in» nella lista.',
						'booking-manager'
					) }
				</p>
			</section>

			<div ref={ resultRef } className="bkng-desk__result">
				<ErrorNotice
					error={ action.error }
					onDismiss={ () => action.setError( null ) }
				/>
				{ answer && (
					<Result
						answer={ answer }
						busy={ action.busy }
						onCheckin={ ( b, rooms, dirty ) =>
							checkin( b, rooms, dirty )
						}
						onCheckout={ ( b, rooms ) => checkout( b, rooms ) }
						onClose={ () => setAnswer( null ) }
					/>
				) }
			</div>

			{ desk.loading && ! d && <Loading /> }
			<ErrorNotice error={ desk.error } onRetry={ desk.reload } />
			{ found && (
				<Card className="bkng-desk__day">
					<Tabs
						current={ tab }
						onChange={ setTab }
						tabs={ [
							[
								'arrivals',
								sprintf(
									/* translators: %d: arrivals still to do. */
									__( 'Arrivi (%d)', 'booking-manager' ),
									todo( 'arrivals' )
								),
							],
							[
								'in_house',
								sprintf(
									/* translators: %d: bookings in house. */
									__(
										'In struttura (%d)',
										'booking-manager'
									),
									lists.in_house.length
								),
							],
							[
								'departures',
								sprintf(
									/* translators: %d: departures still to do. */
									__( 'Partenze (%d)', 'booking-manager' ),
									todo( 'departures' )
								),
							],
						] }
					/>
					{ found[ tab ].length ? (
						<ul className="bkng-desk__list">
							{ found[ tab ].map( ( g ) => (
								<Guest
									key={ g.booking_id }
									group={ g }
									kind={ tab }
									busy={ action.busy }
									onRun={ ( group, rooms ) =>
										tab === 'arrivals'
											? checkin(
													group,
													rooms.map(
														( r ) =>
															r.booking_room_id
													),
													false,
													'list'
											  )
											: checkout(
													group,
													rooms.map(
														( r ) =>
															r.booking_room_id
													),
													'list'
											  )
									}
								/>
							) ) }
						</ul>
					) : (
						<p className="bkng-muted bkng-desk__empty">
							{ q
								? __(
										'Nessun ospite di oggi corrisponde: premi «Apri» per cercare il codice.',
										'booking-manager'
								  )
								: KIND[ tab ].empty }
						</p>
					) }
				</Card>
			) }
			{ d && d.recent.length > 0 && (
				<details className="bkng-desk__recent">
					<summary>
						{ __( 'Ultime operazioni di oggi', 'booking-manager' ) }
					</summary>
					<ul className="bkng-list">
						{ d.recent.map( ( r ) => (
							<li key={ r.id }>
								<span className="bkng-nowrap bkng-muted">
									{ r.local_time }
								</span>
								<div className="bkng-list__main">
									<span
										className={
											'bkng-badge is-' +
											( TONE[ r.result ] || 'muted' )
										}
									>
										{ r.label }
									</span>{ ' ' }
									{ r.booking
										? r.booking.name +
										  ' · ' +
										  r.booking.code
										: '' }
								</div>
								<span className="bkng-small bkng-muted">
									{ r.operator }
								</span>
							</li>
						) ) }
					</ul>
				</details>
			) }
		</Page>
	);
}
