<?php
/**
 * /admin/coupons — coupons with redemption stats, create/edit, delete
 * (used coupons are only deactivated), redemptions and code generator.
 */

namespace Marclie\Booking\Rest\Admin;

use Marclie\Booking\Container;
use Marclie\Booking\Coupons\Coupon;
use Marclie\Booking\Domain\Exception\NotFoundException;
use Marclie\Booking\Domain\Exception\ValidationException;
use Marclie\Booking\Install\Activator;
use Marclie\Booking\Repository\BookingRepository;
use Marclie\Booking\Repository\CouponRepository;
use Marclie\Booking\Repository\Db;
use Marclie\Booking\Service\PriceService;

defined( 'ABSPATH' ) || exit;

final class CouponsController extends BaseController {

	private const ID = '/coupons/(?P<id>\d+)';

	private const FIELDS = array( 'code', 'type', 'amount', 'min_nights', 'book_from', 'book_to', 'stay_from', 'stay_to', 'unit_ids', 'usage_limit', 'per_guest_limit', 'guest_id', 'active' );

	private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	/** Upper bounds of the optional integer fields. */
	private const LIMITS = array(
		'min_nights'      => 365,
		'usage_limit'     => 1000000,
		'per_guest_limit' => 1000000,
	);

	public function register(): void {
		$cap = Activator::CAP_RATES;

		$this->route(
			'/coupons',
			array(
				$this->endpoint(
					\WP_REST_Server::READABLE,
					array( $this, 'index' ),
					$cap,
					array_merge(
						self::paginationArgs(),
						array(
							'active' => array( 'type' => 'boolean' ),
							'search' => array( 'type' => 'string' ),
						)
					)
				),
				$this->endpoint( \WP_REST_Server::CREATABLE, array( $this, 'create' ), $cap, self::fieldArgs( true ) ),
			)
		);
		$this->route(
			'/coupons/generate-code',
			$this->endpoint(
				\WP_REST_Server::READABLE,
				array( $this, 'generateCode' ),
				$cap,
				array( 'prefix' => array( 'type' => 'string' ) )
			)
		);
		$this->route(
			self::ID,
			array(
				$this->endpoint( 'PATCH', array( $this, 'update' ), $cap, self::fieldArgs( false ) ),
				$this->endpoint( \WP_REST_Server::DELETABLE, array( $this, 'delete' ), $cap ),
			)
		);
		$this->route( self::ID . '/redemptions', $this->endpoint( \WP_REST_Server::READABLE, array( $this, 'redemptions' ), $cap, self::paginationArgs() ) );
	}

	/** @return array<string, mixed> */
	public function index( \WP_REST_Request $request ): array {
		$repo   = Container::couponRepository();
		$result = $repo->search(
			array(
				'active' => null === $request['active'] ? null : (bool) $request['active'],
				'search' => (string) $request['search'],
			),
			(int) $request['page'],
			(int) $request['per_page']
		);
		$stats = $repo->stats( array_map( static fn( array $r ): int => (int) $r['id'], $result['items'] ) );

		$items = array_map(
			static fn( array $row ): array => Presenter::coupon( $row ) + array(
				'stats' => $stats[ (int) $row['id'] ] ?? array(
					'redemptions'          => 0,
					'discount_total_cents' => 0,
				),
			),
			$result['items']
		);

		return self::listResponse( $items, $result['total'], $request );
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response {
		$row = self::validate( self::fields( $request ), null );
		$id  = Db::transaction(
			static function () use ( $row ): int {
				$id = Container::couponRepository()->insert( $row );
				Db::check( $id ?: false, 'Creating coupon' );
				self::log( 'coupon_created', array( 'coupon_id' => $id, 'code' => $row['code'] ) );

				return $id;
			}
		);

		return new \WP_REST_Response( Presenter::coupon( self::mustFind( $id ) ), 201 );
	}

	/** @return array<string, mixed> */
	public function update( \WP_REST_Request $request ): array {
		$existing = self::mustFind( (int) $request['id'] );
		$patch    = self::fields( $request );
		$row      = array_intersect_key( self::validate( $patch, $existing ), $patch );
		if ( $row ) {
			Db::transaction(
				static function () use ( $existing, $row ): void {
					Db::check( Container::couponRepository()->update( (int) $existing['id'], $row ) ?: false, 'Updating coupon' );
					self::log(
						'coupon_updated',
						array(
							'coupon_id' => (int) $existing['id'],
							'code'      => (string) $existing['code'],
							'fields'    => array_keys( $row ),
						)
					);
				}
			);
		}

		return Presenter::coupon( self::mustFind( (int) $existing['id'] ) );
	}

	/** @return array{deleted: bool, deactivated?: bool} */
	public function delete( \WP_REST_Request $request ): array {
		$coupon = self::mustFind( (int) $request['id'] );
		$repo   = Container::couponRepository();
		$id     = (int) $coupon['id'];

		// Used = counted, redeemed, or carried by a booking that may still be paid/redeemed.
		// Checked and deleted in one transaction so a booking cannot pick it up in between.
		return Db::transaction(
			static function () use ( $repo, $id, $coupon ): array {
				$used = (int) $coupon['used_count'] > 0
					|| $repo->countRedemptions( $id ) > 0
					|| Container::bookingRepository()->countWithCoupon( (string) $coupon['code'], BookingRepository::ACTIVE_STATUSES ) > 0;

				if ( $used ) {
					Db::check( $repo->update( $id, array( 'active' => false ) ) ?: false, 'Deactivating coupon' );
					self::log( 'coupon_deactivated', array( 'coupon_id' => $id, 'code' => (string) $coupon['code'] ) );

					return array(
						'deleted'     => false,
						'deactivated' => true,
					);
				}

				Db::check( $repo->delete( $id ) ?: false, 'Deleting coupon' );
				self::log( 'coupon_deleted', array( 'coupon_id' => $id, 'code' => (string) $coupon['code'] ) );

				return array( 'deleted' => true );
			}
		);
	}

	/** @return array<string, mixed> */
	public function redemptions( \WP_REST_Request $request ): array {
		$coupon = self::mustFind( (int) $request['id'] );
		$result = Container::couponRedemptionRepository()->pageForCoupon( (int) $coupon['id'], (int) $request['page'], (int) $request['per_page'] );

		$ids      = array_map( static fn( array $r ): int => (int) $r['booking_id'], $result['items'] );
		$bookings = array();
		if ( $ids ) {
			foreach ( Presenter::bookingSummaries( Container::bookingRepository()->findAll( array( 'ids' => $ids ), 'created_at', 'DESC', count( $ids ) ) ) as $summary ) {
				$bookings[ $summary['id'] ] = $summary;
			}
		}

		$items = array();
		foreach ( $result['items'] as $row ) {
			if ( ! isset( $bookings[ (int) $row['booking_id'] ] ) ) {
				continue;
			}
			$items[] = array(
				'booking'        => $bookings[ (int) $row['booking_id'] ],
				'discount_cents' => (int) $row['discount_cents'],
				'created_at'     => Presenter::iso( (string) $row['created_at'] ),
			);
		}

		return self::listResponse( $items, $result['total'], $request );
	}

	/** @return array{code: string} */
	public function generateCode( \WP_REST_Request $request ): array {
		$prefix = substr( (string) preg_replace( '/[^A-Z0-9]/', '', strtoupper( (string) $request['prefix'] ) ), 0, 20 );
		$repo   = Container::couponRepository();
		$max    = strlen( self::CODE_ALPHABET ) - 1;

		for ( $attempt = 0; $attempt < 20; $attempt++ ) {
			$random = '';
			for ( $i = 0; $i < 6; $i++ ) {
				$random .= self::CODE_ALPHABET[ random_int( 0, $max ) ];
			}
			$code = '' !== $prefix ? $prefix . '-' . $random : $random;
			if ( null === $repo->findRowByCode( $code ) ) {
				return array( 'code' => $code );
			}
		}

		throw new \RuntimeException( 'Could not generate a unique coupon code.' );
	}

	/* Helpers ---------------------------------------------------------------- */

	/** @return array<string, mixed> */
	private static function mustFind( int $id ): array {
		$row = Container::couponRepository()->findRow( $id );
		if ( null === $row ) {
			throw new NotFoundException( 'Coupon not found.', array( 'coupon_id' => $id ) );
		}

		return $row;
	}

	/** @return array<string, mixed> */
	private static function fields( \WP_REST_Request $request ): array {
		$out = array();
		foreach ( self::FIELDS as $field ) {
			if ( self::has( $request, $field ) ) {
				$out[ $field ] = $request[ $field ];
			}
		}

		return $out;
	}

	/**
	 * Validates the merged coupon (existing row + patch) and returns DB columns.
	 *
	 * @param array<string, mixed>      $data
	 * @param array<string, mixed>|null $existing Raw row when updating.
	 * @return array<string, mixed>
	 */
	private static function validate( array $data, ?array $existing ): array {
		$current = null === $existing ? array() : Presenter::coupon( $existing );
		$c       = array_merge( $current, $data );
		$errors  = array();

		$code = CouponRepository::normalizeCode( (string) ( $c['code'] ?? '' ) );
		if ( ! preg_match( '/^[A-Z0-9_-]{3,50}$/', $code ) ) {
			$errors['code'] = __( 'Use 3–50 letters, digits, - or _.', 'marclie-booking' );
		} else {
			$other = Container::couponRepository()->findRowByCode( $code );
			if ( null !== $other && ( null === $existing || (int) $other['id'] !== (int) $existing['id'] ) ) {
				$errors['code'] = __( 'This code is already in use.', 'marclie-booking' );
			}
		}

		$type = (string) ( $c['type'] ?? '' );
		if ( ! in_array( $type, array( Coupon::PCT, Coupon::FIXED ), true ) ) {
			$errors['type'] = __( 'Choose percentage or fixed amount.', 'marclie-booking' );
		}
		$amount = $c['amount'] ?? null;
		$maxAmount = Coupon::PCT === $type ? 100 : PriceService::MAX_CENTS;
		if ( ! is_int( $amount ) || $amount < 1 || $amount > $maxAmount ) {
			$errors['amount'] = Coupon::PCT === $type ? __( 'Enter a percentage between 1 and 100.', 'marclie-booking' ) : __( 'Enter an amount between 0,01 € and 1.000.000 €.', 'marclie-booking' );
		}

		foreach ( self::LIMITS as $field => $max ) {
			$value = $c[ $field ] ?? null;
			if ( null !== $value && ( ! is_int( $value ) || $value < 1 || $value > $max ) ) {
				/* translators: %d: maximum */
				$errors[ $field ] = sprintf( __( 'Enter a whole number between 1 and %d, or leave empty.', 'marclie-booking' ), $max );
			}
		}

		foreach ( array( 'book_from', 'book_to', 'stay_from', 'stay_to' ) as $field ) {
			$value = $c[ $field ] ?? null;
			if ( null === $value || '' === $value ) {
				$c[ $field ] = null;
				continue;
			}
			$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $value );
			if ( false === $d || $d->format( 'Y-m-d' ) !== $value ) {
				$errors[ $field ] = __( 'Enter a valid date.', 'marclie-booking' );
			}
		}
		foreach ( array( 'book', 'stay' ) as $range ) {
			if ( ! isset( $errors[ $range . '_from' ], $errors[ $range . '_to' ] ) && null !== $c[ $range . '_from' ] && null !== $c[ $range . '_to' ] && $c[ $range . '_to' ] < $c[ $range . '_from' ] ) {
				$errors[ $range . '_to' ] = __( 'The end date must not be before the start date.', 'marclie-booking' );
			}
		}

		$unitIds = null;
		if ( isset( $c['unit_ids'] ) && null !== $c['unit_ids'] ) {
			$unitIds = array();
			foreach ( (array) $c['unit_ids'] as $id ) {
				$unit = Container::units()->get( (int) $id );
				if ( null === $unit ) {
					$errors['unit_ids'] = __( 'Unknown unit.', 'marclie-booking' );
					break;
				}
				$unitIds[] = $unit->id;
			}
			$unitIds = $unitIds ?: null;
		}

		$guestId = isset( $c['guest_id'] ) && null !== $c['guest_id'] ? (int) $c['guest_id'] : null;
		if ( null !== $guestId && null === Container::guestRepository()->find( $guestId ) ) {
			$errors['guest_id'] = __( 'Unknown guest.', 'marclie-booking' );
		}

		if ( $errors ) {
			throw ValidationException::fields( $errors );
		}

		return array(
			'code'            => $code,
			'type'            => $type,
			'amount'          => $amount,
			'min_nights'      => (int) ( $c['min_nights'] ?? 0 ),
			'book_from'       => $c['book_from'],
			'book_to'         => $c['book_to'],
			'stay_from'       => $c['stay_from'],
			'stay_to'         => $c['stay_to'],
			'unit_ids'        => $unitIds,
			'usage_limit'     => $c['usage_limit'] ?? null,
			'per_guest_limit' => $c['per_guest_limit'] ?? null,
			'guest_id'        => $guestId,
			'active'          => (bool) ( $c['active'] ?? true ),
		);
	}

	/** @param array<string, mixed> $payload */
	private static function log( string $type, array $payload ): void {
		Container::eventRepository()->log( $type, self::actor(), null, null, $payload );
	}

	/** @return array<string, array<string, mixed>> */
	private static function fieldArgs( bool $create ): array {
		$nullableInt  = array( 'type' => array( 'integer', 'null' ) );
		$nullableDate = array(
			'type'    => array( 'string', 'null' ),
			'pattern' => '^(\d{4}-\d{2}-\d{2})?$',
		);

		return array(
			'code'            => array(
				'type'     => 'string',
				'required' => $create,
			),
			'type'            => array(
				'type'     => 'string',
				'enum'     => array( Coupon::PCT, Coupon::FIXED ),
				'required' => $create,
			),
			'amount'          => array(
				'type'     => 'integer',
				'required' => $create,
			),
			'min_nights'      => $nullableInt,
			'book_from'       => $nullableDate,
			'book_to'         => $nullableDate,
			'stay_from'       => $nullableDate,
			'stay_to'         => $nullableDate,
			'unit_ids'        => array(
				'type'  => array( 'array', 'null' ),
				'items' => array( 'type' => 'integer' ),
			),
			'usage_limit'     => $nullableInt,
			'per_guest_limit' => $nullableInt,
			'guest_id'        => $nullableInt,
			'active'          => array( 'type' => 'boolean' ),
		);
	}
}
