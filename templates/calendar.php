<?php
/**
 * Inline availability calendar (#dostupnost) sharing its selection with the bookbox.
 * Markup = mockup unit page "Availability" block content (inside div.w-unit-body__block#dostupnost, after its
 * h2.w-unit-body__heading): nights line, calendar host, key. Three roots, each marked data-mbk-widget.
 *
 * Override: {child-theme}/marclie-booking/calendar.php
 *
 * @var array<string, mixed> $args
 */

defined( 'ABSPATH' ) || exit;

/** @var \Marclie\Booking\Units\Unit $unit */
$unit = $args['unit'];
$key  = (string) $args['key'];
$lang = $args['lang_attr']; // Renderer::langAttr(), pre-escaped.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $lang: Renderer::langAttr().
?>
<p class="w-unit-body__nights"<?php echo $lang; ?> data-mbk-widget><b class="w-unit-body__nights-title" data-nights-title="<?php echo esc_attr( $key ); ?>" data-name="<?php echo esc_attr( $unit->title ); ?>"><?php esc_html_e( 'Select your check-in date', 'marclie-booking' ); ?></b> <span class="w-unit-body__nights-range" data-nights-range="<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Add your travel dates', 'marclie-booking' ); ?></span></p>
<div class="w-unit-body__calendar"<?php echo $lang; ?> data-mbk-widget data-mbk-calendar data-date-inline="<?php echo esc_attr( $key ); ?>" data-unit="<?php echo esc_attr( (string) $unit->id ); ?>" data-months="<?php echo esc_attr( (string) $args['months'] ); ?>"<?php echo $args['view'] ? ' data-date-view="' . esc_attr( $args['view'] ) . '"' : ''; ?>></div>
<p class="w-unit-body__key"<?php echo $lang; ?> data-mbk-widget><span class="w-unit-body__key-item"><?php esc_html_e( 'Struck through: booked or closed', 'marclie-booking' ); ?></span><span class="w-unit-body__key-item"><?php esc_html_e( 'Pick your check-in, then your check-out; the booking box follows', 'marclie-booking' ); ?></span></p>
<?php
// phpcs:enable
