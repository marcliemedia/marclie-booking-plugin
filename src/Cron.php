<?php
/**
 * Cron hook names, custom schedule and (un)scheduling.
 */

namespace Marclie\Booking;

defined( 'ABSPATH' ) || exit;

final class Cron {

	/** Releases expired checkout holds. */
	public const REAP_HOLDS = 'mbk_reap_holds';

	/**
	 * Daily jobs (balance charges, completed bookings, reminders) at 09:00 site time.
	 * A self-rescheduling single event: a fixed 86400 s interval would drift by an hour across DST.
	 */
	public const DAILY = 'mbk_daily';

	public const DAILY_HOUR = 9;

	public const FIVE_MINUTES = 'mbk_five_minutes';

	/** Stripe refunds left unsent (network errors) — retried well inside the 24 h idempotency window. */
	public const HOURLY = 'mbk_hourly';

	/** iCal imports (Ical\SyncService) every 15 minutes. */
	public const ICAL_SYNC = 'mbk_ical_sync';

	public const FIFTEEN_MINUTES = 'mbk_fifteen_minutes';

	/** Single event per unit: conflicts and emails of a pre-checkout iCal refresh (argument: unit ID). */
	public const ICAL_FOLLOWUP = 'mbk_ical_followup';

	public const HOOKS = array( self::REAP_HOLDS, self::DAILY, self::HOURLY, self::ICAL_SYNC );

	public static function hooks(): void {
		add_filter( 'cron_schedules', array( self::class, 'addSchedules' ) );
		// Installs that predate a hook (no activation since) get it on the next request.
		add_action( 'init', array( self::class, 'scheduleMissing' ) );
		// Rescheduled first, so a failing daily job cannot break the chain.
		add_action( self::DAILY, array( self::class, 'rescheduleDaily' ), PHP_INT_MIN );
	}

	public static function scheduleMissing(): void {
		if ( ! wp_next_scheduled( self::HOURLY ) || ! wp_next_scheduled( self::ICAL_SYNC ) ) {
			self::schedule();
		}
	}

	/**
	 * @param array<string, array{interval:int, display:string}> $schedules
	 * @return array<string, array{interval:int, display:string}>
	 */
	public static function addSchedules( array $schedules ): array {
		$schedules[ self::FIVE_MINUTES ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (Marclie Booking)', 'marclie-booking' ),
		);
		$schedules[ self::FIFTEEN_MINUTES ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (Marclie Booking)', 'marclie-booking' ),
		);

		return $schedules;
	}

	/**
	 * Idempotent: only schedules hooks that have no pending event.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::REAP_HOLDS ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::FIVE_MINUTES, self::REAP_HOLDS );
		}
		if ( ! wp_next_scheduled( self::HOURLY ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::HOURLY );
		}
		if ( ! wp_next_scheduled( self::ICAL_SYNC ) ) {
			wp_schedule_event( time() + 2 * MINUTE_IN_SECONDS, self::FIFTEEN_MINUTES, self::ICAL_SYNC );
		}

		// Migrate the former recurring (daily interval) event to a single event.
		if ( false !== wp_get_schedule( self::DAILY ) ) {
			wp_clear_scheduled_hook( self::DAILY );
		}

		self::rescheduleDaily();
	}

	/**
	 * Schedules the next mbk_daily run at 09:00 site time unless one is pending.
	 * Runs at the start of every mbk_daily execution (the current event is already unscheduled).
	 */
	public static function rescheduleDaily(): void {
		if ( ! wp_next_scheduled( self::DAILY ) ) {
			wp_schedule_single_event( self::nextDailyRun()->getTimestamp(), self::DAILY );
		}
	}

	public static function clear(): void {
		foreach ( self::HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		// Single events with arguments: every instance.
		wp_unschedule_hook( self::ICAL_FOLLOWUP );
	}

	/**
	 * Next 09:00 in the site timezone, strictly in the future.
	 */
	public static function nextDailyRun(): \DateTimeImmutable {
		$now = new \DateTimeImmutable( 'now', wp_timezone() );
		$run = $now->setTime( self::DAILY_HOUR, 0 );

		return $run > $now ? $run : $run->modify( '+1 day' );
	}
}
