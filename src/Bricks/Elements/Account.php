<?php
/**
 * Bricks element `mbk-account` (Moj račun page). Part `head`: h1 + "Signed in as …" (page-title
 * section); `board`: tabs and panels (account section); `all`: both. Builder: sample data of
 * the first unit (the editor has no guest account).
 */

namespace Marclie\Booking\Bricks\Elements;

use Marclie\Booking\Bricks\Preview;
use Marclie\Booking\Container;
use Marclie\Booking\Frontend\AccountPages;

defined( 'ABSPATH' ) || exit;

final class Account extends BookingElement {

	public $name = 'mbk-account';

	public $icon = 'ti-user';

	public function get_label() {
		return esc_html__( 'My account', 'marclie-booking' );
	}

	public function set_controls() {
		$this->controls['part'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Part', 'marclie-booking' ),
			'type'        => 'select',
			'options'     => array(
				'board' => esc_html__( 'Tabs and panels', 'marclie-booking' ),
				'head'  => esc_html__( 'Heading and signed-in line', 'marclie-booking' ),
				'all'   => esc_html__( 'Both', 'marclie-booking' ),
			),
			'inline'      => true,
			'placeholder' => esc_html__( 'Tabs and panels', 'marclie-booking' ),
		);
	}

	protected function markup(): string {
		$part   = (string) ( $this->settings['part'] ?? 'board' );
		$sample = Preview::active() ? AccountPages::sample( Preview::sampleUnit(), Container::clock()->today() ) : null;

		return AccountPages::account( $part, $sample );
	}
}
