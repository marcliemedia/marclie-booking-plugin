<?php
/**
 * Bricks element `mbk-search-results`: filter panel + stay cards of the bookable units for
 * the URL stay (client-side facets and sort). For a designer-built card grid use a query
 * loop of type `mbk_available_units` instead.
 */

namespace Marclie\Booking\Bricks\Elements;

use Marclie\Booking\Bricks\Preview;
use Marclie\Booking\Frontend\Renderer;

defined( 'ABSPATH' ) || exit;

final class SearchResults extends BookingElement {

	public $name = 'mbk-search-results';

	public $icon = 'ti-layout-grid2';

	public function get_label() {
		return esc_html__( 'Search results', 'marclie-booking' );
	}

	public function set_controls() {
		$this->controls['info'] = array(
			'tab'     => 'content',
			'type'    => 'info',
			'content' => esc_html__( 'Lists the apartments free for the dates and guests in the URL (dolazak, odlazak, odrasli, djeca). The builder shows a sample stay.', 'marclie-booking' ),
		);
	}

	protected function markup(): string {
		return Preview::run( static fn(): string => Renderer::searchResults() );
	}
}
