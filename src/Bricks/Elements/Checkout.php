<?php
/**
 * Bricks element `mbk-checkout` (Rezervacija page): guest details + payment steps, state from
 * the URL. Builder: a static state with the sample unit/stay (no quote request, no Stripe).
 */

namespace Marclie\Booking\Bricks\Elements;

use Marclie\Booking\Bricks\Preview;
use Marclie\Booking\Container;
use Marclie\Booking\Frontend\Renderer;

defined( 'ABSPATH' ) || exit;

final class Checkout extends BookingElement {

	public $name = 'mbk-checkout';

	public $icon = 'ti-receipt';

	/** Builder only: draws the date picker of the "Choose dates" state. */
	public $scripts = array( 'mbkBricksCalendar' );

	public function get_label() {
		return esc_html__( 'Checkout', 'marclie-booking' );
	}

	public function set_controls() {
		$this->controls['previewState'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Builder preview', 'marclie-booking' ),
			'type'        => 'select',
			'options'     => array(
				'free'    => esc_html__( 'Apartment and dates chosen', 'marclie-booking' ),
				'dates'   => esc_html__( 'Choose dates', 'marclie-booking' ),
				'no_unit' => esc_html__( 'No apartment', 'marclie-booking' ),
			),
			'inline'      => true,
			'placeholder' => esc_html__( 'Apartment and dates chosen', 'marclie-booking' ),
			'desc'        => esc_html__( 'Builder only; the live page follows the booking link.', 'marclie-booking' ),
		);
	}

	protected function markup(): string {
		if ( ! Preview::active() ) {
			return Renderer::checkout();
		}

		$unit  = Preview::sampleUnit();
		$state = (string) ( $this->settings['previewState'] ?? 'free' );
		$slug  = null === $unit || 'no_unit' === $state ? null : Renderer::slug( $unit );
		$query = array( 'apartman' => $slug ) + Preview::sampleQuery( Container::clock()->today() );
		if ( 'free' !== $state ) {
			$query['dolazak'] = null;
			$query['odlazak'] = null;
		}

		return Preview::withQuery( $query, static fn(): string => Renderer::checkout() );
	}
}
