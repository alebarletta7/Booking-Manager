/**
 * Small building blocks of the public form: plain HTML elements, styled with the
 * theme's colours by style.scss.
 */
import { useId } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/** A label with its control; children is a function that receives the control's id. */
export function Field( { label, error, inline, children } ) {
	const id = 'bkng-p-' + useId();
	return (
		<div
			className={
				'bkng-p-field' +
				( inline ? ' is-inline' : '' ) +
				( error ? ' has-error' : '' )
			}
		>
			<label htmlFor={ id }>{ label }</label>
			{ children( id ) }
			{ error && <p className="bkng-p-field__error">{ error }</p> }
		</div>
	);
}

export function ErrorBox( { error } ) {
	if ( ! error ) {
		return null;
	}
	return (
		<div className="bkng-p-error" role="alert">
			{ error.message ||
				__(
					'Si è verificato un errore. Riprova tra poco.',
					'booking-manager'
				) }
		</div>
	);
}

export function Spinner() {
	return <span className="bkng-p-spinner" aria-hidden="true" />;
}

/** A checkbox with its text; the text may hold links. */
export function Check( { checked, onChange, required, children } ) {
	const id = 'bkng-p-check-' + useId();
	return (
		<div className="bkng-p-check">
			<input
				id={ id }
				type="checkbox"
				required={ required }
				checked={ checked }
				onChange={ ( e ) => onChange( e.target.checked ) }
			/>
			<label htmlFor={ id }>{ children }</label>
		</div>
	);
}
