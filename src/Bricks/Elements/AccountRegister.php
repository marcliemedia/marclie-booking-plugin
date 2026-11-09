<?php
/**
 * Bricks element `mbk-register` (Registracija page): the `.w-auth-split__panel` form part (place it in the panel, after the breadcrumb) — heading, lede,
 * sign-up form (?email= prefills the address), log-in link. Builder: the form even for the editor.
 */

namespace Marclie\Booking\Bricks\Elements;

use Marclie\Booking\Bricks\Preview;
use Marclie\Booking\Frontend\AccountPages;

defined( 'ABSPATH' ) || exit;

final class AccountRegister extends BookingElement {

	public $name = 'mbk-register';

	public $icon = 'ti-id-badge';

	public function get_label() {
		return esc_html__( 'Account sign-up', 'marclie-booking' );
	}

	public function set_controls() {}

	protected function markup(): string {
		return AccountPages::register( Preview::active() );
	}
}
