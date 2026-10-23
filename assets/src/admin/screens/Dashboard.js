/**
 * Today at a glance: arrivals, departures, occupancy, money still due, what needs a hand.
 */
import { Button } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import { get } from '../lib/api';
import { can } from '../lib/boot';
import { date, money, OP_STATUS } from '../lib/format';
import { useLoad } from '../lib/hooks';
import { useProperty } from '../lib/property';
import { navigate } from '../lib/router';
import {
	Badge,
	Card,
	Empty,
	ErrorNotice,
	Kpi,
	Loading,
	Page,
} from '../components/ui';

function StayList( { rows, empty } ) {
	if ( ! rows || ! rows.length ) {
		return <p className="bkng-muted">{ empty }</p>;
	}
	return (
		<ul className="bkng-list">
			{ rows.map( ( r ) => (
				<li key={ r.booking_room_id || r.booking_id }>
					<div className="bkng-list__main">
						{ r.booking_id && can( 'bkng_view_bookings' ) ? (
							<a href={ '#/bookings/' + r.booking_id }>
								{ r.name || r.code }
							</a>
						) : (
							<span>{ r.name }</span>
						) }
						<div className="bkng-muted bkng-small">
							{ date( r.arrival ) } → { date( r.departure ) }
							{ r.unit_code ? ' · ' + r.unit_code : '' }
						</div>
					</div>
					{ r.stay_status ? (
						<Badge status={ r.stay_status } kind="stay" />
					) : (
						<Badge status={ r.status } />
					) }
				</li>
			) ) }
		</ul>
	);
}

export default function Dashboard() {
	const { property } = useProperty();
	const summary = useLoad(
		() => get( '/properties/' + property.id + '/dashboard' ),
		[ property.id ]
	);
	const day = useLoad(
		() =>
			can( 'bkng_view_housekeeping' )
				? get( '/properties/' + property.id + '/day' )
				: null,
		[ property.id ]
	);

	if ( summary.loading ) {
		return <Loading />;
	}
	if ( summary.error ) {
		return (
			<ErrorNotice error={ summary.error } onRetry={ summary.reload } />
		);
	}
	const s = summary.data;
	const currency = s.currency || property.currency;

	return (
		<Page
			title={ __( 'Dashboard', 'booking-manager' ) }
			subtitle={ sprintf(
				/* translators: %s: today's date. */
				__( 'Oggi, %s', 'booking-manager' ),
				date( s.date, {
					weekday: 'long',
					day: 'numeric',
					month: 'long',
				} )
			) }
			actions={
				<Button
					variant="secondary"
					onClick={ () => navigate( '/planning' ) }
				>
					{ __( 'Apri il planning', 'booking-manager' ) }
				</Button>
			}
		>
			<div className="bkng-kpis">
				<Kpi
					label={ __( 'Arrivi di oggi', 'booking-manager' ) }
					value={ s.arrivals }
					hint={ sprintf(
						/* translators: %d: guests already arrived. */ __(
							'%d già arrivati',
							'booking-manager'
						),
						s.arrivals_done
					) }
					tone="accent"
				/>
				<Kpi
					label={ __( 'Partenze di oggi', 'booking-manager' ) }
					value={ s.departures }
					hint={ sprintf(
						/* translators: %d: guests already left. */ __(
							'%d già partiti',
							'booking-manager'
						),
						s.departures_done
					) }
				/>
				<Kpi
					label={ __( 'Ospiti in struttura', 'booking-manager' ) }
					value={ s.guests_in_house }
				/>
				<Kpi
					label={ __( 'Occupazione oggi', 'booking-manager' ) }
					value={ s.occupancy_today + '%' }
					hint={
						sprintf(
							/* translators: %d: occupied rooms. */
							_n(
								'%d occupata',
								'%d occupate',
								s.rooms_occupied,
								'booking-manager'
							),
							s.rooms_occupied
						) +
						', ' +
						sprintf(
							/* translators: %d: free rooms. */
							_n(
								'%d libera',
								'%d libere',
								s.rooms_free,
								'booking-manager'
							),
							s.rooms_free
						)
					}
				/>
				<Kpi
					label={ __( 'Occupazione 30 giorni', 'booking-manager' ) }
					value={ s.occupancy_30_days + '%' }
				/>
				<Kpi
					label={ __( 'Prenotazioni future', 'booking-manager' ) }
					value={ s.future_bookings }
				/>
				<Kpi
					label={ __( 'Da confermare', 'booking-manager' ) }
					value={ s.pending }
					tone={ s.pending ? 'warn' : '' }
				/>
				{ s.balance_due !== undefined && (
					<Kpi
						label={ __( 'Saldo da incassare', 'booking-manager' ) }
						value={ money( s.balance_due, currency ) }
						hint={ sprintf(
							/* translators: %s: amount. */ __(
								'%s entro 7 giorni',
								'booking-manager'
							),
							money( s.balance_due_7_days, currency )
						) }
					/>
				) }
				{ s.deposits_overdue !== undefined && (
					<Kpi
						label={ __( 'Caparre scadute', 'booking-manager' ) }
						value={ s.deposits_overdue }
						tone={ s.deposits_overdue ? 'warn' : '' }
					/>
				) }
				<Kpi
					label={ __( 'Senza camera (7 giorni)', 'booking-manager' ) }
					value={ s.unassigned_7_days }
					tone={ s.unassigned_7_days ? 'warn' : '' }
					hint={ __(
						'Prenotazioni a cui assegnare la camera',
						'booking-manager'
					) }
				/>
				<Kpi
					label={ __( 'Camere fuori servizio', 'booking-manager' ) }
					value={ s.rooms_out_of_service }
					hint={ sprintf(
						/* translators: %d: total rooms. */ __(
							'su %d camere',
							'booking-manager'
						),
						s.rooms_total
					) }
				/>
			</div>

			{ s.rooms_total === 0 && (
				<Empty
					title={ __( 'Nessuna camera', 'booking-manager' ) }
					text={ __(
						'Aggiungi tipologie e camere per iniziare a ricevere prenotazioni.',
						'booking-manager'
					) }
				>
					{ can( 'bkng_manage_rooms' ) && (
						<Button
							variant="primary"
							onClick={ () => navigate( '/rooms' ) }
						>
							{ __( 'Vai a Camere', 'booking-manager' ) }
						</Button>
					) }
				</Empty>
			) }

			{ day.data && (
				<div className="bkng-grid is-3">
					<Card title={ __( 'Arrivi', 'booking-manager' ) }>
						<StayList
							rows={ day.data.arrivals }
							empty={ __(
								'Nessun arrivo oggi.',
								'booking-manager'
							) }
						/>
					</Card>
					<Card title={ __( 'Partenze', 'booking-manager' ) }>
						<StayList
							rows={ day.data.departures }
							empty={ __(
								'Nessuna partenza oggi.',
								'booking-manager'
							) }
						/>
					</Card>
					<Card title={ __( 'Stato camere', 'booking-manager' ) }>
						<ul className="bkng-list">
							{ Object.entries( s.rooms_by_status || {} ).map(
								( [ status, n ] ) => (
									<li key={ status }>
										<div className="bkng-list__main">
											<Badge
												status={ status }
												kind="op"
											/>
										</div>
										<span className="bkng-strong">
											{ n }
										</span>
									</li>
								)
							) }
						</ul>
						{ ! Object.keys( s.rooms_by_status || {} ).length && (
							<p className="bkng-muted">{ OP_STATUS.ready }</p>
						) }
					</Card>
				</div>
			) }
			{ day.data?.pending?.length > 0 && (
				<Card
					title={ __( 'Richieste da confermare', 'booking-manager' ) }
				>
					<StayList
						rows={ day.data.pending.map( ( r ) => ( {
							...r,
							booking_id: r.id,
						} ) ) }
						empty=""
					/>
				</Card>
			) }
		</Page>
	);
}
