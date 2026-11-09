<?php
/**
 * Bricks element `mbk-login` (Prijava page): the `.w-auth-split__panel` form part (place it in the panel, after the breadcrumb) — heading, lede, login
 * form, "Forgot your password?" disclosure, sign-up link. Builder: the form even for the editor.
 */

namespace Marclie\Booking\Bricks\Elements;

use Marclie\Booking\Bricks\Preview;
use Marclie\Booking\Frontend\AccountPages;

defined( 'ABSPATH' ) || exit;

final class AccountLogin extends BookingElement {

	public $name = 'mbk-login';

	public $icon = 'ti-unlock';

	public function get_label() {
		return esc_html__( 'Account log in', 'marclie-booking' );
	}

	public function set_controls() {}

	protected function markup(): string {
		return AccountPages::login( Preview::active() );
	}
}
