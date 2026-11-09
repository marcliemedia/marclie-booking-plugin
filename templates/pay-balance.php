<?php
/**
 * Balance payment page (/rezervacija/placanje/?ref&t): outstanding amount + Stripe Payment Element
 * (clientSecret from POST /bookings/{ref}/balance-intent), filled by pay-balance.js.
 *
 * No mockup page: composed from the checkout payment step (`rezervacija-apartman-1.html`
 * `.w-checkout`: card group, secure line, nav, summary price lines) and the unavailable/thanks cards for
 * the other states. The page document supplies section.w-booking-checkout > div.w-booking-checkout__container.
 *
 * Override: {child-theme}/marclie-booking/pay-balance.php
 *
 * @var array<string, mixed> $args
 */

defined( 'ABSPATH' ) || exit;

$phone  = (string) $args['phone'];
$tel    = preg_replace( '/[^\d+]/', '', $phone );
$callUs = '' === $phone ? '' : sprintf(
	/* translators: %s: phone number link. */
	esc_html__( 'Call us on %s.', 'marclie-booking' ),
	'<a href="tel:' . esc_attr( $tel ) . '">' . esc_html( $phone ) . '</a>'
);
$panel = static function ( string $state, string $icon, string $title, string $text, string $extra = '', string $role = '' ) use ( $callUs ): void {
	?>
	<div class="w-checkout__form w-checkout__form--unavailable" data-pb-state="<?php echo esc_attr( $state ); ?>"<?php echo '' !== $role ? ' role="' . esc_attr( $role ) . '"' : ''; ?> hidden>
		<?php if ( 'spinner' === $icon ) : ?>
			<span class="mbk-spinner" aria-hidden="true"></span>
		<?php else : ?>
			<span class="w-checkout__done-icon<?php echo 'check' === $icon ? '' : ' w-checkout__done-icon--off'; ?>" aria-hidden="true"><i data-icon="<?php echo esc_attr( $icon ); ?>"></i></span>
		<?php endif; ?>
		<h2 class="w-checkout__unavailable-title" data-state-title><?php echo esc_html( $title ); ?></h2>
		<p class="w-checkout__unavailable-text"><?php echo esc_html( $text ); ?><?php echo 'call' === $extra && '' !== $callUs ? ' ' . $callUs : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></p>
		<?php if ( 'reload' === $extra ) : ?>
			<div class="w-checkout__done-actions"><button class="w-button-primary w-button-primary--large" type="button" data-pb-reload><?php esc_html_e( 'Try again', 'marclie-booking' ); ?></button></div>
		<?php endif; ?>
	</div>
	<?php
};
?>
<div class="w-checkout w-checkout--summary-last"<?php echo $args['lang_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::langAttr(). ?> data-mbk-widget data-mbk-pay-balance>
	<div class="w-checkout__form w-checkout__form--unavailable" data-pb-state="loading" role="status">
		<span class="mbk-spinner" aria-hidden="true"></span>
		<p class="w-checkout__unavailable-text"><?php esc_html_e( 'Loading your booking…', 'marclie-booking' ); ?></p>
	</div>
	<?php
	$panel( 'processing', 'spinner', __( 'We are confirming your payment', 'marclie-booking' ), __( 'This usually takes a few seconds. Please keep this page open.', 'marclie-booking' ), '', 'status' );
	$panel( 'timeout', 'clock', __( 'Your payment is still being processed', 'marclie-booking' ), __( 'We will email you the receipt as soon as the payment is confirmed. You can also reload this page in a minute.', 'marclie-booking' ), '', 'status' );
	$panel( 'error', 'info', __( 'We could not load your booking', 'marclie-booking' ), __( 'Please check your connection and try again.', 'marclie-booking' ), 'reload', 'alert' );
	$panel( 'closed', 'x', __( 'This booking cannot be paid online', 'marclie-booking' ), __( 'The booking is no longer open for payment, or another payment for it is in progress.', 'marclie-booking' ), 'call' );
	$panel( 'invalid', 'info', __( 'We could not open this booking', 'marclie-booking' ), __( 'The link is incomplete or no longer valid. Please use the link from your email.', 'marclie-booking' ), 'call' );
	$panel( 'nothing', 'check', __( 'Nothing left to pay', 'marclie-booking' ), __( 'This booking has no outstanding amount. Thank you!', 'marclie-booking' ) );
	$panel( 'success', 'check', __( 'Thank you, the balance is paid', 'marclie-booking' ), __( 'We will email you the receipt. See you soon!', 'marclie-booking' ), '', 'status' );
	?>

	<form class="w-checkout__form" method="post" action="#" novalidate data-pb-form data-pb-state="form" hidden>
		<header class="w-checkout__head">
			<p class="w-eyebrow"><?php esc_html_e( 'Balance payment', 'marclie-booking' ); ?></p>
			<h2 class="w-checkout__heading" data-state-title tabindex="-1"><?php esc_html_e( 'Pay the balance of your booking', 'marclie-booking' ); ?></h2>
			<p class="w-checkout__intro"><?php esc_html_e( 'Pay the outstanding amount of your booking by card. The payment is confirmed immediately.', 'marclie-booking' ); ?></p>
		</header>
		<fieldset class="w-checkout__group" data-pb-pay<?php echo $args['stripe'] ? '' : ' hidden'; ?>>
			<legend class="w-checkout__legend"><i data-icon="credit-card" class="w-icon--small"></i> <span class="w-checkout__legend-text"><?php esc_html_e( 'Card details', 'marclie-booking' ); ?></span></legend>
			<div data-pb-element data-stripe-element></div>
			<p class="w-checkout__secure"><i data-icon="lock-key" class="w-icon--small"></i> <span class="w-checkout__secure-text"><?php esc_html_e( 'Your card details travel over an encrypted connection.', 'marclie-booking' ); ?></span></p>
		</fieldset>
		<p class="w-note" data-pb-offline role="status"<?php echo $args['stripe'] ? ' hidden' : ''; ?>><strong class="w-note__title"><?php esc_html_e( 'Online payment is currently unavailable.', 'marclie-booking' ); ?></strong><?php echo '' !== $callUs ? ' <span class="w-note__text">' . $callUs . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></p>
		<p class="w-note" data-pb-alert role="alert" hidden></p>
		<div class="w-checkout__nav">
			<div class="w-checkout__back"></div>
			<button class="w-button-primary w-button-primary--large" type="submit" data-pb-submit disabled><i data-icon="lock-key" class="w-icon--small"></i> <span class="w-button-primary__label" data-pb-submit-label><?php esc_html_e( 'Pay now', 'marclie-booking' ); ?></span></button>
		</div>
	</form>

	<aside class="w-checkout__summary" aria-label="<?php esc_attr_e( 'Your stay', 'marclie-booking' ); ?>" data-pb-state="form" hidden>
		<div class="w-checkout__stay">
			<p class="w-checkout__stay-title" data-pb="unit"></p>
		</div>
		<dl class="w-checkout__done-facts">
			<div class="w-checkout__fact"><dt class="w-checkout__fact-term"><?php esc_html_e( 'Booking reference', 'marclie-booking' ); ?></dt><dd class="w-checkout__fact-value" data-pb="ref"></dd></div>
			<div class="w-checkout__fact"><dt class="w-checkout__fact-term"><?php esc_html_e( 'Dates', 'marclie-booking' ); ?></dt><dd class="w-checkout__fact-value" data-pb="dates"></dd></div>
		</dl>
		<dl class="w-checkout__price">
			<div class="w-checkout__line w-checkout__line--total"><dt class="w-checkout__price-term"><?php esc_html_e( 'Total', 'marclie-booking' ); ?></dt><dd class="w-checkout__price-value" data-pb="total"></dd></div>
			<div class="w-checkout__line"><dt class="w-checkout__price-term"><?php esc_html_e( 'Paid so far', 'marclie-booking' ); ?></dt><dd class="w-checkout__price-value" data-pb="paid"></dd></div>
			<div class="w-checkout__line w-checkout__line--due"><dt class="w-checkout__price-term"><?php esc_html_e( 'Outstanding', 'marclie-booking' ); ?></dt><dd class="w-checkout__price-value" data-pb="balance"></dd></div>
		</dl>
	</aside>
</div>
