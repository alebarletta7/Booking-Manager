<?php
/**
 * The look of the e-mails: the property's name on top, a title, the text, the details
 * of the stay in a table, the QR for the check-in, a button and the footer with the
 * property's contacts.
 *
 * Built with tables and inline styles (what e-mail clients understand), in the plugin's
 * green palette; every value is escaped. No remote images: the QR is embedded (cid).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Notifications;

defined( 'ABSPATH' ) || exit;

final class EmailDesign {

	private const PRIMARY = '#1F5C43';
	private const ACCENT  = '#2F8A5F';
	private const SOFT    = '#E3F0E8';
	private const PAGE    = '#F2F6F3';
	private const INK     = '#1B2420';
	private const MUTED   = '#56675E';
	private const LINE    = '#D6E0DA';
	private const FONT    = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

	/**
	 * The HTML of an e-mail.
	 *
	 * @param array{
	 *   title: string, intro: string,
	 *   rows: array<int, array{0: string, 1: string}>,
	 *   qr_cid?: string|null, code?: string,
	 *   button?: array{url: string, label: string}|null,
	 *   note?: string
	 * } $c
	 * @param array<string, mixed> $property
	 */
	public static function render( array $c, array $property ): string {
		$f    = self::FONT;
		$rows = '';
		foreach ( $c['rows'] as $row ) {
			$rows .= '<tr><td style="padding:10px 0;border-bottom:1px solid ' . self::LINE . ';color:' . self::MUTED . ';font:14px/1.4 ' . $f . ';width:38%;vertical-align:top">' . esc_html( $row[0] ) . '</td>'
				. '<td style="padding:10px 0;border-bottom:1px solid ' . self::LINE . ';color:' . self::INK . ';font:600 14px/1.4 ' . $f . ';vertical-align:top">' . nl2br( esc_html( $row[1] ) ) . '</td></tr>';
		}
		$qr = '';
		if ( ! empty( $c['qr_cid'] ) ) {
			$qr = '<tr><td style="padding:24px 32px 0;text-align:center">'
				. '<div style="display:inline-block;padding:16px;background:#fff;border:1px solid ' . self::LINE . ';border-radius:12px">'
				. '<img src="cid:' . esc_attr( (string) $c['qr_cid'] ) . '" width="180" height="180" alt="' . esc_attr__( 'QR per il check-in', 'booking-manager' ) . '" style="display:block;border:0"></div>'
				. '<p style="margin:10px 0 0;color:' . self::MUTED . ';font:13px/1.5 ' . $f . '">' . esc_html__( 'Mostra questo QR alla reception per un check-in più veloce.', 'booking-manager' )
				. ( empty( $c['code'] ) ? '' : '<br>' . esc_html__( 'Codice prenotazione:', 'booking-manager' ) . ' <strong style="color:' . self::INK . ';letter-spacing:1px">' . esc_html( (string) $c['code'] ) . '</strong>' ) . '</p></td></tr>';
		}
		$button = '';
		if ( ! empty( $c['button']['url'] ) ) {
			$button = '<tr><td style="padding:28px 32px 0;text-align:center"><a href="' . esc_url( (string) $c['button']['url'] ) . '" style="display:inline-block;padding:13px 26px;background:' . self::PRIMARY . ';color:#fff;border-radius:8px;text-decoration:none;font:600 15px/1 ' . $f . '">' . esc_html( (string) $c['button']['label'] ) . '</a></td></tr>';
		}
		$note = empty( $c['note'] ) ? '' : '<tr><td style="padding:20px 32px 0"><div style="padding:14px 16px;background:' . self::SOFT . ';border-radius:8px;color:' . self::INK . ';font:14px/1.5 ' . $f . '">' . nl2br( esc_html( (string) $c['note'] ) ) . '</div></td></tr>';

		$contacts = array_filter(
			array(
				trim( implode( ', ', array_filter( array( (string) ( $property['address'] ?? '' ), trim( ( $property['postcode'] ?? '' ) . ' ' . ( $property['city'] ?? '' ) ) ) ) ) ),
				(string) ( $property['phone'] ?? '' ),
				(string) ( $property['email'] ?? '' ),
			)
		);

		return '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $c['title'] ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:' . self::PAGE . '">'
			. '<span style="display:none;max-height:0;overflow:hidden;opacity:0">' . esc_html( wp_trim_words( $c['intro'], 20 ) ) . '</span>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . self::PAGE . '"><tr><td align="center" style="padding:24px 12px">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:14px;overflow:hidden;border:1px solid ' . self::LINE . '">'
			. '<tr><td style="padding:22px 32px;background:' . self::PRIMARY . ';color:#fff;font:600 18px/1.3 ' . $f . '">' . esc_html( (string) $property['name'] ) . '</td></tr>'
			. '<tr><td style="padding:28px 32px 0"><h1 style="margin:0 0 12px;color:' . self::INK . ';font:700 24px/1.25 ' . $f . '">' . esc_html( $c['title'] ) . '</h1>'
			. '<p style="margin:0;color:' . self::INK . ';font:15px/1.6 ' . $f . '">' . nl2br( esc_html( $c['intro'] ) ) . '</p></td></tr>'
			. ( '' === $rows ? '' : '<tr><td style="padding:20px 32px 0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table></td></tr>' )
			. $note . $qr . $button
			. '<tr><td style="padding:32px;color:' . self::MUTED . ';font:13px/1.6 ' . $f . '"><div style="border-top:3px solid ' . self::ACCENT . ';width:40px;margin:0 0 12px"></div>'
			. '<strong style="color:' . self::INK . '">' . esc_html( (string) $property['name'] ) . '</strong>' . ( $contacts ? '<br>' . implode( '<br>', array_map( 'esc_html', $contacts ) ) : '' ) . '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}
}
