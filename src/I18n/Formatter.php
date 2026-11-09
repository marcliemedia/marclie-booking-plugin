<?php
/**
 * Money and date formatting by plugin language (hr|en), never by site locale.
 *
 * Dates are always rendered in wp_timezone(). IntlDateFormatter (hr_HR / en_GB)
 * is used when ext-intl is loaded; otherwise built-in month tables.
 */

namespace Marclie\Booking\I18n;

defined( 'ABSPATH' ) || exit;

final class Formatter {

	public const LONG        = 'long';
	public const SHORT       = 'short';
	public const MONTH_TITLE = 'month_title';

	/** ICU patterns per language/style. `MMMM` = format (genitive in HR), `LLLL` = standalone (nominative). */
	private const PATTERNS = array(
		'hr' => array(
			self::LONG        => 'd. MMMM y.',
			self::SHORT       => 'd. MMM y.',
			self::MONTH_TITLE => 'LLLL y.',
		),
		'en' => array(
			self::LONG        => 'd MMMM y',
			self::SHORT       => 'd MMM y',
			self::MONTH_TITLE => 'LLLL y',
		),
	);

	private const INTL_LOCALES = array(
		'hr' => 'hr_HR',
		'en' => 'en_GB',
	);

	/** Fallback month names (index 1-12) when ext-intl is missing. */
	private const MONTHS = array(
		'hr' => array(
			'nominative' => array( 1 => 'siječanj', 'veljača', 'ožujak', 'travanj', 'svibanj', 'lipanj', 'srpanj', 'kolovoz', 'rujan', 'listopad', 'studeni', 'prosinac' ),
			'genitive'   => array( 1 => 'siječnja', 'veljače', 'ožujka', 'travnja', 'svibnja', 'lipnja', 'srpnja', 'kolovoza', 'rujna', 'listopada', 'studenoga', 'prosinca' ),
			'short'      => array( 1 => 'sij', 'velj', 'ožu', 'tra', 'svi', 'lip', 'srp', 'kol', 'ruj', 'lis', 'stu', 'pro' ),
		),
		'en' => array(
			'nominative' => array( 1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December' ),
			'genitive'   => array( 1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December' ),
			'short'      => array( 1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ),
		),
	);

	/** @var array<string, ?\IntlDateFormatter> */
	private static array $formatters = array();

	/**
	 * HR "1.365,00 €", EN "€1,365.00".
	 */
	public static function money( int $cents, string $lang ): string {
		$lang   = LanguageAdapter::normalize( $lang ) ?? LanguageAdapter::DEFAULT;
		$sign   = $cents < 0 ? '-' : '';
		$amount = abs( $cents ) / 100;

		if ( 'en' === $lang ) {
			return $sign . '€' . number_format( $amount, 2, '.', ',' );
		}

		return $sign . number_format( $amount, 2, ',', '.' ) . ' €';
	}

	/**
	 * Display price: whole euros without decimals. HR "195 €", "1.365 €",
	 * "409,50 €"; EN "€195", "€1,365", "€409.50". Negative amounts use U+2212.
	 */
	public static function price( int $cents, string $lang ): string {
		$lang     = LanguageAdapter::normalize( $lang ) ?? LanguageAdapter::DEFAULT;
		$sign     = $cents < 0 ? "\u{2212}" : '';
		$abs      = abs( $cents );
		$decimals = 0 === $abs % 100 ? 0 : 2;

		if ( 'en' === $lang ) {
			return $sign . '€' . number_format( $abs / 100, $decimals, '.', ',' );
		}

		return $sign . number_format( $abs / 100, $decimals, ',', '.' ) . ' €';
	}

	/** HR "30 %", EN "30%". */
	public static function percent( int $value, string $lang ): string {
		return 'en' === ( LanguageAdapter::normalize( $lang ) ?? LanguageAdapter::DEFAULT ) ? $value . '%' : $value . ' %';
	}

	/**
	 * Stay range as running text, the shared month/year written once: HR "od 12. do 19. srpnja 2027.",
	 * "od 28. lipnja do 5. srpnja 2027.", "od 28. prosinca 2026. do 3. siječnja 2027."; EN "from 12 to
	 * 19 July 2027". '' when a date is invalid.
	 *
	 * @param string $from `Y-m-d`.
	 * @param string $to   `Y-m-d`.
	 */
	public static function stayRange( string $from, string $to, string $lang ): string {
		$lang  = LanguageAdapter::normalize( $lang ) ?? LanguageAdapter::DEFAULT;
		$start = self::toSiteDate( $from );
		$end   = self::toSiteDate( $to );
		if ( null === $start || null === $end ) {
			return '';
		}

		$first = self::date( $start, $lang );
		if ( $start->format( 'Y' ) === $end->format( 'Y' ) ) {
			$first = $start->format( 'Ym' ) === $end->format( 'Ym' )
				? $start->format( 'j' ) . ( 'hr' === $lang ? '.' : '' )
				: (string) preg_replace( '/\s+\d{4}\.?$/u', '', $first );
		}

		/* translators: 1: first day of the stay, 2: last day (with month and year), e.g. "12." / "19. srpnja 2027." */
		return sprintf( __( 'from %1$s to %2$s', 'marclie-booking' ), $first, self::date( $end, $lang ) );
	}

	/**
	 * Formats a date in wp_timezone().
	 *
	 * @param \DateTimeInterface|string $date  DateTimeInterface, `Y-m-d` stay date (site-local),
	 *                                         or `Y-m-d H:i:s` DB timestamp (UTC).
	 * @param string                    $lang  hr|en.
	 * @param string                    $style long|short|month_title.
	 */
	public static function date( $date, string $lang, string $style = self::LONG ): string {
		$lang  = LanguageAdapter::normalize( $lang ) ?? LanguageAdapter::DEFAULT;
		$style = isset( self::PATTERNS[ $lang ][ $style ] ) ? $style : self::LONG;
		$dt    = self::toSiteDate( $date );
		if ( null === $dt ) {
			return '';
		}

		$formatter = extension_loaded( 'intl' ) ? self::formatter( $lang, $style ) : null;
		if ( null !== $formatter ) {
			$formatted = $formatter->format( $dt );
			if ( is_string( $formatted ) && '' !== $formatted ) {
				return self::MONTH_TITLE === $style ? self::ucfirst( $formatted ) : $formatted;
			}
		}

		return self::fallback( $dt, $lang, $style );
	}

	/**
	 * @param \DateTimeInterface|string $date
	 */
	private static function toSiteDate( $date ): ?\DateTimeImmutable {
		$tz = wp_timezone();

		if ( $date instanceof \DateTimeInterface ) {
			return \DateTimeImmutable::createFromInterface( $date )->setTimezone( $tz );
		}

		if ( ! is_string( $date ) || '' === trim( $date ) ) {
			return null;
		}

		$date = trim( $date );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $tz );
			return $dt ? $dt : null;
		}

		try {
			return ( new \DateTimeImmutable( $date, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/** Whether ICU has real data for the language (otherwise dates use the built-in month names). */
	public static function hasIntlLocale( string $lang ): bool {
		$lang = LanguageAdapter::normalize( $lang ) ?? LanguageAdapter::DEFAULT;

		return extension_loaded( 'intl' ) && null !== self::formatter( $lang, self::LONG );
	}

	/**
	 * Null when ICU lacks data for the locale (e.g. Alpine's English-only
	 * icu-data-en): it would silently format in the root locale (English months).
	 * Detected by formatting April's standalone name and comparing it with
	 * MONTHS (ACTUAL_LOCALE is empty for pattern-only formatters on ICU 77).
	 */
	private static function formatter( string $lang, string $style ): ?\IntlDateFormatter {
		$key = $lang . '|' . $style;
		if ( ! array_key_exists( $key, self::$formatters ) ) {
			$probe = new \IntlDateFormatter( self::INTL_LOCALES[ $lang ], \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, 'LLLL' );
			$april = (string) $probe->format( gmmktime( 12, 0, 0, 4, 15, 2000 ) );

			self::$formatters[ $key ] = mb_strtolower( $april ) === mb_strtolower( self::MONTHS[ $lang ]['nominative'][4] )
				? new \IntlDateFormatter(
					self::INTL_LOCALES[ $lang ],
					\IntlDateFormatter::NONE,
					\IntlDateFormatter::NONE,
					wp_timezone(),
					\IntlDateFormatter::GREGORIAN,
					self::PATTERNS[ $lang ][ $style ]
				)
				: null;
		}

		return self::$formatters[ $key ];
	}

	private static function fallback( \DateTimeImmutable $dt, string $lang, string $style ): string {
		$day    = (int) $dt->format( 'j' );
		$month  = (int) $dt->format( 'n' );
		$year   = $dt->format( 'Y' );
		$months = self::MONTHS[ $lang ];

		if ( 'hr' === $lang ) {
			switch ( $style ) {
				case self::SHORT:
					return sprintf( '%d. %s %s.', $day, $months['short'][ $month ], $year );
				case self::MONTH_TITLE:
					return sprintf( '%s %s.', self::ucfirst( $months['nominative'][ $month ] ), $year );
				default:
					return sprintf( '%d. %s %s.', $day, $months['genitive'][ $month ], $year );
			}
		}

		switch ( $style ) {
			case self::SHORT:
				return sprintf( '%d %s %s', $day, $months['short'][ $month ], $year );
			case self::MONTH_TITLE:
				return sprintf( '%s %s', $months['nominative'][ $month ], $year );
			default:
				return sprintf( '%d %s %s', $day, $months['genitive'][ $month ], $year );
		}
	}

	private static function ucfirst( string $value ): string {
		return mb_strtoupper( mb_substr( $value, 0, 1 ) ) . mb_substr( $value, 1 );
	}
}
