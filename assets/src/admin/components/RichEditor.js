/**
 * The visual editor of the e-mail texts (0.2.5): WordPress's own TinyMCE (wp.editor,
 * loaded by wp_enqueue_editor), with only what the e-mails allow: paragraphs, headings,
 * bold, italic, lists and links. The server cleans the HTML again (wp_kses). Without
 * wp.editor it falls back to the plain text box.
 *
 * Mount it with a React key per text: the content passed when it mounts is loaded once.
 */
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { Textarea } from './ui';

let counter = 0;

/** A plain text (one line each, as before 0.2.5) as paragraphs for the editor. */
export function toHtml( text ) {
	if (
		! text ||
		/<(p|br|strong|b|em|i|u|a|ul|ol|li|h2|h3)\b/i.test( text )
	) {
		return text || '';
	}
	const esc = ( s ) =>
		s
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	return text
		.split( /\n{2,}/ )
		.map( ( p ) => '<p>' + esc( p ).replace( /\n/g, '<br>' ) + '</p>' )
		.join( '' );
}

export function editorAvailable() {
	return !! window.wp?.editor?.initialize && !! window.tinymce;
}

export default function RichEditor( {
	label,
	value,
	onChange,
	help,
	rows = 10,
	onReady,
} ) {
	const id = useRef( 'bkng-rich-' + ++counter ).current;
	const changed = useRef( onChange );
	changed.current = onChange;
	const available = editorAvailable();

	useEffect( () => {
		if ( ! available ) {
			return undefined;
		}
		const area = document.getElementById( id );
		area.value = toHtml( value );
		window.wp.editor.initialize( id, {
			tinymce: {
				wpautop: false,
				height: rows * 26,
				menubar: false,
				branding: false,
				plugins: 'lists,link,paste,wordpress,wplink,wptextpattern',
				toolbar1:
					'formatselect,bold,italic,bullist,numlist,link,unlink,undo,redo',
				toolbar2: '',
				block_formats: [
					__( 'Paragrafo', 'booking-manager' ) + '=p',
					__( 'Titolo', 'booking-manager' ) + '=h2',
					__( 'Sottotitolo', 'booking-manager' ) + '=h3',
				].join( ';' ),
				valid_elements:
					'p,br,strong/b,em/i,u,a[href|title],ul,ol,li,h2,h3',
				paste_as_text: false,
				setup( editor ) {
					editor.on( 'change input keyup undo redo SetContent', () =>
						changed.current( editor.getContent() )
					);
					editor.on( 'init', () => onReady && onReady( editor ) );
				},
			},
			quicktags: false,
			mediaButtons: false,
		} );
		return () => window.wp.editor.remove( id );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( ! available ) {
		return (
			<Textarea
				label={ label }
				rows={ rows }
				value={ value }
				onChange={ onChange }
				help={ help }
			/>
		);
	}
	return (
		<div className="bkng-field bkng-rich">
			<label className="bkng-field__label" htmlFor={ id }>
				{ label }
			</label>
			<textarea id={ id } defaultValue="" rows={ rows } />
			{ help && <p className="bkng-help">{ help }</p> }
		</div>
	);
}

/** Puts a placeholder where the cursor is, in the editor of a RichEditor. */
export function insertAtCursor( editor, text ) {
	if ( editor && ! editor.removed ) {
		editor.insertContent( text );
	}
}
