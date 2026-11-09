<?php
/**
 * WP-CLI: `studio wp mbk ical …` — iCal busy/free sync with Airbnb / Booking.com.
 */

namespace Marclie\Booking\Cli;

use Marclie\Booking\Container;
use Marclie\Booking\Ical\FeedRepository;

defined( 'ABSPATH' ) || exit;

final class Ical {

	/**
	 * Imports the active iCal feeds now (all, one unit's, or one feed).
	 *
	 * ## OPTIONS
	 *
	 * [--unit=<unit>]
	 * : Unit ID or unit code.
	 *
	 * [--feed=<id>]
	 * : Feed ID (also when switched off).
	 *
	 * ## EXAMPLES
	 *
	 *     studio wp mbk ical sync
	 *     studio wp mbk ical sync --unit=101
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assocArgs
	 */
	public function sync( array $args, array $assocArgs ): void {
		$sync = Container::icalSync();
		if ( isset( $assocArgs['feed'] ) ) {
			$feed = Container::icalFeeds()->find( (int) $assocArgs['feed'] );
			if ( null === $feed ) {
				\WP_CLI::error( 'Feed not found.' );
			}
			$results = array( $sync->syncFeed( $feed ) );
		} elseif ( isset( $assocArgs['unit'] ) ) {
			$results = $sync->syncAll( self::unit( (string) $assocArgs['unit'] )->id );
		} else {
			$results = $sync->syncAll();
		}

		if ( ! $results ) {
			\WP_CLI::warning( 'No active iCal feeds.' );
			return;
		}

		$feeds = Container::icalFeeds();
		$units = Container::units();
		$rows  = array();
		foreach ( $results as $result ) {
			$feed   = $feeds->find( $result['feed_id'] );
			$unit   = null !== $feed ? $units->get( (int) $feed['unit_id'] ) : null;
			$rows[] = array(
				'feed'      => $result['feed_id'],
				'unit'      => null !== $unit ? $unit->code : '',
				'channel'   => null !== $feed ? FeedRepository::label( (string) $feed['channel'] ) : '',
				'status'    => $result['status'],
				'events'    => $result['events_count'],
				'conflicts' => $result['conflicts'],
				'error'     => (string) $result['error']?->describe(),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'feed', 'unit', 'channel', 'status', 'events', 'conflicts', 'error' ) );

		$failed = count( array_filter( $results, static fn( array $r ): bool => ! $r['ok'] ) );
		if ( $failed > 0 ) {
			\WP_CLI::error( sprintf( '%d feed(s) failed.', $failed ) );
		}
		\WP_CLI::success( sprintf( '%d feed(s) synced.', count( $results ) ) );
	}

	/**
	 * Prints the export URLs to paste into Airbnb / Booking.com (one per unit and channel).
	 *
	 * ## OPTIONS
	 *
	 * [--unit=<unit>]
	 * : Unit ID or unit code.
	 *
	 * [--format=<format>]
	 * : table, csv, json.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     studio wp mbk ical urls --format=csv
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assocArgs
	 */
	public function urls( array $args, array $assocArgs ): void {
		$units    = isset( $assocArgs['unit'] ) ? array( self::unit( (string) $assocArgs['unit'] ) ) : Container::units()->all();
		$exporter = Container::icalExporter();
		$rows     = array();
		foreach ( $units as $unit ) {
			$urls   = $exporter->urls( $unit );
			$rows[] = array(
				'unit'    => $unit->code,
				'title'   => $unit->title,
				'airbnb'  => $urls['airbnb'],
				'booking' => $urls['booking'],
				'other'   => $urls['other'],
			);
		}
		\WP_CLI\Utils\format_items( (string) ( $assocArgs['format'] ?? 'table' ), $rows, array( 'unit', 'title', 'airbnb', 'booking', 'other' ) );
	}

	private static function unit( string $idOrCode ): \Marclie\Booking\Units\Unit {
		$units = Container::units();
		$unit  = $units->byCode( $idOrCode );
		$unit ??= ctype_digit( $idOrCode ) ? $units->get( (int) $idOrCode ) : null;
		if ( null === $unit ) {
			\WP_CLI::error( 'Unit not found: ' . $idOrCode );
		}

		return $unit;
	}
}
