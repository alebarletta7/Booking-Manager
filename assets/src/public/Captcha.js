/**
 * Cloudflare Turnstile, when the site owner set its keys. The script is loaded by
 * PublicFrontend (render=explicit); the widget is drawn here and its token goes with the
 * booking, where the server checks it once.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export function Captcha( { config, onToken } ) {
	const box = useRef( null );
	const [ failed, setFailed ] = useState( false );

	useEffect( () => {
		let widget = null;
		let tries = 0;
		let timer = null;
		const draw = () => {
			if ( ! box.current ) {
				return;
			}
			if ( ! window.turnstile ) {
				tries += 1;
				if ( tries > 50 ) {
					setFailed( true );
					return;
				}
				timer = window.setTimeout( draw, 200 );
				return;
			}
			widget = window.turnstile.render( box.current, {
				sitekey: config.site_key,
				action: config.action,
				callback: ( token ) => onToken( token ),
				'expired-callback': () => onToken( '' ),
				'error-callback': () => onToken( '' ),
			} );
		};
		draw();
		return () => {
			window.clearTimeout( timer );
			if ( widget !== null && window.turnstile ) {
				window.turnstile.remove( widget );
			}
		};
	}, [ config, onToken ] );

	return (
		<div className="bkng-p-captcha">
			<div ref={ box } />
			{ failed && (
				<p className="bkng-p-field__error">
					{ __(
						'La verifica anti-robot non si è caricata. Disattiva eventuali blocchi dei contenuti e ricarica la pagina.',
						'booking-manager'
					) }
				</p>
			) }
		</div>
	);
}
