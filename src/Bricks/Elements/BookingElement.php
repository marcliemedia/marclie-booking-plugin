<?php
/**
 * Shared base of the Marclie Booking Bricks elements: panel category, unit-source controls
 * and the root wrapper. Elements only configure behaviour; all styling comes from Bricks
 * global classes named after the mockup's `w-` BEM classes the plugin markup prints
 * (`w-checkout__steps`, `w-card-stay`…). The root wrapper takes no box (mbk-base.css) unless
 * the page gives it a `w-` class. Rendered server-side in builder and frontend (no x-template).
 */

namespace Marclie\Booking\Bricks\Elements;

use Marclie\Booking\Bricks\Loader;
use Marclie\Booking\Bricks\Preview;

defined( 'ABSPATH' ) || exit;

abstract class BookingElement extends \Bricks\Element {

	public $category = Loader::CATEGORY;

	/** Widget markup for the current settings ('' when there is nothing to show). */
	abstract protected function markup(): string;

	public function render() {
		$html = $this->markup();

		if ( '' === $html ) {
			if ( ! $this->is_frontend ) {
				$this->render_element_placeholder( array( 'title' => $this->emptyMessage() ) );
			}
			return;
		}

		echo "<div {$this->render_attributes( '_root' )}>{$html}</div>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bricks attributes + escaped plugin templates.
	}

	/** Builder placeholder text when markup() is empty. */
	protected function emptyMessage(): string {
		return esc_html__( 'Nothing to show here.', 'marclie-booking' );
	}

	/** Unit source: the current `property` (single template / loop item) or a chosen one. */
	protected function unitControls(): void {
		$this->controls['unitSource'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Apartment', 'marclie-booking' ),
			'type'        => 'select',
			'options'     => array(
				'current'  => esc_html__( 'Current apartment', 'marclie-booking' ),
				'selected' => esc_html__( 'Selected apartment', 'marclie-booking' ),
			),
			'inline'      => true,
			'placeholder' => esc_html__( 'Current apartment', 'marclie-booking' ),
			'default'     => 'current',
		);

		$this->controls['unit'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Selected apartment', 'marclie-booking' ),
			'type'        => 'select',
			'optionsAjax' => array(
				'action'   => 'bricks_get_posts',
				'postType' => 'property',
			),
			'searchable'  => true,
			'placeholder' => esc_html__( 'Select apartment', 'marclie-booking' ),
			'required'    => array( 'unitSource', '=', 'selected' ),
		);
	}

	/**
	 * Unit reference for Renderer: the selected post ID, or '' (= current post). In the
	 * builder, when the previewed post is not a `property`, the first unit.
	 *
	 * @return int|string
	 */
	protected function unitRef() {
		$source = $this->settings['unitSource'] ?? 'current';
		if ( 'selected' === $source && ! empty( $this->settings['unit'] ) ) {
			return (int) $this->settings['unit'];
		}

		if ( Preview::active() && 'property' !== get_post_type( (int) get_the_ID() ) ) {
			$unit = Preview::sampleUnit();
			return null === $unit ? '' : $unit->id;
		}

		return '';
	}
}
