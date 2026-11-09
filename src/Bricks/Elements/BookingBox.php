<?php
/**
 * Bricks element `mbk-booking-box`: the bookbox of a unit (price from, dates, guests, live
 * quote, CTA to /rezervacija/). Builder: sample unit and stay.
 */

namespace Marclie\Booking\Bricks\Elements;

use Marclie\Booking\Bricks\Preview;
use Marclie\Booking\Frontend\Renderer;

defined( 'ABSPATH' ) || exit;

final class BookingBox extends BookingElement {

	public $name = 'mbk-booking-box';

	public $icon = 'ti-layout-sidebar-right';

	public function get_label() {
		return esc_html__( 'Booking box', 'marclie-booking' );
	}

	public function set_controls() {
		$this->unitControls();

		$this->controls['hideInquiry'] = array(
			'tab'   => 'content',
			'label' => esc_html__( 'Hide enquiry button', 'marclie-booking' ),
			'type'  => 'checkbox',
		);

		$this->controls['hideNote'] = array(
			'tab'   => 'content',
			'label' => esc_html__( 'Hide policy note', 'marclie-booking' ),
			'type'  => 'checkbox',
		);
	}

	protected function markup(): string {
		return Preview::run(
			fn(): string => Renderer::bookbox( $this->unitRef(), empty( $this->settings['hideInquiry'] ), empty( $this->settings['hideNote'] ) )
		);
	}

	protected function emptyMessage(): string {
		return esc_html__( 'No apartment: use this element in the apartment template or select an apartment.', 'marclie-booking' );
	}
}
