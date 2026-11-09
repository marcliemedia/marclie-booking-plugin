<?php
/**
 * Runs the iCal imports: every 15 minutes (cron `mbk_ical_sync`), on demand
 * from the admin app / CLI, and right before a checkout holds nights
 * (`mbk_checkout_before_hold`).
 *
 * Before a checkout the unit's feeds older than 5 minutes are fetched within
 * ONE 3-second deadline (each fetch gets the time left, no redirects; feeds
 * the deadline does not reach keep their cached blocks). Failures keep the
 * cached blocks and are logged; conflict detection and admin emails of that
 * refresh run later in the single cron event `mbk_ical_followup` (unit), never
 * in the checkout request.
 *
 * After a successful import new conflicts are logged and mailed
 * (ConflictDetector) and echo blocks are re-checked (FeedRepository::
 * refreshEchoes). A feed failing for more than 2 hours mails the admin once per
 * failure streak (mbk_events `ical_feed_failing`, external_id
 * `ical_feed_failing:{feed_id}:{fail_since}`); the dashboard also flags active
 * feeds not synced successfully for 2 hours (cron not running).
 *
 * One sync, change or removal per feed at a time: an atomic lock row
 * (Db::claimLock, 2-minute lease); a fatal error during a sync records the
 * failure and frees the lock from a shutdown handler.
 */

namespace Marclie\Booking\Ical;

use Marclie\Booking\Container;
use Marclie\Booking\Cron;
use Marclie\Booking\Domain\Clock;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\Email\Mailer;
use Marclie\Booking\Repository\Db;
use Marclie\Booking\Repository\EventRepository;
use Marclie\Booking\Repository\LockConflictException;
use Marclie\Booking\Service\AvailabilityService;
use Marclie\Booking\Units\UnitProvider;

defined( 'ABSPATH' ) || exit;

final class SyncService {

	public const INTERVAL_MINUTES = 15;

	/** Feeds synced longer ago than this are refreshed before a checkout hold. */
	public const STALE_SECONDS = 5 * MINUTE_IN_SECONDS;

	/** Total time the pre-checkout refresh may take. */
	public const ON_DEMAND_SECONDS = 3.0;

	/** Less time left than this: the remaining feeds are not fetched. */
	private const MIN_FETCH_SECONDS = 0.5;

	/** A feed failing (or not synced) this long alerts the admin. */
	public const FAILING_HOURS = 2;

	/** An echo still imported this long after the OTA re-read our export is flagged. */
	public const STALE_ECHO_HOURS = 24;

	public const EVENT_FAILING = 'ical_feed_failing';

	public const ACTOR = 'system:ical';

	public const LOCK_PREFIX = 'mbk_ical_lock_';

	private const LOCK_SECONDS = 120;

	/** @var array{feed:array<string, mixed>, lock:string}|null The sync running in this request. */
	private ?array $running = null;

	private bool $shutdownRegistered = false;

	public function __construct(
		private FeedRepository $feeds,
		private Importer $importer,
		private ConflictDetector $conflicts,
		private EventRepository $events,
		private Mailer $mailer,
		private UnitProvider $units,
		private Clock $clock
	) {}

	public static function hooks(): void {
		add_action( Cron::ICAL_SYNC, array( self::class, 'cron' ) );
		add_action( Cron::ICAL_FOLLOWUP, array( self::class, 'followUpCron' ) );
		add_action( 'mbk_checkout_before_hold', array( self::class, 'beforeHold' ) );
	}

	/** Cron `mbk_ical_sync`. */
	public static function cron(): void {
		Container::icalSync()->syncAll();
	}

	/**
	 * Cron `mbk_ical_followup` (single event per unit).
	 *
	 * @param int|mixed $unitId
	 */
	public static function followUpCron( $unitId ): void {
		Container::icalSync()->followUp( (int) $unitId );
	}

	/**
	 * Action `mbk_checkout_before_hold`.
	 *
	 * @param Stay|mixed $stay
	 */
	public static function beforeHold( $stay ): void {
		if ( $stay instanceof Stay ) {
			Container::icalSync()->refreshUnit( $stay->unitId );
		}
	}

	/**
	 * Syncs one feed now. Never throws.
	 *
	 * @param array<string, mixed> $feed    FeedRepository row.
	 * @param float                $seconds Deadline of the fetch (all hops).
	 * @param bool                 $defer   Conflicts and emails later, in cron (checkout requests).
	 * @return array{feed_id:int, ok:bool, status:string, events_count:int, error:?IcalException, conflicts:int, warnings:list<string>}
	 */
	public function syncFeed( array $feed, float $seconds = Importer::TIMEOUT, int $maxRedirects = Importer::MAX_REDIRECTS, bool $defer = false ): array {
		$lock = Db::claimLock( self::LOCK_PREFIX . (int) $feed['id'], self::LOCK_SECONDS );
		if ( null === $lock ) {
			return self::result( $feed, 'busy' ); // Another request is syncing this feed right now.
		}

		try {
			return $this->runSync( $feed, $lock, $seconds, $maxRedirects, $defer );
		} finally {
			Db::releaseLock( self::LOCK_PREFIX . (int) $feed['id'], $lock );
		}
	}

	/**
	 * Every active feed (optionally of one unit), one after another.
	 *
	 * @return list<array{feed_id:int, ok:bool, status:string, events_count:int, error:?IcalException, conflicts:int, warnings:list<string>}>
	 */
	public function syncAll( ?int $unitId = null ): array {
		$feeds = null === $unitId ? $this->feeds->all( true ) : $this->feeds->forUnit( $unitId, true );

		return array_map( fn( array $feed ): array => $this->syncFeed( $feed ), $feeds );
	}

	/**
	 * Before a checkout hold: refreshes the unit's feeds older than STALE_SECONDS
	 * within one ON_DEMAND_SECONDS deadline, without redirects; conflicts and
	 * emails are deferred to cron. Failures are logged; cached blocks stay.
	 *
	 * @return list<array{feed_id:int, ok:bool, status:string, events_count:int, error:?IcalException, conflicts:int, warnings:list<string>}>
	 */
	public function refreshUnit( int $unitId, float $seconds = self::ON_DEMAND_SECONDS ): array {
		$deadline = microtime( true ) + $seconds;
		$unit     = $this->units->get( $unitId );
		$unitId   = null !== $unit ? $unit->id : $unitId;
		$results  = array();
		try {
			$limit = Db::utc( $this->clock->now()->modify( sprintf( '-%d seconds', self::STALE_SECONDS ) ) );
			foreach ( $this->feeds->forUnit( $unitId, true ) as $feed ) {
				if ( null !== $feed['last_synced_at'] && (string) $feed['last_synced_at'] > $limit ) {
					continue;
				}
				$left = $deadline - microtime( true );
				if ( $left < self::MIN_FETCH_SECONDS ) {
					error_log( sprintf( '[marclie-booking] iCal refresh before checkout: no time left for feed %d (cached blocks used)', (int) $feed['id'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					break;
				}
				$result    = $this->syncFeed( $feed, $left, 0, true );
				$results[] = $result;
				if ( ! $result['ok'] && null !== $result['error'] ) {
					error_log( sprintf( '[marclie-booking] iCal refresh before checkout, feed %d: %s (cached blocks used)', (int) $feed['id'], $result['error']->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
		} catch ( \Throwable $e ) {
			error_log( '[marclie-booking] iCal refresh before checkout: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $results;
	}

	/** Deferred work of a pre-checkout refresh: conflicts and failing-feed emails of the unit. */
	public function followUp( int $unitId ): void {
		$this->conflicts->detect( $unitId );
		foreach ( $this->feeds->forUnit( $unitId, true ) as $feed ) {
			$this->alertIfFailing( (int) $feed['id'] );
		}
	}

	/**
	 * Adds an import feed after a test fetch + parse, and imports it right away.
	 *
	 * @return array<string, mixed> The stored feed.
	 * @throws IcalException The URL cannot be fetched or is not a calendar (nothing is stored).
	 */
	public function addFeed( int $unitId, string $channel, string $url ): array {
		[ $events, $fetched, $warnings ] = $this->probe( $url );

		$id = $this->feeds->insert( $unitId, $channel, $url );
		if ( 0 === $id ) {
			throw new \RuntimeException( Db::message( 'Saving the iCal feed failed' ) );
		}
		$feed = (array) $this->feeds->find( $id );
		$this->importer->store( $feed, $events, $fetched['etag'], $fetched['last_modified'], $warnings );
		$this->conflicts->detect( $unitId );

		return (array) $this->feeds->find( $id );
	}

	/**
	 * Changes a feed's URL (test fetch first, then re-import) and/or switches it
	 * on or off. Switched off, its blocks are removed (they no longer block the
	 * calendar); switched on, it is synced at once. Holds the feed lock.
	 *
	 * @param array<string, mixed> $feed
	 * @return array<string, mixed> The stored feed.
	 * @throws IcalException busy, or the new URL cannot be fetched / read (nothing is changed).
	 */
	public function changeFeed( array $feed, ?string $url, ?bool $active ): array {
		$id = (int) $feed['id'];

		return $this->locked(
			$id,
			function ( string $lock ) use ( $id, $url, $active ): array {
				$feed = (array) $this->feeds->find( $id );
				if ( null !== $url && $url !== $feed['url'] ) {
					[ $events, $fetched, $warnings ] = $this->probe( $url );
					$this->feeds->update(
						$id,
						array(
							'url'           => $url,
							'etag'          => null,
							'last_modified' => null,
						)
					);
					$feed = (array) $this->feeds->find( $id );
					if ( $feed['active'] && false !== $active ) {
						$this->importer->store( $feed, $events, $fetched['etag'], $fetched['last_modified'], $warnings );
						$this->conflicts->detect( (int) $feed['unit_id'] );
					}
				}

				if ( false === $active && $feed['active'] ) {
					$this->feeds->update(
						$id,
						array(
							'active'        => 0,
							'fail_since'    => null,
							'last_error'    => null,
							'events_count'  => 0,
							'etag'          => null,
							'last_modified' => null,
						)
					);
					if ( $this->feeds->deleteBlocksOfFeed( $id ) > 0 ) {
						AvailabilityService::changed( (int) $feed['unit_id'] );
					}
				} elseif ( true === $active && ! $feed['active'] ) {
					$this->feeds->update( $id, array( 'active' => 1 ) );
					$this->runSync( (array) $this->feeds->find( $id ), $lock, Importer::TIMEOUT, Importer::MAX_REDIRECTS, false );
				}

				return (array) $this->feeds->find( $id );
			}
		);
	}

	/**
	 * Deletes a feed and the blocks it imported. Holds the feed lock.
	 *
	 * @param array<string, mixed> $feed
	 * @throws IcalException busy.
	 */
	public function removeFeed( array $feed ): void {
		$id = (int) $feed['id'];
		$this->locked(
			$id,
			function () use ( $id, $feed ): bool {
				$this->feeds->delete( $id );
				if ( $this->feeds->deleteBlocksOfFeed( $id ) > 0 ) {
					AvailabilityService::changed( (int) $feed['unit_id'] );
				}

				return true;
			}
		);
	}

	/**
	 * Test fetch + parse of a URL (admin form, 10 s deadline).
	 *
	 * @return array{0: list<array{uid:string, date_from:string, date_to:string, summary:string}>, 1: array{status:string, body:string, etag:string, last_modified:string}, 2: list<string>}
	 * @throws IcalException
	 */
	public function probe( string $url ): array {
		$fetched  = $this->importer->fetch( $url, null, null );
		$warnings = array();
		$events   = $this->importer->parse( $fetched['body'], $warnings );

		return array( $events, $fetched, $warnings );
	}

	/**
	 * Active feeds failing for more than FAILING_HOURS, or without a successful
	 * sync for that long (`stalled`: cron not running) — dashboard.
	 *
	 * @return list<array<string, mixed>> Feed rows plus `stalled` (bool).
	 */
	public function failingFeeds(): array {
		$limit = $this->failingLimit();
		$out   = array();
		foreach ( $this->feeds->all( true ) as $feed ) {
			$failing = null !== $feed['fail_since'] && (string) $feed['fail_since'] <= $limit;
			$lastOk  = $feed['last_ok_at'] ?? $feed['created_at'] ?? null;
			$stalled = ! $failing && null !== $lastOk && (string) $lastOk <= $limit;
			if ( $failing || $stalled ) {
				$out[] = $feed + array( 'stalled' => $stalled );
			}
		}

		return $out;
	}

	/**
	 * Echo blocks still imported STALE_ECHO_HOURS after the OTA re-read our
	 * export although our own event is gone (dashboard info).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function staleEchoes(): array {
		return $this->feeds->staleEchoes( Db::utc( $this->clock->now()->modify( sprintf( '-%d hours', self::STALE_ECHO_HOURS ) ) ) );
	}

	/* Internals -------------------------------------------------------------- */

	/**
	 * @param array<string, mixed> $feed
	 * @return array{feed_id:int, ok:bool, status:string, events_count:int, error:?IcalException, conflicts:int, warnings:list<string>}
	 */
	private function runSync( array $feed, string $lock, float $seconds, int $maxRedirects, bool $defer ): array {
		$this->running = array(
			'feed' => $feed,
			'lock' => $lock,
		);
		$this->registerShutdown();

		try {
			$imported = $this->importer->import( $feed, $seconds, $maxRedirects );
			$result   = self::result( $feed, $imported['status'], $imported['events_count'] );

			$result['warnings'] = $imported['warnings'];
			if ( 'inactive' !== $imported['status'] ) {
				$this->feeds->refreshEchoes( (int) $feed['id'], (int) $feed['unit_id'], (string) $feed['channel'], Db::utc( $this->clock->now() ) );
			}
			if ( 'ok' === $imported['status'] ) {
				if ( $defer ) {
					$this->scheduleFollowUp( (int) $feed['unit_id'] );
				} else {
					$result['conflicts'] = count( $this->conflicts->detect( (int) $feed['unit_id'] ) );
				}
			}
		} catch ( IcalException $e ) {
			$result = $this->failed( $feed, $e, $defer );
		} catch ( LockConflictException $e ) {
			// The feed row was locked (deadlock / lock wait): nothing written, the next cron run retries.
			error_log( sprintf( '[marclie-booking] iCal feed %d: busy (%s)', (int) $feed['id'], $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$result = self::result( $feed, 'busy' );
		} catch ( \Throwable $e ) {
			error_log( sprintf( '[marclie-booking] iCal feed %d: %s: %s', (int) $feed['id'], get_class( $e ), $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$result = $this->failed( $feed, new IcalException( 'import_failed' ), $defer );
		} finally {
			$this->running = null;
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $feed
	 * @return array{feed_id:int, ok:bool, status:string, events_count:int, error:?IcalException, conflicts:int, warnings:list<string>}
	 */
	private static function result( array $feed, string $status, ?int $eventsCount = null, ?IcalException $error = null ): array {
		return array(
			'feed_id'      => (int) $feed['id'],
			'ok'           => null === $error,
			'status'       => $status,
			'events_count' => $eventsCount ?? (int) $feed['events_count'],
			'error'        => $error,
			'conflicts'    => 0,
			'warnings'     => array(),
		);
	}

	/**
	 * @param array<string, mixed> $feed
	 * @return array{feed_id:int, ok:bool, status:string, events_count:int, error:?IcalException, conflicts:int, warnings:list<string>}
	 */
	private function failed( array $feed, IcalException $error, bool $defer ): array {
		$this->feeds->markFailed( (int) $feed['id'], Db::utc( $this->clock->now() ), $error->toStored() );
		if ( $defer ) {
			$this->scheduleFollowUp( (int) $feed['unit_id'] );
		} else {
			$this->alertIfFailing( (int) $feed['id'] );
		}

		return self::result( $feed, 'error', null, $error );
	}

	/**
	 * Runs `$fn( $lock )` holding the feed lock.
	 *
	 * @template T
	 * @param callable(string): T $fn
	 * @return T
	 * @throws IcalException busy.
	 */
	private function locked( int $feedId, callable $fn ) {
		$lock = Db::claimLock( self::LOCK_PREFIX . $feedId, self::LOCK_SECONDS );
		if ( null === $lock ) {
			throw new IcalException( 'busy' );
		}
		try {
			return $fn( $lock );
		} finally {
			Db::releaseLock( self::LOCK_PREFIX . $feedId, $lock );
		}
	}

	private function scheduleFollowUp( int $unitId ): void {
		if ( ! wp_next_scheduled( Cron::ICAL_FOLLOWUP, array( $unitId ) ) ) {
			wp_schedule_single_event( time(), Cron::ICAL_FOLLOWUP, array( $unitId ) );
		}
	}

	/** A fatal error (memory, time limit…) during a sync still records the failure and frees the lock. */
	private function registerShutdown(): void {
		if ( $this->shutdownRegistered ) {
			return;
		}
		$this->shutdownRegistered = true;
		register_shutdown_function(
			function (): void {
				$error = error_get_last();
				if ( null === $this->running || null === $error || ! in_array( $error['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
					return;
				}
				$feed = $this->running['feed'];
				$this->feeds->markFailed( (int) $feed['id'], Db::utc( $this->clock->now() ), ( new IcalException( 'fatal', (string) $error['message'] ) )->toStored() );
				Db::releaseLock( self::LOCK_PREFIX . (int) $feed['id'], $this->running['lock'] );
			}
		);
	}

	/** Mails the admin once per failure streak longer than FAILING_HOURS. */
	private function alertIfFailing( int $feedId ): void {
		$feed = $this->feeds->find( $feedId );
		if ( null === $feed || null === $feed['fail_since'] || (string) $feed['fail_since'] > $this->failingLimit() ) {
			return;
		}

		$key = self::EVENT_FAILING . ':' . $feedId . ':' . str_replace( array( '-', ' ', ':' ), '', (string) $feed['fail_since'] );
		if ( ! $this->events->logExternal(
			$key,
			self::EVENT_FAILING,
			self::ACTOR,
			null,
			null,
			array(
				'feed_id' => $feedId,
				'unit_id' => (int) $feed['unit_id'],
				'channel' => (string) $feed['channel'],
				'since'   => (string) $feed['fail_since'],
				'error'   => (string) $feed['last_error'],
			)
		) ) {
			return; // Already alerted for this streak.
		}

		$unit = $this->units->get( (int) $feed['unit_id'] );
		try {
			$this->mailer->sendAdminAlert(
				// Built in the admin language: the stored error is translated here.
				static fn(): array => array(
					'subject_ref'   => ( null !== $unit ? $unit->title : '#' . $feed['unit_id'] ) . ' · ' . FeedRepository::label( (string) $feed['channel'] ),
					'alert_title'   => __( 'Calendar sync failing', 'marclie-booking' ),
					'alert_message' => sprintf(
						/* translators: 1: channel (Airbnb, Booking.com…), 2: unit name, 3: number of hours, 4: error message */
						__( 'The %1$s calendar of %2$s has not been imported for more than %3$d hours: %4$s Bookings made there meanwhile are not blocked on the website. Check the import URL in Settings › iCal.', 'marclie-booking' ),
						FeedRepository::label( (string) $feed['channel'] ),
						null !== $unit ? $unit->title : '#' . $feed['unit_id'],
						self::FAILING_HOURS,
						rtrim( (string) IcalException::fromStored( $feed['last_error'] )?->describe(), '.' ) . '.'
					),
				),
				'#/settings/ical?unit=' . (int) $feed['unit_id'],
				self::ACTOR
			);
		} catch ( \Throwable $e ) {
			error_log( sprintf( '[marclie-booking] iCal failing-feed email, feed %d: %s', $feedId, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	private function failingLimit(): string {
		return Db::utc( $this->clock->now()->modify( sprintf( '-%d hours', self::FAILING_HOURS ) ) );
	}
}
