/**
 * Site settings: data retention and uninstall, anti-spam for the online form
 * (Cloudflare Turnstile), the Cloudflare panel (how visitors reach the site, recommended
 * rules applied through the API or copied by hand).
 */
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { get, post, put } from '../lib/api';
import { dateTime } from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';
import {
	Card,
	ErrorNotice,
	Grid,
	Loading,
	NumberField,
	Page,
	Success,
	Tabs,
	Text,
	Toggle,
} from '../components/ui';

function General( { data, onSaved } ) {
	const action = useAction();
	const [ s, setS ] = useState( data );
	const [ secret, setSecret ] = useState( '' );
	const set = ( k ) => ( v ) => setS( ( x ) => ( { ...x, [ k ]: v } ) );
	return (
		<form
			onSubmit={ async ( e ) => {
				e.preventDefault();
				const body = {
					delete_data_on_uninstall: s.delete_data_on_uninstall,
					audit_store_ip: s.audit_store_ip,
					guest_retention_months: s.guest_retention_months,
					audit_retention_months: s.audit_retention_months,
					notification_retention_months:
						s.notification_retention_months,
					checkin_retention_months: s.checkin_retention_months,
					captcha: secret
						? { site_key: s.captcha.site_key, secret }
						: { site_key: s.captcha.site_key },
				};
				const res = await action.run( () => put( '/settings', body ) );
				if ( res ) {
					setSecret( '' );
					onSaved( res );
				}
			} }
		>
			<ErrorNotice error={ action.error } />
			<Card title={ __( 'Conservazione dei dati', 'booking-manager' ) }>
				<p className="bkng-help">
					{ __(
						'Mesi dopo i quali i dati vengono cancellati o anonimizzati ogni notte. 0 = conserva sempre.',
						'booking-manager'
					) }
				</p>
				<Grid columns={ 4 }>
					<NumberField
						label={ __(
							'Ospiti senza soggiorni (anonimizza)',
							'booking-manager'
						) }
						min={ 0 }
						value={ s.guest_retention_months }
						onChange={ set( 'guest_retention_months' ) }
					/>
					<NumberField
						label={ __( 'Registro attività', 'booking-manager' ) }
						min={ 0 }
						value={ s.audit_retention_months }
						onChange={ set( 'audit_retention_months' ) }
					/>
					<NumberField
						label={ __( 'Registro messaggi', 'booking-manager' ) }
						min={ 0 }
						value={ s.notification_retention_months }
						onChange={ set( 'notification_retention_months' ) }
					/>
					<NumberField
						label={ __( 'Registro check-in', 'booking-manager' ) }
						min={ 0 }
						value={ s.checkin_retention_months }
						onChange={ set( 'checkin_retention_months' ) }
					/>
				</Grid>
				<div className="bkng-stack">
					<Toggle
						label={ __(
							"Salva l'indirizzo IP (troncato) nel registro attività",
							'booking-manager'
						) }
						checked={ !! s.audit_store_ip }
						onChange={ set( 'audit_store_ip' ) }
					/>
					<Toggle
						label={ __(
							'Cancella tutti i dati quando il plugin viene eliminato',
							'booking-manager'
						) }
						help={ __(
							'Spento (consigliato): eliminando il plugin prenotazioni, ospiti e impostazioni restano nel database.',
							'booking-manager'
						) }
						checked={ !! s.delete_data_on_uninstall }
						onChange={ set( 'delete_data_on_uninstall' ) }
					/>
				</div>
			</Card>
			<Card
				title={ __(
					'Anti-spam del modulo online (Cloudflare Turnstile)',
					'booking-manager'
				) }
			>
				<p className="bkng-help">
					{ s.captcha.enabled
						? __( 'Attivo.', 'booking-manager' )
						: __(
								'Non attivo: il modulo usa solo i controlli interni (tempo di compilazione, limiti per indirizzo).',
								'booking-manager'
						  ) }
				</p>
				<Grid>
					<Text
						label={ __( 'Site key', 'booking-manager' ) }
						value={ s.captcha.site_key || '' }
						onChange={ ( v ) =>
							setS( ( x ) => ( {
								...x,
								captcha: { ...x.captcha, site_key: v },
							} ) )
						}
					/>
					<Text
						label={ __( 'Secret key', 'booking-manager' ) }
						type="password"
						autoComplete="off"
						placeholder={
							s.captcha.secret_set
								? __(
										'Salvata: lascia vuoto per non cambiarla',
										'booking-manager'
								  )
								: ''
						}
						value={ secret }
						onChange={ setSecret }
					/>
				</Grid>
			</Card>
			<Button variant="primary" type="submit" isBusy={ action.busy }>
				{ __( 'Salva', 'booking-manager' ) }
			</Button>
		</form>
	);
}

const MODES = {
	direct: __(
		"Il sito riceve le visite attraverso Cloudflare e legge l'IP reale dei visitatori.",
		'booking-manager'
	),
	restored: __(
		"Il sito è dietro Cloudflare e l'hosting fornisce già l'IP reale: nulla da fare.",
		'booking-manager'
	),
	proxy: __(
		"Ci sono intestazioni di Cloudflare, ma la richiesta passa da un altro proxy: l'IP dei visitatori non è affidabile. Controlla la configurazione dell'hosting.",
		'booking-manager'
	),
	none: __( 'Il sito non sembra passare da Cloudflare.', 'booking-manager' ),
};

function Cloudflare() {
	const view = useLoad( () => get( '/cloudflare' ), [] );
	const [ zone, setZone ] = useState( null );
	const [ token, setToken ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );
	const [ verified, setVerified ] = useState( null );
	const action = useAction();

	if ( view.loading && ! view.data ) {
		return <Loading />;
	}
	if ( view.error ) {
		return <ErrorNotice error={ view.error } onRetry={ view.reload } />;
	}
	const v = view.data;
	const zoneValue = zone ?? v.zone_id ?? '';

	return (
		<>
			<Success onDismiss={ () => setNotice( '' ) }>{ notice }</Success>
			<ErrorNotice
				error={ action.error }
				onDismiss={ () => action.setError( null ) }
			/>
			<Card title={ __( 'Collegamento del sito', 'booking-manager' ) }>
				<p>{ MODES[ v.mode ] }</p>
				<p className="bkng-small bkng-muted">
					{ sprintf(
						/* translators: 1: address seen by the server, 2: visitor address. */ __(
							'Indirizzo che arriva al server: %1$s · indirizzo del visitatore: %2$s',
							'booking-manager'
						),
						v.remote || '—',
						v.visitor || '—'
					) }
				</p>
			</Card>
			<Card
				title={ __(
					'API di Cloudflare (facoltativa)',
					'booking-manager'
				) }
			>
				<p className="bkng-help">
					{ __(
						'Con un token API il plugin crea da solo le regole consigliate. Permessi del token:',
						'booking-manager'
					) }
				</p>
				<ul
					className="bkng-small"
					style={ { listStyle: 'disc', marginLeft: 18 } }
				>
					{ v.token_permissions.map( ( p ) => (
						<li key={ p }>{ p }</li>
					) ) }
				</ul>
				{ v.api_token_lost && (
					<ErrorNotice
						error={ {
							message: __(
								'Il token salvato non è più leggibile (sono cambiate le chiavi di WordPress): inseriscilo di nuovo.',
								'booking-manager'
							),
						} }
					/>
				) }
				<form
					onSubmit={ async ( e ) => {
						e.preventDefault();
						const body = { zone_id: zoneValue };
						if ( token ) {
							body.api_token = token;
						}
						const res = await action.run( () =>
							put( '/cloudflare', body )
						);
						if ( res ) {
							setToken( '' );
							setZone( null );
							setNotice(
								__(
									'Dati di Cloudflare salvati.',
									'booking-manager'
								)
							);
							view.reload();
						}
					} }
				>
					<Grid>
						<Text
							label={ __( 'Zone ID', 'booking-manager' ) }
							value={ zoneValue }
							onChange={ setZone }
							disabled={ v.zone_id_source === 'wp-config' }
							help={
								v.zone_id_source === 'wp-config'
									? __(
											'Definito in wp-config.php',
											'booking-manager'
									  )
									: ''
							}
						/>
						<Text
							label={ __( 'Token API', 'booking-manager' ) }
							type="password"
							autoComplete="off"
							value={ token }
							onChange={ setToken }
							disabled={ v.api_token_source === 'wp-config' }
							placeholder={
								v.api_token_hint
									? sprintf(
											/* translators: %s: last characters of the token. */ __(
												'Salvato (%s): lascia vuoto per non cambiarlo',
												'booking-manager'
											),
											v.api_token_hint
									  )
									: ''
							}
						/>
					</Grid>
					<div className="bkng-page__actions">
						<Button
							variant="primary"
							type="submit"
							isBusy={ action.busy }
						>
							{ __( 'Salva', 'booking-manager' ) }
						</Button>
						{ v.api_ready && (
							<Button
								variant="secondary"
								onClick={ async () => {
									const res = await action.run( () =>
										post( '/cloudflare/verify' )
									);
									setVerified( res );
								} }
							>
								{ __(
									'Verifica collegamento',
									'booking-manager'
								) }
							</Button>
						) }
						{ v.api_ready && (
							<Button
								variant="secondary"
								onClick={ async () => {
									const res = await action.run( () =>
										post( '/cloudflare/rules' )
									);
									if ( res ) {
										setNotice(
											__(
												'Regole consigliate applicate.',
												'booking-manager'
											)
										);
										view.reload();
									}
								} }
							>
								{ __(
									'Applica le regole consigliate',
									'booking-manager'
								) }
							</Button>
						) }
						{ v.api_token_hint &&
							v.api_token_source !== 'wp-config' && (
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () =>
										action
											.run( () =>
												put( '/cloudflare', {
													clear_api_token: true,
												} )
											)
											.then( ( r ) => r && view.reload() )
									}
								>
									{ __(
										'Cancella il token',
										'booking-manager'
									) }
								</Button>
							) }
					</div>
				</form>
				{ verified && verified.zone && (
					<p className="bkng-help">
						{ sprintf(
							/* translators: 1: zone, 2: plan, 3: status. */ __(
								'Zona %1$s (%2$s), stato %3$s.',
								'booking-manager'
							),
							verified.zone.name,
							verified.zone.plan,
							verified.zone.status
						) }{ ' ' }
						{ verified.host_in_zone
							? ''
							: __(
									'Attenzione: il dominio del sito non appartiene a questa zona.',
									'booking-manager'
							  ) }
					</p>
				) }
				{ v.rules_applied_at && (
					<p className="bkng-small bkng-muted">
						{ sprintf(
							/* translators: %s: date. */ __(
								'Regole applicate il %s.',
								'booking-manager'
							),
							dateTime( v.rules_applied_at )
						) }
					</p>
				) }
			</Card>
			<Card title={ __( 'Regole consigliate', 'booking-manager' ) }>
				<p className="bkng-help">
					{ __(
						"Se preferisci crearle a mano nel pannello di Cloudflare, copia queste espressioni. Dopo aver collegato un nuovo account Stripe o Nexi, applica di nuovo le regole: l'indirizzo del webhook cambia.",
						'booking-manager'
					) }
				</p>
				{ v.rules.map( ( r ) => (
					<div key={ r.kind } style={ { marginBottom: 16 } }>
						<p className="bkng-strong">{ r.title }</p>
						<p className="bkng-small">{ r.action }</p>
						<div className="bkng-code">{ r.expression }</div>
					</div>
				) ) }
			</Card>
		</>
	);
}

export default function Settings() {
	const data = useLoad( () => get( '/settings' ), [] );
	const [ tab, setTab ] = useState( 'general' );
	const [ notice, setNotice ] = useState( '' );
	return (
		<Page title={ __( 'Impostazioni', 'booking-manager' ) }>
			<Tabs
				current={ tab }
				onChange={ setTab }
				tabs={ [
					[ 'general', __( 'Generali', 'booking-manager' ) ],
					[ 'cloudflare', __( 'Cloudflare', 'booking-manager' ) ],
				] }
			/>
			<Success onDismiss={ () => setNotice( '' ) }>{ notice }</Success>
			{ tab === 'general' && data.loading && <Loading /> }
			{ tab === 'general' && (
				<ErrorNotice error={ data.error } onRetry={ data.reload } />
			) }
			{ tab === 'general' && data.data && (
				<General
					data={ data.data }
					onSaved={ ( fresh ) => {
						data.setData( fresh );
						setNotice(
							__( 'Impostazioni salvate.', 'booking-manager' )
						);
					} }
				/>
			) }
			{ tab === 'cloudflare' && <Cloudflare /> }
		</Page>
	);
}
