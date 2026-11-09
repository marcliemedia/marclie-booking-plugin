<?php
/**
 * Card change from /moj-racun/ (Plaćanja): a Stripe SetupIntent on the
 * guest's Customer (created when missing), confirmed in the browser; the
 * confirmed card becomes the payment method of every running booking of the
 * user that still has a balance to charge (BalanceCharger uses
 * `stripe_customer_id` + `stripe_payment_method_id`). Without Stripe keys
 * both steps answer 503 payments_unavailable.
 */

namespace Marclie\Booking\Account;

use Marclie\Booking\Domain\Exception\PaymentException;
use Marclie\Booking\Domain\Exception\ValidationException;
use Marclie\Booking\Payment\GatewayException;
use Marclie\Booking\Payment\PaymentGateway;
use Marclie\Booking\Payment\StripeConfig;
use Marclie\Booking\Repository\BookingRepository;
use Marclie\Booking\Repository\EventRepository;
use Marclie\Booking\Repository\GuestRepository;
use Marclie\Booking\Repository\PaymentRepository;

defined( 'ABSPATH' ) || exit;

final class CardUpdate {

	/** Bookings whose future balance is charged to the saved card. */
	private const CHARGEABLE = array( 'confirmed', 'balance_failed' );

	public function __construct(
		private ?PaymentGateway $gateway,
		private AccountService $accounts,
		private GuestRepository $guests,
		private BookingRepository $bookings,
		private PaymentRepository $payments,
		private EventRepository $events
	) {}

	/**
	 * @return array{client_secret: string, setup_intent: string}
	 * @throws PaymentException payments_unavailable
	 */
	public function setupIntent( \WP_User $user ): array {
		$gateway = $this->gateway();
		$guestId = $this->guestId( $user );
		try {
			$customer = $this->customer( $user, $guestId, $gateway );
			$intent   = $gateway->createSetupIntent(
				array(
					'customer'             => $customer,
					'usage'                => 'off_session',
					'allowed_payment_method_types' => array( 'card' ),
					'metadata'             => array(
						'user_id'  => (string) $user->ID,
						'guest_id' => (string) $guestId,
						'site'     => StripeConfig::siteId(),
					),
				),
				'mbk-setup-' . $user->ID . '-' . bin2hex( random_bytes( 8 ) )
			);
		} catch ( GatewayException $e ) {
			error_log( '[marclie-booking] card setup user ' . $user->ID . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			throw PaymentException::unavailable( $e );
		}

		return array(
			'client_secret' => (string) $intent->clientSecret,
			'setup_intent'  => $intent->id,
		);
	}

	/**
	 * Saves the card of a succeeded SetupIntent on the user's running bookings.
	 *
	 * @return array{card: array{brand: string, last4: string, exp_month: ?int, exp_year: ?int}, bookings: list<string>}
	 * @throws PaymentException|ValidationException
	 */
	public function confirm( \WP_User $user, string $setupIntentId ): array {
		$gateway = $this->gateway();
		$guest   = $this->accounts->guestFor( $user );
		try {
			$intent = $gateway->retrieveSetupIntent( $setupIntentId );
		} catch ( GatewayException $e ) {
			throw ValidationException::fields( array( 'setup_intent' => __( 'The card could not be saved. Please try again.', 'marclie-booking' ) ) );
		}

		$customer = (string) ( $guest['stripe_customer_id'] ?? '' );
		if ( 'succeeded' !== $intent->status || null === $intent->paymentMethod || (string) ( $intent->metadata['user_id'] ?? '' ) !== (string) $user->ID || '' === $customer || $intent->customer !== $customer ) {
			throw ValidationException::fields( array( 'setup_intent' => __( 'The card could not be saved. Please try again.', 'marclie-booking' ) ) );
		}

		$refs = array();
		foreach ( $this->bookings->forUser( $user->ID ) as $booking ) {
			$adminHold = 'pending_payment' === $booking['status'] && null === $booking['hold_expires_at'];
			if ( ! in_array( $booking['status'], self::CHARGEABLE, true ) && ! $adminHold ) {
				continue;
			}
			if ( (int) $booking['total_cents'] <= $this->payments->netPaidCents( (int) $booking['id'] ) ) {
				continue;
			}
			$this->bookings->update(
				(int) $booking['id'],
				array(
					'stripe_customer_id'       => $customer,
					'stripe_payment_method_id' => $intent->paymentMethod,
				)
			);
			$this->events->log(
				'card_updated',
				AccountService::actor( $user->ID ),
				(int) $booking['id'],
				(int) $guest['id'],
				array(
					'card_brand' => (string) $intent->cardBrand,
					'card_last4' => (string) $intent->cardLast4,
				)
			);
			$refs[] = (string) $booking['ref'];
		}

		$card = array(
			'brand'     => (string) $intent->cardBrand,
			'last4'     => (string) $intent->cardLast4,
			'exp_month' => $intent->cardExpMonth,
			'exp_year'  => $intent->cardExpYear,
		);
		update_user_meta( $user->ID, AccountService::META_CARD, $card + array( 'payment_method' => $intent->paymentMethod ) );

		return array(
			'card'     => $card,
			'bookings' => $refs,
		);
	}

	private function gateway(): PaymentGateway {
		if ( null === $this->gateway ) {
			throw PaymentException::unavailable();
		}

		return $this->gateway;
	}

	private function guestId( \WP_User $user ): int {
		$guest = $this->accounts->guestFor( $user );

		return null !== $guest ? (int) $guest['id'] : $this->accounts->link( $user, AccountService::userLang( $user->ID ) );
	}

	/** Stripe Customer of the guest, created (and stored) when missing. */
	private function customer( \WP_User $user, int $guestId, PaymentGateway $gateway ): string {
		$guest = $this->guests->find( $guestId );
		$id    = (string) ( $guest['stripe_customer_id'] ?? '' );
		if ( '' !== $id ) {
			return $id;
		}

		$id = $gateway->createCustomer(
			array(
				'email'    => (string) $user->user_email,
				'name'     => trim( $user->first_name . ' ' . $user->last_name ),
				'metadata' => array(
					'guest_id' => (string) $guestId,
					'user_id'  => (string) $user->ID,
				),
			),
			'mbk-customer-guest-' . $guestId . '-' . $gateway->mode()
		);
		$this->guests->update( $guestId, array( 'stripe_customer_id' => $id ) );

		return $id;
	}
}
