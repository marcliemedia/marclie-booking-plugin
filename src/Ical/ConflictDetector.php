<?php
/**
 * `external` imported blocks overlapping a booking that holds nights (direct
 * or admin): each new conflict is logged once as an `ical_conflict` event and
 * mailed once to the admin recipients (admin_alert). The once-key is the
 * booking, channel and block dates (external_id
 * `ical_conflict:{booking}:{channel}:{Ymd}-{Ymd}`), so a new UID, a re-added
 * feed or a URL change for the same OTA stay does not alert again. Nothing is
 * cancelled. The dashboard and calendar show conflicts from the live overlap,
 * so a resolved conflict disappears there by itself. Echoes of our own export
 * are never conflicts (NightsRepository::externalConflicts).
 */

namespace Marclie\Booking\Ical;

use Marclie\Booking\Domain\Clock;
use Marclie\Booking\Email\Mailer;
use Marclie\Booking\I18n\Formatter;
use Marclie\Booking\I18n\LanguageAdapter;
use Marclie\Booking\Repository\BookingRepository;
use Marclie\Booking\Repository\EventRepository;
use Marclie\Booking\Repository\NightsRepository;

defined( 'ABSPATH' ) || exit;

final class ConflictDetector {

	public const EVENT = 'ical_conflict';

	public const ACTOR = 'system:ical';

	/** Conflicts further ahead than this are not checked. */
	private const HORIZON_YEARS = 3;

	public function __construct(
		private NightsRepository $nights,
		private BookingRepository $bookings,
		private EventRepository $events,
		private Mailer $mailer,
		private Clock $clock
	) {}

	public static function key( int $bookingId, string $channel, string $dateFrom, string $dateTo ): string {
		return self::EVENT . ':' . $bookingId . ':' . $channel . ':' . str_replace( '-', '', $dateFrom ) . '-' . str_replace( '-', '', $dateTo );
	}

	/**
	 * Logs and mails the unit's new conflicts (from today on).
	 *
	 * @return list<array<string, mixed>> The new conflicts (NightsRepository::externalConflicts rows).
	 */
	public function detect( int $unitId ): array {
		$today = $this->clock->today();
		$new   = array();
		foreach ( $this->nights->externalConflicts( $today->format( 'Y-m-d' ), $today->modify( '+' . self::HORIZON_YEARS . ' years' )->format( 'Y-m-d' ), $unitId ) as $conflict ) {
			$logged = $this->events->logExternal(
				self::key( $conflict['booking_id'], $conflict['channel'], $conflict['date_from'], $conflict['date_to'] ),
				self::EVENT,
				self::ACTOR,
				$conflict['booking_id'],
				null,
				array(
					'external_block_id' => $conflict['external_block_id'],
					'feed_id'           => $conflict['feed_id'],
					'unit_id'           => $conflict['unit_id'],
					'channel'           => $conflict['channel'],
					'date'              => $conflict['date'],
					'date_from'         => $conflict['date_from'],
					'date_to'           => $conflict['date_to'],
				)
			);
			if ( ! $logged ) {
				continue;
			}
			$new[] = $conflict;
			$this->mail( $conflict );
		}

		return $new;
	}

	/** @param array<string, mixed> $conflict */
	private function mail( array $conflict ): void {
		$booking = $this->bookings->find( (int) $conflict['booking_id'] );
		if ( null === $booking ) {
			return;
		}

		try {
			$lang  = Mailer::adminLang();
			$extra = LanguageAdapter::withLanguage(
				$lang,
				static fn(): array => array(
					'alert_title'   => __( 'Calendar conflict', 'marclie-booking' ),
					'alert_message' => sprintf(
						/* translators: 1: booking reference, 2: channel (Airbnb, Booking.com…), 3: first date, 4: last night's end date */
						__( '%1$s overlaps dates that are blocked on %2$s (%3$s – %4$s). Nothing was cancelled: check both bookings and resolve the double booking with the guest or on %2$s today.', 'marclie-booking' ),
						(string) $booking['ref'],
						FeedRepository::label( (string) $conflict['channel'] ),
						Formatter::date( (string) $conflict['date_from'], $lang ),
						Formatter::date( (string) $conflict['date_to'], $lang )
					),
				)
			);
			$this->mailer->sendForBooking(
				'admin_alert',
				$booking,
				array(
					'extra' => $extra,
					'actor' => self::ACTOR,
				)
			);
		} catch ( \Throwable $e ) {
			error_log( sprintf( '[marclie-booking] iCal conflict email for booking %d: %s', (int) $booking['id'], $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
