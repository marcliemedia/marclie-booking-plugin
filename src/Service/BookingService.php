<?php
/**
 * Booking lifecycle: creation with an atomic night hold (checkout and admin),
 * admin edits/moves/outside payments, state transitions (architecture §4) and
 * guest access-token verification.
 */

namespace Marclie\Booking\Service;

use Marclie\Booking\Account\AccountService;
use Marclie\Booking\Coupons\Coupon;
use Marclie\Booking\Domain\BookingStatus;
use Marclie\Booking\Domain\Clock;
use Marclie\Booking\Domain\Exception\CapacityException;
use Marclie\Booking\Domain\Exception\CouponException;
use Marclie\Booking\Domain\Exception\DuplicatePaymentException;
use Marclie\Booking\Domain\Exception\InvalidTransitionException;
use Marclie\Booking\Domain\Exception\NotFoundException;
use Marclie\Booking\Domain\Exception\UnavailableException;
use Marclie\Booking\Domain\Exception\UnitNotFoundException;
use Marclie\Booking\Domain\Exception\ValidationException;
use Marclie\Booking\Domain\Policy;
use Marclie\Booking\Domain\Quote;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\Guests\GuestService;
use Marclie\Booking\Repository\BookingRepository;
use Marclie\Booking\Repository\CouponRedemptionRepository;
use Marclie\Booking\Repository\CouponRepository;
use Marclie\Booking\Repository\Db;
use Marclie\Booking\Repository\EventRepository;
use Marclie\Booking\Repository\LockConflictException;
use Marclie\Booking\Repository\PaymentRepository;
use Marclie\Booking\Repository\TokenRepository;
use Marclie\Booking\Units\UnitProvider;

defined( 'ABSPATH' ) || exit;

final class BookingService {

	private const REF_ATTEMPTS = 5;

	/** An identical outside payment within this window is treated as a double submit. */
	private const DUPLICATE_WINDOW_SECONDS = 10;

	/** Statuses a booking can be moved in. */
	private const MOVABLE = array( BookingStatus::PendingPayment, BookingStatus::Confirmed, BookingStatus::Paid, BookingStatus::BalanceFailed );

	/** Statuses that accept outside payments (every status that keeps its nights). */
	private const PAYABLE = array( BookingStatus::PendingPayment, BookingStatus::Confirmed, BookingStatus::Paid, BookingStatus::BalanceFailed, BookingStatus::Completed );

	public function __construct(
		private BookingRepository $bookings,
		private AvailabilityService $availability,
		private GuestService $guests,
		private ReferenceGenerator $references,
		private UnitProvider $units,
		private EventRepository $events,
		private CouponRepository $coupons,
		private CouponRedemptionRepository $redemptions,
		private CouponService $couponService,
		private PaymentRepository $payments,
		private PricingService $pricing,
		private Policy $policy,
		private Clock $clock,
		private TokenRepository $tokens
	) {}

	/**
	 * Creates a pending_payment booking and holds its nights for policy.holdMinutes.
	 * Guest upsert, coupon re-validation, booking insert and night hold run in one
	 * transaction (retried once on a lock conflict): a failed hold leaves no
	 * booking and no new guest. The unit is always stored as the canonical (HR) id.
	 *
	 * @param array{email:string, first_name?:string, last_name?:string, phone?:string, country?:string, arrival_time?:string, message?:string, terms_accepted?:bool, terms_version?:string, marketing_consent?:bool, user_id?:int|null} $guest
	 * @return array{booking: array<string, mixed>, token: string} The plain access token is only returned here.
	 * @throws UnitNotFoundException|UnavailableException|CouponException
	 */
	public function create( Stay $stay, Quote $quote, array $guest, string $lang, string $source = 'direct', ?int $actorUserId = null ): array {
		$expires = Db::utc( $this->clock->now()->modify( sprintf( '+%d minutes', $this->policy->holdMinutes ) ) );

		return $this->insertNew( $stay, $quote, $guest, $lang, $source, $actorUserId, $expires );
	}

	/**
	 * Shared by create() and createManual(). `$expiresUtc` null = hold without expiry.
	 *
	 * @param array<string, mixed> $guest
	 * @param array<string, mixed> $extraRow Additional booking columns (e.g. internal_notes).
	 * @return array{booking: array<string, mixed>, token: string}
	 */
	private function insertNew( Stay $stay, Quote $quote, array $guest, string $lang, string $source, ?int $actorUserId, ?string $expiresUtc, array $extraRow = array() ): array {
		$unit = $this->units->get( $stay->unitId );
		if ( null === $unit ) {
			throw new UnitNotFoundException( 'Unit not found.', array( 'unit_id' => $stay->unitId ) );
		}
		if ( $unit->id !== $stay->unitId ) {
			$stay = new Stay( $unit->id, $stay->arrival, $stay->departure );
		}

		if ( $quote->stay->arrivalString() !== $stay->arrivalString()
			|| $quote->stay->departureString() !== $stay->departureString()
			|| $this->units->get( $quote->stay->unitId )?->id !== $unit->id ) {
			throw new \InvalidArgumentException( 'The quote does not match the stay.' );
		}

		$email = strtolower( trim( (string) ( $guest['email'] ?? '' ) ) );
		if ( '' === $email || ! is_email( $email ) ) {
			throw new \InvalidArgumentException( 'A valid guest email is required.' );
		}

		if ( ! $this->availability->isAvailable( $stay ) ) {
			throw AvailabilityService::unavailable( $stay );
		}

		$token   = bin2hex( random_bytes( 32 ) );
		$nowUtc  = Db::utc( $this->clock->now() );
		$expires = $expiresUtc;

		$row = array(
			'unit_id'           => $stay->unitId,
			'arrival'           => $stay->arrivalString(),
			'departure'         => $stay->departureString(),
			'nights'            => $stay->nights(),
			'adults'            => $quote->adults,
			'children'          => $quote->children,
			'infants'           => $quote->infants,
			'status'            => BookingStatus::PendingPayment->value,
			'lang'              => $lang,
			'first_name'        => trim( (string) ( $guest['first_name'] ?? '' ) ),
			'last_name'         => trim( (string) ( $guest['last_name'] ?? '' ) ),
			'email'             => $email,
			'phone'             => trim( (string) ( $guest['phone'] ?? '' ) ),
			'country'           => strtoupper( substr( trim( (string) ( $guest['country'] ?? '' ) ), 0, 2 ) ),
			'arrival_time'      => trim( (string) ( $guest['arrival_time'] ?? '' ) ),
			'message'           => isset( $guest['message'] ) && '' !== trim( (string) $guest['message'] ) ? trim( (string) $guest['message'] ) : null,
			// Only the account that verified this booking email (Account\AccountService::checkoutUserId()).
			'user_id'           => ! empty( $guest['user_id'] ) && AccountService::verifiedEmailOf( (int) $guest['user_id'] ) === $email ? (int) $guest['user_id'] : null,
			'currency'          => 'EUR',
			'subtotal_cents'    => $quote->subtotal->cents(),
			'discount_cents'    => $quote->discount->cents(),
			'total_cents'       => $quote->total->cents(),
			'deposit_cents'     => $quote->deposit->cents(),
			'balance_cents'     => $quote->balance->cents(),
			'balance_due_on'    => $quote->balanceDueOn?->format( 'Y-m-d' ),
			'free_cancel_until' => $quote->freeCancelUntil?->format( 'Y-m-d' ),
			'coupon_code'       => $quote->couponCode,
			'quote_json'        => wp_json_encode( $quote->toArray() ),
			'policy_json'       => wp_json_encode( $this->policy->toArray() ),
			'terms_accepted_at' => empty( $guest['terms_accepted'] ) ? null : $nowUtc,
			'terms_version'     => isset( $guest['terms_version'] ) ? (string) $guest['terms_version'] : null,
			'hold_expires_at'   => $expires,
			'source'            => $source,
			'created_at'        => $nowUtc,
			'updated_at'        => $nowUtc,
		) + $extraRow;
		$tokenHash = hash( 'sha256', $token );

		$attempt = function () use ( $row, $stay, $quote, $guest, $lang, $unit, $nowUtc, $source, $actorUserId, $expires, $tokenHash ): int {
			return Db::transaction(
				function () use ( $row, $stay, $quote, $guest, $lang, $unit, $nowUtc, $source, $actorUserId, $expires, $tokenHash ): int {
					// Online checkouts are unverified (anyone can type an email): they never overwrite CRM data.
					$guestId         = $this->guests->upsertFromBooking( $guest, $lang, 'direct' === $source );
					$row['guest_id'] = $guestId;

					if ( null !== $quote->couponCode && '' !== $quote->couponCode ) {
						$coupon = $this->couponService->validate( $quote->couponCode, $stay, $quote->subtotal, $guestId );
						$this->assertUsageLimitWithHolds( $coupon, $nowUtc );
					}

					$bookingId = $this->insertWithReference( $row, $stay, $unit->code );
					$this->availability->hold( $bookingId, $stay );
					Db::check( $this->tokens->insert( $bookingId, TokenRepository::ACCESS, $tokenHash, null ) ?: false, 'Storing access token' );

					Db::check(
						$this->events->log(
							'booking_created',
							$this->actor( $actorUserId, $source ),
							$bookingId,
							$guestId,
							array(
								'source'          => $source,
								'total_cents'     => $quote->total->cents(),
								'deposit_cents'   => $quote->deposit->cents(),
								'coupon_code'     => $quote->couponCode,
								'hold_expires_at' => $expires,
							)
						) ?: false,
						'Logging booking_created'
					);

					return $bookingId;
				}
			);
		};

		try {
			$bookingId = Db::retryOnLock( $attempt );
		} catch ( LockConflictException $e ) {
			throw AvailabilityService::unavailable( $stay, $e );
		}

		$booking = (array) $this->bookings->find( $bookingId );

		/**
		 * Fires after a booking was created and its nights are held.
		 *
		 * @param array<string, mixed> $booking
		 */
		do_action( 'mbk_booking_created', $booking );

		return array(
			'booking' => $booking,
			'token'   => $token,
		);
	}

	/**
	 * Moves a booking to `$to`. Nights are released (cancelled/expired) and the
	 * coupon redemption is recorded (confirmed/paid, once) in the same transaction.
	 * `$latePayment` additionally allows BookingStatus::latePaymentTargets() (the
	 * caller re-holds the nights first, see reviveExpired()).
	 *
	 * @param array<string, mixed> $context Stored in the event payload.
	 * @return array<string, mixed> The updated booking.
	 * @throws InvalidTransitionException
	 */
	public function transition( int $bookingId, BookingStatus $to, string $actor, array $context = array(), bool $latePayment = false ): array {
		$booking = $this->bookings->find( $bookingId );
		if ( null === $booking ) {
			throw new \InvalidArgumentException( 'Booking not found.' );
		}

		$from    = BookingStatus::tryFrom( (string) $booking['status'] );
		$allowed = null !== $from && ( $from->canTransitionTo( $to ) || ( $latePayment && in_array( $to, $from->latePaymentTargets(), true ) ) );
		if ( ! $allowed ) {
			throw new InvalidTransitionException(
				sprintf( 'Cannot change booking status from %s to %s.', $booking['status'], $to->value ),
				array(
					'from' => $booking['status'],
					'to'   => $to->value,
				)
			);
		}

		Db::transaction(
			function () use ( $booking, $from, $to, $actor, $context ): void {
				$extra   = in_array( $to, array( BookingStatus::Confirmed, BookingStatus::Paid ), true ) ? array( 'hold_expires_at' => null ) : array();
				$changed = Db::check( $this->bookings->updateStatus( $booking['id'], $from->value, $to->value, $extra ), 'Updating booking status' );
				if ( 1 !== $changed ) {
					throw new InvalidTransitionException(
						'The booking status changed concurrently.',
						array(
							'from' => $from->value,
							'to'   => $to->value,
						)
					);
				}

				if ( ! $to->occupiesNights() ) {
					$this->availability->release( $booking['id'] );
				}

				$redemption = null;
				if ( in_array( $to, array( BookingStatus::Confirmed, BookingStatus::Paid ), true ) ) {
					$redemption = $this->redeemCoupon( $booking );
				}

				Db::check(
					$this->events->log(
						'status_changed',
						$actor,
						$booking['id'],
						$booking['guest_id'],
						array_filter(
							array(
								'from'       => $from->value,
								'to'         => $to->value,
								'context'    => $context,
								'redemption' => $redemption,
							)
						)
					) ?: false,
					'Logging status change'
				);
			}
		);

		$updated = (array) $this->bookings->find( $bookingId );

		/**
		 * Fires after every booking status change.
		 *
		 * @param array<string, mixed> $updated Booking row after the change.
		 * @param BookingStatus        $from
		 * @param BookingStatus        $to
		 */
		do_action( 'mbk_booking_status_changed', $updated, $from, $to );

		return $updated;
	}

	/**
	 * Admin booking (source `admin`): for an existing guest (`guest.id`) or new
	 * contact data. The nights are held without expiry. Payment mode `outside`
	 * records the money received outside the system (cash, bank, other) and moves
	 * the booking to confirmed/paid by amount; mode `none` leaves it
	 * pending_payment (payment link: phase 06).
	 *
	 * @param array{id?:int|null, email?:string, first_name?:string, last_name?:string, phone?:string, country?:string, lang?:string} $guest
	 * @param array{mode:string, amount_cents?:int|null, method?:string|null} $payment
	 * @return array<string, mixed> The booking row.
	 * @throws ValidationException|NotFoundException|UnitNotFoundException|UnavailableException|CouponException
	 */
	public function createManual( Stay $stay, int $adults, int $children, array $guest, ?string $couponCode, array $payment, ?string $internalNotes, int $actorUserId, int $infants = 0 ): array {
		$mode = (string) ( $payment['mode'] ?? '' );
		if ( ! in_array( $mode, array( 'outside', 'none' ), true ) ) {
			throw ValidationException::fields( array( 'payment.mode' => __( 'Choose how the booking is paid.', 'marclie-booking' ) ) );
		}
		$amount = (int) ( $payment['amount_cents'] ?? 0 );
		$method = (string) ( $payment['method'] ?? '' );
		if ( 'outside' === $mode ) {
			$errors = array();
			if ( $amount <= 0 ) {
				$errors['payment.amount_cents'] = __( 'Enter the amount received.', 'marclie-booking' );
			}
			if ( ! in_array( $method, PaymentRepository::OUTSIDE_METHODS, true ) ) {
				$errors['payment.method'] = __( 'Choose a payment method.', 'marclie-booking' );
			}
			if ( $errors ) {
				throw ValidationException::fields( $errors );
			}
		}

		$guestId = null;
		$data    = array();
		$lang    = in_array( $guest['lang'] ?? null, array( 'hr', 'en' ), true ) ? (string) $guest['lang'] : null;
		if ( ! empty( $guest['id'] ) ) {
			$existing = $this->guests->find( (int) $guest['id'] );
			if ( null === $existing ) {
				throw new NotFoundException( 'Guest not found.', array( 'guest_id' => (int) $guest['id'] ) );
			}
			if ( null !== $existing['anonymized_at'] ) {
				throw ValidationException::fields( array( 'guest.id' => __( 'This guest was anonymized and cannot be booked.', 'marclie-booking' ) ) );
			}
			$guestId = (int) $existing['id'];
			$data    = array(
				'email'      => (string) $existing['email'],
				'first_name' => (string) $existing['first_name'],
				'last_name'  => (string) $existing['last_name'],
				'phone'      => (string) $existing['phone'],
				'country'    => (string) $existing['country'],
			);
			$lang  ??= in_array( $existing['lang'], array( 'hr', 'en' ), true ) ? (string) $existing['lang'] : 'hr';
		}
		foreach ( array( 'first_name', 'last_name', 'phone', 'country' ) as $field ) {
			$value = trim( (string) ( $guest[ $field ] ?? '' ) );
			if ( '' !== $value ) {
				$data[ $field ] = $value;
			}
		}
		if ( null === $guestId ) {
			$errors        = array();
			$data['email'] = strtolower( trim( (string) ( $guest['email'] ?? '' ) ) );
			if ( '' === $data['email'] || ! is_email( $data['email'] ) ) {
				$errors['guest.email'] = __( 'Enter a valid email address.', 'marclie-booking' );
			}
			if ( '' === ( $data['first_name'] ?? '' ) ) {
				$errors['guest.first_name'] = __( 'Enter the first name.', 'marclie-booking' );
			}
			if ( '' === ( $data['last_name'] ?? '' ) ) {
				$errors['guest.last_name'] = __( 'Enter the last name.', 'marclie-booking' );
			}
			if ( $errors ) {
				throw ValidationException::fields( $errors );
			}
			$existing = $this->guests->findByEmail( $data['email'] );
			$guestId  = $existing ? (int) $existing['id'] : null;
		}

		$quote = $this->pricing->quote( $stay, $adults, $children, $couponCode, $guestId, $infants );
		$notes = null !== $internalNotes && '' !== trim( $internalNotes ) ? trim( $internalNotes ) : null;

		$booking = $this->insertNew( $stay, $quote, $data, $lang ?? 'hr', 'admin', $actorUserId, null, array( 'internal_notes' => $notes ) )['booking'];

		if ( 'outside' === $mode ) {
			$kind    = $amount >= $quote->total->cents() ? 'full' : 'deposit';
			$booking = $this->recordPayment( (int) $booking['id'], $kind, $amount, $method, null, $actorUserId );
		}

		return $booking;
	}

	/**
	 * Admin edit of contact data, guest counts (capacity checked), arrival time
	 * and internal notes. The booked price is kept (to re-price, move the booking),
	 * so the quote snapshot only gets the new counts.
	 *
	 * @param array<string, mixed> $patch Subset of PATCHABLE (already sanitized strings/ints).
	 * @return array<string, mixed> The updated booking.
	 * @throws NotFoundException|ValidationException|CapacityException
	 */
	public function update( int $bookingId, array $patch, int $actorUserId ): array {
		$booking = $this->mustFind( $bookingId );
		$row     = array();
		$errors  = array();

		foreach ( array( 'first_name', 'last_name', 'phone', 'arrival_time' ) as $field ) {
			if ( array_key_exists( $field, $patch ) ) {
				$row[ $field ] = trim( (string) $patch[ $field ] );
			}
		}
		if ( array_key_exists( 'email', $patch ) ) {
			$email = strtolower( trim( (string) $patch['email'] ) );
			if ( '' === $email || ! is_email( $email ) ) {
				$errors['email'] = __( 'Enter a valid email address.', 'marclie-booking' );
			}
			$row['email'] = $email;
		}
		if ( array_key_exists( 'country', $patch ) ) {
			$country = strtoupper( trim( (string) $patch['country'] ) );
			if ( '' !== $country && ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
				$errors['country'] = __( 'Use a two-letter country code.', 'marclie-booking' );
			}
			$row['country'] = $country;
		}
		if ( array_key_exists( 'lang', $patch ) ) {
			if ( ! in_array( $patch['lang'], array( 'hr', 'en' ), true ) ) {
				$errors['lang'] = __( 'Choose Croatian or English.', 'marclie-booking' );
			}
			$row['lang'] = (string) $patch['lang'];
		}
		if ( array_key_exists( 'internal_notes', $patch ) ) {
			$notes                 = trim( (string) $patch['internal_notes'] );
			$row['internal_notes'] = '' !== $notes ? $notes : null;
		}
		if ( $errors ) {
			throw ValidationException::fields( $errors );
		}

		if ( array_key_exists( 'adults', $patch ) || array_key_exists( 'children', $patch ) || array_key_exists( 'infants', $patch ) ) {
			$adults   = (int) ( $patch['adults'] ?? $booking['adults'] );
			$children = (int) ( $patch['children'] ?? $booking['children'] );
			$infants  = (int) ( $patch['infants'] ?? $booking['infants'] );
			$unit     = $this->units->get( (int) $booking['unit_id'] );
			if ( $adults < 1 || $children < 0 || $infants < 0 || ( null !== $unit && ( $adults + $children > $unit->capacity || $children > $unit->maxChildren ) ) ) {
				throw new CapacityException(
					'Guest count exceeds unit capacity.',
					array(
						'max'          => $unit?->capacity,
						'max_children' => $unit?->maxChildren,
					)
				);
			}
			$row['adults']   = $adults;
			$row['children'] = $children;
			$row['infants']  = $infants;

			$snapshot = json_decode( (string) $booking['quote_json'], true );
			if ( is_array( $snapshot ) ) {
				$snapshot['adults']   = $adults;
				$snapshot['children'] = $children;
				$snapshot['infants']  = $infants;
				$row['quote_json']    = wp_json_encode( $snapshot );
			}
		}

		$changed = array();
		foreach ( $row as $field => $value ) {
			if ( 'quote_json' !== $field && (string) $booking[ $field ] !== (string) $value ) {
				$changed[] = $field;
			}
		}
		if ( ! $changed ) {
			return $booking;
		}

		Db::transaction(
			function () use ( $booking, $row, $changed, $actorUserId ): void {
				Db::check( $this->bookings->update( (int) $booking['id'], $row ) ?: false, 'Updating booking' );
				Db::check( $this->events->log( 'booking_updated', 'user:' . $actorUserId, (int) $booking['id'], $booking['guest_id'], array( 'fields' => $changed ) ) ?: false, 'Logging booking update' );
			}
		);

		return $this->mustFind( $bookingId );
	}

	/**
	 * Moves an active booking to another unit and/or dates. The old nights are
	 * released and the new ones held in one transaction, so a failed hold keeps
	 * the booking where it was. `$dryRun` only prices and checks availability.
	 * With `$keepPrice` the amounts stay as booked; otherwise the booking takes
	 * the new quote (the coupon is dropped when it no longer applies). The
	 * reference stays unchanged (guest links keep working).
	 *
	 * @return array{available: bool, quote_old: Quote, quote_new: Quote, diff_cents: int, coupon_dropped: bool}|array<string, mixed>
	 *         Dry run: the comparison; otherwise the updated booking row.
	 * @throws NotFoundException|InvalidTransitionException|UnitNotFoundException|UnavailableException
	 */
	public function move( int $bookingId, Stay $stay, bool $dryRun, bool $keepPrice, int $actorUserId ): array {
		$booking = $this->mustFind( $bookingId );
		$status  = BookingStatus::tryFrom( (string) $booking['status'] );
		if ( null === $status || ! in_array( $status, self::MOVABLE, true ) ) {
			throw new InvalidTransitionException( 'Only active bookings can be moved.', array( 'from' => $booking['status'] ) );
		}

		$unit = $this->units->get( $stay->unitId );
		if ( null === $unit ) {
			throw new UnitNotFoundException( 'Unit not found.', array( 'unit_id' => $stay->unitId ) );
		}
		$stay = new Stay( $unit->id, $stay->arrival, $stay->departure );

		$tz       = $this->clock->today()->getTimezone();
		$snapshot = json_decode( (string) $booking['quote_json'], true );
		$quoteOld = Quote::fromArray( is_array( $snapshot ) ? $snapshot : self::snapshotFromRow( $booking ), $tz );

		$couponDropped = false;
		$coupon        = '' !== (string) $booking['coupon_code'] ? (string) $booking['coupon_code'] : null;
		try {
			$quoteNew = $this->pricing->quote( $stay, (int) $booking['adults'], (int) $booking['children'], $coupon, $booking['guest_id'], (int) $booking['infants'] );
		} catch ( CouponException $e ) {
			$quoteNew      = $this->pricing->quote( $stay, (int) $booking['adults'], (int) $booking['children'], null, $booking['guest_id'], (int) $booking['infants'] );
			$couponDropped = true;
		}

		$available = $this->availability->isAvailableExcept( $stay, $bookingId );

		if ( $dryRun ) {
			return array(
				'available'      => $available,
				'quote_old'      => $quoteOld,
				'quote_new'      => $quoteNew,
				'diff_cents'     => $quoteNew->total->cents() - (int) $booking['total_cents'],
				'coupon_dropped' => $couponDropped,
			);
		}

		if ( ! $available ) {
			throw AvailabilityService::unavailable( $stay );
		}

		$snapshotNew = $quoteNew->toArray();
		$row         = array(
			'unit_id'           => $stay->unitId,
			'arrival'           => $stay->arrivalString(),
			'departure'         => $stay->departureString(),
			'nights'            => $stay->nights(),
			'balance_due_on'    => $quoteNew->balanceDueOn?->format( 'Y-m-d' ),
			'free_cancel_until' => $quoteNew->freeCancelUntil?->format( 'Y-m-d' ),
		);
		if ( $keepPrice ) {
			foreach ( array( 'lines', 'subtotal_cents', 'discount_cents', 'total_cents', 'deposit_cents', 'balance_cents', 'pay_in_full', 'coupon_code', 'coupon_id' ) as $key ) {
				$snapshotNew[ $key ] = $quoteOld->toArray()[ $key ];
			}
			$snapshotNew['price_kept'] = true;
			// The kept balance falls due policy.balanceDays before the new arrival (possibly today).
			$row['balance_due_on']        = $quoteOld->balance->cents() > 0 ? $this->balanceDueOn( $stay ) : null;
			$snapshotNew['balance_due_on'] = $row['balance_due_on'];
		} else {
			$row += array(
				'subtotal_cents' => $quoteNew->subtotal->cents(),
				'discount_cents' => $quoteNew->discount->cents(),
				'total_cents'    => $quoteNew->total->cents(),
				'deposit_cents'  => $quoteNew->deposit->cents(),
				'balance_cents'  => $quoteNew->balance->cents(),
				'coupon_code'    => $quoteNew->couponCode,
			);
		}
		$row['quote_json'] = wp_json_encode( $snapshotNew );

		$payload = array(
			'from'           => array(
				'unit_id'     => (int) $booking['unit_id'],
				'arrival'     => (string) $booking['arrival'],
				'departure'   => (string) $booking['departure'],
				'total_cents' => (int) $booking['total_cents'],
			),
			'to'             => array(
				'unit_id'     => $stay->unitId,
				'arrival'     => $stay->arrivalString(),
				'departure'   => $stay->departureString(),
				'total_cents' => $keepPrice ? (int) $booking['total_cents'] : $quoteNew->total->cents(),
			),
			'keep_price'     => $keepPrice,
			'coupon_dropped' => $couponDropped && ! $keepPrice,
		);

		$attempt = function () use ( $bookingId, $stay, $row, $booking, $payload, $actorUserId, $keepPrice ): void {
			Db::transaction(
				function () use ( $bookingId, $stay, $row, $booking, $payload, $actorUserId, $keepPrice ): void {
					// Re-check under the row lock: the booking may have been cancelled/expired meanwhile.
					$current = $this->lockActive( $bookingId, self::MOVABLE );

					if ( ! $keepPrice ) {
						// A balance left after the new price falls due before arrival (possibly today),
						// also when the new dates are inside balance_days (pay-in-full quote).
						$net                   = $this->payments->netPaidCents( $bookingId );
						$row['balance_due_on'] = $net < (int) $row['total_cents'] ? $this->balanceDueOn( $stay ) : null;
					}

					$this->availability->release( $bookingId );
					$this->availability->hold( $bookingId, $stay );
					Db::check( $this->bookings->update( $bookingId, $row ) ?: false, 'Moving booking' );
					Db::check( $this->events->log( 'booking_moved', 'user:' . $actorUserId, $bookingId, $booking['guest_id'], $payload ) ?: false, 'Logging booking move' );

					if ( ! $keepPrice ) {
						$status = BookingStatus::from( (string) $current['status'] );
						$target = $status->afterPayment( $net, (int) $row['total_cents'], (int) $row['deposit_cents'] );
						if ( null !== $target ) {
							$this->transition( $bookingId, $target, 'user:' . $actorUserId, array( 'moved' => true ) );
						}
					}
				}
			);
		};

		try {
			Db::retryOnLock( $attempt );
		} catch ( LockConflictException $e ) {
			throw AvailabilityService::unavailable( $stay, $e );
		}

		if ( ! empty( $booking['guest_id'] ) ) {
			$this->guests->recomputeStats( (int) $booking['guest_id'] );
		}

		return $this->mustFind( $bookingId );
	}

	/**
	 * Records money received outside Stripe (cash, bank transfer, other) and
	 * moves the booking to confirmed/paid when the deposit/total is covered.
	 * Payments of a booking are serialized by a row lock. A repeated
	 * `$idempotencyKey` is a no-op; without a key an identical payment within
	 * DUPLICATE_WINDOW_SECONDS is rejected as a double submit.
	 *
	 * @return array<string, mixed> The updated booking.
	 * @throws NotFoundException|ValidationException|InvalidTransitionException|DuplicatePaymentException
	 */
	public function recordPayment( int $bookingId, string $kind, int $amountCents, string $method, ?string $note, int $actorUserId, ?string $idempotencyKey = null ): array {
		$booking = $this->mustFind( $bookingId );
		$status  = BookingStatus::tryFrom( (string) $booking['status'] );
		if ( null === $status || ! $status->occupiesNights() ) {
			throw new InvalidTransitionException( 'Payments cannot be recorded on a cancelled or expired booking.', array( 'from' => $booking['status'] ) );
		}

		$errors = array();
		if ( ! in_array( $kind, PaymentRepository::CHARGE_KINDS, true ) ) {
			$errors['kind'] = __( 'Choose deposit, balance or full payment.', 'marclie-booking' );
		}
		if ( $amountCents <= 0 ) {
			$errors['amount_cents'] = __( 'Enter an amount greater than zero.', 'marclie-booking' );
		}
		if ( ! in_array( $method, PaymentRepository::OUTSIDE_METHODS, true ) ) {
			$errors['method'] = __( 'Choose a payment method.', 'marclie-booking' );
		}
		$idempotencyKey = null !== $idempotencyKey && '' !== trim( $idempotencyKey ) ? trim( $idempotencyKey ) : null;
		if ( null !== $idempotencyKey && strlen( $idempotencyKey ) > 64 ) {
			$errors['idempotency_key'] = __( 'The idempotency key is too long.', 'marclie-booking' );
		}
		if ( $errors ) {
			throw ValidationException::fields( $errors );
		}

		$note  = null !== $note && '' !== trim( $note ) ? trim( $note ) : null;
		$actor = 'user:' . $actorUserId;

		Db::transaction(
			function () use ( $bookingId, $kind, $amountCents, $method, $note, $actor, $idempotencyKey ): void {
				// Serialize payments of this booking; re-check the status under the lock.
				$booking = $this->lockActive( $bookingId, self::PAYABLE );
				$status  = BookingStatus::from( (string) $booking['status'] );
				$now     = $this->clock->now();

				if ( null !== $idempotencyKey ) {
					$previous = $this->payments->findByIdempotencyKey( $idempotencyKey );
					if ( null !== $previous ) {
						if ( (int) $previous['booking_id'] !== $bookingId ) {
							throw ValidationException::fields( array( 'idempotency_key' => __( 'This idempotency key was used for another booking.', 'marclie-booking' ) ) );
						}

						return; // Replay of a recorded request.
					}
				} elseif ( $this->payments->hasRecentDuplicate( $bookingId, $kind, $amountCents, $method, Db::utc( $now->modify( sprintf( '-%d seconds', self::DUPLICATE_WINDOW_SECONDS ) ) ) ) ) {
					throw new DuplicatePaymentException(
						'An identical payment was recorded a moment ago.',
						array( 'window_seconds' => self::DUPLICATE_WINDOW_SECONDS )
					);
				}

				$paymentId = $this->payments->insert(
					array(
						'booking_id'      => $bookingId,
						'kind'            => $kind,
						'method'          => $method,
						'amount_cents'    => $amountCents,
						'status'          => 'succeeded',
						'note'            => $note,
						'idempotency_key' => $idempotencyKey,
						'created_at'      => Db::utc( $now ),
					)
				);
				Db::check( $paymentId ?: false, 'Recording payment' );
				Db::check(
					$this->events->log(
						'payment_recorded',
						$actor,
						$bookingId,
						$booking['guest_id'],
						array(
							'payment_id'   => $paymentId,
							'kind'         => $kind,
							'method'       => $method,
							'amount_cents' => $amountCents,
						)
					) ?: false,
					'Logging payment'
				);

				$target = $status->afterPayment( $this->payments->netPaidCents( $bookingId ), (int) $booking['total_cents'], (int) $booking['deposit_cents'] );
				if ( null !== $target ) {
					$this->transition( $bookingId, $target, $actor, array( 'payment_id' => $paymentId ) );
				}
			}
		);

		return $this->mustFind( $bookingId );
	}

	/**
	 * Locks the booking row (current transaction) and asserts it is in `$allowed`.
	 *
	 * @param list<BookingStatus> $allowed
	 * @return array<string, mixed> The fresh row.
	 * @throws NotFoundException|InvalidTransitionException
	 */
	private function lockActive( int $bookingId, array $allowed ): array {
		$booking = $this->bookings->lockForUpdate( $bookingId );
		if ( null === $booking ) {
			throw new NotFoundException( 'Booking not found.', array( 'booking_id' => $bookingId ) );
		}
		$status = BookingStatus::tryFrom( (string) $booking['status'] );
		if ( null === $status || ! in_array( $status, $allowed, true ) ) {
			throw new InvalidTransitionException( 'The booking status changed meanwhile.', array( 'from' => $booking['status'] ) );
		}

		return $booking;
	}

	/** Balance due date for a stay: policy.balanceDays before arrival, not before today. */
	private function balanceDueOn( Stay $stay ): string {
		return max( $stay->arrival->modify( sprintf( '-%d days', $this->policy->balanceDays ) ), $this->clock->today() )->format( 'Y-m-d' );
	}

	/**
	 * Booking for a guest link (`?ref=&t=`), or null when the token does not
	 * match a live token of one of `$purposes` (hashes compared with hash_equals).
	 *
	 * @param list<string> $purposes TokenRepository::PURPOSES subset.
	 * @return array<string, mixed>|null
	 */
	public function verifyToken( string $ref, string $token, array $purposes = TokenRepository::PURPOSES ): ?array {
		if ( '' === $ref || '' === $token ) {
			return null;
		}

		$booking = $this->bookings->findByRef( $ref );
		if ( null === $booking ) {
			return null;
		}

		$hash  = hash( 'sha256', $token );
		$now   = Db::utc( $this->clock->now() );
		$match = false;
		foreach ( $this->tokens->forBooking( (int) $booking['id'] ) as $row ) {
			$live  = null === $row['expires_at'] || (string) $row['expires_at'] > $now;
			$match = ( hash_equals( (string) $row['token_hash'], $hash ) && $live && in_array( $row['purpose'], $purposes, true ) ) || $match;
		}

		return $match ? $booking : null;
	}

	/**
	 * New guest link token (`pay`, `manage`…) for a booking; the plain token is only returned here.
	 */
	public function issueToken( int $bookingId, string $purpose, ?\DateTimeImmutable $expiresAt ): string {
		if ( ! in_array( $purpose, TokenRepository::PURPOSES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown token purpose.' );
		}

		$token = bin2hex( random_bytes( 32 ) );
		Db::check( $this->tokens->insert( $bookingId, $purpose, hash( 'sha256', $token ), null === $expiresAt ? null : Db::utc( $expiresAt ) ) ?: false, 'Storing token' );

		return $token;
	}

	/**
	 * A payment arrived for an expired booking: re-holds its nights and moves it
	 * to `$to` (confirmed/paid). False (nothing changed) when a night was taken
	 * meanwhile. Runs in the caller's transaction when one is open.
	 *
	 * @param array<string, mixed> $context
	 */
	public function reviveExpired( int $bookingId, BookingStatus $to, string $actor, array $context = array() ): bool {
		return Db::transaction(
			function () use ( $bookingId, $to, $actor, $context ): bool {
				$booking = $this->lockActive( $bookingId, array( BookingStatus::Expired ) );
				$tz      = $this->clock->today()->getTimezone();
				$stay    = new Stay( (int) $booking['unit_id'], new \DateTimeImmutable( (string) $booking['arrival'], $tz ), new \DateTimeImmutable( (string) $booking['departure'], $tz ) );
				if ( ! $this->availability->isAvailable( $stay ) ) {
					return false;
				}
				try {
					$this->availability->hold( $bookingId, $stay );
				} catch ( UnavailableException $e ) {
					// A lock conflict is rethrown: the caller's transaction was rolled back (webhook → 500, Stripe retries).
					return false;
				}

				$this->transition( $bookingId, $to, $actor, $context + array( 'late_payment' => true ), true );

				return true;
			}
		);
	}

	/**
	 * Marks the GA purchase of a booking as sent. True only for the first caller.
	 */
	public function markGaSent( int $bookingId ): bool {
		return $this->bookings->markGaSent( $bookingId, Db::utc( $this->clock->now() ) );
	}

	/** @return array<string, mixed>|null */
	public function find( int $id ): ?array {
		return $this->bookings->find( $id );
	}

	/** @return array<string, mixed>|null */
	public function findByRef( string $ref ): ?array {
		return $this->bookings->findByRef( $ref );
	}

	/**
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 */
	public function mustFind( int $id ): array {
		$booking = $this->bookings->find( $id );
		if ( null === $booking ) {
			throw new NotFoundException( 'Booking not found.', array( 'booking_id' => $id ) );
		}

		return $booking;
	}

	/**
	 * Quote snapshot rebuilt from booking columns (bookings without quote_json).
	 *
	 * @param array<string, mixed> $booking
	 * @return array<string, mixed>
	 */
	private static function snapshotFromRow( array $booking ): array {
		return array(
			'unit_id'           => (int) $booking['unit_id'],
			'arrival'           => (string) $booking['arrival'],
			'departure'         => (string) $booking['departure'],
			'adults'            => (int) $booking['adults'],
			'children'          => (int) $booking['children'],
			'infants'           => (int) $booking['infants'],
			'lines'             => array(),
			'subtotal_cents'    => (int) $booking['subtotal_cents'],
			'discount_cents'    => (int) $booking['discount_cents'],
			'total_cents'       => (int) $booking['total_cents'],
			'deposit_cents'     => (int) $booking['deposit_cents'],
			'balance_cents'     => (int) $booking['balance_cents'],
			'balance_due_on'    => $booking['balance_due_on'],
			'free_cancel_until' => $booking['free_cancel_until'],
			'pay_in_full'       => 0 === (int) $booking['balance_cents'],
			'coupon_code'       => $booking['coupon_code'],
		);
	}

	/**
	 * usage_limit also counts live holds (pending_payment, hold not expired) so
	 * concurrent checkouts cannot overshoot the limit.
	 *
	 * @throws CouponException reason `limit`.
	 */
	private function assertUsageLimitWithHolds( Coupon $coupon, string $nowUtc ): void {
		if ( null === $coupon->usageLimit ) {
			return;
		}

		if ( $coupon->usedCount + $this->bookings->countLiveHoldsWithCoupon( $coupon->code, $nowUtc ) >= $coupon->usageLimit ) {
			throw new CouponException(
				'coupon_limit',
				array(
					'reason' => 'limit',
					'code'   => $coupon->code,
				)
			);
		}
	}

	/**
	 * Inserts the row with the next free reference. A reference taken by a
	 * concurrent (possibly uncommitted) booking is skipped locally.
	 *
	 * @param array<string, mixed> $row
	 */
	private function insertWithReference( array $row, Stay $stay, string $unitCode ): int {
		$tried = array();
		for ( $attempt = 0; $attempt < self::REF_ATTEMPTS; $attempt++ ) {
			$row['ref'] = $this->references->generate( $stay, $unitCode, $tried );
			$id         = $this->bookings->insert( $row );
			if ( $id > 0 ) {
				return $id;
			}

			$kind = Db::errorKind();
			if ( Db::ERROR_LOCK === $kind ) {
				throw new LockConflictException( Db::message( 'Creating booking hit a lock conflict' ) );
			}
			if ( Db::ERROR_DUPLICATE !== $kind ) {
				throw new \RuntimeException( Db::message( 'Creating booking failed' ) );
			}
			$tried[] = $row['ref'];
		}

		throw new \RuntimeException( 'Could not allocate a booking reference.' );
	}

	/**
	 * Records the coupon redemption once per booking and bumps used_count.
	 *
	 * @param array<string, mixed> $booking
	 * @return array{coupon_id:int, discount_cents:int}|null
	 */
	private function redeemCoupon( array $booking ): ?array {
		if ( empty( $booking['coupon_code'] ) || $this->redemptions->existsForBooking( $booking['id'] ) ) {
			return null;
		}

		$quote    = json_decode( (string) $booking['quote_json'], true );
		$couponId = is_array( $quote ) && ! empty( $quote['coupon_id'] ) ? (int) $quote['coupon_id'] : 0;
		if ( 0 === $couponId ) {
			$row      = $this->coupons->findRowByCode( (string) $booking['coupon_code'] );
			$couponId = $row ? (int) $row['id'] : 0;
		}
		if ( 0 === $couponId ) {
			return null;
		}

		Db::check( $this->redemptions->insert( $couponId, $booking['id'], $booking['guest_id'], $booking['discount_cents'] ) ?: false, 'Recording coupon redemption' );
		Db::check( $this->coupons->incrementUsed( $couponId ), 'Incrementing coupon usage' );

		return array(
			'coupon_id'      => $couponId,
			'discount_cents' => $booking['discount_cents'],
		);
	}

	private function actor( ?int $userId, string $source ): string {
		return null !== $userId ? 'user:' . $userId : ( 'direct' === $source ? 'guest' : 'system:' . $source );
	}
}
