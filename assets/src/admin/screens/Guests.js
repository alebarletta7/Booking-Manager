/**
 * Guest cards of the properties the user works in: search by name, e-mail or phone.
 */
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { get } from '../lib/api';
import { useLoad } from '../lib/hooks';
import { navigate } from '../lib/router';
import {
	Card,
	Empty,
	ErrorNotice,
	Loading,
	Page,
	Text,
} from '../components/ui';

const PER_PAGE = 30;

export default function Guests() {
	const [ text, setText ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const list = useLoad(
		() => get( '/guests', { search, page, per_page: PER_PAGE } ),
		[ search, page ]
	);

	return (
		<Page title={ __( 'Ospiti', 'booking-manager' ) }>
			<form
				className="bkng-filters"
				onSubmit={ ( e ) => {
					e.preventDefault();
					setSearch( text.trim() );
					setPage( 1 );
				} }
			>
				<div className="bkng-filters__search">
					<Text
						label={ __( 'Cerca', 'booking-manager' ) }
						placeholder={ __(
							'Nome, email o telefono',
							'booking-manager'
						) }
						value={ text }
						onChange={ setText }
					/>
				</div>
				<Button variant="secondary" type="submit">
					{ __( 'Cerca', 'booking-manager' ) }
				</Button>
			</form>
			<Card className="is-flush">
				{ list.loading && <Loading /> }
				<ErrorNotice error={ list.error } onRetry={ list.reload } />
				{ list.data && ! list.data.length && (
					<Empty
						title={ __( 'Nessun ospite', 'booking-manager' ) }
						text={
							search
								? __(
										'Nessun ospite corrisponde alla ricerca.',
										'booking-manager'
								  )
								: __(
										'Le schede si creano da sole con le prenotazioni.',
										'booking-manager'
								  )
						}
					/>
				) }
				{ list.data && list.data.length > 0 && (
					<div className="bkng-table-wrap">
						<table className="bkng-table">
							<thead>
								<tr>
									<th>
										{ __( 'Ospite', 'booking-manager' ) }
									</th>
									<th>
										{ __( 'Email', 'booking-manager' ) }
									</th>
									<th>
										{ __( 'Telefono', 'booking-manager' ) }
									</th>
									<th>
										{ __( 'Paese', 'booking-manager' ) }
									</th>
									<th className="is-num">
										{ __( 'Soggiorni', 'booking-manager' ) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ list.data.map( ( g ) => (
									<tr
										key={ g.id }
										className="is-link"
										onClick={ () =>
											navigate( '/guests/' + g.id )
										}
									>
										<td>
											<a
												href={ '#/guests/' + g.id }
												onClick={ ( e ) =>
													e.stopPropagation()
												}
											>
												{ g.first_name } { g.last_name }
											</a>
										</td>
										<td>{ g.email }</td>
										<td>{ g.phone }</td>
										<td>{ g.country }</td>
										<td className="is-num">
											{ g.stays_count }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
				{ list.data &&
					( page > 1 || list.data.length === PER_PAGE ) && (
						<div className="bkng-pager">
							<Button
								variant="secondary"
								disabled={ page <= 1 }
								onClick={ () => setPage( page - 1 ) }
							>
								{ __( 'Precedenti', 'booking-manager' ) }
							</Button>
							<span>{ page }</span>
							<Button
								variant="secondary"
								disabled={ list.data.length < PER_PAGE }
								onClick={ () => setPage( page + 1 ) }
							>
								{ __( 'Successivi', 'booking-manager' ) }
							</Button>
						</div>
					) }
			</Card>
		</Page>
	);
}
