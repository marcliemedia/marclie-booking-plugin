<?php
/**
 * POST /coupons/validate {code, unit_id, arrival, departure, adults, children}
 * — checks a coupon against the priced stay („Primijeni”). An unusable coupon
 * is `valid: false` with the reason as message; other stay problems are errors.
 * Rate limited per IP (coupon guessing); never cached.
 */

namespace Marclie\Booking\Rest\Public;

use Marclie\Booking\Container;
use Marclie\Booking\Domain\Exception\CouponException;
use Marclie\Booking\I18n\Formatter;

defined( 'ABSPATH' ) || exit;

final class CouponController extends BaseController {

	public const RATE_LIMIT = 10;

	public function register(): void {
		$this->route(
			'/coupons/validate',
			\WP_REST_Server::CREATABLE,
			array( $this, 'validate' ),
			QuoteController::stayArgs() + array(
				'code' => array(
					'type'      => 'string',
					'required'  => true,
					'maxLength' => 40,
				),
			),
			self::CACHE_NONE
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	public function validate( \WP_REST_Request $request, string $lang ) {
		$limited = self::throttle( 'coupon', self::RATE_LIMIT );
		if ( $limited ) {
			return $limited;
		}

		$code = strtoupper( trim( (string) $request['code'] ) );
		if ( '' === $code ) {
			return self::invalid( 'not_found', array() );
		}

		$stay = QuoteController::requestStay( $request );
		if ( $stay instanceof \WP_Error ) {
			return $stay;
		}

		try {
			$quote = Container::pricing()->quote( $stay, (int) $request['adults'], (int) $request['children'], $code );
		} catch ( CouponException $e ) {
			return self::invalid( (string) ( $e->context()['reason'] ?? '' ), $e->context() );
		}

		$discount = $quote->discount->cents();

		return array(
			'valid'              => true,
			'code'               => $quote->couponCode,
			'discount_cents'     => $discount,
			'discount_formatted' => Formatter::price( $discount, $lang ),
			/* translators: 1: coupon code, 2: discount amount, e.g. "68,25 €" */
			'message'            => sprintf( __( 'Coupon %1$s applied: %2$s off.', 'marclie-booking' ), $quote->couponCode, Formatter::price( $discount, $lang ) ),
		);
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	private static function invalid( string $reason, array $context ): array {
		$reason = self::publicCouponReason( $reason );

		return array(
			'valid'              => false,
			'reason'             => $reason,
			'discount_cents'     => 0,
			'discount_formatted' => null,
			'message'            => self::couponMessage( $reason, $context ),
		);
	}
}
