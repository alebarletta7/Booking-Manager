<?php
/**
 * The look of the e-mails: a header with logo, name and photo of the property, the
 * title with a tick, the text, a summary of the stay with icons, the QR for the
 * check-in, a button and a footer with address, contacts and a closing sentence.
 *
 * Built with tables and inline styles (what e-mail clients understand); on phones the
 * columns stack (media query, where the client reads it). Colours, images and sentences
 * come from EmailBrand; every value is escaped.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Notifications;

defined( 'ABSPATH' ) || exit;

final class EmailDesign {

	private const FONT   = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
	private const SCRIPT = "'Segoe Script','Brush Script MT','Snell Roundhand',Georgia,cursive";

	/**
	 * The HTML of an e-mail.
	 *
	 * @param array{
	 *   title: string, intro: string,
	 *   rows: array<int, array{0: string, 1: string, 2?: string}>,
	 *   tick?: bool,
	 *   qr_cid?: string|null, code?: string,
	 *   button?: array{url: string, label: string}|null,
	 *   note?: string
	 * } $c
	 * @param array<string, mixed> $property
	 */
	public static function render( array $c, array $property, EmailBrand $brand ): string {
		$f     = self::FONT;
		$k     = $brand->colors;
		$t     = $brand->texts;
		$on    = EmailBrand::on( $k['primary'] );
		$e     = static fn( string $s ): string => esc_html( $s );
		$img   = static fn( ?string $src, int $size, string $alt = '' ): string => null === $src ? '' : '<img src="' . esc_attr( $src ) . '" width="' . $size . '" height="' . $size . '" alt="' . esc_attr( $alt ) . '" style="display:block;border:0;width:' . $size . 'px;height:' . $size . 'px">';
		$pad   = 'padding-left:36px;padding-right:36px';

		// Header: logo, name, subtitle and sentence on the left; the photo on the right.
		$logo   = $brand->logo();
		$banner = $brand->banner();
		$name   = '<div style="color:' . $k['primary'] . ';font:700 26px/1.2 ' . $f . '">' . $e( $t['name'] ) . '</div>'
			. ( '' === $t['subtitle'] ? '' : '<div style="margin-top:6px;color:' . $k['ink'] . ';font:600 12px/1.4 ' . $f . ';letter-spacing:3px;text-transform:uppercase">' . $e( $t['subtitle'] ) . '</div>' );
		$brand_block = null === $logo ? $name
			: '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="padding-right:14px;vertical-align:middle"><img src="' . esc_attr( $logo ) . '" width="72" alt="' . esc_attr( $t['name'] ) . '" style="display:block;border:0;max-width:72px;height:auto"></td><td style="vertical-align:middle">' . $name . '</td></tr></table>';
		$tagline = '' === $t['tagline'] ? '' : '<div style="margin-top:14px;color:' . $k['primary'] . ';font:italic 19px/1.3 ' . self::SCRIPT . '">' . $e( $t['tagline'] ) . '</div>';
		$header  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $k['header'] . '"><tr>'
			. '<td class="bk-col bk-head" width="' . ( $banner ? '52%' : '100%' ) . '" style="padding:28px 28px 28px 32px;vertical-align:middle">' . $brand_block . $tagline . '</td>'
			. ( $banner ? '<td class="bk-col" width="48%" style="padding:0;vertical-align:middle"><img src="' . esc_attr( $banner ) . '" width="288" alt="" style="display:block;border:0;width:100%;max-width:100%;height:auto"></td>' : '' )
			. '</tr></table>';

		// Title and text: the greeting line ("Ciao Anna,") in bold.
		$tick  = empty( $c['tick'] ) ? null : $brand->icon( 'check' );
		$title = '<h1 style="margin:0;color:' . $k['primary'] . ';font:700 26px/1.25 ' . $f . '">' . $e( $c['title'] ) . '</h1>';
		$lines = preg_split( '/\R/', trim( $c['intro'] ) ) ?: array();
		$intro = '';
		foreach ( $lines as $i => $line ) {
			$bold   = 0 === $i && count( $lines ) > 1 && str_ends_with( rtrim( $line ), ',' );
			$intro .= ( $i ? '<br>' : '' ) . ( $bold ? '<strong>' . $e( $line ) . '</strong>' : $e( $line ) );
		}
		$heading = '<tr><td class="bk-pad" style="padding-top:30px;' . $pad . '">'
			. ( null === $tick ? $title : '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="padding-right:14px;vertical-align:middle">' . $img( $tick, 44 ) . '</td><td style="vertical-align:middle">' . $title . '</td></tr></table>' )
			. '<p style="margin:16px 0 0;color:' . $k['ink'] . ';font:16px/1.6 ' . $f . '">' . $intro . '</p></td></tr>';

		// Summary of the stay: two columns of icon, label and value.
		$summary = '';
		if ( $c['rows'] ) {
			$cells = array();
			foreach ( $c['rows'] as $row ) {
				$parts   = explode( "\n", (string) $row[1] );
				$value   = '<div style="color:' . $k['ink'] . ';font:700 16px/1.35 ' . $f . '">' . $e( (string) array_shift( $parts ) ) . '</div>';
				foreach ( $parts as $part ) {
					$value .= '<div style="color:' . $k['ink'] . ';font:14px/1.45 ' . $f . '">' . $e( $part ) . '</div>';
				}
				$icon    = isset( $row[2] ) ? $brand->icon( $row[2] ) : null;
				$cells[] = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
					. ( null === $icon ? '' : '<td width="44" style="width:44px;vertical-align:top;padding-top:2px">' . $img( $icon, 28 ) . '</td>' )
					. '<td style="vertical-align:top"><div style="color:' . $k['muted'] . ';font:13px/1.4 ' . $f . '">' . $e( (string) $row[0] ) . '</div>' . $value . '</td></tr></table>';
			}
			$grid  = '';
			$pairs = array_chunk( $cells, 2 );
			foreach ( $pairs as $i => $pair ) {
				$line  = $i < count( $pairs ) - 1 ? 'border-bottom:1px solid ' . $k['line'] . ';' : '';
				$grid .= '<tr><td class="bk-col bk-flat" width="50%" style="' . $line . 'padding:14px 16px 14px 0;vertical-align:top">' . $pair[0] . '</td>'
					. '<td class="bk-col bk-flat" width="50%" style="' . $line . 'padding:14px 0 14px 16px;vertical-align:top">' . ( $pair[1] ?? '' ) . '</td></tr>';
			}
			$cal     = $brand->icon( 'calendar' );
			$summary = '<tr><td class="bk-pad" style="padding-top:26px;' . $pad . '"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $k['soft'] . ';border:1px solid ' . $k['line'] . ';border-radius:12px"><tr><td style="padding:22px 24px 10px">'
				. '<table role="presentation" cellpadding="0" cellspacing="0"><tr>' . ( null === $cal ? '' : '<td style="padding-right:12px;vertical-align:middle">' . $img( $cal, 28 ) . '</td>' )
				. '<td style="vertical-align:middle;color:' . $k['primary'] . ';font:700 19px/1.3 ' . $f . '">' . $e( __( 'Riepilogo prenotazione', 'booking-manager' ) ) . '</td></tr></table>'
				. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:6px">' . $grid . '</table>'
				. '</td></tr></table></td></tr>';
		}

		$note = empty( $c['note'] ) ? '' : '<tr><td class="bk-pad" style="padding-top:20px;' . $pad . '"><div style="padding:14px 16px;background:' . $k['soft'] . ';border-left:3px solid ' . $k['primary'] . ';border-radius:6px;color:' . $k['ink'] . ';font:14px/1.5 ' . $f . '">' . nl2br( $e( (string) $c['note'] ) ) . '</div></td></tr>';

		$qr = '';
		if ( ! empty( $c['qr_cid'] ) ) {
			$qr = '<tr><td class="bk-pad" style="padding-top:26px;' . $pad . ';text-align:center">'
				. '<div style="display:inline-block;padding:16px;background:#fff;border:1px solid ' . $k['line'] . ';border-radius:12px">'
				. '<img src="cid:' . esc_attr( (string) $c['qr_cid'] ) . '" width="180" height="180" alt="' . esc_attr__( 'QR per il check-in', 'booking-manager' ) . '" style="display:block;border:0"></div>'
				. '<p style="margin:10px 0 0;color:' . $k['muted'] . ';font:13px/1.5 ' . $f . '">' . esc_html__( 'Mostra questo QR alla reception per un check-in più veloce.', 'booking-manager' )
				. ( empty( $c['code'] ) ? '' : '<br>' . esc_html__( 'Codice prenotazione:', 'booking-manager' ) . ' <strong style="color:' . $k['ink'] . ';letter-spacing:1px">' . $e( (string) $c['code'] ) . '</strong>' ) . '</p></td></tr>';
		}

		$button = '';
		if ( ! empty( $c['button']['url'] ) ) {
			$button = '<tr><td class="bk-pad" style="padding-top:30px;' . $pad . ';text-align:center"><a href="' . esc_url( (string) $c['button']['url'] ) . '" style="display:inline-block;padding:15px 30px;background:' . $k['primary'] . ';color:' . $on . ';border-radius:10px;text-decoration:none;font:700 16px/1.2 ' . $f . '">' . $e( (string) $c['button']['label'] ) . ' &rarr;</a></td></tr>';
		}

		// Footer: where, how to reach the property, the closing sentence.
		$address = array_filter( array( (string) ( $property['address'] ?? '' ), trim( ( $property['postcode'] ?? '' ) . ' ' . ( $property['city'] ?? '' ) ) ) );
		$contact = static function ( ?string $icon, string $html ) use ( $img ): string {
			return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:6px"><tr>' . ( null === $icon ? '' : '<td style="padding-right:10px;vertical-align:top">' . $img( $icon, 20 ) . '</td>' ) . '<td style="vertical-align:top">' . $html . '</td></tr></table>';
		};
		$where = $contact( $brand->icon( 'pin' ), '<strong style="color:' . $k['ink'] . '">' . $e( $t['name'] ) . '</strong>' . ( $address ? '<br>' . implode( '<br>', array_map( $e, $address ) ) : '' ) );
		$reach = '';
		$phone = trim( (string) ( $property['phone'] ?? '' ) );
		$email = trim( (string) ( $property['email'] ?? '' ) );
		if ( '' !== $phone ) {
			$reach .= $contact( $brand->icon( 'phone' ), '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ) . '" style="color:' . $k['ink'] . ';text-decoration:none">' . $e( $phone ) . '</a>' );
		}
		if ( is_email( $email ) ) {
			$reach .= $contact( $brand->icon( 'mail' ), '<a href="mailto:' . esc_attr( $email ) . '" style="color:' . $k['ink'] . ';text-decoration:none">' . $e( $email ) . '</a>' );
		}
		$signoff = '' === $t['signoff'] ? '' : '<div style="color:' . $k['primary'] . ';font:italic 20px/1.3 ' . self::SCRIPT . '">' . $e( $t['signoff'] ) . ' &#9825;</div>';
		$footer  = '<tr><td class="bk-pad" style="padding-top:34px;' . $pad . ';padding-bottom:26px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid ' . $k['line'] . '"><tr>'
			. '<td class="bk-col bk-flat" width="38%" style="padding:22px 12px 0 0;vertical-align:top;color:' . $k['muted'] . ';font:14px/1.5 ' . $f . '">' . $where . '</td>'
			. '<td class="bk-col bk-flat" width="34%" style="padding:22px 12px 0 0;vertical-align:top;color:' . $k['ink'] . ';font:14px/1.5 ' . $f . '">' . $reach . '</td>'
			. '<td class="bk-col bk-flat bk-left" width="28%" style="padding:22px 0 0;vertical-align:top;text-align:right">' . $signoff . '</td>'
			. '</tr></table></td></tr>';
		$bar = '<tr><td style="padding:12px 20px;background:' . $k['primary'] . ';color:' . $on . ';text-align:center;font:12px/1.4 ' . $f . '"><strong>' . $e( $t['name'] ) . '</strong>' . ( '' === $t['subtitle'] ? '' : ' &nbsp;|&nbsp; ' . $e( $t['subtitle'] ) ) . '</td></tr>';

		$style = '<style>@media only screen and (max-width:620px){.bk-col{display:block!important;width:100%!important;box-sizing:border-box}.bk-pad{padding-left:20px!important;padding-right:20px!important}.bk-left{text-align:left!important}td.bk-flat{padding-left:0!important;padding-right:0!important}td.bk-head{padding:24px 20px!important}}</style>';

		return '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e( $c['title'] ) . '</title>' . $style . '</head>'
			. '<body style="margin:0;padding:0;background:' . $k['page'] . '">'
			. '<span style="display:none;max-height:0;overflow:hidden;opacity:0">' . $e( wp_trim_words( $c['intro'], 20 ) ) . '</span>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $k['page'] . '"><tr><td align="center" style="padding:24px 10px">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid ' . $k['line'] . '">'
			. '<tr><td style="padding:0">' . $header . '</td></tr>'
			. $heading . $summary . $note . $qr . $button . $footer . $bar
			. '</table></td></tr></table></body></html>';
	}
}
