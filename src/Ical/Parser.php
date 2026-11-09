<?php
/**
 * Minimal RFC 5545 reader for busy/free feeds (Airbnb, Booking.com, …):
 * only VEVENTs, and of those only UID, SUMMARY, DTSTART, DTEND/DURATION,
 * STATUS, TRANSP, RRULE and EXDATE. WordPress-free (unit tested).
 *
 * - Lines are unfolded (CRLF / LF / CR followed by a space or tab) and TEXT
 *   values unescaped (`\\`, `\;`, `\,`, `\n`).
 * - DATE values are taken as is (DTEND exclusive, like a departure); DATE-TIME
 *   values (UTC `Z`, `TZID=…`, or floating in X-WR-TIMEZONE / the site zone)
 *   become the local date in `$tz`: the stay runs from the start date to the
 *   end date (a 10:00 check-out does not occupy that night). A missing or
 *   non-positive end means one night.
 * - RRULE: FREQ=DAILY / WEEKLY with INTERVAL, COUNT, UNTIL (and EXDATE) is
 *   expanded up to 18 months after `$today`; each occurrence gets the UID
 *   `{uid}#{Y-m-d}`. Any other rule (BYDAY, MONTHLY…) keeps only the first
 *   occurrence and adds the warning `rrule_unsupported`. RECURRENCE-ID
 *   overrides are extra events (the original occurrence stays blocked).
 * - The same UID and start date twice: the longest stay is kept; the same UID
 *   with another start date becomes `{uid}#{start}`.
 * - Skipped: STATUS:CANCELLED, TRANSP:TRANSPARENT, events without a readable
 *   DTSTART, nested components (VALARM…), and events entirely before `$today`.
 * - Malformed lines are ignored; only a body without BEGIN:VCALENDAR fails.
 */

namespace Marclie\Booking\Ical;

defined( 'ABSPATH' ) || exit;

final class Parser {

	/** mbk_external_blocks.uid / summary lengths. */
	public const UID_MAX = 191;

	public const SUMMARY_MAX = 255;

	/** Recurrences are expanded this far after today (the export window). */
	public const HORIZON_MONTHS = 18;

	/** Occurrences generated per recurring event at most (the importer caps the feed anyway). */
	private const MAX_OCCURRENCES = 2001;

	/**
	 * @param string|null       $today    Y-m-d: events ending on or before it are left out (null = keep all).
	 * @param list<string>|null $warnings Receives warning codes (`rrule_unsupported`).
	 * @return list<array{uid:string, date_from:string, date_to:string, summary:string}>
	 * @throws IcalException not_ical.
	 */
	public static function parse( string $ics, \DateTimeZone $tz, ?string $today = null, ?array &$warnings = null ): array {
		$warnings = array();
		$lines    = self::unfold( $ics );

		$calendar = false;
		$calTz    = null;
		foreach ( $lines as $line ) {
			$prop = self::property( $line );
			if ( null === $prop ) {
				continue;
			}
			if ( 'BEGIN' === $prop['name'] && 'VCALENDAR' === strtoupper( trim( $prop['value'] ) ) ) {
				$calendar = true;
			} elseif ( 'X-WR-TIMEZONE' === $prop['name'] && null === $calTz ) {
				$calTz = self::zone( $prop['value'] );
			}
		}
		if ( ! $calendar ) {
			throw new IcalException( 'not_ical' );
		}

		$events = array();
		$seen   = array();
		$index  = array();
		$event  = null;
		$nested = 0;
		foreach ( $lines as $line ) {
			$prop = self::property( $line );
			if ( null === $prop ) {
				continue;
			}
			$name = $prop['name'];
			if ( 'BEGIN' === $name ) {
				$component = strtoupper( trim( $prop['value'] ) );
				if ( null !== $event ) {
					++$nested;
				} elseif ( 'VEVENT' === $component ) {
					$event  = array();
					$nested = 0;
				}
				continue;
			}
			if ( 'END' === $name ) {
				if ( null === $event ) {
					continue;
				}
				if ( $nested > 0 ) {
					--$nested;
					continue;
				}
				if ( 'VEVENT' === strtoupper( trim( $prop['value'] ) ) ) {
					foreach ( self::events( $event, $tz, $calTz ?? $tz, $today, $warnings ) as $parsed ) {
						$key = $parsed['uid'] . '|' . $parsed['date_from'];
						if ( isset( $index[ $key ] ) ) {
							// Same UID and start: keep the longest stay.
							$i = $index[ $key ];
							if ( $parsed['date_to'] > $events[ $i ]['date_to'] ) {
								$events[ $i ]['date_to'] = $parsed['date_to'];
							}
							continue;
						}
						$uid = $parsed['uid'];
						if ( isset( $seen[ $uid ] ) ) {
							// RECURRENCE-ID overrides / reused UIDs with another start.
							$uid = self::limitUid( $uid . '#' . $parsed['date_from'] );
							if ( isset( $seen[ $uid ] ) ) {
								continue;
							}
						}
						$seen[ $uid ]  = true;
						$parsed['uid'] = $uid;
						$index[ $key ] = count( $events );
						$events[]      = $parsed;
					}
				}
				$event = null;
				continue;
			}
			if ( null === $event || 0 !== $nested ) {
				continue;
			}
			if ( 'EXDATE' === $name ) {
				$event['EXDATE'][] = $prop;
			} elseif ( ! isset( $event[ $name ] ) ) {
				$event[ $name ] = $prop;
			}
		}

		$warnings = array_values( array_unique( $warnings ) );

		return $events;
	}

	/**
	 * Unfolded content lines (BOM stripped, empty lines dropped).
	 *
	 * @return list<string>
	 */
	public static function unfold( string $ics ): array {
		if ( str_starts_with( $ics, "\xEF\xBB\xBF" ) ) {
			$ics = substr( $ics, 3 );
		}

		$lines = array();
		foreach ( preg_split( '/\r\n|\n|\r/', $ics ) ?: array() as $raw ) {
			if ( '' === $raw ) {
				continue;
			}
			if ( ( ' ' === $raw[0] || "\t" === $raw[0] ) && $lines ) {
				$lines[ count( $lines ) - 1 ] .= substr( $raw, 1 );
				continue;
			}
			$lines[] = $raw;
		}

		return $lines;
	}

	/** TEXT value with RFC 5545 escapes resolved. */
	public static function unescape( string $value ): string {
		return (string) preg_replace_callback(
			'/\\\\([\\\\;,nN])/',
			static fn( array $m ): string => 'n' === $m[1] || 'N' === $m[1] ? "\n" : $m[1],
			$value
		);
	}

	/**
	 * `NAME;PARAM=v;PARAM="quoted:value":VALUE` → parts (name/params upper-cased), or null.
	 *
	 * @return array{name:string, params:array<string, string>, value:string}|null
	 */
	public static function property( string $line ): ?array {
		$length = strlen( $line );
		$i      = strcspn( $line, ';:' );
		if ( $i >= $length || 0 === $i ) {
			return null;
		}
		$name   = strtoupper( trim( substr( $line, 0, $i ) ) );
		$params = array();
		while ( $i < $length && ';' === $line[ $i ] ) {
			++$i;
			$eq = strcspn( $line, '=;:', $i );
			if ( $i + $eq >= $length || '=' !== $line[ $i + $eq ] ) {
				// Parameter without a value: skip to the next separator.
				$i += $eq;
				continue;
			}
			$param = strtoupper( trim( substr( $line, $i, $eq ) ) );
			$i    += $eq + 1;
			$value = '';
			while ( $i < $length && ';' !== $line[ $i ] && ':' !== $line[ $i ] ) {
				if ( '"' === $line[ $i ] ) {
					$close = strpos( $line, '"', $i + 1 );
					if ( false === $close ) {
						return null;
					}
					$value .= substr( $line, $i + 1, $close - $i - 1 );
					$i      = $close + 1;
					continue;
				}
				$value .= $line[ $i ];
				++$i;
			}
			$params[ $param ] = $value;
		}
		if ( $i >= $length || ':' !== $line[ $i ] ) {
			return null;
		}

		return array(
			'name'   => $name,
			'params' => $params,
			'value'  => substr( $line, $i + 1 ),
		);
	}

	/**
	 * The event's stays (several for a supported RRULE).
	 *
	 * @param array<string, mixed> $props
	 * @param list<string>         $warnings
	 * @return list<array{uid:string, date_from:string, date_to:string, summary:string}>
	 */
	private static function events( array $props, \DateTimeZone $tz, \DateTimeZone $floating, ?string $today, array &$warnings ): array {
		if ( 'CANCELLED' === strtoupper( trim( $props['STATUS']['value'] ?? '' ) ) || 'TRANSPARENT' === strtoupper( trim( $props['TRANSP']['value'] ?? '' ) ) ) {
			return array();
		}
		if ( ! isset( $props['DTSTART'] ) ) {
			return array();
		}

		$start = self::date( $props['DTSTART'], $tz, $floating );
		if ( null === $start ) {
			return array();
		}
		$from = $start->format( 'Y-m-d' );

		$end = isset( $props['DTEND'] ) ? self::date( $props['DTEND'], $tz, $floating ) : null;
		if ( null === $end && isset( $props['DURATION'] ) ) {
			$end = self::plusDuration( $start, trim( $props['DURATION']['value'] ) );
		}
		$to = null !== $end ? $end->format( 'Y-m-d' ) : '';
		if ( $to <= $from ) {
			$to = ( new \DateTimeImmutable( $from ) )->modify( '+1 day' )->format( 'Y-m-d' );
		}

		$summary = self::truncate( trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', self::unescape( $props['SUMMARY']['value'] ?? '' ) ) ), self::SUMMARY_MAX );
		$uid     = trim( self::unescape( $props['UID']['value'] ?? '' ) );
		if ( '' === $uid ) {
			$uid = 'nouid-' . md5( $from . '|' . $to . '|' . $summary );
		}
		$uid = self::limitUid( $uid );

		$stays     = array( array( $from, $to ) );
		$recurring = false;
		if ( isset( $props['RRULE'] ) ) {
			$rule = self::rule( (string) $props['RRULE']['value'], $tz );
			if ( null === $rule ) {
				$warnings[] = 'rrule_unsupported';
			} else {
				$stays     = self::occurrences( $from, $to, $rule, $today );
				$recurring = true;
				// Room for `#Y-m-d`.
				$uid = strlen( $uid ) > self::UID_MAX - 11 ? substr( $uid, 0, self::UID_MAX - 44 ) . '-' . md5( $uid ) : $uid;
			}
		}
		$excluded = self::exdates( (array) ( $props['EXDATE'] ?? array() ), $tz, $floating );

		$out = array();
		foreach ( $stays as [ $stayFrom, $stayTo ] ) {
			if ( isset( $excluded[ $stayFrom ] ) || ( null !== $today && $stayTo <= $today ) ) {
				continue;
			}
			$out[] = array(
				'uid'       => $recurring ? $uid . '#' . $stayFrom : $uid,
				'date_from' => $stayFrom,
				'date_to'   => $stayTo,
				'summary'   => $summary,
			);
		}

		return $out;
	}

	/**
	 * A supported RRULE (DAILY / WEEKLY with INTERVAL, COUNT, UNTIL, WKST), or null.
	 *
	 * @return array{days:int, count:?int, until:?string}|null
	 */
	private static function rule( string $value, \DateTimeZone $tz ): ?array {
		$parts = array();
		foreach ( explode( ';', strtoupper( trim( $value ) ) ) as $part ) {
			[ $key, $val ] = array_pad( explode( '=', $part, 2 ), 2, '' );
			if ( '' !== $key ) {
				$parts[ $key ] = $val;
			}
		}
		$freq = $parts['FREQ'] ?? '';
		if ( ! in_array( $freq, array( 'DAILY', 'WEEKLY' ), true ) || array_diff( array_keys( $parts ), array( 'FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'WKST' ) ) ) {
			return null;
		}
		$interval = isset( $parts['INTERVAL'] ) ? (int) $parts['INTERVAL'] : 1;
		if ( $interval < 1 ) {
			return null;
		}
		$until = null;
		if ( isset( $parts['UNTIL'] ) ) {
			$at = self::date(
				array(
					'name'   => 'UNTIL',
					'params' => array(),
					'value'  => $parts['UNTIL'],
				),
				$tz,
				$tz
			);
			if ( null === $at ) {
				return null;
			}
			$until = $at->format( 'Y-m-d' );
		}

		return array(
			'days'  => ( 'WEEKLY' === $freq ? 7 : 1 ) * $interval,
			'count' => isset( $parts['COUNT'] ) ? max( 0, (int) $parts['COUNT'] ) : null,
			'until' => $until,
		);
	}

	/**
	 * Stays of a recurring event up to HORIZON_MONTHS after today (or after its start).
	 *
	 * @param array{days:int, count:?int, until:?string} $rule
	 * @return list<array{0:string, 1:string}>
	 */
	private static function occurrences( string $from, string $to, array $rule, ?string $today ): array {
		$first   = new \DateTimeImmutable( $from );
		$length  = (int) $first->diff( new \DateTimeImmutable( $to ) )->days;
		$horizon = ( new \DateTimeImmutable( $today ?? $from ) )->modify( '+' . self::HORIZON_MONTHS . ' months' )->format( 'Y-m-d' );

		$stays = array();
		for ( $n = 0; $n < self::MAX_OCCURRENCES; $n++ ) {
			if ( null !== $rule['count'] && $n >= $rule['count'] ) {
				break;
			}
			$start = $first->modify( sprintf( '+%d days', $n * $rule['days'] ) );
			$ymd   = $start->format( 'Y-m-d' );
			if ( ( null !== $rule['until'] && $ymd > $rule['until'] ) || $ymd >= $horizon ) {
				break;
			}
			$stays[] = array( $ymd, $start->modify( sprintf( '+%d days', $length ) )->format( 'Y-m-d' ) );
		}

		return $stays;
	}

	/**
	 * Local start dates listed in EXDATE properties.
	 *
	 * @param list<array{name:string, params:array<string, string>, value:string}> $props
	 * @return array<string, true>
	 */
	private static function exdates( array $props, \DateTimeZone $tz, \DateTimeZone $floating ): array {
		$dates = array();
		foreach ( $props as $prop ) {
			foreach ( explode( ',', (string) $prop['value'] ) as $value ) {
				$at = self::date( array( 'value' => $value ) + $prop, $tz, $floating );
				if ( null !== $at ) {
					$dates[ $at->format( 'Y-m-d' ) ] = true;
				}
			}
		}

		return $dates;
	}

	/**
	 * DATE or DATE-TIME value as a point in `$tz` (dates: midnight local), or null.
	 *
	 * @param array{name:string, params:array<string, string>, value:string} $prop
	 */
	private static function date( array $prop, \DateTimeZone $tz, \DateTimeZone $floating ): ?\DateTimeImmutable {
		$value = strtoupper( trim( $prop['value'] ) );
		// A list (`DTSTART:a,b`) is not valid here; take the first value.
		$value = explode( ',', $value )[0];

		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $value, $m ) ) {
			return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? new \DateTimeImmutable( "{$m[1]}-{$m[2]}-{$m[3]}", $tz ) : null;
		}
		if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z?)$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}

		if ( 'Z' === $m[7] ) {
			$zone = new \DateTimeZone( 'UTC' );
		} elseif ( isset( $prop['params']['TZID'] ) ) {
			$zone = self::zone( $prop['params']['TZID'] ) ?? $floating;
		} else {
			$zone = $floating;
		}

		$at = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}", $zone );

		return false === $at ? null : $at->setTimezone( $tz );
	}

	private static function plusDuration( \DateTimeImmutable $start, string $duration ): ?\DateTimeImmutable {
		if ( ! preg_match( '/^\+?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/i', $duration, $m ) || 'P' === strtoupper( ltrim( $duration, '+' ) ) ) {
			return null;
		}
		$days    = (int) ( $m[1] ?? 0 ) * 7 + (int) ( $m[2] ?? 0 );
		$seconds = (int) ( $m[3] ?? 0 ) * 3600 + (int) ( $m[4] ?? 0 ) * 60 + (int) ( $m[5] ?? 0 );

		return $start->modify( sprintf( '+%d days +%d seconds', $days, $seconds ) );
	}

	/** IANA zone from a TZID / X-WR-TIMEZONE (also `/vendor/…/Europe/Zagreb` and Windows names via intl), or null. */
	private static function zone( string $tzid ): ?\DateTimeZone {
		$tzid = trim( $tzid, " \t\"" );
		if ( '' === $tzid ) {
			return null;
		}
		$candidates = array( $tzid );
		if ( preg_match( '~([A-Za-z_]+/[A-Za-z_+\-]+(?:/[A-Za-z_+\-]+)?)$~', $tzid, $m ) ) {
			$candidates[] = $m[1];
		}
		if ( class_exists( \IntlTimeZone::class ) && method_exists( \IntlTimeZone::class, 'getIDForWindowsID' ) ) {
			$windows = \IntlTimeZone::getIDForWindowsID( $tzid );
			if ( is_string( $windows ) && '' !== $windows ) {
				$candidates[] = $windows;
			}
		}
		foreach ( $candidates as $candidate ) {
			try {
				return new \DateTimeZone( $candidate );
			} catch ( \Exception $e ) {
				continue;
			}
		}

		return null;
	}

	private static function limitUid( string $uid ): string {
		return strlen( $uid ) <= self::UID_MAX ? $uid : substr( $uid, 0, self::UID_MAX - 33 ) . '-' . md5( $uid );
	}

	private static function truncate( string $text, int $max ): string {
		if ( strlen( $text ) <= $max ) {
			return $text;
		}

		return function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, $max, 'UTF-8' ) : substr( $text, 0, $max );
	}
}
