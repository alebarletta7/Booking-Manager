<?php
/**
 * Small, pure input helpers shared by the services. No WordPress calls except the
 * error object, so they are unit-tested without a database.
 *
 * Every service collects field messages in an array and turns them into ONE
 * bkng_validation error (400) with data.fields, shown by the app next to each field.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Core;

defined( 'ABSPATH' ) || exit;

final class Validation {

	/** Local date-time as typed in the app: "2026-10-31 23:30" (seconds optional). */
	public static function local_datetime( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( str_replace( 'T', ' ', $value ) );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $m ) ) {
			return null;
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || (int) $m[4] > 23 || (int) $m[5] > 59 || ( isset( $m[6] ) && (int) $m[6] > 59 ) ) {
			return null;
		}
		return sprintf( '%s-%s-%s %s:%s:00', $m[1], $m[2], $m[3], $m[4], $m[5] );
	}

	/** Strict Y-m-d date (2026-02-30 is rejected). */
	public static function date( mixed $value ): ?string {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return null;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $value : null;
	}

	/**
	 * A whole number in a range; null when not a whole number or out of range.
	 * Accepts integers and numeric strings such as "12" (not "12.5", not "1e3").
	 */
	public static function int_between( mixed $value, int $min, int $max ): ?int {
		if ( is_string( $value ) && preg_match( '/^-?\d+$/', trim( $value ) ) ) {
			$value = (int) trim( $value );
		}
		if ( ! is_int( $value ) || $value < $min || $value > $max ) {
			return null;
		}
		return $value;
	}

	/** True for null, "" and missing values: optional fields left empty. */
	public static function blank( mixed $value ): bool {
		return null === $value || ( is_string( $value ) && '' === trim( $value ) );
	}

	/** ISO 4217 currency code, upper case. */
	public static function currency( mixed $value ): ?string {
		return is_string( $value ) && preg_match( '/^[A-Za-z]{3}$/', $value ) ? strtoupper( $value ) : null;
	}

	/**
	 * A media library image anyone may see: shown on the public storefront and in wallet
	 * passes, so never an attachment that is private or belongs to a private or
	 * password-protected post (an id typed by hand could otherwise publish it).
	 */
	public static function public_image( int $id ): bool {
		if ( $id <= 0 || ! wp_attachment_is_image( $id ) ) {
			return false;
		}
		$post   = get_post( $id );
		$parent = $post && $post->post_parent ? get_post( (int) $post->post_parent ) : null;
		return $post && self::attachment_public(
			(string) $post->post_status,
			$parent ? (string) $parent->post_status : null,
			$parent && '' !== (string) $parent->post_password
		);
	}

	/**
	 * Pure part of public_image() (unit-tested): the attachment is "inherit" or
	 * "publish", and its parent post, if any, is neither private nor password-protected.
	 */
	public static function attachment_public( string $status, ?string $parent_status, bool $parent_protected ): bool {
		if ( ! in_array( $status, array( 'inherit', 'publish' ), true ) ) {
			return false;
		}
		return null === $parent_status || ( 'private' !== $parent_status && ! $parent_protected );
	}

	/**
	 * @param array<string, string> $fields field => message
	 */
	public static function error( array $fields, string $message = '' ): \WP_Error {
		return new \WP_Error(
			'bkng_validation',
			'' !== $message ? $message : (string) reset( $fields ),
			array(
				'status' => 400,
				'fields' => $fields,
			)
		);
	}
}
