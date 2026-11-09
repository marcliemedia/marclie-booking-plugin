<?php
/**
 * Data for the admin "Pregled" dashboard: KPIs, today's arrivals/departures,
 * alerts and chart series. Booking rows are returned raw (with `paid_cents`);
 * the REST layer presents them.
 */

namespace Marclie\Booking\Service;

use Marclie\Booking\Domain\Clock;
use Marclie\Booking\Ical\FeedRepository;
use Marclie\Booking\Ical\IcalException;
use Marclie\Booking\Ical\SyncService;
use Marclie\Booking\Payment\StripeConfig;
use Marclie\Booking\Payment\StripeStatus;
use Marclie\Booking\Repository\BookingRepository;
use Marclie\Booking\Repository\Db;
use Marclie\Booking\Repository\EventRepository;
use Marclie\Booking\Repository\NightsRepository;
use Marclie\Booking\Repository\PaymentRepository;
use Marclie\Booking\Units\UnitProvider;

defined( 'ABSPATH' ) || exit;

final class DashboardService {

	/** An expiring hold this far past its expiry means the reaper cron is not running. */
	private const STALE_EXPIRED_MINUTES = 15;

	/** Admin holds without expiry are flagged after this many hours without payment. */
	private const STALE_ADMIN_HOLD_HOURS = 48;

	private const CHART_MONTHS = 12;

	private const BALANCE_WINDOW_DAYS = 14;

	/** Disputes stay on the dashboard this long. */
	private const DISPUTE_DAYS = 60;

	/** Automatic overpayment refunds stay on the dashboard this long. */
	private const OVERPAYMENT_DAYS = 14;

	/** No webhook this long after a card payment succeeded → endpoint misconfigured. */
	private const WEBHOOK_SILENT_HOURS = 24;

	/** Payments younger than this may still be waiting for their webhook. */
	private const WEBHOOK_GRACE_MINUTES = 15;

	public function __construct(
		private BookingRepository $bookings,
		private PaymentRepository $payments,
		private NightsRepository $nights,
		private EventRepository $events,
		private UnitProvider $units,
		private Clock $clock,
		private SyncService $ical
	) {}

	/**
	 * @param string $month Y-m (KPIs and the first chart month).
	 * @return array{kpis: array<string, int|float>, today: array{arrivals: list<array<string, mixed>>, departures: list<array<string, mixed>>}, alerts: list<array<string, mixed>>, occupancy_by_month: list<array{month:string, pct:float}>, revenue_by_unit: list<array{unit_id:int, cents:int}>}
	 */
	public function build( string $month ): array {
		$tz        = $this->clock->today()->getTimezone();
		$today     = $this->clock->today();
		$todayYmd  = $today->format( 'Y-m-d' );
		$unitCount = count( $this->units->all() );

		$monthStart = new \DateTimeImmutable( $month . '-01', $tz );
		$chartEnd   = $monthStart->modify( sprintf( '+%d months', self::CHART_MONTHS ) );

		$sold      = $this->bookings->overlapping( $monthStart->format( 'Y-m-d' ), $chartEnd->format( 'Y-m-d' ), BookingRepository::SOLD_STATUSES );
		$occupancy = Occupancy::byMonth( $sold, $month, self::CHART_MONTHS, $unitCount );
		$values    = array_values( $occupancy );

		$monthEnd   = $monthStart->modify( '+1 month' );
		$due        = $this->bookings->findAll(
			array(
				'status'           => array( 'confirmed', 'paid', 'balance_failed' ),
				'balance_due_from' => $monthStart->format( 'Y-m-d' ),
				'balance_due_to'   => $monthEnd->modify( '-1 day' )->format( 'Y-m-d' ),
				'payment_state'    => 'outstanding',
			),
			'arrival'
		);
		$dueSoon    = $this->bookings->findAll(
			array(
				'status'         => array( 'confirmed', 'paid', 'balance_failed' ),
				'balance_due_to' => $today->modify( sprintf( '+%d days', self::BALANCE_WINDOW_DAYS ) )->format( 'Y-m-d' ),
				'payment_state'  => 'outstanding',
			),
			'arrival'
		);
		$outstanding = static fn( array $rows ): int => array_sum( array_map( static fn( array $b ): int => max( 0, (int) $b['total_cents'] - (int) $b['paid_cents'] ), $rows ) );

		$yearStart = $today->setDate( (int) $today->format( 'Y' ), 1, 1 );
		$revenue   = $this->bookings->revenueByUnit( $yearStart->format( 'Y-m-d' ), $yearStart->modify( '+1 year' )->format( 'Y-m-d' ) );

		return array(
			'kpis'               => array(
				'occupancy_pct'          => $values[0] ?? 0.0,
				'occupancy_next_pct'     => $values[1] ?? 0.0,
				'collected_cents'        => $this->payments->netReceivedBetween( Db::utc( $monthStart ), Db::utc( $monthEnd ) ),
				'scheduled_cents'        => $outstanding( $due ),
				'new_bookings_7d'        => $this->bookings->countCreatedSince( Db::utc( $this->clock->now()->modify( '-7 days' ) ), array( 'expired' ) ),
				'balances_due_14d_cents' => $outstanding( $dueSoon ),
			),
			'today'              => array(
				'arrivals'   => $this->bookings->findAll( array( 'status' => BookingRepository::ACTIVE_STATUSES, 'date_field' => 'arrival', 'from' => $todayYmd, 'to' => $todayYmd ) ),
				'departures' => $this->bookings->findAll( array( 'status' => BookingRepository::ACTIVE_STATUSES, 'date_field' => 'departure', 'from' => $todayYmd, 'to' => $todayYmd ) ),
			),
			'alerts'             => $this->alerts( $todayYmd ),
			'occupancy_by_month' => array_map(
				static fn( string $m, float $pct ): array => array(
					'month' => $m,
					'pct'   => $pct,
				),
				array_keys( $occupancy ),
				$values
			),
			'revenue_by_unit'    => array_map(
				static fn( $unit ): array => array(
					'unit_id' => $unit->id,
					'cents'   => $revenue[ $unit->id ] ?? 0,
				),
				$this->units->all()
			),
		);
	}

	/**
	 * Things that need the owner's attention.
	 *
	 * @return list<array{type:string, booking_id?:int, booking_ref?:string, message:string, url?:string}>
	 */
	private function alerts( string $todayYmd ): array {
		$alerts = array();

		if ( ! StripeConfig::configured() ) {
			$alerts[] = array(
				'type'    => 'stripe_not_configured',
				'message' => __( 'Stripe is not configured: online card payments are disabled.', 'marclie-booking' ),
			);
		}

		$silent = $this->webhookSilent();
		if ( null !== $silent ) {
			$alerts[] = $silent;
		}

		$since = Db::utc( $this->clock->now()->modify( sprintf( '-%d days', self::DISPUTE_DAYS ) ) );
		foreach ( $this->events->ofTypeSince( 'dispute_created', $since ) as $event ) {
			$alerts[] = array(
				'type'        => 'dispute',
				'booking_id'  => (int) $event['booking_id'],
				'booking_ref' => (string) $event['booking_ref'],
				/* translators: 1: booking reference, 2: amount */
				'message'     => sprintf( __( 'The guest’s bank disputed a card payment of %1$s (%2$s). Respond in the Stripe Dashboard.', 'marclie-booking' ), $event['booking_ref'], number_format_i18n( (int) ( $event['payload']['amount_cents'] ?? 0 ) / 100, 2 ) . ' €' ),
				'url'         => StripeConfig::paymentUrl( (string) ( $event['payload']['intent'] ?? '' ) ),
			);
		}

		$since = Db::utc( $this->clock->now()->modify( sprintf( '-%d days', self::OVERPAYMENT_DAYS ) ) );
		foreach ( $this->events->ofTypeSince( 'overpayment', $since ) as $event ) {
			$alerts[] = array(
				'type'        => 'overpayment',
				'booking_id'  => (int) $event['booking_id'],
				'booking_ref' => (string) $event['booking_ref'],
				/* translators: 1: booking reference, 2: amount */
				'message'     => sprintf( __( '%1$s was paid twice by card; the extra %2$s is refunded automatically. Check the refund in the booking’s payments.', 'marclie-booking' ), $event['booking_ref'], number_format_i18n( (int) ( $event['payload']['amount_cents'] ?? 0 ) / 100, 2 ) . ' €' ),
			);
		}

		foreach ( $this->bookings->findAll( array( 'status' => 'balance_failed' ), 'arrival', 'ASC', 50 ) as $booking ) {
			$alerts[] = array(
				'type'        => 'balance_failed',
				'booking_id'  => (int) $booking['id'],
				'booking_ref' => (string) $booking['ref'],
				/* translators: 1: booking reference, 2: guest name */
				'message'     => sprintf( __( 'Balance payment failed for %1$s (%2$s).', 'marclie-booking' ), $booking['ref'], trim( $booking['first_name'] . ' ' . $booking['last_name'] ) ),
			);
		}

		// Guest cancellation requests from /moj-racun/ while the booking is still running.
		$since = Db::utc( $this->clock->now()->modify( sprintf( '-%d days', self::DISPUTE_DAYS ) ) );
		foreach ( $this->events->ofTypeSince( 'cancel_requested', $since ) as $event ) {
			$booking = $this->bookings->find( (int) $event['booking_id'] );
			if ( null === $booking || ! in_array( $booking['status'], array( 'confirmed', 'paid', 'balance_failed' ), true ) ) {
				continue;
			}
			$reason   = (string) ( $event['payload']['reason'] ?? '' );
			$alerts[] = array(
				'type'         => 'cancel_request',
				'booking_id'   => (int) $booking['id'],
				'booking_ref'  => (string) $booking['ref'],
				/* translators: 1: booking reference, 2: guest name */
				'message'      => sprintf( __( '%1$s: %2$s asked to cancel the booking from their account.', 'marclie-booking' ), $booking['ref'], trim( $booking['first_name'] . ' ' . $booking['last_name'] ) ),
				'reason'       => '' !== $reason ? $reason : null,
				'requested_at' => ( new \DateTimeImmutable( (string) $event['created_at'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( DATE_ATOM ),
			);
		}

		$now          = $this->clock->now();
		$expiredLimit = Db::utc( $now->modify( sprintf( '-%d minutes', self::STALE_EXPIRED_MINUTES ) ) );
		$adminLimit   = Db::utc( $now->modify( sprintf( '-%d hours', self::STALE_ADMIN_HOLD_HOURS ) ) );
		foreach ( $this->bookings->findAll( array( 'status' => 'pending_payment' ), 'created_at', 'ASC', 100 ) as $booking ) {
			$expires = $booking['hold_expires_at'];
			$stale   = null === $expires ? (string) $booking['created_at'] < $adminLimit : (string) $expires < $expiredLimit;
			if ( ! $stale ) {
				continue;
			}
			$alerts[] = array(
				'type'        => 'stale_hold',
				'booking_id'  => (int) $booking['id'],
				'booking_ref' => (string) $booking['ref'],
				'message'     => null === $expires
					/* translators: %s: booking reference */
					? sprintf( __( '%s is still awaiting payment.', 'marclie-booking' ), $booking['ref'] )
					/* translators: %s: booking reference */
					: sprintf( __( 'The hold of %s has expired but was not released (check WP-Cron).', 'marclie-booking' ), $booking['ref'] ),
			);
		}

		// Live overlaps (a resolved conflict disappears by itself); one alert per booking, at its first conflicting night.
		$horizon  = ( new \DateTimeImmutable( $todayYmd ) )->modify( '+1 year' )->format( 'Y-m-d' );
		$reported = array();
		foreach ( $this->nights->externalConflicts( $todayYmd, $horizon ) as $conflict ) {
			if ( isset( $reported[ $conflict['booking_id'] ] ) ) {
				continue;
			}
			$reported[ $conflict['booking_id'] ] = true;
			$alerts[]                            = array(
				'type'        => 'ical_conflict',
				'booking_id'  => $conflict['booking_id'],
				'booking_ref' => $conflict['ref'],
				'unit_id'     => $conflict['unit_id'],
				'channel'     => $conflict['channel'],
				'date'        => $conflict['date'],
				/* translators: 1: booking reference, 2: channel (Airbnb, Booking.com…) */
				'message'     => sprintf( __( '%1$s overlaps dates blocked on %2$s. Resolve the double booking.', 'marclie-booking' ), $conflict['ref'], FeedRepository::label( $conflict['channel'] ) ),
			);
		}

		$iso = static fn( string $utc ): string => ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( DATE_ATOM );
		foreach ( $this->ical->failingFeeds() as $feed ) {
			$unit     = $this->units->get( (int) $feed['unit_id'] );
			$name     = null !== $unit ? $unit->title : '#' . $feed['unit_id'];
			$channel  = FeedRepository::label( (string) $feed['channel'] );
			$alerts[] = array(
				'type'    => 'ical_feed_failing',
				'feed_id' => (int) $feed['id'],
				'unit_id' => (int) $feed['unit_id'],
				'channel' => (string) $feed['channel'],
				'since'   => $iso( (string) ( $feed['stalled'] ? ( $feed['last_ok_at'] ?? $feed['created_at'] ) : $feed['fail_since'] ) ),
				'stalled' => (bool) $feed['stalled'],
				'message' => $feed['stalled']
					/* translators: 1: channel (Airbnb, Booking.com…), 2: unit name */
					? sprintf( __( 'The %1$s calendar of %2$s has not been synced for more than 2 hours. Check that WP-Cron runs.', 'marclie-booking' ), $channel, $name )
					/* translators: 1: channel (Airbnb, Booking.com…), 2: unit name, 3: error message */
					: sprintf( __( 'The %1$s calendar of %2$s cannot be imported: %3$s', 'marclie-booking' ), $channel, $name, (string) IcalException::fromStored( $feed['last_error'] )?->describe() ),
			);
		}

		// Info: an OTA keeps echoing dates we no longer export (our booking/block is gone).
		foreach ( $this->ical->staleEchoes() as $echo ) {
			$unit     = $this->units->get( $echo['unit_id'] );
			$alerts[] = array(
				'type'              => 'ical_stale_echo',
				'level'             => 'info',
				'feed_id'           => $echo['feed_id'],
				'unit_id'           => $echo['unit_id'],
				'channel'           => $echo['channel'],
				'external_block_id' => $echo['id'],
				'date'              => $echo['date_from'],
				'date_to'           => $echo['date_to'],
				'message'           => sprintf(
					/* translators: 1: channel (Airbnb, Booking.com…), 2: unit name, 3: first night, 4: departure date */
					__( '%1$s still blocks %2$s from %3$s to %4$s although the website no longer exports these dates. Unblock them on %1$s if they should be free.', 'marclie-booking' ),
					FeedRepository::label( $echo['channel'] ),
					null !== $unit ? $unit->title : '#' . $echo['unit_id'],
					$echo['date_from'],
					$echo['date_to']
				),
			);
		}

		return $alerts;
	}

	/**
	 * A card payment succeeded more than WEBHOOK_GRACE_MINUTES ago within the
	 * last WEBHOOK_SILENT_HOURS, but no webhook arrived since: payments are only
	 * confirmed by the page/cron fallbacks — the endpoint or secret is wrong.
	 *
	 * @return array{type:string, message:string}|null
	 */
	private function webhookSilent(): ?array {
		if ( ! StripeConfig::configured() ) {
			return null;
		}

		$now    = $this->clock->now();
		$oldest = $this->payments->oldestStripeChargeBetween(
			Db::utc( $now->modify( sprintf( '-%d hours', self::WEBHOOK_SILENT_HOURS ) ) ),
			Db::utc( $now->modify( sprintf( '-%d minutes', self::WEBHOOK_GRACE_MINUTES ) ) )
		);
		if ( null === $oldest ) {
			return null;
		}

		$last = StripeStatus::get()['webhook']['last_received_at'];
		if ( null !== $last && Db::utc( new \DateTimeImmutable( $last ) ) >= $oldest ) {
			return null;
		}

		return array(
			'type'    => 'webhook_silent',
			'message' => __( 'No Stripe webhook arrived for recent card payments. Check the webhook endpoint and MBK_STRIPE_WEBHOOK_SECRET.', 'marclie-booking' ),
		);
	}
}
