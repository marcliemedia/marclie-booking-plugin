<?php
/**
 * GET /ical/{unit_code}.ics?k={secret}&for=airbnb|booking|other — the unit's
 * busy/free calendar for an OTA (Ical\Exporter). A wrong code or key is a
 * plain 404 (constant-time compare); 60 requests/minute per client, and
 * misses count toward 20/hour. `text/calendar; charset=utf-8` body served
 * raw (not JSON), `Cache-Control: private, no-store`, weak ETag with
 * If-None-Match → 304. Every valid read with a known `for` is recorded as
 * that channel's last fetch (echo provenance, see Ical\FeedRepository).
 */

namespace Marclie\Booking\Rest\Public;

use Marclie\Booking\Container;
use Marclie\Booking\Email\BusinessInfo;
use Marclie\Booking\Ical\FeedRepository;
use Marclie\Booking\Plugin;

defined( 'ABSPATH' ) || exit;

final class IcalController extends BaseController {

	public const ROUTE = '/ical/(?P<code>[A-Za-z0-9_-]{1,40})\.ics';

	public const RATE_LIMIT = 60;

	public const MISS_LIMIT = 20;

	public function register(): void {
		$this->route(
			self::ROUTE,
			\WP_REST_Server::READABLE,
			array( $this, 'export' ),
			array(
				'k'   => array(
					'type'    => 'string',
					'default' => '',
				),
				'for' => array(
					'type'    => 'string',
					'default' => '',
				),
			),
			'private, no-store',
			true
		);
		add_filter( 'rest_pre_serve_request', array( self::class, 'serveRaw' ), 10, 3 );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function export( \WP_REST_Request $request, string $lang ) {
		$limited = self::throttle( 'ical', self::RATE_LIMIT );
		if ( $limited ) {
			return $limited;
		}

		$exporter = Container::icalExporter();
		$unit     = $exporter->verify( (string) $request['code'], (string) $request['k'] );
		if ( null === $unit ) {
			return self::throttle( 'ical_miss', self::MISS_LIMIT, HOUR_IN_SECONDS ) ?? new \WP_Error( 'not_found', __( 'Calendar not found.', 'marclie-booking' ), array( 'status' => 404 ) );
		}

		$for      = (string) $request['for'];
		$channel  = in_array( $for, FeedRepository::CHANNELS, true ) ? $for : null;
		$calendar = $exporter->calendar( $unit, $channel );
		if ( null !== $channel ) {
			$exporter->recordFetch( $unit, $channel );
		}

		$response = self::matches( (string) $request->get_header( 'if_none_match' ), $calendar['etag'] )
			? new \WP_REST_Response( null, 304 )
			: new \WP_REST_Response( $calendar['body'], 200 );
		$response->header( 'Content-Type', 'text/calendar; charset=utf-8' );
		$response->header( 'ETag', $calendar['etag'] );
		$response->header( 'Content-Disposition', 'inline; filename="' . sanitize_file_name( sanitize_title( BusinessInfo::get()['name'] . ' ' . $unit->code ) ) . '.ics"' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );

		return $response;
	}

	/** Whether an If-None-Match header matches the ETag (weak comparison, lists, `*`). */
	public static function matches( string $ifNoneMatch, string $etag ): bool {
		if ( '' === trim( $ifNoneMatch ) ) {
			return false;
		}
		$strip = static fn( string $tag ): string => (string) preg_replace( '/^W\//i', '', trim( $tag ) );
		foreach ( explode( ',', $ifNoneMatch ) as $candidate ) {
			if ( '*' === trim( $candidate ) || $strip( $candidate ) === $strip( $etag ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Filter `rest_pre_serve_request`: the export body is sent as is (not JSON-encoded).
	 *
	 * @param bool|mixed $served
	 * @param mixed      $result
	 */
	public static function serveRaw( $served, $result, \WP_REST_Request $request ): bool {
		if ( $served || ! $result instanceof \WP_REST_Response || $result->is_error() || ! preg_match( '#^/' . preg_quote( Plugin::REST_NAMESPACE, '#' ) . '/ical/[^/]+\.ics$#', $request->get_route() ) ) {
			return (bool) $served;
		}

		$body = $result->get_data();
		if ( 'HEAD' !== $request->get_method() && is_string( $body ) ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/calendar body.
		}

		return true;
	}
}
