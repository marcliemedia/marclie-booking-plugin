<?php
/**
 * Cron `mbk_reap_holds` (every 5 minutes): pending_payment bookings past
 * hold_expires_at become expired and their nights are released.
 */

namespace Marclie\Booking\Service;

use Marclie\Booking\Domain\BookingStatus;
use Marclie\Booking\Domain\Clock;
use Marclie\Booking\Domain\Exception\InvalidTransitionException;
use Marclie\Booking\Repository\BookingRepository;
use Marclie\Booking\Repository\Db;

defined( 'ABSPATH' ) || exit;

final class HoldReaper {

	public const ACTOR = 'system:hold_reaper';

	public function __construct(
		private BookingRepository $bookings,
		private BookingService $service,
		private Clock $clock
	) {}

	/**
	 * @return int Number of bookings expired.
	 */
	public function run(): int {
		$expired = 0;
		foreach ( $this->bookings->expiredHolds( Db::utc( $this->clock->now() ) ) as $booking ) {
			/**
			 * Whether an expired hold may be expired now. Phase 06 checks the Stripe
			 * PaymentIntent here so a paid booking is never expired.
			 *
			 * @param bool                 $expire
			 * @param array<string, mixed> $booking
			 */
			if ( ! apply_filters( 'mbk_hold_reaper_should_expire', true, $booking ) ) {
				continue;
			}

			try {
				$this->service->transition( (int) $booking['id'], BookingStatus::Expired, self::ACTOR, array( 'hold_expires_at' => $booking['hold_expires_at'] ) );
				++$expired;
			} catch ( InvalidTransitionException $e ) {
				// Status changed meanwhile (e.g. payment webhook confirmed it).
				continue;
			}
		}

		return $expired;
	}
}
