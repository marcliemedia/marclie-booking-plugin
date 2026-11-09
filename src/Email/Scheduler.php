<?php
/**
 * Daily emails (cron `mbk_daily`, 09:00 site time, after the balance charges):
 *
 * - guest_balance_reminder: confirmed bookings with a saved card and an
 *   outstanding balance charged in 1–3 days.
 * - guest_pre_arrival: confirmed/paid bookings arriving within 3 days (today
 *   included, so a missed run or a last-minute booking still gets it).
 *
 * Idempotent: a booking never gets the same code twice (`email_sent` event),
 * so running twice a day or catching up after a missed day is safe.
 */

namespace Marclie\Booking\Email;

use Marclie\Booking\Domain\BookingStatus;
use Marclie\Booking\Domain\Clock;
use Marclie\Booking\Repository\BookingRepository;

defined( 'ABSPATH' ) || exit;

final class Scheduler {

	public const ACTOR = 'system:cron';

	public const DAYS_BEFORE = 3;

	public function __construct(
		private BookingRepository $bookings,
		private Mailer $mailer,
		private Clock $clock
	) {}

	/**
	 * @param string|null    $todayYmd   Defaults to today (site timezone).
	 * @param list<int>|null $bookingIds Only these bookings (tests, CLI); null = all.
	 * @return list<array{booking_id: int, ref: string, code: string, sent: bool}>
	 */
	public function run( ?string $todayYmd = null, ?array $bookingIds = null ): array {
		$today = null !== $todayYmd ? new \DateTimeImmutable( $todayYmd, wp_timezone() ) : $this->clock->today();
		$limit = $today->modify( sprintf( '+%d days', self::DAYS_BEFORE ) )->format( 'Y-m-d' );
		$out   = array();
		$only  = null === $bookingIds ? array() : array( 'ids' => $bookingIds ?: array( 0 ) );

		$reminders = $this->bookings->findAll(
			array(
				'status'           => BookingStatus::Confirmed->value,
				'balance_due_from' => $today->modify( '+1 day' )->format( 'Y-m-d' ),
				'balance_due_to'   => $limit,
				'payment_state'    => 'outstanding',
			) + $only
		);
		foreach ( $reminders as $booking ) {
			if ( ! empty( $booking['stripe_customer_id'] ) && ! empty( $booking['stripe_payment_method_id'] ) ) {
				$out[] = $this->send( 'guest_balance_reminder', $booking );
			}
		}

		$arrivals = $this->bookings->findAll(
			array(
				'status'     => array( BookingStatus::Confirmed->value, BookingStatus::Paid->value ),
				'date_field' => 'arrival',
				'from'       => $today->format( 'Y-m-d' ),
				'to'         => $limit,
			) + $only
		);
		foreach ( $arrivals as $booking ) {
			$out[] = $this->send( 'guest_pre_arrival', $booking );
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $booking
	 * @return array{booking_id: int, ref: string, code: string, sent: bool}
	 */
	private function send( string $code, array $booking ): array {
		try {
			$sent = $this->mailer->sendForBooking(
				$code,
				$booking,
				array(
					'once'  => true,
					'actor' => self::ACTOR,
				)
			);
		} catch ( \Throwable $e ) {
			error_log( sprintf( '[marclie-booking] %s for %s: %s', $code, (string) $booking['ref'], $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$sent = false;
		}

		return array(
			'booking_id' => (int) $booking['id'],
			'ref'        => (string) $booking['ref'],
			'code'       => $code,
			'sent'       => $sent,
		);
	}
}
