/**
 * A guest card: contacts, stays, consents, possible duplicates; data export and
 * anonymization (GDPR) for who may do it.
 */
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { get, post, put } from '../lib/api';
import { can } from '../lib/boot';
import { date, money } from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';
import { navigate } from '../lib/router';
import {
	Badge,
	Card,
	Confirm,
	Details,
	ErrorNotice,
	FormModal,
	Grid,
	Loading,
	Page,
	Success,
	Text,
	Textarea,
} from '../components/ui';

const PURPOSES = {
	privacy: __( 'Informativa privacy', 'booking-manager' ),
	marketing: __( 'Comunicazioni promozionali', 'booking-manager' ),
	terms: __( 'Condizioni di prenotazione', 'booking-manager' ),
};

function EditModal( { guest, onClose, onDone } ) {
	const action = useAction();
	const [ form, setForm ] = useState( {
		first_name: guest.first_name,
		last_name: guest.last_name,
		email: guest.email || '',
		phone: guest.phone || '',
		country: guest.country || '',
		language: guest.language || '',
		notes: guest.notes || '',
	} );
	const set = ( k ) => ( v ) => setForm( ( f ) => ( { ...f, [ k ]: v } ) );
	return (
		<FormModal
			title={ __( 'Modifica ospite', 'booking-manager' ) }
			onClose={ onClose }
			busy={ action.busy }
			error={ action.error }
			onSubmit={ async () => {
				const res = await action.run( () =>
					put( '/guests/' + guest.id, {
						...form,
						version: guest.version,
					} )
				);
				if ( res ) {
					onDone();
				}
			} }
		>
			<Grid>
				<Text
					label={ __( 'Nome', 'booking-manager' ) }
					value={ form.first_name }
					onChange={ set( 'first_name' ) }
					required
				/>
				<Text
					label={ __( 'Cognome', 'booking-manager' ) }
					value={ form.last_name }
					onChange={ set( 'last_name' ) }
					required
				/>
				<Text
					label={ __( 'Email', 'booking-manager' ) }
					type="email"
					value={ form.email }
					onChange={ set( 'email' ) }
				/>
				<Text
					label={ __( 'Telefono', 'booking-manager' ) }
					value={ form.phone }
					onChange={ set( 'phone' ) }
				/>
				<Text
					label={ __( 'Paese (sigla, es. IT)', 'booking-manager' ) }
					value={ form.country }
					maxLength={ 2 }
					onChange={ set( 'country' ) }
				/>
				<Text
					label={ __( 'Lingua (es. it)', 'booking-manager' ) }
					value={ form.language }
					onChange={ set( 'language' ) }
				/>
			</Grid>
			<Textarea
				label={ __( "Note sull'ospite", 'booking-manager' ) }
				value={ form.notes }
				onChange={ set( 'notes' ) }
			/>
		</FormModal>
	);
}

export default function GuestDetail( { params } ) {
	const guest = useLoad( () => get( '/guests/' + params.id ), [ params.id ] );
	const [ modal, setModal ] = useState( null );
	const [ notice, setNotice ] = useState( '' );
	const action = useAction();

	if ( guest.loading ) {
		return <Loading />;
	}
	if ( guest.error ) {
		return <ErrorNotice error={ guest.error } onRetry={ guest.reload } />;
	}
	const g = guest.data;

	const exportData = async () => {
		const data = await action.run( () =>
			get( '/guests/' + g.id + '/export' )
		);
		if ( data ) {
			const blob = new window.Blob( [ JSON.stringify( data, null, 2 ) ], {
				type: 'application/json',
			} );
			const url = window.URL.createObjectURL( blob );
			const a = document.createElement( 'a' );
			a.href = url;
			a.download = 'ospite-' + g.uuid + '.json';
			a.click();
			window.URL.revokeObjectURL( url );
		}
	};

	return (
		<Page
			title={ g.first_name + ' ' + g.last_name }
			subtitle={
				g.anonymized
					? __( 'Scheda anonimizzata', 'booking-manager' )
					: null
			}
			actions={
				<>
					<Button
						variant="tertiary"
						onClick={ () => navigate( '/guests' ) }
					>
						{ __( 'Elenco', 'booking-manager' ) }
					</Button>
					{ can( 'bkng_edit_guest' ) && ! g.anonymized && (
						<Button
							variant="secondary"
							onClick={ () => setModal( 'edit' ) }
						>
							{ __( 'Modifica', 'booking-manager' ) }
						</Button>
					) }
					{ can( 'bkng_export_guest_data' ) &&
						can( 'bkng_access_all_properties' ) && (
							<Button
								variant="secondary"
								onClick={ exportData }
								isBusy={ action.busy }
							>
								{ __(
									'Esporta i dati (GDPR)',
									'booking-manager'
								) }
							</Button>
						) }
					{ can( 'bkng_anonymize_guest' ) &&
						can( 'bkng_access_all_properties' ) &&
						! g.anonymized && (
							<Button
								variant="secondary"
								isDestructive
								onClick={ () => setModal( 'anonymize' ) }
							>
								{ __( 'Anonimizza', 'booking-manager' ) }
							</Button>
						) }
				</>
			}
		>
			<Success onDismiss={ () => setNotice( '' ) }>{ notice }</Success>
			<ErrorNotice
				error={ action.error }
				onDismiss={ () => action.setError( null ) }
			/>
			<div className="bkng-columns">
				<div>
					<Card
						title={ __( 'Soggiorni', 'booking-manager' ) }
						className="is-flush"
					>
						<div className="bkng-table-wrap">
							<table className="bkng-table">
								<thead>
									<tr>
										<th>
											{ __(
												'Codice',
												'booking-manager'
											) }
										</th>
										<th>
											{ __( 'Date', 'booking-manager' ) }
										</th>
										<th>
											{ __( 'Stato', 'booking-manager' ) }
										</th>
										{ can( 'bkng_view_payments' ) && (
											<th className="is-num">
												{ __(
													'Totale',
													'booking-manager'
												) }
											</th>
										) }
									</tr>
								</thead>
								<tbody>
									{ g.bookings.map( ( b ) => (
										<tr
											key={ b.id }
											className="is-link"
											onClick={ () =>
												navigate( '/bookings/' + b.id )
											}
										>
											<td>
												<a
													href={
														'#/bookings/' + b.id
													}
												>
													{ b.code }
												</a>
											</td>
											<td className="bkng-nowrap">
												{ date( b.arrival ) } →{ ' ' }
												{ date( b.departure ) }
											</td>
											<td>
												<Badge status={ b.status } />
											</td>
											{ can( 'bkng_view_payments' ) && (
												<td className="is-num">
													{ money(
														b.total,
														b.currency
													) }
												</td>
											) }
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					</Card>
					{ g.duplicates.length > 0 && (
						<Card
							title={ __(
								'Possibili doppioni',
								'booking-manager'
							) }
						>
							<p className="bkng-help">
								{ __(
									'Schede con la stessa email o lo stesso telefono.',
									'booking-manager'
								) }
							</p>
							<ul className="bkng-list">
								{ g.duplicates.map( ( d ) => (
									<li key={ d.id }>
										<a href={ '#/guests/' + d.id }>
											{ d.first_name } { d.last_name }
										</a>
										<span className="bkng-muted bkng-small">
											{ d.email || d.phone }
										</span>
									</li>
								) ) }
							</ul>
						</Card>
					) }
				</div>
				<div>
					<Card title={ __( 'Contatti', 'booking-manager' ) }>
						<Details
							rows={ [
								[ __( 'Email', 'booking-manager' ), g.email ],
								[
									__( 'Telefono', 'booking-manager' ),
									g.phone,
								],
								[ __( 'Paese', 'booking-manager' ), g.country ],
								[
									__( 'Lingua', 'booking-manager' ),
									g.language,
								],
								[
									__( 'Soggiorni', 'booking-manager' ),
									String( g.stays_count ),
								],
								[ __( 'Note', 'booking-manager' ), g.notes ],
							] }
						/>
					</Card>
					{ g.consents.length > 0 && (
						<Card title={ __( 'Consensi', 'booking-manager' ) }>
							<ul className="bkng-list">
								{ g.consents.map( ( c, i ) => (
									<li key={ i }>
										<div className="bkng-list__main">
											{ PURPOSES[ c.purpose ] ||
												c.purpose }
										</div>
										<span className="bkng-small bkng-muted">
											{ Number( c.granted )
												? __(
														'dato',
														'booking-manager'
												  )
												: __(
														'negato',
														'booking-manager'
												  ) }{ ' ' }
											·{ ' ' }
											{ date(
												String( c.created_at ).slice(
													0,
													10
												)
											) }
										</span>
									</li>
								) ) }
							</ul>
						</Card>
					) }
				</div>
			</div>
			{ modal === 'edit' && (
				<EditModal
					guest={ g }
					onClose={ () => setModal( null ) }
					onDone={ () => {
						setModal( null );
						setNotice( __( 'Scheda salvata.', 'booking-manager' ) );
						guest.reload();
					} }
				/>
			) }
			{ modal === 'anonymize' && (
				<Confirm
					destructive
					title={ __( "Anonimizza l'ospite", 'booking-manager' ) }
					text={ __(
						'Nome, contatti e note vengono cancellati da scheda, prenotazioni e messaggi. Restano solo date e importi per la contabilità. Non si può annullare.',
						'booking-manager'
					) }
					confirmLabel={ __( 'Anonimizza', 'booking-manager' ) }
					busy={ action.busy }
					onCancel={ () => setModal( null ) }
					onConfirm={ async () => {
						const ok = await action.run( () =>
							post( '/guests/' + g.id + '/anonymize' )
						);
						setModal( null );
						if ( ok ) {
							navigate( '/guests' );
						}
					} }
				/>
			) }
		</Page>
	);
}
