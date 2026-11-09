<?php
/**
 * Bricks element `mbk-availability-calendar`: inline availability calendar (#dostupnost);
 * it always shares the date selection with the bookbox of the page (same date-pair key).
 */

namespace Marclie\Booking\Bricks\Elements;

use Marclie\Booking\Frontend\Renderer;

defined( 'ABSPATH' ) || exit;

final class AvailabilityCalendar extends BookingElement {

	public $name = 'mbk-availability-calendar';

	public $icon = 'ti-calendar';

	/** Builder only (defined by Loader in the builder iframe): draws the JS calendar after a render. */
	public $scripts = array( 'mbkBricksCalendar' );

	public function get_label() {
		return esc_html__( 'Availability calendar', 'marclie-booking' );
	}

	public function set_controls() {
		$this->unitControls();

		$this->controls['months'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Months', 'marclie-booking' ),
			'type'        => 'select',
			'options'     => array(
				'1' => '1',
				'2' => '2',
			),
			'inline'      => true,
			'placeholder' => '2',
			'desc'        => esc_html__( 'Months side by side on wide screens (one on phones).', 'marclie-booking' ),
		);

		$this->controls['view'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Open on month', 'marclie-booking' ),
			'type'        => 'text',
			'placeholder' => 'YYYY-MM',
			'desc'        => esc_html__( 'Optional; without chosen dates the calendar opens on this month.', 'marclie-booking' ),
		);
	}

	protected function markup(): string {
		$view = trim( (string) $this->render_dynamic_data( (string) ( $this->settings['view'] ?? '' ) ) );

		return Renderer::calendar( $this->unitRef(), (int) ( $this->settings['months'] ?? 2 ), $view );
	}

	protected function emptyMessage(): string {
		return esc_html__( 'No apartment: use this element in the apartment template or select an apartment.', 'marclie-booking' );
	}
}
