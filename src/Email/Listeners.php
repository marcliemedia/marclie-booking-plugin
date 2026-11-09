<?php
/**
 * Lifecycle hooks → emails. Sending is deferred until the surrounding DB
 * transaction committed (Db::afterCommit) and re-reads the booking, so a
 * rolled-back change never emails and amounts include rows written in the
 * same transaction (refunds of a cancellation). A mail failure never breaks
 * the booking flow: it is logged (`email_failed` / error_log).
 *
 * | Hook                                   | Emails                                            |
 * |----------------------------------------|---------------------------------------------------|
 * | status pending_payment/expired → confirmed/paid | guest_confirmed, admin_new_booking (not for admin-made bookings) |
 * | status confirmed/balance_failed → paid | guest_balance_paid                                |
 * | status confirmed/paid/balance_failed → cancelled | guest_cancelled                         |
 * | mbk_payment_failed (off-session balance) | guest_balance_failed (once per booking), admin_alert |
 * | mbk_hold_lost                          | guest_hold_lost, admin_alert                      |
 * | mbk_dispute_created                    | admin_alert                                       |
 * | mbk_payment_succeeded with an `overpayment` event | admin_alert                            |
 *
 * guest_payment_link is sent by POST /admin/bookings/{id}/payment-link
 * (`send_email`); reminders and pre-arrival emails by Scheduler (mbk_daily).
 */

namespace Marclie\Booking\Email;

use Marclie\Booking\Container;
use Marclie\Booking\Domain\BookingStatus;
use Marclie\Booking\I18n\Formatter;
use Marclie\Booking\I18n\LanguageAdapter;
use Marclie\Booking\Repository\Db;

defined( 'ABSPATH' ) || exit;

final class Listeners {

	public static function hooks(): void {
		add_action( 'mbk_booking_status_changed', array( self::class, 'onStatusChanged' ), 20, 3 );
		add_action( 'mbk_payment_failed', array( self::class, 'onPaymentFailed' ), 10, 3 );
		add_action( 'mbk_payment_succeeded', array( self::class, 'onPaymentSucceeded' ), 10, 2 );
		add_action( 'mbk_hold_lost', array( self::class, 'onHoldLost' ) );
		add_action( 'mbk_dispute_created', array( self::class, 'onDispute' ), 10, 2 );
	}

	/**
	 * @param array<string, mixed> $booking
	 * @param BookingStatus|string $from
	 * @param BookingStatus|string $to
	 */
	public static function onStatusChanged( array $booking, $from, $to ): void {
		$from  = $from instanceof BookingStatus ? $from : BookingStatus::tryFrom( (string) $from );
		$to    = $to instanceof BookingStatus ? $to : BookingStatus::tryFrom( (string) $to );
		$codes = array();

		if ( in_array( $to, array( BookingStatus::Confirmed, BookingStatus::Paid ), true ) && in_array( $from, array( BookingStatus::PendingPayment, BookingStatus::Expired ), true ) ) {
			$codes[] = 'guest_confirmed';
			if ( 'admin' !== ( $booking['source'] ?? '' ) ) {
				$codes[] = 'admin_new_booking';
			}
		} elseif ( BookingStatus::Paid === $to && in_array( $from, array( BookingStatus::Confirmed, BookingStatus::BalanceFailed ), true ) ) {
			$codes[] = 'guest_balance_paid';
		} elseif ( BookingStatus::Cancelled === $to && in_array( $from, array( BookingStatus::Confirmed, BookingStatus::Paid, BookingStatus::BalanceFailed ), true ) ) {
			$codes[] = 'guest_cancelled';
		}

		foreach ( $codes as $code ) {
			self::later( $code, (int) $booking['id'] );
		}
	}

	/**
	 * @param array<string, mixed>|null $booking
	 * @param array<string, mixed>|null $payment
	 * @param array<string, mixed>      $error code, message, requires_action, off_session.
	 */
	public static function onPaymentFailed( $booking, $payment, $error = array() ): void {
		if ( ! is_array( $booking ) || ! is_array( $payment ) || 'balance' !== ( $payment['kind'] ?? '' ) || empty( $error['off_session'] ) ) {
			return; // On-session failures are shown on the checkout/payment page.
		}

		$id   = (int) $booking['id'];
		$code = (string) ( $error['code'] ?? '' );
		// The guest gets the payment link once per booking: tell the admin what happened this time.
		$link = Container::eventRepository()->hasEmailSent( $id, 'guest_balance_failed' )
			? 'earlier'
			: ( Container::mailer()->enabled( 'guest_balance_failed', $booking ) ? 'now' : 'off' );
		self::later( 'guest_balance_failed', $id, array( 'once' => true ) );
		self::later(
			'admin_alert',
			$id,
			array(
				'extra' => static fn(): array => array(
					'alert_title'   => __( 'Balance payment failed', 'marclie-booking' ),
					'alert_message' => ( ! empty( $error['requires_action'] )
						? __( 'The bank asked the guest to authenticate the card payment.', 'marclie-booking' )
						/* translators: %s: Stripe decline code */
						: sprintf( __( 'The card was declined (%s).', 'marclie-booking' ), '' !== $code ? $code : '—' ) )
						. ' ' . match ( $link ) {
							'earlier' => __( 'A payment link was emailed to the guest earlier; send a new one from the booking if needed.', 'marclie-booking' ),
							'now'     => __( 'The guest is being emailed a payment link now.', 'marclie-booking' ),
							default   => __( 'No payment link was emailed (the template is switched off): send one from the booking.', 'marclie-booking' ),
						},
				),
			)
		);
	}

	/**
	 * Overpayment (two card payments raced): the excess was refunded automatically.
	 *
	 * @param array<string, mixed>|null $booking
	 * @param array<string, mixed>|null $payment
	 */
	public static function onPaymentSucceeded( $booking, $payment ): void {
		if ( ! is_array( $booking ) || ! is_array( $payment ) ) {
			return;
		}

		foreach ( Container::eventRepository()->forBooking( (int) $booking['id'] ) as $event ) {
			if ( 'overpayment' === $event['type'] && (int) ( $event['payload']['payment_id'] ?? 0 ) === (int) $payment['id'] ) {
				self::later(
					'admin_alert',
					(int) $booking['id'],
					array(
						'extra' => static fn(): array => array(
							'alert_title'   => __( 'Paid twice by card', 'marclie-booking' ),
							/* translators: %s: refunded amount */
							'alert_message' => sprintf( __( 'Two card payments arrived for the same amount due. %s was refunded to the guest automatically.', 'marclie-booking' ), Formatter::price( (int) ( $event['payload']['amount_cents'] ?? 0 ), Mailer::adminLang() ) ),
						),
					)
				);
				return;
			}
		}
	}

	/** @param array<string, mixed>|null $booking */
	public static function onHoldLost( $booking ): void {
		if ( ! is_array( $booking ) ) {
			return;
		}

		$id = (int) $booking['id'];
		self::later( 'guest_hold_lost', $id );
		self::later(
			'admin_alert',
			$id,
			array(
				'extra' => static fn(): array => array(
					'alert_title'   => __( 'Payment after the hold expired', 'marclie-booking' ),
					'alert_message' => __( 'The payment arrived after the hold had expired and the dates were taken meanwhile. The booking was cancelled and the payment refunded in full.', 'marclie-booking' ),
				),
			)
		);
	}

	/**
	 * @param array<string, mixed>|null $booking
	 * @param array<string, mixed>      $dispute amount_cents, reason…
	 */
	public static function onDispute( $booking, $dispute = array() ): void {
		if ( ! is_array( $booking ) ) {
			return;
		}

		self::later(
			'admin_alert',
			(int) $booking['id'],
			array(
				'extra' => static fn(): array => array(
					'alert_title'   => __( 'Card payment disputed', 'marclie-booking' ),
					/* translators: 1: amount, 2: dispute reason given by the bank */
					'alert_message' => sprintf( __( 'The guest’s bank opened a dispute of %1$s (reason: %2$s). Respond in the Stripe Dashboard before the deadline.', 'marclie-booking' ), Formatter::price( (int) ( $dispute['amount_cents'] ?? 0 ), Mailer::adminLang() ), (string) ( $dispute['reason'] ?? '' ) ),
				),
			)
		);
	}

	/**
	 * Sends after the current transaction commits (now when none is open).
	 *
	 * @param array<string, mixed> $opts Mailer::sendForBooking() options.
	 */
	private static function later( string $code, int $bookingId, array $opts = array() ): void {
		Db::afterCommit(
			static function () use ( $code, $bookingId, $opts ): void {
				try {
					$booking = Container::bookingRepository()->find( $bookingId );
					if ( isset( $opts['extra'] ) && is_callable( $opts['extra'] ) ) {
						// Alert texts go to the admin recipients: translate them in the admin language.
						$opts['extra'] = LanguageAdapter::withLanguage( Mailer::adminLang(), $opts['extra'] );
					}
					if ( null !== $booking ) {
						Container::mailer()->sendForBooking( $code, $booking, $opts );
					}
				} catch ( \Throwable $e ) {
					error_log( sprintf( '[marclie-booking] email %s for booking %d: %s', $code, $bookingId, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
		);
	}
}
