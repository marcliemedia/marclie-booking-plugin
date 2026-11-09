<?php
/**
 * „Zatraži otkazivanje”: a guest asks, from /moj-racun/, to cancel one of
 * their bookings before arrival. Nothing is cancelled automatically (refunds
 * follow the cancellation policy and stay an admin decision): the request is
 * logged once per booking (`cancel_requested`, shown as a dashboard alert)
 * and the admin recipients get an admin_alert email.
 */

namespace Marclie\Booking\Account;

use Marclie\Booking\Domain\Exception\NotFoundException;
use Marclie\Booking\Domain\Exception\ValidationException;
use Marclie\Booking\Domain\Clock;
use Marclie\Booking\Email\Mailer;
use Marclie\Booking\I18n\LanguageAdapter;
use Marclie\Booking\Repository\EventRepository;

defined( 'ABSPATH' ) || exit;

final class CancelRequest {

	public const EVENT = 'cancel_requested';

	public const REASON_MAX = 1000;

	private const CANCELLABLE = array( 'confirmed', 'paid', 'balance_failed' );

	public function __construct(
		private AccountService $accounts,
		private EventRepository $events,
		private Mailer $mailer,
		private Clock $clock
	) {}

	/**
	 * @return array{requested_at: string} ISO 8601.
	 * @throws NotFoundException|ValidationException|\Marclie\Booking\Domain\Exception\InvalidTransitionException
	 */
	public function request( \WP_User $user, string $ref, string $reason ): array {
		$booking = $this->accounts->ownBooking( $user, $ref );
		if ( null === $booking ) {
			throw new NotFoundException( 'Booking not found.', array( 'ref' => $ref ) );
		}
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( mb_strlen( $reason ) > self::REASON_MAX ) {
			/* translators: %d: maximum number of characters */
			throw ValidationException::fields( array( 'reason' => sprintf( __( 'The message can be at most %d characters long.', 'marclie-booking' ), self::REASON_MAX ) ) );
		}
		$today = $this->clock->today()->format( 'Y-m-d' );
		if ( ! in_array( $booking['status'], self::CANCELLABLE, true ) || (string) $booking['arrival'] <= $today ) {
			throw ValidationException::fields( array( 'ref' => __( 'This booking can no longer be cancelled online. Please contact us.', 'marclie-booking' ) ) );
		}

		$id    = (int) $booking['id'];
		$claim = $this->events->claim( 'cancel_request:' . $id, self::EVENT, AccountService::actor( $user->ID ), $id, empty( $booking['guest_id'] ) ? null : (int) $booking['guest_id'], array( 'reason' => $reason ) );
		if ( 0 === $claim ) {
			throw ValidationException::fields( array( 'ref' => __( 'You have already asked us to cancel this booking. We will get back to you soon.', 'marclie-booking' ) ) );
		}

		$guestName = trim( $booking['first_name'] . ' ' . $booking['last_name'] );
		$extra     = LanguageAdapter::withLanguage(
			Mailer::adminLang(),
			static fn(): array => array(
				'alert_title'   => __( 'Cancellation request', 'marclie-booking' ),
				'alert_message' => '' !== $reason
					/* translators: 1: guest name, 2: booking reference, 3: the guest's message */
					? sprintf( __( '%1$s asks to cancel booking %2$s from their account: “%3$s”. Cancel it in the booking admin (the refund follows the cancellation policy) or contact the guest.', 'marclie-booking' ), $guestName, $booking['ref'], $reason )
					/* translators: 1: guest name, 2: booking reference */
					: sprintf( __( '%1$s asks to cancel booking %2$s from their account. Cancel it in the booking admin (the refund follows the cancellation policy) or contact the guest.', 'marclie-booking' ), $guestName, $booking['ref'] ),
			)
		);
		try {
			$this->mailer->sendForBooking(
				'admin_alert',
				$booking,
				array(
					'actor' => AccountService::actor( $user->ID ),
					'extra' => $extra,
				)
			);
		} catch ( \Throwable $e ) {
			error_log( '[marclie-booking] cancel request email ' . $booking['ref'] . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return array( 'requested_at' => $this->clock->now()->format( DATE_ATOM ) );
	}
}
