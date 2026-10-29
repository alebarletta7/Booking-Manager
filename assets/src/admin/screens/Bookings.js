/**
 * The bookings of the property: search, filters by status, dates and payment.
 */
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { getPaged } from '../lib/api';
import { can } from '../lib/boot';
import {
	BOOKING_STATUS,
	date,
	guestsLabel,
	money,
	options,
	PAYMENT_STATUS,
	SOURCES,
} from '../lib/format';
import { useLoad } from '../lib/hooks';
import { useProperty } from '../lib/property';
import { navigate } from '../lib/router';
import {
	Badge,
	Card,
	DateField,
	Empty,
	ErrorNotice,
	Loading,
	Page,
	Pager,
	Select,
	Text,
} from '../components/ui';

const PER_PAGE = 30;

const VIEWS = {
	upcoming: __( 'Prossimi arrivi', 'booking-manager' ),
	open: __( 'Da confermare', 'booking-manager' ),
	all: __( 'Tutte', 'booking-manager' ),
};

export default function Bookings( { query } ) {
	const { property } = useProperty();
	const [ filters, setFilters ] = useState( {
		view: query.view || 'upcoming',
		search: query.search || '',
		status: '',
		payment_status: '',
		source: '',
		from: '',
		to: '',
	} );
	const [ search, setSearch ] = useState( filters.search );
	const [ page, setPage ] = useState( 1 );
	const set = ( key, value ) => {
		setFilters( ( f ) => ( { ...f, [ key ]: value } ) );
		setPage( 1 );
	};

	const params = {
		search: filters.search,
		payment_status: filters.payment_status,
		source: filters.source,
		page,
		per_page: PER_PAGE,
	};
	if ( filters.view === 'upcoming' ) {
		params.from = property.today;
		params.order = 'arrival_asc';
		params.status = filters.status || 'pending,option,confirmed,in_house';
	} else if ( filters.view === 'open' ) {
		params.status = 'pending,option';
		params.order = 'created';
	} else {
		params.status = filters.status;
		params.from = filters.from;
		params.to = filters.to;
	}

	const list = useLoad(
		() => getPaged( '/properties/' + property.id + '/bookings', params ),
		[ property.id, JSON.stringify( params ) ]
	);

	return (
		<Page
			title={ __( 'Prenotazioni', 'booking-manager' ) }
			actions={
				can( 'bkng_create_booking' ) && (
					<Button
						variant="primary"
						onClick={ () => navigate( '/bookings/new' ) }
					>
						{ __( 'Nuova prenotazione', 'booking-manager' ) }
					</Button>
				)
			}
		>
			<div className="bkng-tabs" role="tablist">
				{ Object.entries( VIEWS ).map( ( [ key, label ] ) => (
					<button
						key={ key }
						type="button"
						className={ filters.view === key ? 'is-active' : '' }
						onClick={ () => set( 'view', key ) }
					>
						{ label }
					</button>
				) ) }
			</div>
			<form
				className="bkng-filters"
				onSubmit={ ( e ) => {
					e.preventDefault();
					set( 'search', search.trim() );
				} }
			>
				<div className="bkng-filters__search">
					<Text
						label={ __( 'Cerca', 'booking-manager' ) }
						placeholder={ __(
							'Nome, email, telefono o codice',
							'booking-manager'
						) }
						value={ search }
						onChange={ setSearch }
						onBlur={ () => set( 'search', search.trim() ) }
					/>
				</div>
				{ filters.view !== 'open' && (
					<Select
						label={ __( 'Stato', 'booking-manager' ) }
						value={ filters.status }
						options={ [
							{
								value: '',
								label: __( 'Tutti', 'booking-manager' ),
							},
							...options( BOOKING_STATUS ),
						] }
						onChange={ ( v ) => set( 'status', v ) }
					/>
				) }
				{ can( 'bkng_view_payments' ) && (
					<Select
						label={ __( 'Pagamento', 'booking-manager' ) }
						value={ filters.payment_status }
						options={ [
							{
								value: '',
								label: __( 'Tutti', 'booking-manager' ),
							},
							...options( PAYMENT_STATUS ),
						] }
						onChange={ ( v ) => set( 'payment_status', v ) }
					/>
				) }
				<Select
					label={ __( 'Origine', 'booking-manager' ) }
					value={ filters.source }
					options={ [
						{ value: '', label: __( 'Tutte', 'booking-manager' ) },
						...options( SOURCES ),
					] }
					onChange={ ( v ) => set( 'source', v ) }
				/>
				{ filters.view === 'all' && (
					<>
						<DateField
							label={ __( 'Soggiorno dal', 'booking-manager' ) }
							value={ filters.from }
							onChange={ ( v ) => set( 'from', v ) }
						/>
						<DateField
							label={ __( 'al', 'booking-manager' ) }
							value={ filters.to }
							onChange={ ( v ) => set( 'to', v ) }
						/>
					</>
				) }
			</form>

			<Card className="is-flush">
				{ list.loading && <Loading /> }
				<ErrorNotice error={ list.error } onRetry={ list.reload } />
				{ list.data && ! list.data.items.length && (
					<Empty
						title={ __(
							'Nessuna prenotazione',
							'booking-manager'
						) }
						text={ __(
							'Nessuna prenotazione corrisponde ai filtri.',
							'booking-manager'
						) }
					/>
				) }
				{ list.data && list.data.items.length > 0 && (
					<div className="bkng-table-wrap">
						<table className="bkng-table">
							<thead>
								<tr>
									<th>
										{ __( 'Codice', 'booking-manager' ) }
									</th>
									<th>
										{ __( 'Ospite', 'booking-manager' ) }
									</th>
									<th>
										{ __( 'Arrivo', 'booking-manager' ) }
									</th>
									<th>
										{ __( 'Partenza', 'booking-manager' ) }
									</th>
									<th>
										{ __( 'Ospiti', 'booking-manager' ) }
									</th>
									<th>
										{ __( 'Stato', 'booking-manager' ) }
									</th>
									{ can( 'bkng_view_payments' ) && (
										<th>
											{ __(
												'Pagamento',
												'booking-manager'
											) }
										</th>
									) }
									{ can( 'bkng_view_payments' ) && (
										<th className="is-num">
											{ __(
												'Totale',
												'booking-manager'
											) }
										</th>
									) }
									{ can( 'bkng_view_payments' ) && (
										<th className="is-num">
											{ __( 'Saldo', 'booking-manager' ) }
										</th>
									) }
								</tr>
							</thead>
							<tbody>
								{ list.data.items.map( ( b ) => (
									<tr
										key={ b.id }
										className="is-link"
										onClick={ () =>
											navigate( '/bookings/' + b.id )
										}
									>
										<td>
											<a
												href={ '#/bookings/' + b.id }
												onClick={ ( e ) =>
													e.stopPropagation()
												}
											>
												{ b.code }
											</a>
										</td>
										<td>
											{ b.first_name } { b.last_name }
											<div className="bkng-muted bkng-small">
												{ SOURCES[ b.source ] ||
													b.source }
											</div>
										</td>
										<td className="bkng-nowrap">
											{ date( b.arrival ) }
										</td>
										<td className="bkng-nowrap">
											{ date( b.departure ) }
										</td>
										<td className="bkng-nowrap">
											{ guestsLabel(
												b.adults,
												b.children
											) }
										</td>
										<td>
											<Badge status={ b.status } />
										</td>
										{ can( 'bkng_view_payments' ) && (
											<td>
												<Badge
													status={ b.payment_status }
													kind="payment"
												/>
											</td>
										) }
										{ can( 'bkng_view_payments' ) && (
											<td className="is-num">
												{ money( b.total, b.currency ) }
											</td>
										) }
										{ can( 'bkng_view_payments' ) && (
											<td className="is-num">
												{ money(
													b.balance,
													b.currency
												) }
											</td>
										) }
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
