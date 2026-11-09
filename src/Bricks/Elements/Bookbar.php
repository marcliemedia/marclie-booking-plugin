<?php
/**
 * Bricks element `mbk-bookbar`: mobile bar with the "from" price and a CTA to the bookbox.
 */

namespace Marclie\Booking\Bricks\Elements;

use Marclie\Booking\Frontend\Renderer;

defined( 'ABSPATH' ) || exit;

final class Bookbar extends BookingElement {

	public $name = 'mbk-bookbar';

	public $icon = 'ti-layout-cta-btn-right';

	public function get_label() {
		return esc_html__( 'Booking bar (mobile)', 'marclie-booking' );
	}

	public function set_controls() {
		$this->unitControls();
	}

	protected function markup(): string {
		return Renderer::bookbar( $this->unitRef() );
	}

	protected function emptyMessage(): string {
		return esc_html__( 'No apartment: use this element in the apartment template or select an apartment.', 'marclie-booking' );
	}
}
