/**
 * Reception: the guest's QR read with the camera (or the booking code typed), check-in
 * and check-out room by room, the lists of the day and the latest attempts.
 */
import { Button } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
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
	Kpi,
	Loading,
	Page,
	Text,
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
function Camera( { onCode, paused } ) {
	const video = useRef( null );
	const canvas = useRef( null );
	const last = useRef( { text: '', at: 0 } );
	const [ error, setError ] = useState( '' );
	const [ on, setOn ] = useState( false );

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
			<Button variant="link" onClick={ () => setOn( false ) }>
				{ __( 'Spegni la fotocamera', 'booking-manager' ) }
			</Button>
		</>
	);
}

function Result( { answer, busy, onCheckin, onCheckout } ) {
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
			<p className="bkng-result__label">{ answer.label }</p>
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

function StayRows( { rows, action, label, onRun } ) {
	if ( ! rows.length ) {
		return (
			<p className="bkng-muted">
				{ __( 'Nessuno.', 'booking-manager' ) }
			</p>
		);
	}
	return (
		<ul className="bkng-list">
			{ rows.map( ( r ) => (
				<li key={ r.booking_room_id }>
					<div className="bkng-list__main">
						<a
							href={ '#/bookings/' + r.booking_id }
							className="bkng-strong"
						>
							{ r.name }
						</a>
						<div className="bkng-small bkng-muted">
							{ r.unit
								? r.unit.code
								: __(
										'senza camera',
										'booking-manager'
								  ) }{ ' ' }
							· { r.code } · { date( r.arrival ) } →{ ' ' }
							{ date( r.departure ) }
						</div>
					</div>
					{ r.unit && (
						<Badge status={ r.unit.op_status } kind="op" />
					) }
					{ action && r.stay_status !== 'departed' && (
						<Button
							variant="secondary"
							onClick={ () => onRun( r ) }
						>
							{ label }
						</Button>
					) }
					{ r.stay_status === 'departed' && (
						<Badge status="departed" kind="stay" />
					) }
				</li>
			) ) }
		</ul>
	);
}

export default function FrontDesk() {
	const { property } = useProperty();
	const desk = useLoad(
		() => get( '/properties/' + property.id + '/frontdesk' ),
		[ property.id ]
	);
	const [ code, setCode ] = useState( '' );
	const [ answer, setAnswer ] = useState( null );
	const action = useAction();
	const base = '/properties/' + property.id;

	const show = useCallback(
		( res ) => {
			if ( res ) {
				setAnswer( res );
				desk.reload();
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
	const onCode = useCallback( ( text ) => scan( text, 'qr' ), [ scan ] );

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
	return (
		<Page
			title={ __( 'Reception', 'booking-manager' ) }
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
			{ d && (
				<div className="bkng-kpis">
					<Kpi
						label={ __( 'Arrivi da fare', 'booking-manager' ) }
						value={ d.counts.arrivals_expected }
						tone="accent"
					/>
					<Kpi
						label={ __( 'In struttura', 'booking-manager' ) }
						value={ d.counts.in_house }
					/>
					<Kpi
						label={ __( 'Partenze da fare', 'booking-manager' ) }
						value={ d.counts.departures_expected }
					/>
					<Kpi
						label={ __( 'Partenze fatte', 'booking-manager' ) }
						value={ d.counts.departures_done }
					/>
				</div>
			) }
			<div className="bkng-scan">
				<Card title={ __( 'Leggi il QR', 'booking-manager' ) }>
					<Camera onCode={ onCode } paused={ action.busy } />
					<form
						className="bkng-row"
						style={ { marginTop: 14 } }
						onSubmit={ ( e ) => {
							e.preventDefault();
							if ( code.trim() ) {
								scan( code.trim(), 'manual' );
							}
						} }
					>
						<div style={ { flex: 1 } }>
							<Text
								label={ __(
									'Oppure il codice della prenotazione',
									'booking-manager'
								) }
								value={ code }
								onChange={ setCode }
								autoComplete="off"
							/>
						</div>
						<Button
							variant="secondary"
							type="submit"
							isBusy={ action.busy }
						>
							{ __( 'Cerca', 'booking-manager' ) }
						</Button>
					</form>
				</Card>
				<div>
					<ErrorNotice
						error={ action.error }
						onDismiss={ () => action.setError( null ) }
					/>
					{ answer ? (
						<Result
							answer={ answer }
							busy={ action.busy }
							onCheckin={ ( b, rooms, dirty ) =>
								checkin( b, rooms, dirty )
							}
							onCheckout={ ( b, rooms ) => checkout( b, rooms ) }
						/>
					) : (
						<div className="bkng-result">
							<p className="bkng-muted">
								{ __(
									'Inquadra il QR della email di conferma o scrivi il codice: qui compare la prenotazione con le camere.',
									'booking-manager'
								) }
							</p>
						</div>
					) }
				</div>
			</div>

			{ desk.loading && ! d && <Loading /> }
			<ErrorNotice error={ desk.error } onRetry={ desk.reload } />
			{ d && (
				<div className="bkng-grid is-3" style={ { marginTop: 20 } }>
					<Card title={ __( 'Arrivi', 'booking-manager' ) }>
						<StayRows
							rows={ d.arrivals }
							action
							label={ __( 'Check-in', 'booking-manager' ) }
							onRun={ ( r ) =>
								checkin(
									r,
									[ r.booking_room_id ],
									false,
									'list'
								)
							}
						/>
					</Card>
					<Card title={ __( 'In struttura', 'booking-manager' ) }>
						<StayRows rows={ d.in_house } />
					</Card>
					<Card title={ __( 'Partenze', 'booking-manager' ) }>
						<StayRows
							rows={ d.departures }
							action
							label={ __( 'Check-out', 'booking-manager' ) }
							onRun={ ( r ) =>
								checkout( r, [ r.booking_room_id ], 'list' )
							}
						/>
					</Card>
				</div>
			) }
			{ d && d.recent.length > 0 && (
				<Card
					title={ __(
						'Ultime operazioni di oggi',
						'booking-manager'
					) }
				>
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
				</Card>
			) }
		</Page>
	);
}
