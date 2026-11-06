/**
 * The activity log of the property: who did what and when (no personal data in it).
 */
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { getPaged } from '../lib/api';
import { actionLabel, dateTime } from '../lib/format';
import { useLoad } from '../lib/hooks';
import { useProperty } from '../lib/property';
import {
	Card,
	DateField,
	Empty,
	ErrorNotice,
	Loading,
	Page,
	Pager,
	Select,
} from '../components/ui';

const PER_PAGE = 50;

const ENTITIES = {
	booking: __( 'Prenotazioni', 'booking-manager' ),
	payment: __( 'Pagamenti', 'booking-manager' ),
	guest: __( 'Ospiti', 'booking-manager' ),
	property: __( 'Struttura', 'booking-manager' ),
	room_type: __( 'Tipologie', 'booking-manager' ),
	unit: __( 'Camere', 'booking-manager' ),
	rate_plan: __( 'Tariffe', 'booking-manager' ),
	user: __( 'Staff', 'booking-manager' ),
	settings: __( 'Impostazioni', 'booking-manager' ),
	license: __( 'Licenza', 'booking-manager' ),
};

function Changes( { row } ) {
	const [ open, setOpen ] = useState( false );
	if ( ! row.old && ! row.new ) {
		return null;
	}
	return (
		<>
			<Button variant="link" onClick={ () => setOpen( ! open ) }>
				{ open
					? __( 'Nascondi', 'booking-manager' )
					: __( 'Dettagli', 'booking-manager' ) }
			</Button>
			{ open && (
				<pre className="bkng-code">
					{ JSON.stringify(
						{ prima: row.old, dopo: row.new },
						null,
						2
					) }
				</pre>
			) }
		</>
	);
}

export default function Audit() {
	const { property } = useProperty();
	const [ filters, setFilters ] = useState( {
		entity_type: '',
		from: '',
		to: '',
	} );
	const [ page, setPage ] = useState( 1 );
	const set = ( k ) => ( v ) => {
		setFilters( ( f ) => ( { ...f, [ k ]: v } ) );
		setPage( 1 );
	};
	const list = useLoad(
		() =>
			getPaged( '/audit-log', {
				property_id: property.id,
				...filters,
				page,
				per_page: PER_PAGE,
			} ),
		[ property.id, JSON.stringify( filters ), page ]
	);

	return (
		<Page title={ __( 'Registro attività', 'booking-manager' ) }>
			<div className="bkng-filters">
				<Select
					label={ __( 'Elemento', 'booking-manager' ) }
					value={ filters.entity_type }
					options={ [
						{ value: '', label: __( 'Tutti', 'booking-manager' ) },
						...Object.entries( ENTITIES ).map(
							( [ value, label ] ) => ( { value, label } )
						),
					] }
					onChange={ set( 'entity_type' ) }
				/>
				<DateField
					label={ __( 'Dal', 'booking-manager' ) }
					value={ filters.from }
					onChange={ set( 'from' ) }
				/>
				<DateField
					label={ __( 'Al', 'booking-manager' ) }
					value={ filters.to }
					onChange={ set( 'to' ) }
				/>
			</div>
			<Card className="is-flush">
				{ list.loading && <Loading /> }
				<ErrorNotice error={ list.error } onRetry={ list.reload } />
				{ list.data && ! list.data.items.length && (
					<Empty
						title={ __( 'Nessuna attività', 'booking-manager' ) }
					/>
				) }
				{ list.data && list.data.items.length > 0 && (
					<div className="bkng-table-wrap">
						<table className="bkng-table">
							<thead>
								<tr>
									<th>
										{ __( 'Quando', 'booking-manager' ) }
									</th>
									<th>{ __( 'Chi', 'booking-manager' ) }</th>
									<th>{ __( 'Cosa', 'booking-manager' ) }</th>
									<th />
								</tr>
							</thead>
							<tbody>
								{ list.data.items.map( ( r ) => (
									<tr key={ r.id }>
										<td className="bkng-nowrap">
											{ dateTime( r.created_at ) }
										</td>
										<td>
											{ r.user_name ||
												( r.actor_type === 'guest'
													? __(
															'Ospite',
															'booking-manager'
													  )
													: __(
															'Sistema',
															'booking-manager'
													  ) ) }
										</td>
										<td>
											<span className="bkng-strong">
												{ actionLabel( r.action ) }
											</span>
											{ r.entity_type === 'booking' &&
												r.entity_id && (
													<>
														{ ' · ' }
														<a
															href={
																'#/bookings/' +
																r.entity_id
															}
														>
															{ __(
																'apri',
																'booking-manager'
															) }
														</a>
													</>
												) }
											{ r.reason && (
												<div className="bkng-small">
													{ r.reason }
												</div>
											) }
										</td>
										<td>
											<Changes row={ r } />
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
		</Page>
	);
}
