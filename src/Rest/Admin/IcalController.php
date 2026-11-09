<?php
/**
 * /admin/ical — iCal sync settings (Postavke › iCal): per unit its export URLs
 * (one per channel, same secret) and its import feeds with their sync state.
 *
 * - GET  /ical                         (mbk_manage_bookings; feed `url` only with mbk_manage_settings)
 * - POST /ical/feeds                   {unit_id, channel, url}: test fetch + parse, then import → Feed (201)
 * - PATCH /ical/feeds/{id}             {url?, active?} → Feed
 * - DELETE /ical/feeds/{id}            removes the feed and its imported blocks
 * - POST /ical/feeds/{id}/sync         → SyncResult
 * - POST /ical/sync-all                → {results: SyncResult[]}
 * - POST /ical/units/{unit_id}/rotate  new export secret → {unit_id, export}
 *
 * A URL that cannot be fetched or read answers 422 `mbk_ical_invalid`
 * (`reason`, `fields.url`); a second feed for the same unit + channel 409 `ical_feed_exists`.
 */

namespace Marclie\Booking\Rest\Admin;

use Marclie\Booking\Container;
use Marclie\Booking\Cron;
use Marclie\Booking\Ical\FeedRepository;
use Marclie\Booking\Ical\IcalException;
use Marclie\Booking\Ical\SyncService;
use Marclie\Booking\Install\Activator;
use Marclie\Booking\Units\Unit;

defined( 'ABSPATH' ) || exit;

final class IcalController extends BaseController {

	public function register(): void {
		$this->route( '/ical', $this->endpoint( \WP_REST_Server::READABLE, array( $this, 'index' ), Activator::CAP_BOOKINGS ) );
		$this->route(
			'/ical/feeds',
			$this->endpoint(
				\WP_REST_Server::CREATABLE,
				array( $this, 'create' ),
				Activator::CAP_SETTINGS,
				array(
					'unit_id' => self::idArg(),
					'channel' => array(
						'type'     => 'string',
						'enum'     => FeedRepository::CHANNELS,
						'required' => true,
					),
					'url'     => array(
						'type'     => 'string',
						'required' => true,
					),
				)
			)
		);
		$this->route(
			'/ical/feeds/(?P<id>\d+)',
			array(
				$this->endpoint(
					'PATCH',
					array( $this, 'update' ),
					Activator::CAP_SETTINGS,
					array(
						'url'    => array( 'type' => 'string' ),
						'active' => array( 'type' => 'boolean' ),
					)
				),
				$this->endpoint( \WP_REST_Server::DELETABLE, array( $this, 'delete' ), Activator::CAP_SETTINGS ),
			)
		);
		$this->route( '/ical/feeds/(?P<id>\d+)/sync', $this->endpoint( \WP_REST_Server::CREATABLE, array( $this, 'sync' ), Activator::CAP_SETTINGS ) );
		$this->route( '/ical/sync-all', $this->endpoint( \WP_REST_Server::CREATABLE, array( $this, 'syncAll' ), Activator::CAP_SETTINGS ) );
		$this->route( '/ical/units/(?P<unit_id>\d+)/rotate', $this->endpoint( \WP_REST_Server::CREATABLE, array( $this, 'rotate' ), Activator::CAP_SETTINGS ) );
	}

	/** @return array{units: list<array<string, mixed>>, cron: array{next_run: ?string, interval_min: int}} */
	public function index(): array {
		$byUnit = array();
		foreach ( Container::icalFeeds()->all() as $feed ) {
			$byUnit[ $feed['unit_id'] ][] = self::feed( $feed );
		}

		$exporter = Container::icalExporter();
		$units    = array();
		foreach ( Container::units()->all() as $unit ) {
			// Raw post meta (ACF stores the field there; get_field() is not reliable in REST requests).
			$listing = get_post_meta( $unit->id, 'airbnb_listing_id', true );
			$units[] = array(
				'unit_id'           => $unit->id,
				'code'              => $unit->code,
				'title'             => $unit->title,
				'house'             => $unit->houseSlug,
				'airbnb_listing_id' => is_scalar( $listing ) && '' !== trim( (string) $listing ) ? trim( (string) $listing ) : null,
				'export'            => $exporter->urls( $unit ),
				'feeds'             => $byUnit[ $unit->id ] ?? array(),
			);
		}

		$next = wp_next_scheduled( Cron::ICAL_SYNC );

		return array(
			'units' => $units,
			'cron'  => array(
				'next_run'     => false !== $next ? wp_date( DATE_ATOM, (int) $next ) : null,
				'interval_min' => SyncService::INTERVAL_MINUTES,
			),
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function create( \WP_REST_Request $request ) {
		$unit = self::unit( (int) $request['unit_id'] );
		if ( $unit instanceof \WP_Error ) {
			return $unit;
		}
		$channel = (string) $request['channel'];
		if ( null !== Container::icalFeeds()->findByUnitChannel( $unit->id, $channel ) ) {
			return new \WP_Error( 'ical_feed_exists', __( 'This unit already has a calendar for this channel. Edit or delete it instead.', 'marclie-booking' ), array( 'status' => 409 ) );
		}

		$url = trim( (string) $request['url'] );
		try {
			$feed = Container::icalSync()->addFeed( $unit->id, $channel, $url );
		} catch ( IcalException $e ) {
			return self::invalid( $e );
		}

		return new \WP_REST_Response( self::feed( $feed ), 201 );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function update( \WP_REST_Request $request ) {
		$feed = Container::icalFeeds()->find( (int) $request['id'] );
		if ( null === $feed ) {
			return self::notFound();
		}

		$url    = self::has( $request, 'url' ) && null !== $request['url'] ? trim( (string) $request['url'] ) : null;
		$active = self::has( $request, 'active' ) && null !== $request['active'] ? rest_sanitize_boolean( $request['active'] ) : null;
		try {
			$feed = Container::icalSync()->changeFeed( $feed, $url, $active );
		} catch ( IcalException $e ) {
			return self::invalid( $e );
		}

		return self::feed( $feed );
	}

	/** @return array{deleted: true}|\WP_Error */
	public function delete( \WP_REST_Request $request ) {
		$feed = Container::icalFeeds()->find( (int) $request['id'] );
		if ( null === $feed ) {
			return self::notFound();
		}
		try {
			Container::icalSync()->removeFeed( $feed );
		} catch ( IcalException $e ) {
			return self::invalid( $e );
		}

		return array( 'deleted' => true );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function sync( \WP_REST_Request $request ) {
		$feed = Container::icalFeeds()->find( (int) $request['id'] );
		if ( null === $feed ) {
			return self::notFound();
		}

		return self::result( Container::icalSync()->syncFeed( $feed ) );
	}

	/** @return array{results: list<array<string, mixed>>} */
	public function syncAll(): array {
		return array( 'results' => array_map( array( self::class, 'result' ), Container::icalSync()->syncAll() ) );
	}

	/** @return array{unit_id:int, export: array{airbnb:string, booking:string, other:string}}|\WP_Error */
	public function rotate( \WP_REST_Request $request ) {
		$unit = self::unit( (int) $request['unit_id'] );
		if ( $unit instanceof \WP_Error ) {
			return $unit;
		}
		Container::icalExporter()->rotate( $unit->id );

		return array(
			'unit_id' => $unit->id,
			'export'  => Container::icalExporter()->urls( $unit ),
		);
	}

	/* Shapes ----------------------------------------------------------------- */

	/**
	 * @param array<string, mixed> $feed FeedRepository row.
	 * @return array<string, mixed>
	 */
	public static function feed( array $feed ): array {
		$error = IcalException::fromStored( $feed['last_error'] );
		$out   = array(
			'id'              => (int) $feed['id'],
			'unit_id'         => (int) $feed['unit_id'],
			'channel'         => (string) $feed['channel'],
			'url_masked'      => self::mask( (string) $feed['url'] ),
			'active'          => (bool) $feed['active'],
			'last_synced_at'  => Presenter::iso( $feed['last_synced_at'] ),
			'last_ok_at'      => Presenter::iso( $feed['last_ok_at'] ),
			'last_status'     => $feed['last_status'],
			// Stored as a code, translated for the reader.
			'last_error'      => $error?->describe(),
			'last_error_code' => $error?->reason,
			'events_count'    => (int) $feed['events_count'],
			'fail_since'      => Presenter::iso( $feed['fail_since'] ),
		);
		if ( current_user_can( Activator::CAP_SETTINGS ) ) {
			$out['url'] = (string) $feed['url'];
		}

		return $out;
	}

	/**
	 * @param array{feed_id:int, ok:bool, status:string, events_count:int, error:?IcalException, conflicts:int, warnings:list<string>} $result
	 * @return array<string, mixed>
	 */
	public static function result( array $result ): array {
		$feed = Container::icalFeeds()->find( $result['feed_id'] );

		return array(
			'feed_id'      => $result['feed_id'],
			'ok'           => $result['ok'],
			'status'       => $result['status'],
			'events_count' => $result['events_count'],
			'error'        => $result['error']?->describe(),
			'error_code'   => $result['error']?->reason,
			'conflicts'    => $result['conflicts'],
			'warnings'     => $result['warnings'],
			'feed'         => null !== $feed ? self::feed( $feed ) : null,
		);
	}

	/** `https://host/…1234` — enough to recognise a URL without exposing its secret token. */
	public static function mask( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '…';
		}
		$path = rtrim( (string) ( $parts['path'] ?? '' ), '/' );

		return strtolower( (string) ( $parts['scheme'] ?? 'https' ) ) . '://' . $parts['host'] . '/…' . substr( $path, -8 );
	}

	private static function unit( int $unitId ): Unit|\WP_Error {
		$unit = Container::units()->get( $unitId );

		return null !== $unit ? $unit : new \WP_Error( 'unit_not_found', self::message( 'unit_not_found' ), array( 'status' => 404 ) );
	}

	private static function notFound(): \WP_Error {
		return new \WP_Error( 'not_found', self::message( 'not_found' ), array( 'status' => 404 ) );
	}

	/** 422 `mbk_ical_invalid` (URL problems) or 409 `ical_feed_busy` (a sync holds the feed). */
	private static function invalid( IcalException $e ): \WP_Error {
		$message = $e->describe();
		if ( 'busy' === $e->reason ) {
			return new \WP_Error( 'ical_feed_busy', $message, array( 'status' => 409 ) );
		}

		return new \WP_Error(
			'mbk_ical_invalid',
			$message,
			array(
				'status' => 422,
				'reason' => $e->reason,
				'fields' => array( 'url' => $message ),
			)
		);
	}
}
