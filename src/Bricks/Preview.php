<?php
/**
 * Bricks Builder preview context: the builder iframe has no K9 query string and often no
 * `property` post, so widgets, dynamic tags and the query type render with sample data —
 * the first unit (menu order) and a stay starting 30 days from today. Nothing here calls
 * Stripe; the booking-flow widgets show a static state instead of their JS-driven one.
 */

namespace Marclie\Booking\Bricks;

use Marclie\Booking\Container;
use Marclie\Booking\Domain\Exception\BookingException;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\I18n\Formatter;
use Marclie\Booking\I18n\LanguageAdapter;
use Marclie\Booking\Service\ReferenceGenerator;
use Marclie\Booking\Units\Unit;

defined( 'ABSPATH' ) || exit;

final class Preview {

	/** Days from today to the sample arrival. */
	public const LEAD_DAYS = 30;

	/** Sample stay length (above every seeded minimum stay). */
	public const NIGHTS = 7;

	/** Inside the builder (page load or element render call). */
	public static function active(): bool {
		return ( function_exists( 'bricks_is_builder_call' ) && bricks_is_builder_call() )
			|| ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() );
	}

	/**
	 * Sample K9 params: arrival today + 30 days, 7 nights, 2 adults.
	 *
	 * @return array{dolazak: string, odlazak: string, odrasli: int, djeca: int}
	 */
	public static function sampleQuery( \DateTimeImmutable $today ): array {
		$arrival = $today->setTime( 0, 0 )->modify( '+' . self::LEAD_DAYS . ' days' );

		return array(
			'dolazak' => $arrival->format( 'Y-m-d' ),
			'odlazak' => $arrival->modify( '+' . self::NIGHTS . ' days' )->format( 'Y-m-d' ),
			'odrasli' => 2,
			'djeca'   => 0,
		);
	}

	/** First unit in menu order, null without any published `property`. */
	public static function sampleUnit(): ?Unit {
		$units = Container::units()->all();

		return $units[0] ?? null;
	}

	/**
	 * Sample booking texts for the static confirmation / balance-payment previews, keyed like
	 * their `data-cf` / `data-pb` fields and worded like confirmation.js. Priced by a local
	 * quote of the sample unit and stay (no booking is created, nothing is sent to Stripe).
	 *
	 * @return array<string, string>
	 */
	public static function sampleBooking(): array {
		$unit = self::sampleUnit();
		if ( null === $unit ) {
			return array();
		}

		$lang   = LanguageAdapter::current();
		$params = self::sampleQuery( Container::clock()->today() );
		try {
			$quote = Container::pricing()->quote( Stay::fromStrings( $unit->id, $params['dolazak'], $params['odlazak'], wp_timezone() ), $params['odrasli'], $params['djeca'] );
		} catch ( BookingException $e ) {
			return array();
		}

		$stay    = $quote->stay;
		$money   = static fn( \Marclie\Booking\Domain\Money $m ): string => Formatter::money( $m->cents(), $lang );
		$nights  = $stay->nights();
		/* translators: %d: number of nights. */
		$nightsText = sprintf( _n( '%d night', '%d nights', $nights, 'marclie-booking' ), $nights );
		$due        = null === $quote->balanceDueOn ? '' : Formatter::date( $quote->balanceDueOn, $lang );
		$free       = null === $quote->freeCancelUntil ? '' : Formatter::date( $quote->freeCancelUntil, $lang );
		$arrival    = Formatter::date( $stay->arrival, $lang, Formatter::SHORT );
		$departure  = Formatter::date( $stay->departure, $lang, Formatter::SHORT );

		return array(
			'ref'                => ReferenceGenerator::base( \Marclie\Booking\Settings::refPrefix(), $stay->arrival, $unit->code, $unit->id ),
			'title'              => $unit->title,
			'unit'               => $unit->title,
			/* translators: %s: apartment name. */
			'intro'              => sprintf( __( 'We have sent the confirmation for %s to your email address.', 'marclie-booking' ), $unit->title ),
			'dates'              => $arrival . ' – ' . $departure . ' · ' . $nightsText,
			'arrival'            => $arrival,
			'departure'          => $departure,
			'place'              => \Marclie\Booking\Frontend\Renderer::unitCard( $unit, $lang )['place'],
			'checkin-window'     => $unit->checkinFrom . '–' . $unit->checkinTo,
			/* translators: %s: latest check-out time, e.g. "10:00". */
			'checkout-until'     => sprintf( __( 'by %s', 'marclie-booking' ), $unit->checkoutUntil ),
			'nights'             => $nightsText,
			/* translators: 1: number of adults, 2: number of children. */
			'guests'             => sprintf( __( 'Adults %1$d · Children %2$d', 'marclie-booking' ), $params['odrasli'], $params['djeca'] ),
			'checkin'            => Formatter::date( $stay->arrival, $lang ) . ', ' . $unit->checkinFrom . '–' . $unit->checkinTo,
			'total'              => $money( $quote->total ),
			'paid'               => $money( $quote->deposit ),
			'paid-amount'        => $money( $quote->deposit ),
			/* translators: 1: balance amount, 2: due date. */
			'balance'            => sprintf( __( '%1$s on %2$s', 'marclie-booking' ), $money( $quote->balance ), $due ),
			/* translators: %s: balance due date. */
			'balance-label'      => sprintf( __( 'Balance on %s', 'marclie-booking' ), $due ),
			'balance-amount'     => $money( $quote->balance ),
			/* translators: %s: balance due date. */
			'next-balance-title' => sprintf( __( 'Balance on %s.', 'marclie-booking' ), $due ),
			/* translators: %s: balance amount. */
			'next-balance'       => sprintf( __( 'We charge %s to the same card and email you the receipt.', 'marclie-booking' ), $money( $quote->balance ) ),
			'cancel'             => '' === $free
				? __( 'The deposit is non-refundable.', 'marclie-booking' )
				/* translators: %s: last day of free cancellation. */
				: sprintf( __( 'Free until %s; after that the deposit is non-refundable.', 'marclie-booking' ), $free ),
			/* translators: %s: apartment name. */
			'back'               => sprintf( __( 'Back to %s', 'marclie-booking' ), $unit->title ),
		);
	}

	/**
	 * Runs `$render` with the sample stay in `$_GET` when previewing in the builder without
	 * dates; elsewhere (frontend, or dates already in the URL) it runs unchanged.
	 *
	 * @param array<string, scalar> $extra More sample params (e.g. `apartman`); null values remove a param.
	 * @return mixed What `$render` returns.
	 */
	public static function run( callable $render, array $extra = array() ): mixed {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only.
		if ( ! self::active() || isset( $_GET['dolazak'] ) ) {
			return $render();
		}

		return self::withQuery( $extra + self::sampleQuery( Container::clock()->today() ), $render );
	}

	/**
	 * Temporarily sets K9 params in `$_GET` (Renderer reads the URL); restored afterwards.
	 *
	 * @param array<string, scalar|null> $params Null removes the param for the call.
	 * @return mixed What `$render` returns.
	 */
	public static function withQuery( array $params, callable $render ): mixed {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- builder preview values, not user input.
		$saved = $_GET;
		foreach ( $params as $key => $value ) {
			if ( null === $value ) {
				unset( $_GET[ $key ] );
			} else {
				$_GET[ $key ] = (string) $value;
			}
		}

		try {
			return $render();
		} finally {
			$_GET = $saved;
		}
		// phpcs:enable
	}

	/**
	 * Static state of a JS-driven widget for the builder: the `$state` panel shown, every other
	 * `[$stateAttr]` panel hidden, and empty `[$fieldAttr="key"]` elements filled with sample text.
	 *
	 * @param array<string, string> $fill Field key => plain text.
	 */
	public static function showState( string $html, string $stateAttr, string $state, string $fieldAttr = '', array $fill = array() ): string {
		$attr = preg_quote( $stateAttr, '/' );
		$html = (string) preg_replace_callback(
			'/<[a-z][a-z0-9]*\b[^>]*\s' . $attr . '="([^"]*)"[^>]*>/i',
			static function ( array $m ) use ( $state ): string {
				$tag = (string) preg_replace( '/\shidden(?=[\s>\/])/', '', $m[0] );

				return $m[1] === $state ? $tag : (string) preg_replace( '/\s*\/?>$/', ' hidden>', $tag );
			},
			$html
		);
		// Panel titles are h2 until front.js promotes the shown one; only that panel is visible here.
		$html = (string) preg_replace( '/<h2(\s[^>]*\bdata-state-title\b[^>]*)>(.*?)<\/h2>/s', '<h1$1>$2</h1>', $html );

		if ( '' === $fieldAttr ) {
			return $html;
		}
		foreach ( $fill as $key => $text ) {
			$html = (string) preg_replace_callback(
				'/(<[a-z][a-z0-9]*\b[^>]*\s' . preg_quote( $fieldAttr, '/' ) . '="' . preg_quote( $key, '/' ) . '"[^>]*>)(<\/)/i',
				static fn( array $m ): string => $m[1] . htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) . $m[2],
				$html
			);
		}

		return $html;
	}
}
