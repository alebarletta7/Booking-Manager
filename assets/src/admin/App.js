/**
 * The shell: licence gate, guided setup, navigation, property switcher, routes.
 */
import { Button, Notice, SelectControl } from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { get, put } from './lib/api';
import { boot, can } from './lib/boot';
import { PropertyContext } from './lib/property';
import { match, navigate, useRoute } from './lib/router';
import { Empty } from './components/ui';

import Audit from './screens/Audit';
import BookingDetail from './screens/BookingDetail';
import Bookings from './screens/Bookings';
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

const NAV = [
	{
		path: '/',
		label: __( 'Dashboard', 'booking-manager' ),
		cap: 'bkng_view_dashboard',
		icon: '◧',
	},
	{
		path: '/planning',
		label: __( 'Planning', 'booking-manager' ),
		cap: 'bkng_view_bookings',
		icon: '▦',
	},
	{
		path: '/bookings',
		label: __( 'Prenotazioni', 'booking-manager' ),
		cap: 'bkng_view_bookings',
		icon: '☰',
	},
	{
		path: '/frontdesk',
		label: __( 'Reception', 'booking-manager' ),
		cap: 'bkng_checkin',
		icon: '⌂',
	},
	{
		path: '/housekeeping',
		label: __( 'Housekeeping', 'booking-manager' ),
		cap: 'bkng_view_housekeeping',
		icon: '✓',
		housekeeping: true,
	},
	{
		path: '/guests',
		label: __( 'Ospiti', 'booking-manager' ),
		cap: 'bkng_view_guests',
		icon: '☺',
	},
	{
		path: '/rooms',
		label: __( 'Camere', 'booking-manager' ),
		cap: 'bkng_manage_rooms',
		icon: '▭',
	},
	{
		path: '/rates',
		label: __( 'Tariffe', 'booking-manager' ),
		cap: 'bkng_manage_rates',
		icon: '€',
	},
	{
		path: '/property',
		label: __( 'Struttura', 'booking-manager' ),
		cap: 'bkng_manage_property_settings',
		icon: '⚑',
	},
	{
		path: '/messages',
		label: __( 'Messaggi', 'booking-manager' ),
		cap: 'bkng_view_bookings',
		icon: '✉',
	},
	{
		path: '/staff',
		label: __( 'Staff', 'booking-manager' ),
		cap: 'bkng_manage_staff',
		icon: '♟',
	},
	{
		path: '/audit',
		label: __( 'Registro attività', 'booking-manager' ),
		cap: 'bkng_view_audit_log',
		icon: '⧗',
	},
	{
		path: '/settings',
		label: __( 'Impostazioni', 'booking-manager' ),
		cap: 'bkng_manage_settings',
		icon: '⚙',
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

	const nav = NAV.filter(
		( item ) =>
			can( item.cap ) &&
			( ! item.housekeeping || ! property || property.housekeeping )
	);
	if ( license.canManage ) {
		nav.push( {
			path: '/license',
			label: __( 'Licenza', 'booking-manager' ),
			icon: '🔑',
		} );
	}
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
						<span className="bkng-brand__mark">B</span> Booking
						Manager
					</div>
					<nav>
						<ul className="bkng-nav">
							{ nav.map( ( item ) => {
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
												active ? 'is-active' : ''
											}
											aria-current={
												active ? 'page' : undefined
											}
										>
											<span
												className="bkng-nav__icon"
												aria-hidden="true"
											>
												{ item.icon }
											</span>
											{ item.label }
										</a>
									</li>
								);
							} ) }
						</ul>
					</nav>
					<div className="bkng-sidebar__footer">
						<span className="bkng-sidebar__user">
							{ me.display_name }
						</span>
						{ ! me.preferences?.operational_locked && (
							<Button
								variant="link"
								onClick={ async () => {
									await put( '/me/preferences', {
										operational_mode: ! operational,
									} );
									window.location.reload();
								} }
							>
								{ operational
									? __(
											'Mostra il menu di WordPress',
											'booking-manager'
									  )
									: __(
											'Modalità operativa',
											'booking-manager'
									  ) }
							</Button>
						) }
						{ operational && (
							<a
								className="bkng-sidebar__logout"
								href={ boot.logoutUrl }
							>
								{ __( "Esci dall'account", 'booking-manager' ) }
							</a>
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
					</div>
					<LicenseBand notice={ license.notice } />
					<main className="bkng-content">{ content }</main>
				</div>
			</div>
		</PropertyContext.Provider>
	);
}
