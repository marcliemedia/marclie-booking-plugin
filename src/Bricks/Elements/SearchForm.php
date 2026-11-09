<?php
/**
 * Bricks element `mbk-search-form`: availability search (hero, header dialog, compact strip),
 * always submitting to the Pretraga page.
 */

namespace Marclie\Booking\Bricks\Elements;

use Marclie\Booking\Bricks\Preview;
use Marclie\Booking\Frontend\Renderer;

defined( 'ABSPATH' ) || exit;

final class SearchForm extends BookingElement {

	public $name = 'mbk-search-form';

	public $icon = 'ti-search';

	public function get_label() {
		return esc_html__( 'Search form', 'marclie-booking' );
	}

	public function set_controls() {
		$this->controls['variant'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Variant', 'marclie-booking' ),
			'type'        => 'select',
			'options'     => array(
				'hero'    => esc_html__( 'Hero', 'marclie-booking' ),
				'dialog'  => esc_html__( 'Dialog (header)', 'marclie-booking' ),
				'compact' => esc_html__( 'Compact strip (Pretraga)', 'marclie-booking' ),
			),
			'inline'      => true,
			'placeholder' => esc_html__( 'Hero', 'marclie-booking' ),
		);
	}

	protected function markup(): string {
		return Preview::run( fn(): string => Renderer::searchForm( (string) ( $this->settings['variant'] ?? 'hero' ) ) );
	}
}
