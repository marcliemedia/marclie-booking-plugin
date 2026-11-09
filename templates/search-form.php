<?php
/**
 * Availability search form (hero, header dialog, compact strip on /pretraga/ and /apartmani/). Always submits
 * to Pretraga. Markup = mockup `w-search-form` (Form: Search); the strip's lead line belongs to the page.
 *
 * Override: {child-theme}/marclie-booking/search-form.php
 *
 * @var array<string, mixed> $args
 */

defined( 'ABSPATH' ) || exit;

$p     = (string) $args['prefix'];
$class = trim( 'w-search-form ' . $args['modifier'] );
?>
<form class="<?php echo esc_attr( $class ); ?>" action="<?php echo esc_url( $args['action'] ); ?>" method="get"<?php echo $args['lang_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::langAttr(). ?> data-mbk-widget data-mbk-search-form<?php echo empty( $args['lazy'] ) ? '' : ' data-mbk-lazy'; ?>>
	<?php foreach ( $args['hidden'] as $name => $value ) : ?>
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
	<?php endforeach; ?>
	<div class="w-field w-field--flush"><label class="w-field__label" for="<?php echo esc_attr( $p ); ?>-dolazak"><?php esc_html_e( 'Check-in', 'marclie-booking' ); ?></label><input class="w-field__input" data-date-end="<?php echo esc_attr( $p ); ?>-odlazak" id="<?php echo esc_attr( $p ); ?>-dolazak" name="dolazak" required type="date" value="<?php echo esc_attr( (string) $args['arrival'] ); ?>"<?php echo 'dialog' === $args['variant'] ? ' autofocus' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal; mockup header dialog focuses check-in on open. ?>></div>
	<div class="w-field w-field--flush"><label class="w-field__label" for="<?php echo esc_attr( $p ); ?>-odlazak"><?php esc_html_e( 'Check-out', 'marclie-booking' ); ?></label><input class="w-field__input" id="<?php echo esc_attr( $p ); ?>-odlazak" name="odlazak" required type="date" value="<?php echo esc_attr( (string) $args['departure'] ); ?>"></div>
	<div class="w-field w-field--flush"><label class="w-field__label" for="<?php echo esc_attr( $p ); ?>-odrasli"><?php echo esc_html( \Marclie\Booking\Frontend\Renderer::adultsLabel() ); ?></label>
		<div class="w-stepper" data-qty><button aria-label="<?php esc_attr_e( 'One adult fewer', 'marclie-booking' ); ?>" class="w-stepper__button" data-step="-1" type="button"><i data-icon="minus" class="w-icon--small"></i></button><input class="w-stepper__input" id="<?php echo esc_attr( $p ); ?>-odrasli" inputmode="numeric" max="<?php echo esc_attr( (string) \Marclie\Booking\Frontend\Renderer::MAX_ADULTS ); ?>" min="1" name="odrasli" type="number" value="<?php echo esc_attr( (string) $args['adults'] ); ?>"><button aria-label="<?php esc_attr_e( 'One adult more', 'marclie-booking' ); ?>" class="w-stepper__button" data-step="1" type="button"><i data-icon="plus" class="w-icon--small"></i></button></div></div>
	<div class="w-field w-field--flush"><label class="w-field__label" for="<?php echo esc_attr( $p ); ?>-djeca"><?php echo esc_html( \Marclie\Booking\Frontend\Renderer::childrenLabel() ); ?></label>
		<div class="w-stepper" data-qty><button aria-label="<?php esc_attr_e( 'One child fewer', 'marclie-booking' ); ?>" class="w-stepper__button" data-step="-1" type="button"><i data-icon="minus" class="w-icon--small"></i></button><input class="w-stepper__input" id="<?php echo esc_attr( $p ); ?>-djeca" inputmode="numeric" max="<?php echo esc_attr( (string) \Marclie\Booking\Frontend\Renderer::MAX_CHILDREN ); ?>" min="0" name="djeca" type="number" value="<?php echo esc_attr( (string) $args['children'] ); ?>"><button aria-label="<?php esc_attr_e( 'One child more', 'marclie-booking' ); ?>" class="w-stepper__button" data-step="1" type="button"><i data-icon="plus" class="w-icon--small"></i></button></div></div>
	<div class="w-search-form__actions"><button class="w-button-primary w-button-primary--field w-button-primary--large" type="submit"><?php esc_html_e( 'Check availability', 'marclie-booking' ); ?></button></div>
</form>
