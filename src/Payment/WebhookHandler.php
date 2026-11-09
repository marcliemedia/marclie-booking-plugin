<?php
/**
 * Verified Stripe events → payment services. Each event ID is processed once
 * (dedupe in mbk_events); handling is idempotent anyway, so a concurrent
 * duplicate delivery or a reconcile racing the webhook cannot double-apply.
 *
 * - payment_intent.succeeded / payment_intent.payment_failed → PaymentReconciler;
 * - refund.created / refund.updated / refund.failed → RefundService::applyRefund();
 * - charge.refunded → RefundService (lists the intent's refunds);
 * - charge.dispute.created → `dispute_created` event (dashboard alert) and the
 *   `mbk_dispute_created` action (admin email, phase 07).
 */

namespace Marclie\Booking\Payment;

defined( 'ABSPATH' ) || exit;

final class WebhookHandler {

	public const DUPLICATE = 'duplicate';
	public const IGNORED   = 'ignored';
	public const PROCESSED = 'processed';

	public function __construct(
		private PaymentLedger $ledger,
		private ?PaymentGateway $gateway,
		private PaymentReconciler $reconciler,
		private RefundService $refunds
	) {}

	/**
	 * @param array<string, mixed> $event Decoded event.
	 * @return string DUPLICATE | IGNORED | PROCESSED
	 * @throws \Throwable On processing errors (the endpoint answers 500, Stripe retries).
	 */
	public function handle( array $event ): string {
		$id     = (string) ( $event['id'] ?? '' );
		$type   = (string) ( $event['type'] ?? '' );
		$object = is_array( $event['data']['object'] ?? null ) ? $event['data']['object'] : array();
		if ( '' === $id || '' === $type ) {
			return self::IGNORED;
		}
		if ( $this->ledger->webhookSeen( $id ) ) {
			return self::DUPLICATE;
		}

		$bookingId = null;
		$result    = self::PROCESSED;
		switch ( $type ) {
			case 'payment_intent.succeeded':
			case 'payment_intent.payment_failed':
				$bookingId = $this->onIntent( Intent::fromArray( $object ) );
				break;
			case 'charge.refunded':
				$bookingId = $this->refunds->onChargeRefunded( $object, PaymentReconciler::ACTOR_WEBHOOK );
				break;
			case 'refund.created':
			case 'refund.updated':
			case 'refund.failed':
				$bookingId = $this->refunds->applyRefund( Refund::fromArray( $object ), PaymentReconciler::ACTOR_WEBHOOK );
				break;
			case 'charge.dispute.created':
				$bookingId = $this->onDispute( $object );
				break;
			default:
				$result = self::IGNORED;
		}

		$this->ledger->markWebhook( $id, $type, $bookingId );

		return $result;
	}

	private function onIntent( Intent $pi ): ?int {
		// Webhook payloads carry the payment method as an ID: fetch the card details.
		if ( $pi->succeeded() && null === $pi->cardLast4 && null !== $this->gateway ) {
			try {
				$pi = $this->gateway->retrieveIntent( $pi->id );
			} catch ( GatewayException ) {
				// Apply without card details rather than not at all.
			}
		}

		$booking = $this->reconciler->apply( $pi, PaymentReconciler::ACTOR_WEBHOOK );

		return null === $booking ? null : (int) $booking['id'];
	}

	/** @param array<string, mixed> $dispute */
	private function onDispute( array $dispute ): ?int {
		$intentId = is_array( $dispute['payment_intent'] ?? null ) ? (string) ( $dispute['payment_intent']['id'] ?? '' ) : (string) ( $dispute['payment_intent'] ?? '' );
		$row      = '' === $intentId ? null : $this->ledger->paymentByIntent( $intentId );
		if ( null === $row ) {
			return null;
		}

		$bookingId = (int) $row['booking_id'];
		$data      = array(
			'dispute_id'   => (string) ( $dispute['id'] ?? '' ),
			'payment_id'   => (int) $row['id'],
			'intent'       => $intentId,
			'amount_cents' => (int) ( $dispute['amount'] ?? 0 ),
			'reason'       => (string) ( $dispute['reason'] ?? '' ),
			'status'       => (string) ( $dispute['status'] ?? '' ),
		);
		$this->ledger->log( 'dispute_created', PaymentReconciler::ACTOR_WEBHOOK, $bookingId, $data );

		/**
		 * Fires when the guest's bank opened a dispute (chargeback) on a card payment.
		 *
		 * @param array<string, mixed> $booking
		 * @param array<string, mixed> $dispute dispute_id, payment_id, intent, amount_cents, reason, status.
		 */
		do_action( 'mbk_dispute_created', $this->ledger->booking( $bookingId ), $data );

		return $bookingId;
	}
}
