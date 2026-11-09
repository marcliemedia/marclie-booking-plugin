<?php
/**
 * Bricks element `mbk-forgot-password` (Zaboravljena lozinka page): the reset-link request form,
 * or the new-password form when the page is opened from the email link (?login&key).
 */

namespace Marclie\Booking\Bricks\Elements;

use Marclie\Booking\Bricks\Preview;
use Marclie\Booking\Frontend\AccountPages;

defined( 'ABSPATH' ) || exit;

final class AccountForgotPassword extends BookingElement {

	public $name = 'mbk-forgot-password';

	public $icon = 'ti-key';

	public function get_label() {
		return esc_html__( 'Forgotten password', 'marclie-booking' );
	}

	public function set_controls() {}

	protected function markup(): string {
		return AccountPages::forgotPassword( Preview::active() );
	}
}
