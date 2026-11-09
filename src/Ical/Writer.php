<?php
/**
 * RFC 5545 writer for the busy/free export: all-day VEVENTs, CRLF line
 * endings, TEXT escaping and line folding at 75 octets (never inside a UTF-8
 * sequence). WordPress-free (unit tested).
 */

namespace Marclie\Booking\Ical;

defined( 'ABSPATH' ) || exit;

final class Writer {

	public const CRLF = "\r\n";

	/** Maximum octets per content line, excluding the CRLF (RFC 5545 §3.1). */
	public const LINE_OCTETS = 75;

	public const PRODID = '-//Marclie//Marclie Booking//EN';

	/**
	 * @param list<array{uid:string, date_from:string, date_to:string, summary:string}> $events Dates Y-m-d, `date_to` exclusive.
	 */
	public static function calendar( array $events, string $name, \DateTimeImmutable $stamp ): string {
		$dtstamp = $stamp->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:' . self::PRODID,
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . self::escape( $name ),
		);
		foreach ( $events as $event ) {
			array_push(
				$lines,
				'BEGIN:VEVENT',
				'UID:' . self::escape( $event['uid'] ),
				'DTSTAMP:' . $dtstamp,
				'DTSTART;VALUE=DATE:' . str_replace( '-', '', $event['date_from'] ),
				'DTEND;VALUE=DATE:' . str_replace( '-', '', $event['date_to'] ),
				'SUMMARY:' . self::escape( $event['summary'] ),
				'TRANSP:OPAQUE',
				'END:VEVENT'
			);
		}
		$lines[] = 'END:VCALENDAR';

		return implode( '', array_map( static fn( string $line ): string => self::fold( $line ) . self::CRLF, $lines ) );
	}

	/** TEXT escaping: backslash, semicolon, comma, newline. */
	public static function escape( string $text ): string {
		$text = str_replace( array( '\\', ';', ',' ), array( '\\\\', '\\;', '\\,' ), $text );

		return str_replace( array( "\r\n", "\r", "\n" ), '\\n', $text );
	}

	/**
	 * Folds a content line into chunks of at most 75 octets; continuation lines
	 * start with a space (which counts toward their 75 octets).
	 */
	public static function fold( string $line ): string {
		if ( strlen( $line ) <= self::LINE_OCTETS ) {
			return $line;
		}

		$out   = array();
		$limit = self::LINE_OCTETS;
		while ( strlen( $line ) > $limit ) {
			$cut = $limit;
			// Never split a multibyte character: back off continuation bytes (10xxxxxx).
			while ( $cut > 0 && 0x80 === ( ord( $line[ $cut ] ) & 0xC0 ) ) {
				--$cut;
			}
			$out[] = substr( $line, 0, $cut );
			$line  = substr( $line, $cut );
			$limit = self::LINE_OCTETS - 1;
		}
		$out[] = $line;

		return implode( self::CRLF . ' ', $out );
	}
}
