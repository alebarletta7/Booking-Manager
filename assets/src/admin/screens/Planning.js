/**
 * The planning: rooms by type on the rows, nights on the columns, stays as bars.
 * A bar dragged to another room or day moves the stay (the server checks that the room
 * is free); an empty cell starts a booking; closures are shown and can be added.
 */
import { Button } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { del, get, post } from '../lib/api';
import { can } from '../lib/boot';
import {
	addDays,
	date,
	money,
	nightsBetween,
	nightsLabel,
	weekday,
} from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';
import { useProperty } from '../lib/property';
import { navigate } from '../lib/router';
import {
	Check,
	Confirm,
	DateField,
	Empty,
	ErrorNotice,
	FormModal,
	Loading,
	Page,
	Select,
	Success,
	Text,
} from '../components/ui';

const CELL = 44;

/** Highlight of the cell under a dragged stay: red when the room type differs. */
function dropClass( isOver, drag, row ) {
	if ( ! isOver ) {
		return '';
	}
	return drag && drag.room_type_id !== row.type.id
		? ' is-drop-bad'
		: ' is-drop';
}

const BLOCK_KINDS = {
	out_of_service: __( 'Fuori servizio', 'booking-manager' ),
	maintenance: __( 'Manutenzione', 'booking-manager' ),
	owner_use: __( 'Uso del proprietario', 'booking-manager' ),
};

function MoveModal( { move, onClose, onDone } ) {
	const action = useAction();
	const sameDates = move.arrival === move.stay.arrival;
	const [ keep, setKeep ] = useState( true );
	return (
		<FormModal
			title={ __( 'Sposta il soggiorno', 'booking-manager' ) }
			onClose={ onClose }
			busy={ action.busy }
			error={ action.error }
			submitLabel={ __( 'Sposta', 'booking-manager' ) }
			onSubmit={ async () => {
				const res = await action.run( () =>
					post(
						'/booking-rooms/' + move.stay.booking_room_id + '/move',
						{
							unit_id: move.unit.id,
							arrival: move.arrival,
							version: move.stay.booking_version,
							keep_price: sameDates || keep,
						}
					)
				);
				if ( res ) {
					onDone( __( 'Soggiorno spostato.', 'booking-manager' ) );
				}
			} }
		>
			<p>
				{ sprintf(
					/* translators: 1: guest, 2: room code, 3: arrival date, 4: nights. */
					__(
						'%1$s in camera %2$s dal %3$s (%4$s).',
						'booking-manager'
					),
					move.stay.name || move.stay.code,
					move.unit.code,
					date( move.arrival ),
					nightsLabel(
						nightsBetween( move.stay.arrival, move.stay.departure )
					)
				) }
			</p>
			{ ! sameDates && can( 'bkng_manage_rates' ) && (
				<Check
					label={ __(
						'Mantieni il prezzo attuale',
						'booking-manager'
					) }
					checked={ keep }
					onChange={ setKeep }
				/>
			) }
		</FormModal>
	);
}

function BlockModal( { units, initial, onClose, onDone } ) {
	const action = useAction();
	const [ form, setForm ] = useState( {
		unit_id: String( initial.unit_id || units[ 0 ]?.id || '' ),
		date_from: initial.date_from,
		date_to: addDays( initial.date_from, 1 ),
		kind: 'out_of_service',
		reason: '',
	} );
	const set = ( k ) => ( v ) => setForm( ( f ) => ( { ...f, [ k ]: v } ) );
	return (
		<FormModal
			title={ __( 'Chiudi una camera', 'booking-manager' ) }
			onClose={ onClose }
			busy={ action.busy }
			error={ action.error }
			onSubmit={ async () => {
				const res = await action.run( () =>
					post( '/units/' + form.unit_id + '/blocks', {
						date_from: form.date_from,
						date_to: form.date_to,
						kind: form.kind,
						reason: form.reason,
					} )
				);
				if ( res ) {
					onDone(
						__(
							'Camera chiusa per il periodo scelto.',
							'booking-manager'
						)
					);
				}
			} }
		>
			<div className="bkng-stack">
				<Select
					label={ __( 'Camera', 'booking-manager' ) }
					value={ form.unit_id }
					options={ units.map( ( u ) => ( {
						value: String( u.id ),
						label:
							u.code +
							( u.name && u.name !== u.code
								? ' · ' + u.name
								: '' ),
					} ) ) }
					onChange={ set( 'unit_id' ) }
				/>
				<div className="bkng-row">
					<DateField
						label={ __( 'Dal', 'booking-manager' ) }
						value={ form.date_from }
						onChange={ set( 'date_from' ) }
					/>
					<DateField
						label={ __( 'Al (escluso)', 'booking-manager' ) }
						value={ form.date_to }
						min={ addDays( form.date_from, 1 ) }
						onChange={ set( 'date_to' ) }
					/>
				</div>
				<Select
					label={ __( 'Motivo', 'booking-manager' ) }
					value={ form.kind }
					options={ Object.entries( BLOCK_KINDS ).map(
						( [ value, label ] ) => ( { value, label } )
					) }
					onChange={ set( 'kind' ) }
				/>
				<Text
					label={ __( 'Nota', 'booking-manager' ) }
					value={ form.reason }
					onChange={ set( 'reason' ) }
				/>
			</div>
		</FormModal>
	);
}

export default function Planning( { query } ) {
	const { property } = useProperty();
	const [ from, setFrom ] = useState(
		query.from || addDays( property.today, -1 )
	);
	const [ days, setDays ] = useState( 21 );
	const to = addDays( from, days );
	const plan = useLoad(
		() => get( '/properties/' + property.id + '/planning', { from, to } ),
		[ property.id, from, to ]
	);
	const [ drag, setDrag ] = useState( null );
	const [ over, setOver ] = useState( null );
	const [ modal, setModal ] = useState( null );
	const [ notice, setNotice ] = useState( '' );
	const remove = useAction();
	const canMove = can( 'bkng_edit_booking' );
	const canBlock =
		can( 'bkng_manage_rooms' ) || can( 'bkng_update_housekeeping' );

	const dates = useMemo(
		() => Array.from( { length: days }, ( _, i ) => addDays( from, i ) ),
		[ from, days ]
	);

	const rows = useMemo( () => {
		if ( ! plan.data ) {
			return [];
		}
		const out = [];
		for ( const type of plan.data.room_types ) {
			const units = plan.data.units.filter(
				( u ) => u.room_type_id === type.id
			);
			const free = ( plan.data.free || [] ).find(
				( f ) => f.room_type_id === type.id
			);
			out.push( { kind: 'type', type, free: free ? free.free : {} } );
			for ( const unit of units ) {
				out.push( {
					kind: 'unit',
					unit,
					type,
					stays: plan.data.stays.filter(
						( s ) => s.unit_id === unit.id
					),
					blocks: plan.data.blocks.filter(
						( b ) => b.unit_id === unit.id
					),
				} );
			}
			const waiting = plan.data.stays.filter(
				( s ) => ! s.unit_id && s.room_type_id === type.id
			);
			if ( waiting.length ) {
				out.push( { kind: 'unassigned', type, stays: waiting } );
			}
		}
		return out;
	}, [ plan.data ] );

	const done = ( message ) => {
		setModal( null );
		setNotice( message );
		plan.reload();
	};

	const bar = ( start, end, children, props ) => {
		const s = start < from ? from : start;
		const e = end > to ? to : end;
		const n = nightsBetween( s, e );
		if ( n <= 0 ) {
			return null;
		}
		const left = start < from ? 0 : CELL / 2;
		const width =
			n * CELL +
			( start < from ? CELL / 2 : 0 ) -
			( end > to ? CELL / 2 : 0 ) -
			4;
		return {
			key: s,
			node: (
				<button type="button" { ...props } style={ { left, width } }>
					{ children }
				</button>
			),
		};
	};

	const renderCells = ( row ) =>
		dates.map( ( d ) => {
			const items = [];
			if ( row.stays ) {
				row.stays
					.filter(
						( s ) =>
							s.arrival === d ||
							( d === from && s.arrival < from )
					)
					.forEach( ( s ) => {
						const b = bar(
							s.arrival,
							s.departure,
							s.name || s.code || '…',
							{
								className:
									'bkng-bar is-' +
									s.status +
									( drag &&
									drag.booking_room_id === s.booking_room_id
										? ' is-dragging'
										: '' ),
								title:
									( s.name || '' ) +
									' · ' +
									date( s.arrival ) +
									' → ' +
									date( s.departure ) +
									( s.balance
										? ' · ' +
										  money( s.balance, property.currency )
										: '' ),
								draggable:
									canMove &&
									row.kind === 'unit' &&
									! [ 'completed' ].includes( s.status ) &&
									s.stay_status !== 'departed',
								onDragStart: ( e ) => {
									e.dataTransfer.effectAllowed = 'move';
									e.dataTransfer.setData(
										'text/plain',
										String( s.booking_room_id )
									);
									setDrag( s );
								},
								onDragEnd: () => {
									setDrag( null );
									setOver( null );
								},
								onClick: () =>
									s.booking_id &&
									can( 'bkng_view_bookings' ) &&
									navigate( '/bookings/' + s.booking_id ),
							}
						);
						if ( b ) {
							items.push( b );
						}
					} );
			}
			if ( row.blocks ) {
				row.blocks
					.filter(
						( bl ) =>
							bl.date_from === d ||
							( d === from && bl.date_from < from )
					)
					.forEach( ( bl ) => {
						const b = bar(
							bl.date_from,
							bl.date_to,
							BLOCK_KINDS[ bl.kind ] || bl.kind,
							{
								className: 'bkng-bar is-block',
								title:
									( BLOCK_KINDS[ bl.kind ] || '' ) +
									( bl.reason ? ' · ' + bl.reason : '' ),
								onClick: () =>
									canBlock &&
									setModal( { kind: 'unblock', block: bl } ),
							}
						);
						if ( b ) {
							items.push( b );
						}
					} );
			}
			const weekend = [ 0, 6 ].includes(
				new Date( d + 'T12:00:00Z' ).getUTCDay()
			);
			const isOver =
				over && over.unit === row.unit?.id && over.date === d;
			return (
				<td
					key={ d }
					className={
						'bkng-planning__cell' +
						( weekend ? ' is-weekend' : '' ) +
						( d === property.today ? ' is-today' : '' ) +
						dropClass( isOver, drag, row )
					}
					onDragOver={ ( e ) => {
						if ( drag && row.kind === 'unit' ) {
							e.preventDefault();
							if ( ! isOver ) {
								setOver( { unit: row.unit.id, date: d } );
							}
						}
					} }
					onDrop={ ( e ) => {
						e.preventDefault();
						if ( drag && row.kind === 'unit' ) {
							setModal( {
								kind: 'move',
								move: {
									stay: drag,
									unit: row.unit,
									arrival: d,
								},
							} );
						}
						setDrag( null );
						setOver( null );
					} }
					onDoubleClick={ () => {
						if (
							row.kind === 'unit' &&
							can( 'bkng_create_booking' ) &&
							d >= property.today
						) {
							navigate(
								'/bookings/new?arrival=' +
									d +
									'&departure=' +
									addDays( d, 1 ) +
									'&room_type_id=' +
									row.type.id +
									'&unit_id=' +
									row.unit.id
							);
						}
					} }
				>
					{ items.map( ( i ) => (
						<span key={ i.key }>{ i.node }</span>
					) ) }
				</td>
			);
		} );

	return (
		<Page
			wide
			title={ __( 'Planning', 'booking-manager' ) }
			subtitle={ __(
				'Trascina una prenotazione per cambiare camera o date. Doppio clic su un giorno libero per una nuova prenotazione.',
				'booking-manager'
			) }
			actions={
				<>
					<Button
						variant="secondary"
						onClick={ () => setFrom( addDays( from, -7 ) ) }
					>
						‹ { __( 'Settimana', 'booking-manager' ) }
					</Button>
					<Button
						variant="secondary"
						onClick={ () =>
							setFrom( addDays( property.today, -1 ) )
						}
					>
						{ __( 'Oggi', 'booking-manager' ) }
					</Button>
					<Button
						variant="secondary"
						onClick={ () => setFrom( addDays( from, 7 ) ) }
					>
						{ __( 'Settimana', 'booking-manager' ) } ›
					</Button>
					<DateField
						label={ __( 'Dal', 'booking-manager' ) }
						value={ from }
						onChange={ ( v ) => v && setFrom( v ) }
					/>
					<Select
						label={ __( 'Giorni', 'booking-manager' ) }
						value={ String( days ) }
						options={ [ 14, 21, 31, 45, 62 ].map( ( n ) => ( {
							value: String( n ),
							label: String( n ),
						} ) ) }
						onChange={ ( v ) => setDays( Number( v ) ) }
					/>
					{ canBlock && plan.data?.units.length > 0 && (
						<Button
							variant="secondary"
							onClick={ () =>
								setModal( {
									kind: 'block',
									initial: { date_from: property.today },
								} )
							}
						>
							{ __( 'Chiudi una camera', 'booking-manager' ) }
						</Button>
					) }
				</>
			}
		>
			<Success onDismiss={ () => setNotice( '' ) }>{ notice }</Success>
			<ErrorNotice
				error={ plan.error || remove.error }
				onRetry={ plan.error ? plan.reload : undefined }
			/>
			{ plan.loading && ! plan.data && <Loading /> }
			{ plan.data && ! plan.data.units.length && (
				<Empty
					title={ __( 'Nessuna camera', 'booking-manager' ) }
					text={ __(
						'Aggiungi le camere in «Camere» per vedere il planning.',
						'booking-manager'
					) }
				/>
			) }
			{ plan.data && plan.data.units.length > 0 && (
				<>
					<div className="bkng-planning">
						<table>
							<thead>
								<tr>
									<th className="bkng-planning__room">
										{ __( 'Camera', 'booking-manager' ) }
									</th>
									{ dates.map( ( d ) => (
										<th
											key={ d }
											className={
												( d === property.today
													? 'is-today'
													: '' ) +
												( [ 0, 6 ].includes(
													new Date(
														d + 'T12:00:00Z'
													).getUTCDay()
												)
													? ' is-weekend'
													: '' )
											}
										>
											{ weekday( d ) }
											<br />
											{ Number( d.slice( 8 ) ) }
										</th>
									) ) }
								</tr>
							</thead>
							<tbody>
								{ rows.map( ( row ) => {
									if ( row.kind === 'type' ) {
										return (
											<tr
												key={ 't' + row.type.id }
												className="is-type"
											>
												<th className="bkng-planning__room">
													{ row.type.name }
												</th>
												{ dates.map( ( d ) => (
													<td
														key={ d }
														className="bkng-planning__free"
														title={ __(
															'Camere libere',
															'booking-manager'
														) }
													>
														{ row.free[ d ] ?? '' }
													</td>
												) ) }
											</tr>
										);
									}
									return (
										<tr
											key={
												row.kind +
												( row.unit
													? row.unit.id
													: row.type.id )
											}
										>
											<th
												className="bkng-planning__room"
												title={
													row.unit
														? row.unit.name
														: ''
												}
											>
												{ row.kind === 'unit' ? (
													row.unit.code
												) : (
													<em>
														{ __(
															'Da assegnare',
															'booking-manager'
														) }
													</em>
												) }
											</th>
											{ renderCells( row ) }
										</tr>
									);
								} ) }
							</tbody>
						</table>
					</div>
					<div className="bkng-legend">
						<span style={ { '--c': '#2f8a5f' } }>
							{ __( 'Confermata', 'booking-manager' ) }
						</span>
						<span style={ { '--c': '#b7791f' } }>
							{ __( 'In attesa / opzione', 'booking-manager' ) }
						</span>
						<span style={ { '--c': '#1f5c43' } }>
							{ __( 'In soggiorno', 'booking-manager' ) }
						</span>
						<span style={ { '--c': '#8a9a91' } }>
							{ __( 'Conclusa', 'booking-manager' ) }
						</span>
						<span style={ { '--c': '#6b6b6b' } }>
							{ __( 'Camera chiusa', 'booking-manager' ) }
						</span>
					</div>
				</>
			) }
			{ modal?.kind === 'move' && (
				<MoveModal
					move={ modal.move }
					onClose={ () => setModal( null ) }
					onDone={ done }
				/>
			) }
			{ modal?.kind === 'block' && (
				<BlockModal
					units={ plan.data.units }
					initial={ modal.initial }
					onClose={ () => setModal( null ) }
					onDone={ done }
				/>
			) }
			{ modal?.kind === 'unblock' && (
				<Confirm
					title={ __( 'Riapri la camera', 'booking-manager' ) }
					text={ sprintf(
						/* translators: 1: start date, 2: end date. */
						__(
							'La chiusura dal %1$s al %2$s viene tolta e la camera torna vendibile.',
							'booking-manager'
						),
						date( modal.block.date_from ),
						date( modal.block.date_to )
					) }
					confirmLabel={ __( 'Riapri', 'booking-manager' ) }
					busy={ remove.busy }
					onCancel={ () => setModal( null ) }
					onConfirm={ async () => {
						const ok = await remove.run( () =>
							del( '/blocks/' + modal.block.id )
						);
						if ( ok ) {
							done( __( 'Camera riaperta.', 'booking-manager' ) );
						} else {
							setModal( null );
						}
					} }
				/>
			) }
		</Page>
	);
}
