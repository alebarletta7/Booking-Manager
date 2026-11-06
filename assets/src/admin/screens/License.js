/**
 * The licence: activation with the key, state as the licence server reports it, updates,
 * release of this site. Until a licence is active this is the only screen of the app.
 */
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { get, post } from '../lib/api';
import { date, dateTime } from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';
import { useProperty } from '../lib/property';
import { navigate } from '../lib/router';
import { boot } from '../lib/boot';
import {
	Card,
	Confirm,
	Details,
	ErrorNotice,
	Loading,
	Page,
	Success,
	Text,
} from '../components/ui';

function Activate( { onDone, hint } ) {
	const [ key, setKey ] = useState( '' );
	const action = useAction();
	return (
		<form
			onSubmit={ async ( e ) => {
				e.preventDefault();
				const res = await action.run( () =>
					post( '/license/activate', { license_key: key.trim() } )
				);
				if ( res ) {
					setKey( '' );
					onDone( res );
				}
			} }
		>
			<ErrorNotice error={ action.error } />
			{ action.error?.data?.sites?.length > 0 && (
				<p className="bkng-help">
					{ __(
						'Siti che usano già la licenza:',
						'booking-manager'
					) }{ ' ' }
					{ action.error.data.sites.join( ', ' ) }
				</p>
			) }
			<div className="bkng-row">
				<div style={ { flex: 1, minWidth: 240 } }>
					<Text
						label={ __( 'Chiave di licenza', 'booking-manager' ) }
						value={ key }
						onChange={ setKey }
						autoComplete="off"
						placeholder={
							hint
								? sprintf(
										/* translators: %s: last characters of the key. */ __(
											'Chiave attuale: …%s',
											'booking-manager'
										),
										hint
								  )
								: ''
						}
						required
					/>
				</div>
				<Button
					variant="primary"
					type="submit"
					isBusy={ action.busy }
					disabled={ action.busy || ! key.trim() }
				>
					{ __( 'Attiva', 'booking-manager' ) }
				</Button>
			</div>
		</form>
	);
}

export default function License() {
	const { setLicense } = useProperty();
	const data = useLoad( () => get( '/license' ), [] );
	const [ notice, setNotice ] = useState( '' );
	const [ confirm, setConfirm ] = useState( false );
	const action = useAction();
	const locked = !! boot.license?.locked;

	const apply = ( fresh, message ) => {
		data.setData( fresh );
		setNotice( message );
		if ( locked && ! fresh.locked ) {
			// First activation: the whole app opens (guided setup if pending).
			setLicense( {
				...boot.license,
				locked: false,
				notice: fresh.notice,
				readOnly: ! fresh.can_write,
			} );
			boot.license = { ...boot.license, locked: false };
			navigate( boot.setupPending ? '/setup' : '/' );
		}
	};

	let body;
	if ( data.loading && ! data.data ) {
		body = <Loading />;
	} else if ( data.error ) {
		body = <ErrorNotice error={ data.error } onRetry={ data.reload } />;
	} else {
		const l = data.data;
		body = (
			<>
				<Success onDismiss={ () => setNotice( '' ) }>
					{ notice }
				</Success>
				<ErrorNotice
					error={ action.error }
					onDismiss={ () => action.setError( null ) }
				/>
				{ ! l.configured && (
					<ErrorNotice
						error={ {
							message: __(
								'Il server delle licenze non è configurato in questa installazione: usa lo zip ufficiale del plugin o chiedi assistenza.',
								'booking-manager'
							),
						} }
					/>
				) }
				{ l.configured && ( ! l.activated || l.locked ) && (
					<Card
						title={
							locked
								? __(
										'Attiva Booking Manager',
										'booking-manager'
								  )
								: __( 'Attiva la licenza', 'booking-manager' )
						}
					>
						<p className="bkng-help">
							{ __(
								"Inserisci la chiave ricevuta con l'acquisto. Il sito si collega al server delle licenze per attivarla; finché non è attiva, prenotazioni e camere non si possono gestire.",
								'booking-manager'
							) }
						</p>
						{ l.activation_error_text && (
							<ErrorNotice
								error={ { message: l.activation_error_text } }
							/>
						) }
						<Activate
							hint={ l.key_hint }
							onDone={ ( res ) =>
								apply(
									res,
									__( 'Licenza attivata.', 'booking-manager' )
								)
							}
						/>
					</Card>
				) }
				{ l.activated && (
					<Card
						title={ __( 'Stato della licenza', 'booking-manager' ) }
						actions={
							<>
								<Button
									variant="secondary"
									isBusy={ action.busy }
									onClick={ async () =>
										( await action.run( () =>
											post( '/license/refresh' )
										) ) && data.reload()
									}
								>
									{ __( 'Controlla ora', 'booking-manager' ) }
								</Button>
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () => setConfirm( true ) }
								>
									{ __(
										'Libera questo sito',
										'booking-manager'
									) }
								</Button>
							</>
						}
					>
						<Details
							rows={ [
								[
									__( 'Stato', 'booking-manager' ),
									<span
										key="s"
										className={
											'bkng-badge is-' +
											( l.can_write ? 'ok' : 'bad' )
										}
									>
										{ l.status_label }
									</span>,
								],
								[
									__( 'Chiave', 'booking-manager' ),
									l.key_hint ? '…' + l.key_hint : '',
								],
								[
									__( 'Piano', 'booking-manager' ),
									l.plan || l.type,
								],
								[
									__( 'Scadenza', 'booking-manager' ),
									l.expires_at
										? date( l.expires_at.slice( 0, 10 ) )
										: __( 'Nessuna', 'booking-manager' ),
								],
								[
									__(
										'Aggiornamenti fino al',
										'booking-manager'
									),
									l.updates_until
										? date( l.updates_until.slice( 0, 10 ) )
										: '',
								],
								[
									__(
										'Assistenza fino al',
										'booking-manager'
									),
									l.support_until
										? date( l.support_until.slice( 0, 10 ) )
										: '',
								],
								[
									__( 'Dominio attivato', 'booking-manager' ),
									l.domain,
								],
								[
									__(
										'Ultima verifica riuscita',
										'booking-manager'
									),
									dateTime( l.last_ok_at ),
								],
								[
									__(
										'Prossima verifica',
										'booking-manager'
									),
									dateTime( l.next_check_at ),
								],
								[
									__(
										'Periodo di tolleranza fino al',
										'booking-manager'
									),
									dateTime( l.grace_until ),
								],
								[
									__( 'Ultimo errore', 'booking-manager' ),
									l.last_error_text,
								],
							] }
						/>
						{ ! l.can_write && l.read_only_reason && (
							<p className="bkng-help">{ l.read_only_reason }</p>
						) }
					</Card>
				) }
				{ l.updates && ! locked && (
					<Card
						title={ __( 'Aggiornamenti', 'booking-manager' ) }
						actions={
							l.activated && (
								<Button
									variant="secondary"
									isBusy={ action.busy }
									onClick={ async () =>
										( await action.run( () =>
											post( '/license/updates/check' )
										) ) && data.reload()
									}
								>
									{ __(
										'Cerca aggiornamenti',
										'booking-manager'
									) }
								</Button>
							)
						}
					>
						<Details
							rows={ [
								[
									__(
										'Versione installata',
										'booking-manager'
									),
									l.updates.version,
								],
								[
									__( 'Ultima versione', 'booking-manager' ),
									l.updates.available
										? l.updates.latest
										: __(
												'Sei aggiornato',
												'booking-manager'
										  ),
								],
								[
									__( 'Ultimo controllo', 'booking-manager' ),
									l.updates.checked_at
										? dateTime( l.updates.checked_at )
										: '',
								],
							] }
						/>
						{ l.updates.available && (
							<p>
								<a
									className="components-button is-primary"
									href={ l.updates.plugins_url }
								>
									{ __(
										'Aggiorna dalla pagina Plugin',
										'booking-manager'
									) }
								</a>
							</p>
						) }
						{ l.updates.renew && (
							<p className="bkng-help">
								{ l.updates.renew.text }
							</p>
						) }
					</Card>
				) }
			</>
		);
	}

	const dialog = confirm && (
		<Confirm
			destructive
			title={ __( 'Libera questo sito', 'booking-manager' ) }
			text={ __(
				'La licenza viene disattivata su questo sito e potrai usarla altrove. Booking Manager si blocca finché non attivi di nuovo una licenza. I dati restano.',
				'booking-manager'
			) }
			confirmLabel={ __( 'Libera', 'booking-manager' ) }
			busy={ action.busy }
			onCancel={ () => setConfirm( false ) }
			onConfirm={ async () => {
				const res = await action.run( () =>
					post( '/license/deactivate' )
				);
				setConfirm( false );
				if ( res ) {
					window.location.reload();
				}
			} }
		/>
	);

	if ( locked ) {
		return (
			<div className="bkng-stack">
				{ body }
				{ dialog }
			</div>
		);
	}
	return (
		<Page title={ __( 'Licenza', 'booking-manager' ) }>
			{ body }
			{ dialog }
		</Page>
	);
}
