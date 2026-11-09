<?php
/**
 * Fetches one import feed, parses it and makes the feed's
 * mbk_external_blocks equal to its events (FeedRepository::replaceBlocks:
 * upsert by UID, vanished UIDs deleted, provenance decided on first sight),
 * then announces `mbk_availability_changed` for the unit. Failures throw
 * IcalException; recording them is SyncService's job.
 *
 * Fetch safety: https only, also for every redirect (followed here, one hop
 * at a time, each checked); the host must resolve (A and AAAA) to public
 * addresses only; `Accept-Encoding: identity` (one gzip layer is decoded,
 * bounded); 5 MB and MAX_EVENTS events at most; a single deadline covers all
 * hops. DNS rebinding between the check and the connection is not prevented
 * (admin-entered URLs only). Filter `mbk_ical_resolve_host` (null, host) may
 * return the host's IPs (tests, split-horizon DNS).
 */

namespace Marclie\Booking\Ical;

use Marclie\Booking\Domain\Clock;
use Marclie\Booking\Repository\Db;
use Marclie\Booking\Rest\Public\RateLimit;
use Marclie\Booking\Service\AvailabilityService;

defined( 'ABSPATH' ) || exit;

final class Importer {

	/** Cron / admin fetch deadline (seconds, all redirects included). */
	public const TIMEOUT = 10;

	public const MAX_REDIRECTS = 3;

	/** Largest accepted feed. */
	public const MAX_BYTES = 5 * 1024 * 1024;

	/** Most events accepted per feed (after recurrence expansion). */
	public const MAX_EVENTS = 2000;

	/** Non-public ranges PHP's FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE may let through. */
	private const BLOCKED_RANGES = array( '100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', 'fc00::/7', 'fe80::/10', '::/128', '::1/128', '64:ff9b::/96', '2001:db8::/32' );

	public function __construct(
		private FeedRepository $feeds,
		private Clock $clock
	) {}

	/** An absolute https URL with a host (no credentials). */
	public static function validUrl( string $url ): bool {
		if ( strlen( $url ) > 2000 || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );

		return is_array( $parts ) && 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) && '' !== (string) ( $parts['host'] ?? '' ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] );
	}

	/**
	 * Throws unless every address of the host is public (A and AAAA records).
	 *
	 * @throws IcalException dns, private_address.
	 */
	public static function assertPublicHost( string $host ): void {
		$host = strtolower( trim( $host, '[]' ) );

		/**
		 * IPs of an iCal feed host, instead of a DNS lookup (null = look up).
		 *
		 * @param list<string>|null $ips
		 * @param string            $host
		 */
		$ips = apply_filters( 'mbk_ical_resolve_host', null, $host );
		if ( ! is_array( $ips ) ) {
			if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
				$ips = array( $host );
			} else {
				$records = function_exists( 'dns_get_record' ) ? @dns_get_record( $host, DNS_A | DNS_AAAA ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- lookup failures are reported below.
				$ips     = array();
				foreach ( is_array( $records ) ? $records : array() as $record ) {
					$ips[] = (string) ( $record['ip'] ?? $record['ipv6'] ?? '' );
				}
				$ips = array_values( array_filter( $ips ) );
			}
		}
		if ( ! $ips ) {
			throw new IcalException( 'dns', $host );
		}
		foreach ( $ips as $ip ) {
			if ( ! self::isPublicIp( (string) $ip ) ) {
				throw new IcalException( 'private_address', $host );
			}
		}
	}

	public static function isPublicIp( string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		$bin = (string) inet_pton( $ip );
		if ( 16 === strlen( $bin ) && str_starts_with( $bin, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
			$ip = (string) inet_ntop( substr( $bin, 12 ) ); // IPv4-mapped IPv6.
		}
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		foreach ( self::BLOCKED_RANGES as $range ) {
			if ( RateLimit::inRange( $ip, $range ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param float $seconds Deadline for the whole fetch (all hops).
	 * @return array{status:'ok'|'not_modified', body:string, etag:string, last_modified:string}
	 * @throws IcalException
	 */
	public function fetch( string $url, ?string $etag, ?string $lastModified, float $seconds = self::TIMEOUT, int $maxRedirects = self::MAX_REDIRECTS ): array {
		$deadline = microtime( true ) + $seconds;
		$headers  = array(
			'Accept'          => 'text/calendar, text/plain;q=0.8, */*;q=0.5',
			'Accept-Encoding' => 'identity',
		);
		if ( null !== $etag && '' !== $etag ) {
			$headers['If-None-Match'] = $etag;
		}
		if ( null !== $lastModified && '' !== $lastModified ) {
			$headers['If-Modified-Since'] = $lastModified;
		}

		for ( $hop = 0; ; $hop++ ) {
			if ( ! self::validUrl( $url ) ) {
				throw new IcalException( 0 === $hop ? 'invalid_url' : 'insecure_redirect' );
			}
			self::assertPublicHost( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$remaining = $deadline - microtime( true );
			if ( $remaining < 0.2 ) {
				throw new IcalException( 'timeout' );
			}

			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => $remaining,
					'redirection'         => 0,
					'decompress'          => false,
					'limit_response_size' => self::MAX_BYTES + 1,
					'headers'             => $headers,
					'user-agent'          => 'MarclieBooking-iCal/' . MBK_VERSION . '; ' . home_url( '/' ),
				)
			);
			if ( is_wp_error( $response ) ) {
				$message = $response->get_error_message();
				throw new IcalException( preg_match( '/timed? ?out/i', $message ) ? 'timeout' : 'network', $message );
			}

			$code     = (int) wp_remote_retrieve_response_code( $response );
			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) && '' !== $location ) {
				if ( $hop >= $maxRedirects ) {
					throw new IcalException( 'http_status', 'too many redirects', $code );
				}
				$url = \WP_Http::make_absolute_url( $location, $url );
				continue;
			}
			break;
		}

		$meta = array(
			'etag'          => (string) wp_remote_retrieve_header( $response, 'etag' ),
			'last_modified' => (string) wp_remote_retrieve_header( $response, 'last-modified' ),
		);
		if ( 304 === $code ) {
			return array(
				'status' => 'not_modified',
				'body'   => '',
			) + $meta;
		}
		if ( 200 !== $code ) {
			throw new IcalException( 'http_status', '', $code );
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_BYTES ) {
			throw new IcalException( 'too_large' );
		}
		if ( str_starts_with( $body, "\x1f\x8b" ) ) {
			// The server ignored `identity`: decode one gzip layer, bounded.
			$decoded = function_exists( 'gzdecode' ) ? @gzdecode( $body, self::MAX_BYTES + 1 ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $decoded || strlen( $decoded ) > self::MAX_BYTES ) {
				throw new IcalException( 'too_large' );
			}
			$body = $decoded;
		}

		return array(
			'status' => 'ok',
			'body'   => $body,
		) + $meta;
	}

	/**
	 * Events of a feed body that are not over yet (dates local to the site).
	 *
	 * @param list<string>|null $warnings Parser warning codes.
	 * @return list<array{uid:string, date_from:string, date_to:string, summary:string}>
	 * @throws IcalException not_ical, too_many_events.
	 */
	public function parse( string $body, ?array &$warnings = null ): array {
		$events = Parser::parse( $body, wp_timezone(), $this->clock->today()->format( 'Y-m-d' ), $warnings );
		if ( count( $events ) > self::MAX_EVENTS ) {
			throw new IcalException( 'too_many_events' );
		}

		return $events;
	}

	/**
	 * Fetches and stores one feed.
	 *
	 * @param array<string, mixed> $feed FeedRepository row.
	 * @return array{status:'ok'|'not_modified'|'inactive', events_count:int, added:int, updated:int, removed:int, warnings:list<string>}
	 * @throws IcalException
	 */
	public function import( array $feed, float $seconds = self::TIMEOUT, int $maxRedirects = self::MAX_REDIRECTS ): array {
		$fetched = $this->fetch( (string) $feed['url'], $feed['etag'], $feed['last_modified'], $seconds, $maxRedirects );
		if ( 'not_modified' === $fetched['status'] ) {
			$this->feeds->markOk( (int) $feed['id'], Db::utc( $this->clock->now() ), 'not_modified', null, null, null );

			return array(
				'status'       => 'not_modified',
				'events_count' => (int) $feed['events_count'],
				'added'        => 0,
				'updated'      => 0,
				'removed'      => 0,
				'warnings'     => array(),
			);
		}

		$warnings = array();
		$events   = $this->parse( $fetched['body'], $warnings );

		return $this->store( $feed, $events, $fetched['etag'], $fetched['last_modified'], $warnings );
	}

	/**
	 * Replaces the feed's blocks with `$events` and records the successful sync.
	 * A feed deleted or switched off meanwhile is left alone (`inactive`).
	 *
	 * @param array<string, mixed>                                                      $feed
	 * @param list<array{uid:string, date_from:string, date_to:string, summary:string}> $events
	 * @param list<string>                                                              $warnings
	 * @return array{status:'ok'|'inactive', events_count:int, added:int, updated:int, removed:int, warnings:list<string>}
	 */
	public function store( array $feed, array $events, string $etag, string $lastModified, array $warnings = array() ): array {
		$now    = Db::utc( $this->clock->now() );
		$counts = $this->feeds->replaceBlocks( (int) $feed['id'], (int) $feed['unit_id'], (string) $feed['channel'], $events, $now );
		if ( null === $counts ) {
			return array(
				'status'       => 'inactive',
				'events_count' => 0,
				'added'        => 0,
				'updated'      => 0,
				'removed'      => 0,
				'warnings'     => $warnings,
			);
		}
		$this->feeds->markOk( (int) $feed['id'], $now, 'ok', $etag, $lastModified, count( $events ) );
		if ( $counts['added'] || $counts['updated'] || $counts['removed'] ) {
			AvailabilityService::changed( (int) $feed['unit_id'] );
		}
		if ( $warnings ) {
			error_log( sprintf( '[marclie-booking] iCal feed %d: %s', (int) $feed['id'], implode( ', ', $warnings ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return array(
			'status'       => 'ok',
			'events_count' => count( $events ),
			'warnings'     => $warnings,
		) + $counts;
	}
}
