/**
 * Housekeeping: every room with its cleaning status and what happens today (arrival,
 * departure, guests staying). No names, no prices.
 */
import { Button, Modal } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { get, post } from '../lib/api';
import { can } from '../lib/boot';
import { dateTime, OP_STATUS } from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';
import { useProperty } from '../lib/property';
import {
	Badge,
	Empty,
	ErrorNotice,
	Kpi,
	Loading,
	Page,
	Select,
	Text,
} from '../components/ui';

// The next step of the usual round, shown as the main button.
const NEXT = {
	dirty: 'cleaning',
	cleaning: 'clean',
	clean: 'ready',
	maintenance: 'dirty',
};

function HistoryModal( { room, onClose } ) {
	const list = useLoad(
		() => get( '/units/' + room.id + '/op-history' ),
		[ room.id ]
	);
	return (
		<Modal
			title={ room.code }
			onRequestClose={ onClose }
			className="bkng-modal"
		>
			{ list.loading && <Loading /> }
			<ErrorNotice error={ list.error } />
			<ul className="bkng-list">
				{ ( list.data || [] ).map( ( h, i ) => (
					<li key={ i }>
						<div className="bkng-list__main">
							{ h.label }
							{ h.note && (
								<div className="bkng-small">{ h.note }</div>
							) }
						</div>
						<span className="bkng-small bkng-muted">
							{ dateTime( h.at ) }
							{ h.operator ? ' · ' + h.operator : '' }
						</span>
					</li>
				) ) }
			</ul>
			{ list.data && ! list.data.length && (
				<p className="bkng-muted">
					{ __( 'Nessun cambio registrato.', 'booking-manager' ) }
				</p>
			) }
		</Modal>
	);
}

function NoteModal( { room, onClose, onSave } ) {
	const [ status, setStatus ] = useState( room.op_status );
	const [ note, setNote ] = useState( room.op_note || '' );
	return (
		<Modal
			title={ room.code }
			onRequestClose={ onClose }
			className="bkng-modal"
		>
			<div className="bkng-stack">
				<Select
					label={ __( 'Stato', 'booking-manager' ) }
					value={ status }
					options={ Object.entries( OP_STATUS ).map(
						( [ value, label ] ) => ( { value, label } )
					) }
					onChange={ setStatus }
				/>
				<Text
					label={ __(
						'Nota (es. guasto, oggetti dimenticati)',
						'booking-manager'
					) }
					value={ note }
					onChange={ setNote }
				/>
			</div>
			<div className="bkng-modal__actions">
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Annulla', 'booking-manager' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ () => onSave( status, note ) }
				>
					{ __( 'Salva', 'booking-manager' ) }
				</Button>
			</div>
		</Modal>
	);
}

export default function Housekeeping() {
	const { property } = useProperty();
	const board = useLoad(
		() => get( '/properties/' + property.id + '/housekeeping' ),
		[ property.id ]
	);
	const [ filter, setFilter ] = useState( '' );
	const [ modal, setModal ] = useState( null );
	const action = useAction();
	const canUpdate = can( 'bkng_update_housekeeping' );

	const change = async ( room, to, note ) => {
		setModal( null );
		const res = await action.run( () =>
			post( '/units/' + room.id + '/op-status', {
				from: room.op_status,
				to,
				note: note === undefined ? room.op_note : note,
			} )
		);
		// Also after a conflict: someone else changed it, the board shows what holds now.
		board.reload();
		return res;
	};

	if ( board.loading && ! board.data ) {
		return <Loading />;
	}
	if ( board.error ) {
		return <ErrorNotice error={ board.error } onRetry={ board.reload } />;
	}
	const b = board.data;
	const rooms = b.rooms.filter( ( r ) => {
		if ( filter === 'today' ) {
			return r.today.departure || r.today.arrival;
		}
		return ! filter || r.op_status === filter;
	} );

	return (
		<Page
			title={ __( 'Housekeeping', 'booking-manager' ) }
			actions={
				<>
					<Select
						label={ __( 'Mostra', 'booking-manager' ) }
						hideLabelFromVision
						value={ filter }
						options={ [
							{
								value: '',
								label: __(
									'Tutte le camere',
									'booking-manager'
								),
							},
							{
								value: 'today',
								label: __(
									'Con arrivo o partenza oggi',
									'booking-manager'
								),
							},
							...Object.entries( b.statuses ).map(
								( [ value, label ] ) => ( { value, label } )
							),
						] }
						onChange={ setFilter }
					/>
					<Button
						variant="secondary"
						onClick={ board.reload }
						isBusy={ board.loading }
					>
						{ __( 'Aggiorna', 'booking-manager' ) }
					</Button>
				</>
			}
		>
			{ ! b.enabled && (
				<p className="bkng-help">
					{ __(
						"L'housekeeping è disattivato per questa struttura: gli stati non cambiano da soli alla partenza.",
						'booking-manager'
					) }
				</p>
			) }
			<div className="bkng-kpis">
				{ Object.entries( b.counts ).map( ( [ status, n ] ) => (
					<Kpi
						key={ status }
						label={ b.statuses[ status ] }
						value={ n }
						tone={ status === 'dirty' && n ? 'warn' : '' }
					/>
				) ) }
			</div>
			<ErrorNotice
				error={ action.error }
				onDismiss={ () => action.setError( null ) }
			/>
			{ ! rooms.length && (
				<Empty title={ __( 'Nessuna camera', 'booking-manager' ) } />
			) }
			<div className="bkng-rooms">
				{ rooms.map( ( r ) => (
					<div
						key={ r.id }
						className={ 'bkng-room is-' + r.op_status }
					>
						<div className="bkng-room__head">
							<span className="bkng-room__code">{ r.code }</span>
							<Badge status={ r.op_status } kind="op" />
						</div>
						<div className="bkng-small bkng-muted">
							{ r.room_type }
							{ r.floor
								? ' · ' +
								  __( 'Piano', 'booking-manager' ) +
								  ' ' +
								  r.floor
								: '' }
						</div>
						<div className="bkng-room__flags">
							{ r.today.departure && (
								<span className="bkng-badge is-warn">
									{ r.today.departed
										? __( 'Partiti', 'booking-manager' )
										: __(
												'Partenza oggi',
												'booking-manager'
										  ) }
								</span>
							) }
							{ r.today.arrival && (
								<span className="bkng-badge is-info">
									{ __( 'Arrivo oggi', 'booking-manager' ) }
									{ r.today.guests
										? ' · ' + r.today.guests
										: '' }
								</span>
							) }
							{ r.today.staying && (
								<span className="bkng-badge">
									{ __( 'Occupata', 'booking-manager' ) }
								</span>
							) }
						</div>
						{ r.op_note && (
							<div className="bkng-small">{ r.op_note }</div>
						) }
						{ canUpdate && (
							<div className="bkng-room__actions">
								{ NEXT[ r.op_status ] && (
									<Button
										variant="primary"
										size="compact"
										disabled={ action.busy }
										onClick={ () =>
											change( r, NEXT[ r.op_status ] )
										}
									>
										{ OP_STATUS[ NEXT[ r.op_status ] ] }
									</Button>
								) }
								<Button
									variant="secondary"
									size="compact"
									onClick={ () =>
										setModal( { kind: 'edit', room: r } )
									}
								>
									{ __( 'Cambia stato', 'booking-manager' ) }
								</Button>
								<Button
									variant="link"
									onClick={ () =>
										setModal( { kind: 'history', room: r } )
									}
								>
									{ __( 'Storico', 'booking-manager' ) }
								</Button>
							</div>
						) }
					</div>
				) ) }
			</div>
			{ modal?.kind === 'edit' && (
				<NoteModal
					room={ modal.room }
					onClose={ () => setModal( null ) }
					onSave={ ( to, note ) => change( modal.room, to, note ) }
				/>
			) }
			{ modal?.kind === 'history' && (
				<HistoryModal
					room={ modal.room }
					onClose={ () => setModal( null ) }
				/>
			) }
		</Page>
	);
}
