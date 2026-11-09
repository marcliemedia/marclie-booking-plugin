<?php
/**
 * POST /quote {unit_id, arrival, departure, adults, children, infants?, coupon?} — full
 * quote (admin contract shape) plus display strings in the request language.
 * Rate limited per IP; never cached.
 */

namespace Marclie\Booking\Rest\Public;

use Marclie\Booking\Container;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\Service\QuoteDisplay;

defined( 'ABSPATH' ) || exit;

final class QuoteController extends BaseController {

	public const RATE_LIMIT = 30;

	public function register(): void {
		$this->route( '/quote', \WP_REST_Server::CREATABLE, array( $this, 'quote' ), self::stayArgs() + array( 'coupon' => array( 'type' => array( 'string', 'null' ), 'maxLength' => 40 ) ), self::CACHE_NONE );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function quote( \WP_REST_Request $request, string $lang ) {
		$coupon  = trim( (string) $request['coupon'] );
		$limited = self::throttle( 'quote', self::RATE_LIMIT );
		// A coupon in the quote is a coupon check too: same budget as /coupons/validate.
		if ( ! $limited && '' !== $coupon ) {
			$limited = self::throttle( 'coupon', CouponController::RATE_LIMIT );
		}
		if ( $limited ) {
			return $limited;
		}

		$stay = self::requestStay( $request );
		if ( $stay instanceof \WP_Error ) {
			return $stay;
		}

		$quote = Container::pricing()->quote( $stay, (int) $request['adults'], (int) $request['children'], '' !== $coupon ? $coupon : null, null, (int) $request['infants'] );
		$data  = $quote->toArray();
		unset( $data['coupon_id'] );

		return array(
			'available' => Container::availability()->isAvailable( $quote->stay ),
			'quote'     => $data,
			'display'   => QuoteDisplay::build( $quote, $lang, Container::policy()->depositPct ),
		);
	}

	/**
	 * Stay of the request on the canonical unit, or `unit_not_found`.
	 *
	 * @return Stay|\WP_Error
	 * @throws \Marclie\Booking\Domain\Exception\InvalidStayException
	 */
	public static function requestStay( \WP_REST_Request $request ) {
		$unit = Container::units()->get( (int) $request['unit_id'] );
		if ( null === $unit ) {
			return self::error( 'unit_not_found', array( 'unit_id' => (int) $request['unit_id'] ) );
		}

		return self::stay( $unit->id, (string) $request['arrival'], (string) $request['departure'] );
	}

	/** @return array<string, array<string, mixed>> */
	public static function stayArgs(): array {
		return array(
			'unit_id'   => self::intArg( 1, PHP_INT_MAX, null, true ),
			'arrival'   => self::dateArg( true ),
			'departure' => self::dateArg( true ),
			'adults'    => self::intArg( 1, SearchController::MAX_ADULTS, 2 ),
			'children'  => self::intArg( 0, SearchController::MAX_CHILDREN, 0 ),
			'infants'   => self::intArg( 0, \Marclie\Booking\Frontend\Renderer::MAX_INFANTS, 0 ),
		);
	}
}
