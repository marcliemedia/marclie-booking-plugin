<?php
/**
 * /admin/payments — every mbk_payments row with period totals, and the
 * balances scheduled for collection.
 */

namespace Marclie\Booking\Rest\Admin;

use Marclie\Booking\Container;
use Marclie\Booking\Install\Activator;
use Marclie\Booking\Repository\PaymentRepository;

defined( 'ABSPATH' ) || exit;

final class PaymentsController extends BaseController {

	public function register(): void {
		$cap = Activator::CAP_BOOKINGS;

		$this->route(
			'/payments',
			$this->endpoint(
				\WP_REST_Server::READABLE,
				array( $this, 'index' ),
				$cap,
				array_merge(
					self::paginationArgs(),
					array(
						'status'  => array(
							'type' => 'string',
							'enum' => array_merge( array( '' ), PaymentRepository::STATUSES ),
						),
						'kind'    => array(
							'type' => 'string',
							'enum' => array_merge( array( '' ), PaymentRepository::KINDS ),
						),
						'method'  => array(
							'type' => 'string',
							'enum' => array_merge( array( '', PaymentRepository::METHOD_STRIPE ), PaymentRepository::OUTSIDE_METHODS ),
						),
						'from'    => self::dateArg(),
						'to'      => self::dateArg(),
						'orderby' => array(
							'type'    => 'string',
							'enum'    => array( 'created_at', 'amount' ),
							'default' => 'created_at',
						),
					)
				)
			)
		);
		$this->route(
			'/payments/scheduled',
			$this->endpoint(
				\WP_REST_Server::READABLE,
				array( $this, 'scheduled' ),
				$cap,
				array(
					'days' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 366,
						'default' => 30,
					),
				)
			)
		);
	}

	/** @return array<string, mixed> */
	public function index( \WP_REST_Request $request ): array {
		$filters = array(
			'status'   => (string) $request['status'],
			'kind'     => (string) $request['kind'],
			'method'   => (string) $request['method'],
			'from_utc' => $request['from'] ? self::utcStartOf( (string) $request['from'], 'from' ) : null,
			'to_utc'   => $request['to'] ? self::utcEndOf( (string) $request['to'], 'to' ) : null,
		);

		$repo   = Container::paymentRepository();
		$result = $repo->search( $filters, (int) $request['page'], (int) $request['per_page'], (string) $request['orderby'], (string) $request['order'] );
		$totals = $repo->totals( $filters );

		return self::listResponse(
			array_map( array( Presenter::class, 'payment' ), $result['items'] ),
			$result['total'],
			$request,
			array( 'totals' => $totals + array( 'net_cents' => $totals['succeeded_cents'] - $totals['refunded_cents'] ) )
		);
	}

	/**
	 * Outstanding balances (confirmed, failed, or paid but re-priced higher after a move) due within `days` (overdue included).
	 *
	 * @return array{items: list<array{booking: array<string, mixed>, amount_cents: int, due_on: string}>}
	 */
	public function scheduled( \WP_REST_Request $request ): array {
		$until = Container::clock()->today()->modify( sprintf( '+%d days', (int) $request['days'] ) )->format( 'Y-m-d' );
		$rows  = Container::bookingRepository()->findAll(
			array(
				'status'         => array( 'confirmed', 'paid', 'balance_failed' ),
				'balance_due_to' => $until,
				'payment_state'  => 'outstanding',
			),
			'arrival'
		);
		usort( $rows, static fn( array $a, array $b ): int => array( $a['balance_due_on'], $a['id'] ) <=> array( $b['balance_due_on'], $b['id'] ) );

		$summaries = Presenter::bookingSummaries( $rows );
		$items     = array();
		foreach ( $summaries as $summary ) {
			$items[] = array(
				'booking'      => $summary,
				'amount_cents' => $summary['balance_cents'],
				'due_on'       => (string) $summary['balance_due_on'],
			);
		}

		return array( 'items' => $items );
	}
}
