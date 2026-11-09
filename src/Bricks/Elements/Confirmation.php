<?php
/**
 * Bricks element `mbk-confirmation` (Hvala page): state panels filled by confirmation.js.
 * Builder: one static panel with sample booking data.
 */

namespace Marclie\Booking\Bricks\Elements;

use Marclie\Booking\Bricks\Preview;
use Marclie\Booking\Frontend\Renderer;

defined( 'ABSPATH' ) || exit;

final class Confirmation extends BookingElement {

	public $name = 'mbk-confirmation';

	public $icon = 'ti-check-box';

	private const STATES = array( 'done', 'loading', 'processing', 'timeout', 'failed', 'hold_lost', 'cancelled', 'error', 'invalid' );

	public function get_label() {
		return esc_html__( 'Booking confirmation', 'marclie-booking' );
	}

	public function set_controls() {
		$this->controls['previewState'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Builder preview', 'marclie-booking' ),
			'type'        => 'select',
			'options'     => array_combine( self::STATES, self::STATES ),
			'inline'      => true,
			'placeholder' => 'done',
			'desc'        => esc_html__( 'Builder only; the live page loads the booking from its link.', 'marclie-booking' ),
		);
	}

	protected function markup(): string {
		$html = Renderer::confirmation();
		if ( ! Preview::active() ) {
			return $html;
		}

		$state = (string) ( $this->settings['previewState'] ?? 'done' );

		return Preview::showState( $html, 'data-cf-state', in_array( $state, self::STATES, true ) ? $state : 'done', 'data-cf', Preview::sampleBooking() );
	}
}
