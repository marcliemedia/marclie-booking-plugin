<?php
/**
 * /admin/bookings — list, detail, quote, manual booking, edit, move, cancel
 * (with Stripe refunds), outside payments, off-session charge of the balance
 * and guest payment links.
 */

namespace Marclie\Booking\Rest\Admin;

use Marclie\Booking\Container;
use Marclie\Booking\Domain\BookingStatus;
use Marclie\Booking\Domain\Exception\InvalidTransitionException;
use Marclie\Booking\Domain\Exception\NotFoundException;
use Marclie\Booking\Domain\Exception\PaymentException;
use Marclie\Booking\Domain\Exception\ValidationException;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\Email\EmailRegistry;
use Marclie\Booking\Frontend\Renderer;
use Marclie\Booking\Install\Activator;
use Marclie\Booking\Repository\PaymentRepository;
use Marclie\Booking\Repository\TokenRepository;
use Marclie\Booking\Service\CancellationDecision;

defined( 'ABSPATH' ) || exit;

final class BookingsController extends BaseController {

	private const ID = '/bookings/(?P<id>\d+)';

	public function register(): void {
		$cap = Activator::CAP_BOOKINGS;

		$this->route(
			'/bookings',
			array(
				$this->endpoint( \WP_REST_Server::READABLE, array( $this, 'index' ), $cap, self::listArgs() ),
				$this->endpoint( \WP_REST_Server::CREATABLE, array( $this, 'create' ), $cap, self::createArgs() ),
			)
		);
		$this->route( '/bookings/quote', $this->endpoint( \WP_REST_Server::CREATABLE, array( $this, 'quote' ), $cap, self::quoteArgs() ) );
		// GET also accepts the booking reference (deep links like #/bookings/BK-27-0712-101).
		$this->route( '/bookings/(?P<ref>[A-Za-z0-9]{1,6}-[A-Za-z0-9-]+)', $this->endpoint( \WP_REST_Server::READABLE, array( $this, 'showByRef' ), $cap ) );
		$this->route(
			self::ID,
			array(
				$this->endpoint( \WP_REST_Server::READABLE, array( $this, 'show' ), $cap ),
				$this->endpoint( 'PATCH', array( $this, 'update' ), $cap, self::updateArgs() ),
			)
		);
		$this->route( self::ID . '/move', $this->endpoint( \WP_REST_Server::CREATABLE, array( $this, 'move' ), $cap, self::moveArgs() ) );
		$this->route( self::ID . '/cancel', $this->endpoint( \WP_REST_Server::CREATABLE, array( $this, 'cancel' ), $cap, self::cancelArgs() ) );
		$this->route( self::ID . '/payments', $this->endpoint( \WP_REST_Server::CREATABLE, array( $this, 'recordPayment' ), $cap, self::paymentArgs() ) );
		$this->route( self::ID . '/charge', $this->endpoint( \WP_REST_Server::CREATABLE, array( $this, 'charge' ), $cap ) );
		$this->route(
			self::ID . '/payment-link',
			$this->endpoint(
				\WP_REST_Server::CREATABLE,
				array( $this, 'paymentLink' ),
				$cap,
				array(
					'expires_days' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 30,
						'default' => 7,
					),
					'send_email'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
				)
			)
		);
		$this->route( self::ID . '/emails/(?P<code>[a-z_]+)/resend', $this->endpoint( \WP_REST_Server::CREATABLE, array( $this, 'resendEmail' ), $cap ) );
	}

	/** @return array<string, mixed> */
	public function index( \WP_REST_Request $request ): array {
		$filters = array(
			'status'        => self::statusFilter( (string) $request['status'] ),
			'unit_id'       => $request['unit_id'] ? (int) $request['unit_id'] : null,
			'source'        => (string) $request['source'],
			'search'        => (string) $request['search'],
			'date_field'    => (string) $request['date_field'],
			'payment_state' => (string) $request['payment_state'],
			'today'         => Container::clock()->today()->format( 'Y-m-d' ),
		);
		if ( 'created' === $filters['date_field'] ) {
			$filters['from_utc'] = $request['from'] ? self::utcStartOf( (string) $request['from'], 'from' ) : null;
			$filters['to_utc']   = $request['to'] ? self::utcEndOf( (string) $request['to'], 'to' ) : null;
		} else {
			$filters['from'] = (string) $request['from'];
			$filters['to']   = (string) $request['to'];
		}

		$repo   = Container::bookingRepository();
		$result = $repo->search( $filters, (int) $request['page'], (int) $request['per_page'], (string) $request['orderby'], (string) $request['order'] );

		return self::listResponse(
			Presenter::bookingSummaries( $result['items'] ),
			$result['total'],
			$request,
			array( 'counts' => $repo->countsByStatus( $filters ) )
		);
	}

	/** @return array<string, mixed> */
	public function show( \WP_REST_Request $request ): array {
		return Presenter::bookingDetail( Container::bookings()->mustFind( (int) $request['id'] ) );
	}

	/** @return array<string, mixed> */
	public function showByRef( \WP_REST_Request $request ): array {
		$booking = Container::bookings()->findByRef( (string) $request['ref'] );
		if ( null === $booking ) {
			throw new NotFoundException( 'Booking not found.', array( 'ref' => (string) $request['ref'] ) );
		}

		return Presenter::bookingDetail( $booking );
	}

	/** @return array<string, mixed> */
	public function quote( \WP_REST_Request $request ): array {
		$stay  = self::stay( $request );
		$quote = Container::pricing()->quote(
			$stay,
			(int) $request['adults'],
			(int) $request['children'],
			'' !== trim( (string) $request['coupon'] ) ? (string) $request['coupon'] : null,
			$request['guest_id'] ? (int) $request['guest_id'] : null,
			(int) $request['infants']
		);

		return array(
			'quote'     => Presenter::quote( $quote ),
			'available' => Container::availability()->isAvailable( $stay ),
		);
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response {
		$booking = Container::bookings()->createManual(
			self::stay( $request ),
			(int) $request['adults'],
			(int) $request['children'],
			(array) $request['guest'],
			'' !== trim( (string) $request['coupon'] ) ? (string) $request['coupon'] : null,
			(array) $request['payment'],
			null !== $request['internal_notes'] ? (string) $request['internal_notes'] : null,
			self::userId(),
			(int) $request['infants']
		);

		return new \WP_REST_Response( Presenter::bookingDetail( $booking ), 201 );
	}

	/** @return array<string, mixed> */
	public function update( \WP_REST_Request $request ): array {
		$patch = array();
		foreach ( array_keys( self::updateArgs() ) as $field ) {
			if ( self::has( $request, $field ) && null !== $request[ $field ] ) {
				$patch[ $field ] = $request[ $field ];
			}
		}

		return Presenter::bookingDetail( Container::bookings()->update( (int) $request['id'], $patch, self::userId() ) );
	}

	/** @return array<string, mixed> */
	public function move( \WP_REST_Request $request ): array {
		$result = Container::bookings()->move(
			(int) $request['id'],
			self::stay( $request ),
			(bool) $request['dry_run'],
			(bool) $request['keep_price'],
			self::userId()
		);

		if ( (bool) $request['dry_run'] ) {
			return array(
				'available'      => $result['available'],
				'quote_old'      => Presenter::quote( $result['quote_old'] ),
				'quote_new'      => Presenter::quote( $result['quote_new'] ),
				'diff_cents'     => $result['diff_cents'],
				'coupon_dropped' => $result['coupon_dropped'],
			);
		}

		return Presenter::bookingDetail( $result );
	}

	/**
	 * refund_mode policy | none | custom (amount_cents + reason; above the policy
	 * amount only with mbk_manage_settings). `dry_run` returns the decision
	 * without cancelling (for the confirmation dialog).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function cancel( \WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$booking = Container::bookings()->mustFind( $id );
		$status  = BookingStatus::tryFrom( (string) $booking['status'] );
		if ( null === $status || ! $status->canTransitionTo( BookingStatus::Cancelled ) ) {
			throw new InvalidTransitionException(
				'This booking cannot be cancelled.',
				array(
					'from' => $booking['status'],
					'to'   => BookingStatus::Cancelled->value,
				)
			);
		}

		$cancellation = Container::cancellation();
		$policy       = $cancellation->decide( $id );
		$mode         = (string) $request['refund_mode'];
		$reason       = trim( (string) $request['reason'] );
		$override     = null;

		if ( 'none' === $mode ) {
			$override = 0;
			$reason   = '' !== $reason ? $reason : 'No refund';
		} elseif ( 'custom' === $mode ) {
			$errors = array();
			if ( null === $request['amount_cents'] ) {
				$errors['amount_cents'] = __( 'Enter the refund amount.', 'marclie-booking' );
			}
			if ( '' === $reason ) {
				$errors['reason'] = __( 'Enter a reason for the custom refund.', 'marclie-booking' );
			}
			if ( $errors ) {
				throw ValidationException::fields( $errors );
			}
			$override = (int) $request['amount_cents'];
			if ( $override > $policy->refund->cents() && ! current_user_can( Activator::CAP_SETTINGS ) ) {
				return new \WP_Error( 'rest_forbidden', __( 'Refunds above the cancellation policy need an administrator.', 'marclie-booking' ), array( 'status' => 403 ) );
			}
		}

		if ( (bool) $request['dry_run'] ) {
			$decision = $cancellation->decide( $id, $override, $reason );
			$decision = $decision->withRefunds( $cancellation->allocate( $id, $decision->refund->cents() ) );

			return array(
				'booking'  => Presenter::bookingDetail( $booking ),
				'decision' => self::decision( $policy, $decision ),
			);
		}

		$decision = $cancellation->cancelWithRefunds( $id, self::actor(), $override, $reason );

		return array(
			'booking'  => Presenter::bookingDetail( Container::bookings()->mustFind( $id ) ),
			'decision' => self::decision( $policy, $decision ),
		);
	}

	/** @return array<string, mixed> */
	public function recordPayment( \WP_REST_Request $request ): array {
		$booking = Container::bookings()->recordPayment(
			(int) $request['id'],
			(string) $request['kind'],
			(int) $request['amount_cents'],
			(string) $request['method'],
			null !== $request['note'] ? (string) $request['note'] : null,
			self::userId(),
			null !== $request['idempotency_key'] ? (string) $request['idempotency_key'] : null
		);

		return Presenter::bookingDetail( $booking );
	}

	/**
	 * Charges the outstanding amount off-session with the saved card now.
	 * Declines / required authentication answer 402 (the booking is then balance_failed).
	 *
	 * @return array<string, mixed>
	 */
	public function charge( \WP_REST_Request $request ): array {
		$id = (int) $request['id'];
		Container::bookings()->mustFind( $id );
		$intent = Container::balanceCharger()->charge( $id, self::actor() );
		if ( $intent->failed() ) {
			throw new PaymentException(
				$intent->needsAction() ? PaymentException::REQUIRES_ACTION : PaymentException::DECLINED,
				'The card payment failed.',
				array( 'decline_code' => $intent->errorCode )
			);
		}

		return Presenter::bookingDetail( Container::bookings()->mustFind( $id ) );
	}

	/**
	 * New guest payment link (/rezervacija/placanje/?ref=&t=) with its own
	 * expiring `pay` token; `send_email` also emails it (guest_payment_link).
	 *
	 * @return array{url: string, expires_at: string, email_sent: bool}
	 */
	public function paymentLink( \WP_REST_Request $request ): array {
		$id      = (int) $request['id'];
		$booking = Container::bookings()->mustFind( $id );
		$status  = BookingStatus::tryFrom( (string) $booking['status'] );
		if ( ! in_array( $status, array( BookingStatus::Confirmed, BookingStatus::BalanceFailed, BookingStatus::PendingPayment ), true )
			|| ( BookingStatus::PendingPayment === $status && null !== $booking['hold_expires_at'] ) ) {
			throw new InvalidTransitionException( 'Payment links are only for confirmed bookings and admin holds.', array( 'from' => $booking['status'] ) );
		}
		if ( Container::paymentRepository()->netPaidCents( $id ) >= (int) $booking['total_cents'] ) {
			throw new PaymentException( PaymentException::NOTHING_DUE, 'Nothing is due.' );
		}
		if ( null === Container::paymentGateway() ) {
			throw PaymentException::unavailable();
		}

		$expires = Container::clock()->now()->modify( sprintf( '+%d days', (int) $request['expires_days'] ) );
		$token   = Container::bookings()->issueToken( $id, TokenRepository::PAY, $expires );
		$lang    = 'en' === $booking['lang'] ? 'en' : 'hr';
		$url     = add_query_arg(
			array(
				'ref' => rawurlencode( (string) $booking['ref'] ),
				't'   => $token,
			),
			Renderer::pageUrl( 'placanje', $lang )
		);
		$at      = $expires->setTimezone( wp_timezone() )->format( DATE_ATOM );

		Container::eventRepository()->log( 'payment_link_created', self::actor(), $id, $booking['guest_id'], array( 'expires_at' => $at ) );

		/**
		 * Fires after an admin created a guest payment link (`send_email` emails it below).
		 *
		 * @param array<string, mixed> $booking
		 * @param string               $url
		 */
		do_action( 'mbk_payment_link_created', $booking, $url );

		$sent = (bool) $request['send_email'] && Container::mailer()->sendForBooking(
			'guest_payment_link',
			Container::bookings()->mustFind( $id ),
			array(
				'force' => true,
				'actor' => self::actor(),
				'extra' => array( 'links' => array( 'payment_link' => $url ) ),
			)
		);

		return array(
			'url'        => $url,
			'expires_at' => $at,
			'email_sent' => $sent,
		);
	}

	/**
	 * Sends a booking email again (also when the template is switched off).
	 * Only codes that apply to the current status (422 `not_applicable`) and
	 * have a recipient (422 `no_recipient`); guest_payment_link gets a fresh
	 * 7-day payment link.
	 *
	 * @return array{sent: bool}|\WP_Error
	 */
	public function resendEmail( \WP_REST_Request $request ) {
		$code    = (string) $request['code'];
		$booking = Container::bookings()->mustFind( (int) $request['id'] );
		if ( ! EmailRegistry::isBookingTemplate( $code ) ) {
			throw ValidationException::fields( array( 'code' => __( 'This email cannot be sent for a booking.', 'marclie-booking' ) ) );
		}
		$mailer = Container::mailer();
		if ( ! in_array( $code, $mailer->applicableCodes( $booking ), true ) ) {
			return new \WP_Error( 'not_applicable', __( 'This email does not apply to the booking’s current status.', 'marclie-booking' ), array( 'status' => 422 ) );
		}
		if ( ! $mailer->recipients( $code, $booking ) ) {
			return new \WP_Error( 'no_recipient', __( 'There is no valid email address to send this email to.', 'marclie-booking' ), array( 'status' => 422 ) );
		}

		$sent = $mailer->sendForBooking(
			$code,
			$booking,
			array(
				'force' => true,
				'actor' => self::actor(),
			)
		);

		return $sent ? array( 'sent' => true ) : EmailTemplatesController::sendFailed();
	}

	/* Helpers ---------------------------------------------------------------- */

	private static function stay( \WP_REST_Request $request ): Stay {
		return Stay::fromStrings( (int) $request['unit_id'], (string) $request['arrival'], (string) $request['departure'], wp_timezone() );
	}

	/** @return string|list<string>|null */
	private static function statusFilter( string $status ): string|array|null {
		if ( '' === $status ) {
			return null;
		}
		if ( 'upcoming' === $status ) {
			return 'upcoming';
		}

		$valid = array_map( static fn( BookingStatus $s ): string => $s->value, BookingStatus::cases() );
		$list  = array_values( array_intersect( array_map( 'trim', explode( ',', $status ) ), $valid ) );
		if ( ! $list ) {
			throw ValidationException::fields( array( 'status' => __( 'Unknown booking status.', 'marclie-booking' ) ) );
		}

		return $list;
	}

	/** @return array<string, mixed> */
	private static function decision( CancellationDecision $policy, CancellationDecision $decision ): array {
		$reason = '' !== $decision->reason ? $decision->reason : ( $decision->withinFreeCancel ? 'policy_free_cancel' : 'policy_no_refund' );

		return array(
			'refundable_cents'   => $policy->refund->cents(),
			'refund_cents'       => $decision->refund->cents(),
			'reason'             => $reason,
			'paid_cents'         => $decision->paid->cents(),
			'within_free_cancel' => $decision->withinFreeCancel,
			'free_cancel_until'  => $decision->freeCancelUntil,
			'refunds'            => $decision->refunds,
		);
	}

	/* Arguments -------------------------------------------------------------- */

	/** @return array<string, array<string, mixed>> */
	private static function listArgs(): array {
		return array_merge(
			self::paginationArgs(),
			array(
				'status'        => array( 'type' => 'string' ),
				'unit_id'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'source'        => array(
					'type' => 'string',
					'enum' => array( '', 'direct', 'admin', 'airbnb', 'booking' ),
				),
				'search'        => array( 'type' => 'string' ),
				'date_field'    => array(
					'type'    => 'string',
					'enum'    => array( 'arrival', 'departure', 'created' ),
					'default' => 'arrival',
				),
				'from'          => self::dateArg(),
				'to'            => self::dateArg(),
				'payment_state' => array(
					'type' => 'string',
					'enum' => array( '', 'unpaid', 'deposit', 'paid', 'failed' ),
				),
				'orderby'       => array(
					'type'    => 'string',
					'enum'    => array( 'arrival', 'created_at', 'total' ),
					'default' => 'created_at',
				),
			)
		);
	}

	/** @return array<string, array<string, mixed>> */
	private static function stayArgs(): array {
		return array(
			'unit_id'   => self::idArg(),
			'arrival'   => self::dateArg( true ),
			'departure' => self::dateArg( true ),
		);
	}

	/** @return array<string, array<string, mixed>> */
	private static function quoteArgs(): array {
		return self::stayArgs() + array(
			'adults'   => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
			'children' => array(
				'type'    => 'integer',
				'minimum' => 0,
				'default' => 0,
			),
			'infants'  => array(
				'type'    => 'integer',
				'minimum' => 0,
				'default' => 0,
			),
			'coupon'   => array( 'type' => array( 'string', 'null' ) ),
			'guest_id' => array( 'type' => array( 'integer', 'null' ) ),
		);
	}

	/** @return array<string, array<string, mixed>> */
	private static function createArgs(): array {
		$args = self::quoteArgs();
		unset( $args['guest_id'] );

		return $args + array(
			'guest'          => array(
				'type'       => 'object',
				'required'   => true,
				'properties' => array(
					'id'         => array( 'type' => array( 'integer', 'null' ) ),
					'first_name' => self::text( 100 ),
					'last_name'  => self::text( 100 ),
					'email'      => self::text( 190 ),
					'phone'      => self::text( 40 ),
					'country'    => self::text( 2 ),
					'lang'       => array( 'type' => array( 'string', 'null' ) ),
				),
			),
			'payment'        => array(
				'type'       => 'object',
				'required'   => true,
				'properties' => array(
					'mode'         => array(
						'type'     => 'string',
						'enum'     => array( 'outside', 'none' ),
						'required' => true,
					),
					'amount_cents' => array(
						'type'    => array( 'integer', 'null' ),
						'minimum' => 0,
					),
					'method'       => array( 'type' => array( 'string', 'null' ) ),
				),
			),
			'internal_notes' => array( 'type' => array( 'string', 'null' ) ),
		);
	}

	/** @return array<string, array<string, mixed>> */
	private static function updateArgs(): array {
		return array(
			'first_name'     => self::text( 100 ),
			'last_name'      => self::text( 100 ),
			'email'          => self::text( 190 ),
			'phone'          => self::text( 40 ),
			'country'        => self::text( 2 ),
			'lang'           => array(
				'type' => 'string',
				'enum' => array( 'hr', 'en' ),
			),
			'adults'         => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'children'       => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'infants'        => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'arrival_time'   => self::text( 20 ),
			'internal_notes' => self::text( 10000 ),
		);
	}

	/** @return array<string, array<string, mixed>> */
	private static function moveArgs(): array {
		return self::stayArgs() + array(
			'dry_run'    => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'keep_price' => array(
				'type'    => 'boolean',
				'default' => false,
			),
		);
	}

	/** @return array<string, array<string, mixed>> */
	private static function cancelArgs(): array {
		return array(
			'refund_mode'  => array(
				'type'     => 'string',
				'enum'     => array( 'policy', 'none', 'custom' ),
				'required' => true,
			),
			'amount_cents' => array(
				'type'    => array( 'integer', 'null' ),
				'minimum' => 0,
			),
			'reason'       => array( 'type' => array( 'string', 'null' ) ),
			'dry_run'      => array(
				'type'    => 'boolean',
				'default' => false,
			),
		);
	}

	/** @return array<string, array<string, mixed>> */
	private static function paymentArgs(): array {
		return array(
			'kind'         => array(
				'type'     => 'string',
				'enum'     => PaymentRepository::CHARGE_KINDS,
				'required' => true,
			),
			'amount_cents' => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
			'method'       => array(
				'type'     => 'string',
				'enum'     => PaymentRepository::OUTSIDE_METHODS,
				'required' => true,
			),
			'note'            => array( 'type' => array( 'string', 'null' ) ),
			'idempotency_key' => array(
				'type'      => array( 'string', 'null' ),
				'maxLength' => 64,
			),
		);
	}
}
