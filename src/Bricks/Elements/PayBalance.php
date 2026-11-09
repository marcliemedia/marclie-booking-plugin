<?php
/**
 * Bricks element `mbk-pay-balance` (Plaćanje page): balance payment filled by pay-balance.js
 * (Stripe Payment Element). Builder: one static panel with sample booking data, no Stripe.
 */

namespace Marclie\Booking\Bricks\Elements;

use Marclie\Booking\Bricks\Preview;
use Marclie\Booking\Frontend\Renderer;

defined( 'ABSPATH' ) || exit;

final class PayBalance extends BookingElement {

	public $name = 'mbk-pay-balance';

	public $icon = 'ti-credit-card';

	private const STATES = array( 'form', 'loading', 'processing', 'timeout', 'error', 'closed', 'invalid', 'nothing', 'success' );

	public function get_label() {
		return esc_html__( 'Balance payment', 'marclie-booking' );
	}

	public function set_controls() {
		$this->controls['previewState'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Builder preview', 'marclie-booking' ),
			'type'        => 'select',
			'options'     => array_combine( self::STATES, self::STATES ),
			'inline'      => true,
			'placeholder' => 'form',
			'desc'        => esc_html__( 'Builder only; the live page loads the booking from its link.', 'marclie-booking' ),
		);
	}

	protected function markup(): string {
		$html = Renderer::payBalance();
		if ( ! Preview::active() ) {
			return $html;
		}

		$state = (string) ( $this->settings['previewState'] ?? 'form' );

		return Preview::showState( $html, 'data-pb-state', in_array( $state, self::STATES, true ) ? $state : 'form', 'data-pb', Preview::sampleBooking() );
	}
}
