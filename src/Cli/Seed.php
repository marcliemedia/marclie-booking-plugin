<?php
/**
 * `wp mbk seed`: the DIRECT5 coupon. Prices are entered by the admin in the
 * 12-month price table (admin › Prices); nothing is seeded there.
 * Idempotent: an existing coupon is kept unless --force overwrites its values.
 */

namespace Marclie\Booking\Cli;

use Marclie\Booking\Repository\CouponRepository;

defined( 'ABSPATH' ) || exit;

final class Seed {

	private const COUPON = 'DIRECT5';

	public function __construct( private CouponRepository $coupons ) {}

	/**
	 * @param array<string, string|bool> $assocArgs force.
	 */
	public function run( array $assocArgs ): void {
		\WP_CLI::log( sprintf( 'Coupon %s: %s.', self::COUPON, $this->coupon( ! empty( $assocArgs['force'] ) ) ) );
		\WP_CLI::success( 'Seed complete.' );
	}

	private function coupon( bool $force ): string {
		$row = array(
			'code'       => self::COUPON,
			'type'       => 'pct',
			'amount'     => 5,
			'min_nights' => 0,
			'unit_ids'   => null,
			'active'     => true,
		);

		$existing = $this->coupons->findRowByCode( self::COUPON );
		if ( null === $existing ) {
			if ( ! $this->coupons->insert( $row ) ) {
				\WP_CLI::error( 'Could not create coupon ' . self::COUPON . '.' );
			}

			return 'created';
		}

		if ( $force ) {
			$this->coupons->update( (int) $existing['id'], $row );

			return 'updated';
		}

		return 'kept';
	}
}
