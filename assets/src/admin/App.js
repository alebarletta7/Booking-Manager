/**
 * The shell: licence gate, guided setup, navigation, property switcher, routes.
 */
import { Button, Notice, SelectControl } from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	brush as brushIcon,
	calendar as calendarIcon,
	category as categoryIcon,
	check as checkIcon,
	currencyEuro as currencyEuroIcon,
	fullscreen as fullscreenIcon,
	globe as globeIcon,
	home as homeIcon,
	inbox as inboxIcon,
	key as keyIcon,
	listView as listViewIcon,
	arrowLeft as logoutIcon,
	people as peopleIcon,
	seen as seenIcon,
	settings as settingsIcon,
	shield as shieldIcon,
	store as storeIcon,
	commentContent as whatsappIcon,
	Icon,
} from '@wordpress/icons';

import { get, put } from './lib/api';
import { boot, can } from './lib/boot';
import { PropertyContext } from './lib/property';
import { match, navigate, useRoute } from './lib/router';
import { Empty } from './components/ui';

import Audit from './screens/Audit';
import BookingDetail from './screens/BookingDetail';
import Bookings from './screens/Bookings';
import Channex from './screens/Channex';
import Dashboard from './screens/Dashboard';
import FrontDesk from './screens/FrontDesk';
import GuestDetail from './screens/GuestDetail';
import Guests from './screens/Guests';
import Housekeeping from './screens/Housekeeping';
import License from './screens/License';
import Messages from './screens/Messages';
import NewBooking from './screens/NewBooking';
import Planning from './screens/Planning';
import PropertySettings from './screens/PropertySettings';
import Rates from './screens/Rates';
import Rooms from './screens/Rooms';
import Settings from './screens/Settings';
import Setup from './screens/Setup';
import Staff from './screens/Staff';
import WhatsApp from './screens/WhatsApp';

// Grouped like the menus of the other plugins of the family.
const NAV = [
	{
		label: '',
		items: [
			{
				path: '/',
				label: __( 'Dashboard', 'booking-manager' ),
				cap: 'bkng_view_dashboard',
				icon: homeIcon,
			},
			{
				path: '/planning',
				label: __( 'Planning', 'booking-manager' ),
				cap: 'bkng_view_bookings',
				icon: calendarIcon,
			},
			{
				path: '/bookings',
				label: __( 'Prenotazioni', 'booking-manager' ),
				cap: 'bkng_view_bookings',
				icon: listViewIcon,
			},
			{
				path: '/frontdesk',
				label: __( 'Check-in / out', 'booking-manager' ),
				cap: 'bkng_checkin',
				icon: checkIcon,
			},
			{
				path: '/housekeeping',
				label: __( 'Housekeeping', 'booking-manager' ),
				cap: 'bkng_view_housekeeping',
				icon: brushIcon,
				housekeeping: true,
			},
			{
				path: '/guests',
				label: __( 'Ospiti', 'booking-manager' ),
				cap: 'bkng_view_guests',
				icon: peopleIcon,
			},
		],
	},
	{
		label: __( 'Gestione', 'booking-manager' ),
		items: [
			{
				path: '/rooms',
				label: __( 'Camere', 'booking-manager' ),
				cap: 'bkng_manage_rooms',
				icon: categoryIcon,
			},
			{
				path: '/rates',
				label: __( 'Tariffe', 'booking-manager' ),
				cap: 'bkng_manage_rates',
				icon: currencyEuroIcon,
			},
			{
				path: '/messages',
				label: __( 'Email', 'booking-manager' ),
				cap: 'bkng_view_bookings',
				icon: inboxIcon,
			},
			{
				path: '/whatsapp',
				label: __( 'WhatsApp', 'booking-manager' ),
				cap: 'bkng_manage_settings',
				icon: whatsappIcon,
			},
			{
				path: '/channex',
				label: __( 'Channex', 'booking-manager' ),
				cap: 'bkng_manage_integrations',
				icon: globeIcon,
			},
		],
	},
	{
		label: __( 'Amministrazione', 'booking-manager' ),
		items: [
			{
				path: '/property',
				label: __( 'Struttura', 'booking-manager' ),
				cap: 'bkng_manage_property_settings',
				icon: storeIcon,
			},
			{
				path: '/staff',
				label: __( 'Staff e permessi', 'booking-manager' ),
				cap: 'bkng_manage_staff',
				icon: shieldIcon,
			},
			{
				path: '/audit',
				label: __( 'Registro attività', 'booking-manager' ),
				cap: 'bkng_view_audit_log',
				icon: seenIcon,
			},
			{
				path: '/settings',
				label: __( 'Impostazioni', 'booking-manager' ),
				cap: 'bkng_manage_settings',
				icon: settingsIcon,
			},
		],
	},
];

const ROUTES = [
	[ '/', Dashboard ],
	[ '/planning', Planning ],
	[ '/bookings', Bookings ],
	[ '/bookings/new', NewBooking ],
	[ '/bookings/:id', BookingDetail ],
	[ '/frontdesk', FrontDesk ],
	[ '/housekeeping', Housekeeping ],
	[ '/guests', Guests ],
	[ '/guests/:id', GuestDetail ],
	[ '/rooms', Rooms ],
	[ '/rates', Rates ],
	[ '/property', PropertySettings ],
	[ '/messages', Messages ],
	[ '/whatsapp', WhatsApp ],
	[ '/channex', Channex ],
	[ '/staff', Staff ],
	[ '/audit', Audit ],
	[ '/settings', Settings ],
	[ '/license', License ],
	[ '/setup', Setup ],
];

function resolve( path ) {
	for ( const [ pattern, Screen ] of ROUTES ) {
		const params = match( pattern, path );
		if ( params ) {
			return { Screen, params };
		}
	}
	return null;
}

function LicenseBand( { notice } ) {
	if ( ! notice ) {
		return null;
	}
	return (
		<Notice
			status={ notice.tone === 'warn' ? 'warning' : notice.tone }
			isDismissible={ false }
			className="bkng-band"
		>
			{ notice.text }{ ' ' }
			{ notice.settings && (
				<Button variant="link" onClick={ () => navigate( '/license' ) }>
					{ __( 'Apri Licenza', 'booking-manager' ) }
				</Button>
			) }
		</Notice>
	);
}

export default function App() {
	const route = useRoute();
	const [ me, setMe ] = useState(
		boot.me || { properties: [], preferences: {}, capabilities: [] }
	);
	const [ license, setLicense ] = useState( boot.license || {} );
	const [ menuOpen, setMenuOpen ] = useState( false );

	const properties = useMemo( () => me.properties || [], [ me.properties ] );
	const propertyId =
		me.preferences?.property_id || properties[ 0 ]?.id || null;
	const property = properties.find( ( p ) => p.id === propertyId ) || null;

	const refreshMe = useCallback( async () => {
		const fresh = await get( '/me' );
		boot.me = fresh;
		setMe( fresh );
		return fresh;
	}, [] );

	const select = useCallback( async ( id ) => {
		setMe( ( m ) => ( {
			...m,
			preferences: { ...m.preferences, property_id: id },
		} ) );
		try {
			await put( '/me/preferences', { property_id: id } );
		} catch ( e ) {
			// The choice still holds for this page.
		}
	}, [] );

	const ctx = useMemo(
		() => ( {
			property,
			properties,
			select,
			refreshMe,
			license,
			setLicense,
		} ),
		[ property, properties, select, refreshMe, license ]
	);

	useEffect( () => {
		if ( boot.setupPending && ! license.locked && route.path === '/' ) {
			navigate( '/setup' );
		}
		// Only on the first load.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	useEffect( () => setMenuOpen( false ), [ route.path ] );

	// Licence not active: only the licence screen (or a message for staff).
	if ( license.locked ) {
		return (
			<PropertyContext.Provider value={ ctx }>
				<div className="bkng-app is-locked">
					<div className="bkng-locked">
						<div className="bkng-brand">
							<span className="bkng-brand__mark">B</span> Booking
							Manager
						</div>
						{ license.canManage ? (
							<License />
						) : (
							<Empty
								title={ __(
									'Booking Manager non è ancora attivo',
									'booking-manager'
								) }
								text={ license.lockedText }
							/>
						) }
					</div>
				</div>
			</PropertyContext.Provider>
		);
	}

	const visible = ( item ) =>
		can( item.cap ) &&
		( ! item.housekeeping || ! property || property.housekeeping );
	const groups = NAV.map( ( group, i ) => ( {
		...group,
		items: [
			...group.items.filter( visible ),
			// The licence is the last entry of Amministrazione, before Impostazioni.
			...( i === NAV.length - 1 && license.canManage
				? [
						{
							path: '/license',
							label: __( 'Licenza', 'booking-manager' ),
							icon: keyIcon,
						},
				  ]
				: [] ),
		],
	} ) ).filter( ( group ) => group.items.length );
	groups.forEach( ( group ) => {
		const settings = group.items.findIndex(
			( item ) => item.path === '/settings'
		);
		if ( settings > -1 && settings < group.items.length - 1 ) {
			group.items.push( ...group.items.splice( settings, 1 ) );
		}
	} );
	const found = resolve( route.path );
	const needsProperty =
		found &&
		! [ '/property', '/staff', '/settings', '/license', '/setup' ].includes(
			route.path
		);
	const operational = !! me.preferences?.operational_mode;

	let content;
	if ( ! found ) {
		content = (
			<Empty
				title={ __( 'Pagina non trovata', 'booking-manager' ) }
				text={ __(
					'Usa il menu per scegliere una sezione.',
					'booking-manager'
				) }
			/>
		);
	} else if ( needsProperty && ! property ) {
		content = (
			<Empty
				title={ __( 'Nessuna struttura', 'booking-manager' ) }
				text={
					can( 'bkng_manage_properties' )
						? __(
								'Crea la prima struttura per iniziare.',
								'booking-manager'
						  )
						: __(
								"Non sei assegnato a nessuna struttura: chiedi all'amministratore.",
								'booking-manager'
						  )
				}
			>
				{ can( 'bkng_manage_properties' ) && (
					<Button
						variant="primary"
						onClick={ () =>
							navigate(
								license.canManage ? '/setup' : '/property'
							)
						}
					>
						{ __( 'Crea la struttura', 'booking-manager' ) }
					</Button>
				) }
			</Empty>
		);
	} else {
		const { Screen, params } = found;
		content = (
			<Screen
				key={ route.path + ( property ? property.id : '' ) }
				params={ params }
				query={ route.params }
			/>
		);
	}

	return (
		<PropertyContext.Provider value={ ctx }>
			<div className={ 'bkng-app' + ( menuOpen ? ' is-menu-open' : '' ) }>
				<aside
					className="bkng-sidebar"
					aria-label={ __(
						'Menu di Booking Manager',
						'booking-manager'
					) }
				>
					<div className="bkng-brand">
						<span className="bkng-brand__mark" aria-hidden="true">
							B
						</span>
						<span className="bkng-brand__name">
							Booking Manager
						</span>
					</div>
					<nav className="bkng-nav">
						{ groups.map( ( group ) => (
							<div
								className="bkng-nav__group"
								key={ group.label || 'main' }
							>
								{ group.label && (
									<p className="bkng-nav__group-label">
										{ group.label }
									</p>
								) }
								<ul>
									{ group.items.map( ( item ) => {
										const active =
											item.path === '/'
												? route.path === '/'
												: route.path === item.path ||
												  route.path.startsWith(
														item.path + '/'
												  );
										return (
											<li key={ item.path }>
												<a
													href={ '#' + item.path }
													className={
														'bkng-nav__item' +
														( active
															? ' is-current'
															: '' )
													}
													aria-current={
														active
															? 'page'
															: undefined
													}
												>
													<Icon
														icon={ item.icon }
														size={ 20 }
													/>
													{ item.label }
												</a>
											</li>
										);
									} ) }
								</ul>
							</div>
						) ) }
					</nav>
					<div className="bkng-sidebar__foot">
						{ ! me.preferences?.operational_locked && (
							<button
								type="button"
								className="bkng-nav__item"
								onClick={ async () => {
									await put( '/me/preferences', {
										operational_mode: ! operational,
									} );
									window.location.reload();
								} }
							>
								<Icon icon={ fullscreenIcon } size={ 20 } />
								{ operational
									? __(
											'Esci da modalità operativa',
											'booking-manager'
									  )
									: __(
											'Modalità operativa',
											'booking-manager'
									  ) }
							</button>
						) }
						{ operational && (
							<a
								className="bkng-nav__item"
								href={ boot.logoutUrl }
							>
								<Icon icon={ logoutIcon } size={ 20 } />
								{ __( 'Esci', 'booking-manager' ) }
							</a>
						) }
						{ boot.version && (
							<p className="bkng-version">v{ boot.version }</p>
						) }
					</div>
				</aside>
				<div className="bkng-main">
					<div className="bkng-topbar">
						<Button
							className="bkng-topbar__menu"
							icon="menu"
							label={ __( 'Menu', 'booking-manager' ) }
							onClick={ () => setMenuOpen( ! menuOpen ) }
						/>
						{ properties.length > 1 ? (
							<SelectControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								className="bkng-topbar__property"
								label={ __( 'Struttura', 'booking-manager' ) }
								hideLabelFromVision
								value={ String( propertyId || '' ) }
								options={ properties.map( ( p ) => ( {
									value: String( p.id ),
									label: p.name,
								} ) ) }
								onChange={ ( v ) => select( Number( v ) ) }
							/>
						) : (
							<span className="bkng-topbar__name">
								{ property ? property.name : '' }
							</span>
						) }
						{ can( 'bkng_create_booking' ) && property && (
							<Button
								variant="primary"
								onClick={ () => navigate( '/bookings/new' ) }
							>
								{ __(
									'Nuova prenotazione',
									'booking-manager'
								) }
							</Button>
						) }
						{ me.display_name && (
							<span className="bkng-topbar__user">
								<span
									className="bkng-topbar__avatar"
									aria-hidden="true"
								>
									{ me.display_name
										.charAt( 0 )
										.toUpperCase() }
								</span>
								{ me.display_name }
							</span>
						) }
					</div>
					<LicenseBand notice={ license.notice } />
					<main className="bkng-content">{ content }</main>
				</div>
			</div>
		</PropertyContext.Provider>
	);
}
