<?php
/**
 * Bricks element `mbk-lang-switch`: HR / EN language menu of the booking UI (header top bar).
 * Give the element the global class `w-lang-switch`; the toggle and menu print their own
 * `w-lang-switch__*` classes (LanguageSwitcher::menu()). Not a booking widget: no plugin CSS
 * or JS (the dropdown opens with the site's header-dropdown engine).
 */

namespace Marclie\Booking\Bricks\Elements;

use Marclie\Booking\Frontend\LanguageSwitcher;

defined( 'ABSPATH' ) || exit;

final class LangSwitch extends BookingElement {

	public $name = 'mbk-lang-switch';

	public $icon = 'ti-world';

	public function get_label() {
		return esc_html__( 'Language switch (HR | EN)', 'marclie-booking' );
	}

	public function set_controls() {}

	public function render() {
		$this->set_attribute( '_root', 'translate', 'no' );
		$this->set_attribute( '_root', 'data-mbk-widget', '' );

		parent::render();
	}

	protected function markup(): string {
		return LanguageSwitcher::menu();
	}
}
