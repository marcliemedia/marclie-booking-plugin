<?php
/**
 * GET /admin/calendar?from&to[&with_prices=1] — timeline data: bookings,
 * manual blocks, imported (iCal) blocks and optionally nightly prices.
 * `to` is exclusive; the range is at most MAX_DAYS.
 */

namespace Marclie\Booking\Rest\Admin;

use Marclie\Booking\Container;
use Marclie\Booking\Domain\Exception\ValidationException;
use Marclie\Booking\Install\Activator;
use Marclie\Booking\Repository\BookingRepository;

defined( 'ABSPATH' ) || exit;

final class CalendarController extends BaseController {

	public const MAX_DAYS = 120;

	public function register(): void {
		$this->route(
			'/calendar',
			$this->endpoint(
				\WP_REST_Server::READABLE,
				array( $this, 'show' ),
				Activator::CAP_BOOKINGS,
				array(
					'from'        => self::dateArg( true ),
					'to'          => self::dateArg( true ),
					'with_prices' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				)
			)
		);
	}

	/** @return array<string, mixed> */
	public function show( \WP_REST_Request $request ): array {
		$from = self::date( (string) $request['from'], 'from' );
		$to   = self::date( (string) $request['to'], 'to' );
		$days = (int) $from->diff( $to )->format( '%r%a' );
		if ( $days < 1 ) {
			throw ValidationException::fields( array( 'to' => __( 'The end date must be after the start date.', 'marclie-booking' ) ) );
		}
		if ( $days > self::MAX_DAYS ) {
			/* translators: %d: maximum number of days */
			throw ValidationException::fields( array( 'to' => sprintf( __( 'Show at most %d days at a time.', 'marclie-booking' ), self::MAX_DAYS ) ) );
		}

		$fromYmd = $from->format( 'Y-m-d' );
		$toYmd   = $to->format( 'Y-m-d' );

		$bookings = array_map(
			static fn( array $b ): array => array(
				'id'         => (int) $b['id'],
				'ref'        => (string) $b['ref'],
				'unit_id'    => (int) $b['unit_id'],
				'arrival'    => (string) $b['arrival'],
				'departure'  => (string) $b['departure'],
				'status'     => (string) $b['status'],
				'source'     => (string) $b['source'],
				'guest_name' => trim( $b['first_name'] . ' ' . $b['last_name'] ),
				'nights'     => (int) $b['nights'],
				'adults'     => (int) $b['adults'],
				'children'   => (int) $b['children'],
				'infants'    => (int) $b['infants'],
			),
			Container::bookingRepository()->overlapping( $fromYmd, $toYmd, BookingRepository::ACTIVE_STATUSES )
		);

		$blocks = array_map(
			static fn( array $b ): array => array(
				'id'        => (int) $b['id'],
				'unit_id'   => (int) $b['unit_id'],
				'date_from' => (string) $b['date_from'],
				'date_to'   => (string) $b['date_to'],
				'reason'    => (string) $b['reason'],
			),
			Container::blockRepository()->overlapping( $fromYmd, $toYmd )
		);

		$out = array(
			'bookings' => $bookings,
			'blocks'   => $blocks,
			'external' => Container::blockRepository()->externalDetailed( $fromYmd, $toYmd ),
		);

		if ( rest_sanitize_boolean( $request['with_prices'] ) ) {
			$out['prices'] = (object) self::prices( $from, $to );
		}

		return $out;
	}

	/**
	 * Lowest apartment price per night (price per person × minimum paying guests), price per
	 * person and the unit's minimum stay per unit and date; nights of closed months are left out.
	 *
	 * @return array<int, array<string, array{cents:int, person_cents:int, min_stay:int}>>
	 */
	private static function prices( \DateTimeImmutable $from, \DateTimeImmutable $to ): array {
		$dates = array();
		for ( $d = $from; $d < $to; $d = $d->modify( '+1 day' ) ) {
			$dates[] = $d->format( 'Y-m-d' );
		}

		$table  = Container::priceRepository()->table();
		$prices = array();
		foreach ( Container::units()->all() as $unit ) {
			$minStay   = $table->minStay( $unit->id );
			$minGuests = $table->minGuests( $unit->id );
			foreach ( $table->priced( $unit->id, $dates ) as $date => $cents ) {
				$prices[ $unit->id ][ $date ] = array(
					'cents'        => $cents * $minGuests,
					'person_cents' => $cents,
					'min_stay'     => $minStay,
				);
			}
		}

		return $prices;
	}
}
