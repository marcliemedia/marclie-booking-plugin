<?php
/**
 * Immutable money amount in integer cents (EUR).
 */

namespace Marclie\Booking\Domain;

defined( 'ABSPATH' ) || exit;

final class Money {

	private int $cents;

	public function __construct( int $cents ) {
		$this->cents = $cents;
	}

	public static function zero(): self {
		return new self( 0 );
	}

	/**
	 * Parses '1.365', '1.296,75', '409,50', '409.50', '195' (euros) into cents.
	 * A comma is always the decimal separator; a dot followed by exactly three digits is a thousands separator.
	 */
	public static function fromDecimalString( string $value ): self {
		$v = str_replace( array( "\u{00A0}", ' ', '€', 'EUR' ), '', trim( $value ) );
		$negative = str_starts_with( $v, '-' );
		$v        = ltrim( $v, '-+' );
		if ( str_contains( $v, ',' ) ) {
			$v = str_replace( array( '.', ',' ), array( '', '.' ), $v );
		} elseif ( preg_match( '/^\d{1,3}(\.\d{3})+$/', $v ) ) {
			$v = str_replace( '.', '', $v );
		}
		if ( ! preg_match( '/^(\d+)(?:\.(\d{1,2}))?$/', $v, $m ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid money string "%s".', $value ) );
		}
		$cents = (int) $m[1] * 100 + (int) str_pad( $m[2] ?? '0', 2, '0' );
		return new self( $negative ? -$cents : $cents );
	}

	public function cents(): int {
		return $this->cents;
	}

	public function add( self $other ): self {
		return new self( $this->cents + $other->cents );
	}

	public function sub( self $other ): self {
		return new self( $this->cents - $other->cents );
	}

	/**
	 * Percentage of this amount, rounded half-up (away from zero) to the cent.
	 */
	public function percent( int|float $pct ): self {
		if ( is_int( $pct ) ) {
			$n    = abs( $this->cents * $pct );
			$cent = intdiv( $n * 2 + 100, 200 );
			return new self( ( $this->cents * $pct ) < 0 ? -$cent : $cent );
		}
		return new self( (int) round( $this->cents * $pct / 100, 0, PHP_ROUND_HALF_UP ) );
	}

	public function isZero(): bool {
		return 0 === $this->cents;
	}

	public function isNegative(): bool {
		return $this->cents < 0;
	}

	public function min( self $other ): self {
		return $this->cents <= $other->cents ? $this : $other;
	}

	public function equals( self $other ): bool {
		return $this->cents === $other->cents;
	}
}
