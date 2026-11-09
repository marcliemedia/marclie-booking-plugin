<?php
/**
 * Stripe keys, read only from wp-config.php constants (never stored in the DB,
 * never returned by an API — only their last four characters).
 */

namespace Marclie\Booking\Payment;

use Marclie\Booking\Plugin;

defined( 'ABSPATH' ) || exit;

final class StripeConfig {

	public const PUBLISHABLE = 'MBK_STRIPE_PUBLISHABLE_KEY';
	public const SECRET      = 'MBK_STRIPE_SECRET_KEY';
	public const WEBHOOK     = 'MBK_STRIPE_WEBHOOK_SECRET';

	/** Constant => required key prefix (restricted `rk_` keys are valid secret keys). */
	public const PREFIXES = array(
		self::PUBLISHABLE => array( 'pk_' ),
		self::SECRET      => array( 'sk_', 'rk_' ),
		self::WEBHOOK     => array( 'whsec_' ),
	);

	/** Events the webhook endpoint must be subscribed to. */
	public const WEBHOOK_EVENTS = array(
		'payment_intent.succeeded',
		'payment_intent.payment_failed',
		'charge.refunded',
		'charge.dispute.created',
		'refund.created',
		'refund.updated',
		'refund.failed',
	);

	public const WEBHOOK_ROUTE = '/stripe/webhook';

	public static function publishableKey(): string {
		return self::constant( self::PUBLISHABLE );
	}

	public static function secretKey(): string {
		return self::constant( self::SECRET );
	}

	public static function webhookSecret(): string {
		return self::constant( self::WEBHOOK );
	}

	/** `test` | `live` from a pk_/sk_/rk_ key prefix, null when unknown. */
	public static function modeOf( string $key ): ?string {
		return preg_match( '/^(?:pk|sk|rk)_(test|live)_/', $key, $m ) ? $m[1] : null;
	}

	/** Mode of the secret key, else of the publishable key. */
	public static function mode(): ?string {
		return self::modeOf( self::secretKey() ) ?? self::modeOf( self::publishableKey() );
	}

	/** Both keys are set and their modes differ (test vs live). */
	public static function mismatch(): bool {
		$publishable = self::modeOf( self::publishableKey() );
		$secret      = self::modeOf( self::secretKey() );

		return null !== $publishable && null !== $secret && $publishable !== $secret;
	}

	public static function hasValidPrefix( string $constant ): bool {
		$value = self::constant( $constant );
		foreach ( self::PREFIXES[ $constant ] ?? array() as $prefix ) {
			if ( str_starts_with( $value, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * All three constants set with valid prefixes, a test/live mode and no mismatch:
	 * checkout may take payments.
	 */
	public static function configured(): bool {
		foreach ( array_keys( self::PREFIXES ) as $constant ) {
			if ( ! self::hasValidPrefix( $constant ) ) {
				return false;
			}
		}

		return null !== self::modeOf( self::publishableKey() ) && null !== self::modeOf( self::secretKey() ) && ! self::mismatch();
	}

	/**
	 * Per-key status for the admin: set, last four characters, mode.
	 *
	 * @return array{publishable: array{set:bool, last4:?string, mode:?string}, secret: array{set:bool, last4:?string, mode:?string}, webhook: array{set:bool, last4:?string}}
	 */
	public static function keys(): array {
		$describe = static function ( string $value, bool $withMode ): array {
			$out = array(
				'set'   => '' !== $value,
				'last4' => '' !== $value ? substr( $value, -4 ) : null,
			);
			if ( $withMode ) {
				$out['mode'] = self::modeOf( $value );
			}

			return $out;
		};

		return array(
			'publishable' => $describe( self::publishableKey(), true ),
			'secret'      => $describe( self::secretKey(), true ),
			'webhook'     => $describe( self::webhookSecret(), false ),
		);
	}

	public static function webhookUrl(): string {
		return rest_url( Plugin::REST_NAMESPACE . self::WEBHOOK_ROUTE );
	}

	public static function dashboardUrl(): string {
		return 'test' === self::mode() ? 'https://dashboard.stripe.com/test/dashboard' : 'https://dashboard.stripe.com/dashboard';
	}

	/** Stripe Dashboard page of a PaymentIntent (test or live by the key mode). */
	public static function paymentUrl( string $intentId ): string {
		return ( 'test' === self::mode() ? 'https://dashboard.stripe.com/test/payments/' : 'https://dashboard.stripe.com/payments/' ) . rawurlencode( $intentId );
	}

	/**
	 * Identifier of this installation, sent as PaymentIntent metadata `site`:
	 * intents of other sites on the same Stripe account are never applied here.
	 */
	public static function siteId(): string {
		return substr( hash( 'sha256', untrailingslashit( home_url() ) ), 0, 16 );
	}

	private static function constant( string $name ): string {
		return defined( $name ) ? trim( (string) constant( $name ) ) : '';
	}
}
