/**
 * Building blocks shared by the screens.
 */
import {
	Button,
	CheckboxControl,
	Modal,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useId, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	BOOKING_STATUS,
	OP_STATUS,
	PAYMENT_STATUS,
	STAY_STATUS,
	fromCents,
	toCents,
} from '../lib/format';

export function Page( { title, subtitle, actions, children, wide = false } ) {
	return (
		<div className={ 'bkng-page' + ( wide ? ' is-wide' : '' ) }>
			<header className="bkng-page__header">
				<div>
					<h1 className="bkng-page__title">{ title }</h1>
					{ subtitle && (
						<p className="bkng-page__subtitle">{ subtitle }</p>
					) }
				</div>
				{ actions && (
					<div className="bkng-page__actions">{ actions }</div>
				) }
			</header>
			{ children }
		</div>
	);
}

export function Card( { title, actions, children, className = '' } ) {
	return (
		<section className={ 'bkng-card ' + className }>
			{ ( title || actions ) && (
				<header className="bkng-card__header">
					{ title && <h2 className="bkng-card__title">{ title }</h2> }
					{ actions && (
						<div className="bkng-card__actions">{ actions }</div>
					) }
				</header>
			) }
			<div className="bkng-card__body">{ children }</div>
		</section>
	);
}

export function Loading( { label } ) {
	return (
		<div className="bkng-loading" role="status">
			<Spinner />
			<span>{ label || __( 'Caricamento…', 'booking-manager' ) }</span>
		</div>
	);
}

/** An error from readableError(), with "Riprova" when a reload is possible. */
export function ErrorNotice( { error, onRetry, onDismiss } ) {
	if ( ! error ) {
		return null;
	}
	const fields = Object.values( error.fields || {} ).filter(
		( m ) => m && m !== error.message
	);
	return (
		<Notice
			status="error"
			isDismissible={ !! onDismiss }
			onRemove={ onDismiss }
			className="bkng-notice"
		>
			<p>{ error.message }</p>
			{ fields.length > 0 && (
				<ul>
					{ fields.map( ( m, i ) => (
						<li key={ i }>{ m }</li>
					) ) }
				</ul>
			) }
			{ onRetry && (
				<Button variant="link" onClick={ onRetry }>
					{ __( 'Riprova', 'booking-manager' ) }
				</Button>
			) }
		</Notice>
	);
}

export function Success( { children, onDismiss } ) {
	if ( ! children ) {
		return null;
	}
	return (
		<Notice
			status="success"
			isDismissible={ !! onDismiss }
			onRemove={ onDismiss }
			className="bkng-notice"
		>
			{ children }
		</Notice>
	);
}

export function Empty( { title, text, children } ) {
	return (
		<div className="bkng-empty" role="status">
			<h2>{ title }</h2>
			{ text && <p>{ text }</p> }
			{ children }
		</div>
	);
}

const TONES = {
	pending: 'warn',
	option: 'warn',
	confirmed: 'ok',
	in_house: 'info',
	completed: 'muted',
	cancelled: 'bad',
	no_show: 'bad',
	unpaid: 'warn',
	deposit_paid: 'info',
	partially_paid: 'info',
	paid: 'ok',
	refunded: 'muted',
	expected: 'muted',
	departed: 'muted',
	dirty: 'bad',
	cleaning: 'warn',
	clean: 'info',
	ready: 'ok',
	maintenance: 'bad',
	sent: 'ok',
	queued: 'info',
	sending: 'info',
	failed: 'bad',
	skipped: 'muted',
};

export function Badge( { status, kind = 'booking', children } ) {
	const labels =
		{
			booking: BOOKING_STATUS,
			payment: PAYMENT_STATUS,
			stay: STAY_STATUS,
			op: OP_STATUS,
		}[ kind ] || {};
	return (
		<span className={ 'bkng-badge is-' + ( TONES[ status ] || 'muted' ) }>
			{ children || labels[ status ] || status }
		</span>
	);
}

/** An amount typed in euro, kept in cents. */
export function MoneyField( {
	label,
	value,
	onChange,
	help,
	required,
	disabled,
} ) {
	const [ text, setText ] = useState( fromCents( value ) );
	return (
		<TextControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			label={ label }
			help={ help }
			value={ text }
			inputMode="decimal"
			required={ required }
			disabled={ disabled }
			onChange={ ( t ) => {
				setText( t );
				onChange( toCents( t ) );
			} }
		/>
	);
}

export function DateField( { label, value, onChange, min, max, required } ) {
	const id = 'bkng-date-' + useId();
	return (
		<div className="bkng-field">
			<label className="bkng-field__label" htmlFor={ id }>
				{ label }
			</label>
			<input
				id={ id }
				type="date"
				className="bkng-input"
				value={ value || '' }
				min={ min }
				max={ max }
				required={ required }
				onChange={ ( e ) => onChange( e.target.value ) }
			/>
		</div>
	);
}

export function Text( props ) {
	return (
		<TextControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			{ ...props }
		/>
	);
}

export function Textarea( props ) {
	return <TextareaControl __nextHasNoMarginBottom { ...props } />;
}

export function Grid( { children, columns = 2 } ) {
	return <div className={ 'bkng-grid is-' + columns }>{ children }</div>;
}

/** A question before an action that matters, with an optional text (a reason). */
export function Confirm( {
	title,
	text,
	confirmLabel,
	onConfirm,
	onCancel,
	busy,
	reasonLabel,
	destructive = false,
	error,
} ) {
	const [ reason, setReason ] = useState( '' );
	return (
		<Modal
			title={ title }
			onRequestClose={ onCancel }
			className="bkng-modal"
		>
			{ text && <p>{ text }</p> }
			<ErrorNotice error={ error } />
			{ reasonLabel && (
				<Textarea
					label={ reasonLabel }
					value={ reason }
					onChange={ setReason }
				/>
			) }
			<div className="bkng-modal__actions">
				<Button
					variant="tertiary"
					onClick={ onCancel }
					disabled={ busy }
				>
					{ __( 'Annulla', 'booking-manager' ) }
				</Button>
				<Button
					variant="primary"
					isDestructive={ destructive }
					isBusy={ busy }
					disabled={ busy || ( reasonLabel && ! reason.trim() ) }
					onClick={ () => onConfirm( reason.trim() ) }
				>
					{ confirmLabel }
				</Button>
			</div>
		</Modal>
	);
}

/** A key/value list (booking details, guest card). */
export function Details( { rows } ) {
	return (
		<dl className="bkng-details">
			{ rows
				.filter(
					( r ) =>
						r &&
						r[ 1 ] !== null &&
						r[ 1 ] !== undefined &&
						r[ 1 ] !== ''
				)
				.map( ( [ k, v ] ) => (
					<div key={ k } className="bkng-details__row">
						<dt>{ k }</dt>
						<dd>{ v }</dd>
					</div>
				) ) }
		</dl>
	);
}

export function Pager( { page, total, perPage, onChange } ) {
	const pages = Math.max( 1, Math.ceil( total / perPage ) );
	if ( pages <= 1 ) {
		return null;
	}
	return (
		<div className="bkng-pager">
			<Button
				variant="secondary"
				disabled={ page <= 1 }
				onClick={ () => onChange( page - 1 ) }
			>
				{ __( 'Precedenti', 'booking-manager' ) }
			</Button>
			<span>
				{ page } / { pages }
			</span>
			<Button
				variant="secondary"
				disabled={ page >= pages }
				onClick={ () => onChange( page + 1 ) }
			>
				{ __( 'Successivi', 'booking-manager' ) }
			</Button>
		</div>
	);
}

export function Select( props ) {
	return (
		<SelectControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			{ ...props }
		/>
	);
}

export function Check( props ) {
	return <CheckboxControl __nextHasNoMarginBottom { ...props } />;
}

export function Toggle( props ) {
	return <ToggleControl __nextHasNoMarginBottom { ...props } />;
}

/** A whole number typed in a field (null when empty). */
export function NumberField( {
	label,
	value,
	onChange,
	min,
	max,
	help,
	disabled,
} ) {
	return (
		<TextControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			type="number"
			label={ label }
			help={ help }
			min={ min }
			max={ max }
			disabled={ disabled }
			value={
				value === null || value === undefined ? '' : String( value )
			}
			onChange={ ( t ) =>
				onChange( t === '' ? null : parseInt( t, 10 ) )
			}
		/>
	);
}

export function Tabs( { tabs, current, onChange } ) {
	return (
		<div className="bkng-tabs" role="tablist">
			{ tabs.map( ( [ key, label ] ) => (
				<button
					key={ key }
					type="button"
					role="tab"
					aria-selected={ current === key }
					className={ current === key ? 'is-active' : '' }
					onClick={ () => onChange( key ) }
				>
					{ label }
				</button>
			) ) }
		</div>
	);
}

export function Kpi( { label, value, hint, tone = '' } ) {
	return (
		<div className={ 'bkng-kpi' + ( tone ? ' is-' + tone : '' ) }>
			<div className="bkng-kpi__label">{ label }</div>
			<div className="bkng-kpi__value">{ value }</div>
			{ hint && <div className="bkng-kpi__hint">{ hint }</div> }
		</div>
	);
}

/** A modal form with Salva / Annulla. */
export function FormModal( {
	title,
	onClose,
	onSubmit,
	busy,
	error,
	submitLabel,
	children,
	wide = false,
	destructive = false,
} ) {
	return (
		<Modal
			title={ title }
			onRequestClose={ onClose }
			className={ 'bkng-modal' + ( wide ? ' is-wide' : '' ) }
		>
			<form
				onSubmit={ ( e ) => {
					e.preventDefault();
					onSubmit();
				} }
			>
				<ErrorNotice error={ error } />
				{ children }
				<div className="bkng-modal__actions">
					<Button
						variant="tertiary"
						onClick={ onClose }
						disabled={ busy }
					>
						{ __( 'Annulla', 'booking-manager' ) }
					</Button>
					<Button
						variant="primary"
						type="submit"
						isBusy={ busy }
						disabled={ busy }
						isDestructive={ destructive }
					>
						{ submitLabel || __( 'Salva', 'booking-manager' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}
