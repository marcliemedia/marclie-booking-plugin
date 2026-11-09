<?php
/**
 * Display strings of a quote for the public widgets (bookbox, checkout
 * summary): every amount and date formatted for `$lang`, labels translated
 * through the plugin text domain (the caller switches the language).
 *
 * HR "175 € × 7 noćenja (2 odrasla × 70 € + 1 dijete × 35 €)", below the unit
 * minimum "140 € × 7 noćenja (najmanje 2 osobe × 70 €)" · "Završno čišćenje:
 * Uključeno" · "Boravišna pristojba: Plaća se na licu mjesta"; EN per the glossary.
 */

namespace Marclie\Booking\Service;

use Marclie\Booking\Domain\Quote;
use Marclie\Booking\Domain\QuoteLine;
use Marclie\Booking\I18n\Formatter;

defined( 'ABSPATH' ) || exit;

final class QuoteDisplay {

	/**
	 * @return array{
	 *     lines: list<array{type: string, label: string, amount: string}>,
	 *     total: string,
	 *     deposit: string,
	 *     balance: string,
	 *     balance_due_on: ?string,
	 *     free_cancel_until: ?string,
	 *     deposit_note: string
	 * }
	 */
	public static function build( Quote $quote, string $lang, int $depositPct ): array {
		$lines       = array();
		$afterNights = 0;
		foreach ( $quote->lines as $line ) {
			$row = self::line( $line, $quote, $lang );
			if ( null !== $row ) {
				$lines[] = $row;
				if ( QuoteLine::NIGHTS === $line->type ) {
					$afterNights = count( $lines );
				}
			}
		}
		// Children 0–4 cost nothing: a line after the nights shows they are counted.
		if ( $quote->infants > 0 ) {
			$infants = array(
				'type'   => 'infants',
				/* translators: %d: number of children aged 0–4. */
				'label'  => sprintf( _n( '%d child up to 4 years', '%d children up to 4 years', $quote->infants, 'marclie-booking' ), $quote->infants ),
				'amount' => __( 'Free', 'marclie-booking' ),
			);
			array_splice( $lines, $afterNights, 0, array( $infants ) );
		}

		$balanceDueOn = null === $quote->balanceDueOn ? null : Formatter::date( $quote->balanceDueOn, $lang );

		return array(
			'lines'             => $lines,
			'total'             => Formatter::price( $quote->total->cents(), $lang ),
			'deposit'           => Formatter::price( $quote->deposit->cents(), $lang ),
			'balance'           => Formatter::price( $quote->balance->cents(), $lang ),
			'balance_due_on'    => $balanceDueOn,
			'free_cancel_until' => null === $quote->freeCancelUntil ? null : Formatter::date( $quote->freeCancelUntil, $lang ),
			// How the money is taken; the amounts are in the summary lines and on the pay button.
			'deposit_note'      => $quote->payInFull || null === $balanceDueOn
				? __( 'You pay the full amount today; nothing is charged later.', 'marclie-booking' )
				/* translators: %s: balance due date, e.g. "28. lipnja 2027." */
				: sprintf( __( 'We charge the balance automatically to the same card on %s.', 'marclie-booking' ), $balanceDueOn ),
		);
	}

	/** @return array{type: string, label: string, amount: string}|null */
	private static function line( QuoteLine $line, Quote $quote, string $lang ): ?array {
		switch ( $line->type ) {
			case QuoteLine::NIGHTS:
				$label = sprintf(
					/* translators: 1: apartment price per night, e.g. "175 €", 2: number of nights, 3: how the price is made, e.g. "2 adults × 70 € + 1 child × 35 €" */
					_n( '%1$s × %2$d night (%3$s)', '%1$s × %2$d nights (%3$s)', $line->nights, 'marclie-booking' ),
					Formatter::price( $line->unitCents, $lang ),
					$line->nights,
					self::breakdown( $line, $lang )
				);
				$amount = Formatter::price( $line->totalCents, $lang );
				break;
			case QuoteLine::CLEANING:
				$label  = __( 'Final cleaning', 'marclie-booking' );
				$amount = 0 === $line->totalCents ? __( 'Included', 'marclie-booking' ) : Formatter::price( $line->totalCents, $lang );
				break;
			case QuoteLine::TOURIST_TAX:
				$label  = __( 'Tourist tax', 'marclie-booking' );
				$amount = 0 === $line->totalCents ? __( 'Paid on site', 'marclie-booking' ) : Formatter::price( $line->totalCents, $lang );
				break;
			case QuoteLine::COUPON:
				/* translators: %s: coupon code, e.g. DIRECT5 */
				$label  = sprintf( __( 'Coupon %s', 'marclie-booking' ), (string) $quote->couponCode );
				$amount = Formatter::price( $line->totalCents, $lang );
				break;
			default:
				return null;
		}

		return array(
			'type'   => $line->type,
			'label'  => $label,
			'amount' => $amount,
		);
	}

	/** "2 adults × €70 + 1 child × €35", or "at least 2 guests × €70" when the unit minimum applies. */
	private static function breakdown( QuoteLine $line, string $lang ): string {
		$person = Formatter::price( $line->personCents, $lang );
		if ( $line->minGuests > 0 ) {
			/* translators: 1: minimum paying guests of the apartment, 2: price per person per night, e.g. "70 €". */
			return sprintf( _n( 'at least %1$d guest × %2$s', 'at least %1$d guests × %2$s', $line->minGuests, 'marclie-booking' ), $line->minGuests, $person );
		}
		/* translators: 1: number of adults, 2: price per person per night, e.g. "70 €". */
		$text = sprintf( _n( '%1$d adult × %2$s', '%1$d adults × %2$s', $line->adults, 'marclie-booking' ), $line->adults, $person );
		if ( $line->children > 0 ) {
			$text = sprintf(
				/* translators: 1: adults part, e.g. "2 adults × 70 €", 2: children part, e.g. "1 child × 35 €". */
				__( '%1$s + %2$s', 'marclie-booking' ),
				$text,
				/* translators: 1: number of children aged 5–10, 2: child price per night (half the person price), e.g. "35 €". */
				sprintf( _n( '%1$d child × %2$s', '%1$d children × %2$s', $line->children, 'marclie-booking' ), $line->children, Formatter::price( $line->childCents, $lang ) )
			);
		}

		return $text;
	}
}
