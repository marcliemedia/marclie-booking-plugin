<?php
/**
 * Booking references `{PREFIX}-{YY}-{MMDD of arrival}-{unit_code}` (prefix from
 * the `policy.ref_prefix` setting; all-digit unit
 * codes padded to 3 digits: 1 → 001) with `-2`, `-3`, … on collision (e.g. an
 * earlier cancelled booking of the same stay). Filter: `mbk_booking_ref`.
 */

namespace Marclie\Booking\Service;

use Marclie\Booking\Domain\Stay;
use Marclie\Booking\Repository\BookingRepository;
use Marclie\Booking\Settings;

defined( 'ABSPATH' ) || exit;

final class ReferenceGenerator {

	public function __construct( private BookingRepository $bookings ) {}

	/** Unit code part: alphanumerics uppercased; all-digit codes padded to 3 digits. */
	public static function formatCode( string $unitCode ): string {
		$code = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $unitCode ) );

		return ctype_digit( $code ) ? str_pad( $code, 3, '0', STR_PAD_LEFT ) : $code;
	}

	/** Base reference before collision suffixes (pure). */
	public static function base( string $prefix, \DateTimeInterface $arrival, string $unitCode, int $unitId = 0 ): string {
		$code = self::formatCode( $unitCode );

		return sprintf( '%s-%s-%s-%s', $prefix, $arrival->format( 'y' ), $arrival->format( 'md' ), '' !== $code ? $code : (string) $unitId );
	}

	/**
	 * First of `$base`, `$base-2`, `$base-3`, … not in `$taken` (pure).
	 *
	 * @param list<string> $taken
	 */
	public static function nextFree( string $base, array $taken ): string {
		$taken = array_flip( $taken );
		if ( ! isset( $taken[ $base ] ) ) {
			return $base;
		}

		for ( $n = 2; ; $n++ ) {
			$ref = $base . '-' . $n;
			if ( ! isset( $taken[ $ref ] ) ) {
				return $ref;
			}
		}
	}

	/**
	 * Next free reference (checks mbk_bookings plus `$exclude`, e.g. refs that
	 * just failed on a concurrent uncommitted insert).
	 *
	 * @param list<string> $exclude
	 */
	public function generate( Stay $stay, string $unitCode, array $exclude = array() ): string {
		/**
		 * Filters the base booking reference (collision suffixes are added after).
		 *
		 * @param string $base     e.g. BK-27-0712-001.
		 * @param Stay   $stay
		 * @param string $unitCode Raw ACF unit_code.
		 */
		$base = (string) apply_filters( 'mbk_booking_ref', self::base( Settings::refPrefix(), $stay->arrival, $unitCode, $stay->unitId ), $stay, $unitCode );

		return self::nextFree( $base, array_merge( $this->bookings->refsWithBase( $base ), $exclude ) );
	}
}
