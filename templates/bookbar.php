<?php
/**
 * Mobile sticky bar mirroring the bookbox price/CTA (mockup div.w-unit-body__bar, after .w-unit-body__grid).
 *
 * Override: {child-theme}/marclie-booking/bookbar.php
 *
 * @var array<string, mixed> $args
 */

defined( 'ABSPATH' ) || exit;

$price = $args['price'];
?>
<div class="w-unit-body__bar brxe-block"<?php echo $args['lang_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::langAttr(). ?> data-mbk-widget data-mbk-bookbar="<?php echo esc_attr( (string) $args['key'] ); ?>">
	<p class="w-unit-body__bar-price" data-bookbar-text><?php echo esc_html( (string) $price ); ?></p>
	<a class="w-button-primary w-button-primary--small" href="#rezerviraj"><?php esc_html_e( 'Book now', 'marclie-booking' ); ?></a>
</div>
