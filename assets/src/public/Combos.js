/**
 * No single room for the whole group: the message («Nessuna camera da 4 ospiti…») and
 * the free rooms that together can host it (two doubles for four, a triple and a
 * double for five…). The guest picks a combination and a rate and books all the rooms
 * at once; the server checks everything again.
 */
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { guestsLabel, money, nightsBetween, nightsLabel, date } from './format';
import { Icon } from './icons';
import { RateRow } from './Results';
import { Spinner } from './ui';

const SHORT = { day: 'numeric', month: 'short' };

/** «2 × Camera Doppia + Camera Tripla»: the rooms of a combination, grouped by type. */
export function comboTitle( rooms ) {
	const counts = [];
	rooms.forEach( ( r ) => {
		const found = counts.find( ( c ) => c.type.id === r.type.id );
		if ( found ) {
			found.n++;
		} else {
			counts.push( { type: r.type, n: 1 } );
		}
	} );
	return counts
		.map( ( c ) => ( c.n > 1 ? c.n + ' × ' : '' ) + c.type.name )
		.join( ' + ' );
}

/** The rooms of a combination with their room type (types not sold online are left out). */
export function comboRooms( property, combo ) {
	const rooms = combo.rooms.map( ( r ) => ( {
		...r,
		type: property.room_types.find( ( t ) => t.id === r.room_type_id ),
	} ) );
	return rooms.every( ( r ) => r.type ) ? rooms : null;
}

function Thumb( { type } ) {
	const img = type.images?.[ 0 ];
	return (
		<span className="bkng-p-combo__thumb" aria-hidden="true">
			{ img ? (
				<img src={ img.thumb || img.url } alt="" loading="lazy" />
			) : (
				<Icon name="bed" size={ 22 } />
			) }
		</span>
	);
}

function ComboCard( { property, stay, combo, rooms, onChoose, busy } ) {
	const [ planId, setPlanId ] = useState(
		String( combo.rates[ 0 ]?.rate_plan_id )
	);
	const name = 'bkng-p-combo-' + combo.key;
	const rate =
		combo.rates.find( ( r ) => String( r.rate_plan_id ) === planId ) ||
		combo.rates[ 0 ];
	const nights = nightsBetween( stay.arrival, stay.departure );
	return (
		<article className="bkng-p-combo">
			<header className="bkng-p-combo__head">
				<span className="bkng-p-pill">
					{ sprintf(
						/* translators: %d: rooms. */
						_n(
							'%d camera',
							'%d camere',
							rooms.length,
							'booking-manager'
						),
						rooms.length
					) }
				</span>
				<h4>{ comboTitle( rooms ) }</h4>
			</header>
			<ul className="bkng-p-combo__rooms">
				{ rooms.map( ( r, i ) => (
					<li key={ i }>
						<Thumb type={ r.type } />
						<span>
							<strong>{ r.type.name }</strong>
							<span className="bkng-p-muted">
								{ guestsLabel( r.adults, r.children ) }
							</span>
						</span>
					</li>
				) ) }
			</ul>
			{ combo.rates.length > 1 && (
				<ul className="bkng-p-sheet__choices">
					{ combo.rates.map( ( r ) => (
						<RateRow
							key={ String( r.rate_plan_id ) }
							rate={ r }
							name={ name }
							checked={ String( r.rate_plan_id ) === planId }
							onChange={ () =>
								setPlanId( String( r.rate_plan_id ) )
							}
							property={ property }
							quantity={ 1 }
						/>
					) ) }
				</ul>
			) }
			<footer className="bkng-p-combo__foot">
				<div className="bkng-p-combo__total">
					{ rate.total !== null && (
						<strong>
							{ money( rate.total, property.currency ) }
						</strong>
					) }
					<span className="bkng-p-muted">
						{ combo.rates.length === 1 ? rate.name + ' · ' : '' }
						{ nightsLabel( nights ) } ·{ ' ' }
						{ date( stay.arrival, SHORT ) } –{ ' ' }
						{ date( stay.departure, SHORT ) }
					</span>
				</div>
				<button
					type="button"
					className="bkng-p-button is-primary"
					disabled={ busy }
					onClick={ () => onChoose( rooms, rate ) }
				>
					{ busy && <Spinner /> }
					{ __( 'Prenota queste camere', 'booking-manager' ) }
				</button>
			</footer>
		</article>
	);
}

export function Combos( {
	property,
	stay,
	results,
	onChoose,
	busy,
	children,
} ) {
	const guests = results.adults + results.children;
	const list = ( results.combos || [] )
		.map( ( combo, i ) => ( {
			combo: { ...combo, key: i },
			rooms: comboRooms( property, combo ),
		} ) )
		.filter( ( c ) => c.rooms && c.combo.rates.length );
	return (
		<div className="bkng-p-card bkng-p-combos">
			<p>
				<strong>
					{ sprintf(
						/* translators: %d: number of guests. */
						_n(
							'Nessuna camera da %d ospite disponibile per queste date.',
							'Nessuna camera da %d ospiti disponibile per queste date.',
							guests,
							'booking-manager'
						),
						guests
					) }
				</strong>
			</p>
			{ list.length > 0 ? (
				<>
					<p className="bkng-p-muted">
						{ __(
							'Potete dividervi in più camere: ecco le combinazioni libere, da prenotare insieme.',
							'booking-manager'
						) }
					</p>
					<div className="bkng-p-combos__list">
						{ list.map( ( { combo, rooms } ) => (
							<ComboCard
								key={ combo.key }
								property={ property }
								stay={ stay }
								combo={ combo }
								rooms={ rooms }
								onChoose={ onChoose }
								busy={ busy }
							/>
						) ) }
					</div>
				</>
			) : (
				<p className="bkng-p-muted">
					{ __(
						'Prova a cambiare le date o il numero di ospiti.',
						'booking-manager'
					) }
				</p>
			) }
			{ children }
		</div>
	);
}
