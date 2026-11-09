<?php
/**
 * Thank-you page (/rezervacija/hvala/?ref&t): state panels filled by confirmation.js from
 * GET /bookings/{ref}?t= — loading, processing (3-D Secure / webhook pending, polled),
 * done, failed, timeout, invalid link. No booking data is rendered server-side.
 *
 * Markup = mockup `rezervacija-hvala-apartman-1.html` `.w-booking-thanks__container > .w-checkout`
 * (done = thanks card + summary); the other states reuse the unavailable card
 * (`w-checkout__form--unavailable`, mockup `rezervacija-apartman-102.html`). The page document supplies
 * section.w-booking-thanks > div.w-booking-thanks__container (+ breadcrumb).
 *
 * Override: {child-theme}/marclie-booking/confirmation.php
 *
 * @var array<string, mixed> $args
 */

defined( 'ABSPATH' ) || exit;

$phone  = (string) $args['phone'];
$tel    = preg_replace( '/[^\d+]/', '', $phone );
$callUs = '' === $phone ? '' : sprintf(
	/* translators: %s: phone number link. */
	esc_html__( 'Questions? Call us on %s.', 'marclie-booking' ),
	'<a href="tel:' . esc_attr( $tel ) . '">' . esc_html( $phone ) . '</a>'
);
$step = static function ( int $n, string $title, string $sub ): void {
	?>
	<li class="w-checkout__step is-done"<?php echo 3 === $n ? ' aria-current="step"' : ''; ?>><span class="w-checkout__step-badge" aria-hidden="true"><span class="w-checkout__step-number"><?php echo esc_html( (string) $n ); ?></span><i data-icon="check" class="w-checkout__step-check"></i></span><span class="w-checkout__step-text"><span class="w-checkout__step-title"><?php echo esc_html( $title ); ?></span><span class="w-checkout__step-subtitle"><?php echo esc_html( $sub ); ?></span></span></li>
	<?php
};
?>
<div class="w-checkout w-checkout--summary-last"<?php echo $args['lang_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::langAttr(). ?> data-mbk-widget data-mbk-confirmation data-guide="<?php echo esc_url( $args['guide'] ); ?>">
	<div class="w-checkout__form w-checkout__form--unavailable" data-cf-state="loading" role="status">
		<span class="mbk-spinner" aria-hidden="true"></span>
		<p class="w-checkout__unavailable-text"><?php esc_html_e( 'Loading your booking…', 'marclie-booking' ); ?></p>
	</div>

	<div class="w-checkout__form w-checkout__form--unavailable" data-cf-state="processing" role="status" hidden>
		<span class="mbk-spinner" aria-hidden="true"></span>
		<h2 class="w-checkout__unavailable-title" data-state-title><?php esc_html_e( 'We are confirming your payment', 'marclie-booking' ); ?></h2>
		<p class="w-checkout__unavailable-text"><?php esc_html_e( 'This usually takes a few seconds. Please keep this page open.', 'marclie-booking' ); ?></p>
	</div>

	<div class="w-checkout__form w-checkout__form--unavailable" data-cf-state="timeout" role="status" hidden>
		<span class="w-checkout__done-icon w-checkout__done-icon--off" aria-hidden="true"><i data-icon="clock"></i></span>
		<h2 class="w-checkout__unavailable-title" data-state-title><?php esc_html_e( 'Your payment is still being processed', 'marclie-booking' ); ?></h2>
		<p class="w-checkout__unavailable-text"><?php esc_html_e( 'We will email you the confirmation as soon as the payment is confirmed. You can also reload this page in a minute.', 'marclie-booking' ); ?></p>
	</div>

	<div class="w-checkout__form w-checkout__form--unavailable" data-cf-state="failed" hidden>
		<span class="w-checkout__done-icon w-checkout__done-icon--off" aria-hidden="true"><i data-icon="x"></i></span>
		<h2 class="w-checkout__unavailable-title" data-state-title><?php esc_html_e( 'The payment did not go through', 'marclie-booking' ); ?></h2>
		<p class="w-checkout__unavailable-text"><?php esc_html_e( 'Your card was not charged and the dates are not booked yet. Try again with the same or another card.', 'marclie-booking' ); ?></p>
		<div class="w-checkout__done-actions">
			<a class="w-button-primary w-button-primary--large" href="<?php echo esc_url( $args['checkout'] ); ?>" data-cf-retry><?php esc_html_e( 'Try the payment again', 'marclie-booking' ); ?></a>
		</div>
	</div>

	<div class="w-checkout__form w-checkout__form--unavailable" data-cf-state="hold_lost" hidden>
		<span class="w-checkout__done-icon w-checkout__done-icon--off" aria-hidden="true"><i data-icon="calendar-blank"></i></span>
		<h2 class="w-checkout__unavailable-title" data-state-title><?php esc_html_e( 'We are sorry, these dates were booked in the meantime', 'marclie-booking' ); ?></h2>
		<p class="w-checkout__unavailable-text"><?php esc_html_e( 'Your payment arrived after the reservation of the dates had expired, and someone else booked them in the meantime. We have refunded the full amount to your card; depending on your bank it shows within 5–10 days.', 'marclie-booking' ); ?></p>
		<div class="w-checkout__done-actions">
			<a class="w-button-primary w-button-primary--large" href="<?php echo esc_url( $args['search'] ); ?>" data-cf-search><?php esc_html_e( 'Apartments free on these dates', 'marclie-booking' ); ?></a>
		</div>
		<?php if ( '' !== $callUs ) : ?>
			<p class="w-checkout__unavailable-note"><?php echo $callUs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></p>
		<?php endif; ?>
	</div>

	<div class="w-checkout__form w-checkout__form--unavailable" data-cf-state="cancelled" hidden>
		<span class="w-checkout__done-icon w-checkout__done-icon--off" aria-hidden="true"><i data-icon="x"></i></span>
		<h2 class="w-checkout__unavailable-title" data-state-title><?php esc_html_e( 'This booking has been cancelled', 'marclie-booking' ); ?></h2>
		<p class="w-checkout__unavailable-text">
			<span data-cf="cancelled-refund" hidden><?php esc_html_e( 'The refund goes back to your card; depending on your bank it shows within 5–10 days.', 'marclie-booking' ); ?></span>
			<?php echo $callUs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
		</p>
	</div>

	<div class="w-checkout__form w-checkout__form--unavailable" data-cf-state="error" role="alert" hidden>
		<span class="w-checkout__done-icon w-checkout__done-icon--off" aria-hidden="true"><i data-icon="info"></i></span>
		<h2 class="w-checkout__unavailable-title" data-state-title><?php esc_html_e( 'We could not load your booking', 'marclie-booking' ); ?></h2>
		<p class="w-checkout__unavailable-text"><?php esc_html_e( 'Please check your connection and try again.', 'marclie-booking' ); ?></p>
		<div class="w-checkout__done-actions"><button class="w-button-primary w-button-primary--large" type="button" data-cf-reload><?php esc_html_e( 'Try again', 'marclie-booking' ); ?></button></div>
	</div>

	<div class="w-checkout__form w-checkout__form--unavailable" data-cf-state="invalid" hidden>
		<span class="w-checkout__done-icon w-checkout__done-icon--off" aria-hidden="true"><i data-icon="info"></i></span>
		<h2 class="w-checkout__unavailable-title" data-state-title><?php esc_html_e( 'We could not open this booking', 'marclie-booking' ); ?></h2>
		<p class="w-checkout__unavailable-text"><?php esc_html_e( 'The link is incomplete or no longer valid. Please use the link from your confirmation email.', 'marclie-booking' ); ?> <?php echo $callUs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></p>
	</div>

	<div class="w-checkout__form w-checkout__form--thanks" data-cf-state="done" hidden>
		<ol class="w-checkout__steps" aria-label="<?php esc_attr_e( 'Booking steps', 'marclie-booking' ); ?>">
			<?php
			$step( 1, __( 'Your details', 'marclie-booking' ), __( 'Guests and arrival', 'marclie-booking' ) );
			$step( 2, __( 'Payment', 'marclie-booking' ), __( 'Deposit and terms', 'marclie-booking' ) );
			$step( 3, __( 'Confirmation', 'marclie-booking' ), __( 'Booking received', 'marclie-booking' ) );
			?>
		</ol>
		<div class="w-checkout__done-top">
			<span class="w-checkout__done-icon" aria-hidden="true"><i data-icon="check"></i></span>
			<header class="w-checkout__head">
				<p class="w-eyebrow"><?php esc_html_e( 'Booking received', 'marclie-booking' ); ?></p>
				<h2 class="w-checkout__title" data-state-title tabindex="-1"><?php esc_html_e( 'Thank you, your stay is booked', 'marclie-booking' ); ?></h2>
				<p class="w-checkout__intro" data-cf="intro"></p>
			</header>
		</div>
		<dl class="w-checkout__done-facts">
			<div class="w-checkout__fact"><dt class="w-checkout__fact-term"><?php esc_html_e( 'Booking reference', 'marclie-booking' ); ?></dt><dd class="w-checkout__fact-value" data-cf="ref"></dd></div>
			<div class="w-checkout__fact"><dt class="w-checkout__fact-term"><?php esc_html_e( 'Dates', 'marclie-booking' ); ?></dt><dd class="w-checkout__fact-value" data-cf="dates"></dd></div>
			<div class="w-checkout__fact"><dt class="w-checkout__fact-term"><?php esc_html_e( 'Guests', 'marclie-booking' ); ?></dt><dd class="w-checkout__fact-value" data-cf="guests"></dd></div>
			<div class="w-checkout__fact"><dt class="w-checkout__fact-term"><?php esc_html_e( 'Check-in', 'marclie-booking' ); ?></dt><dd class="w-checkout__fact-value" data-cf="checkin"></dd></div>
			<div class="w-checkout__fact"><dt class="w-checkout__fact-term" data-cf="paid-label"><?php esc_html_e( 'Paid today', 'marclie-booking' ); ?></dt><dd class="w-checkout__fact-value" data-cf="paid"></dd></div>
			<div class="w-checkout__fact" data-cf-balance><dt class="w-checkout__fact-term"><?php esc_html_e( 'Balance', 'marclie-booking' ); ?></dt><dd class="w-checkout__fact-value" data-cf="balance"></dd></div>
		</dl>
		<h2 class="w-checkout__thanks-subtitle"><?php esc_html_e( 'What happens next', 'marclie-booking' ); ?></h2>
		<ol class="w-checkout__next-steps">
			<li class="w-checkout__next-step"><strong><?php esc_html_e( 'Confirmation email.', 'marclie-booking' ); ?></strong> <?php esc_html_e( 'Your booking reference and the amounts are in your inbox now.', 'marclie-booking' ); ?></li>
			<li class="w-checkout__next-step" data-cf-balance><strong data-cf="next-balance-title"></strong> <span data-cf="next-balance"></span></li>
			<li class="w-checkout__next-step"><strong><?php esc_html_e( 'Directions.', 'marclie-booking' ); ?></strong> <?php esc_html_e( 'Before your arrival we email you the way to us, step by step.', 'marclie-booking' ); ?></li>
			<li class="w-checkout__next-step"><strong><?php esc_html_e( 'Arrival.', 'marclie-booking' ); ?></strong> <?php esc_html_e( 'Please bring an ID card or passport for every guest.', 'marclie-booking' ); ?></li>
		</ol>
		<div class="w-note" data-cf-account="invite" hidden>
			<strong class="w-note__title"><?php esc_html_e( 'Keep your bookings in one place', 'marclie-booking' ); ?></strong>
			<span class="w-note__text"><?php esc_html_e( 'Create an account with this email address: you see this booking and its payments at any time, and you get a welcome coupon for your next stay.', 'marclie-booking' ); ?>
			<a href="<?php echo esc_url( $args['register'] ); ?>" data-cf-register><?php esc_html_e( 'Create an account', 'marclie-booking' ); ?></a></span>
		</div>
		<div class="w-checkout__done-actions">
			<a class="w-button-primary w-button-primary--large" href="<?php echo esc_url( $args['account'] ); ?>" data-cf-account="linked" hidden><span class="w-button-primary__label"><?php esc_html_e( 'Go to my account', 'marclie-booking' ); ?></span> <i data-icon="arrow-right" class="w-icon--small"></i></a>
			<?php if ( '' !== $args['guide'] ) : ?>
				<a class="w-button-secondary w-button-secondary--large" href="<?php echo esc_url( $args['guide'] ); ?>"><?php esc_html_e( 'Getting here', 'marclie-booking' ); ?></a>
			<?php endif; ?>
			<a class="w-checkout__back-link w-button-text w-button-text--back" href="#" data-cf-back><i data-icon="arrow-left" class="w-icon--small w-button-text__icon"></i> <span class="w-checkout__back-link-text" data-cf="back"></span></a>
		</div>
	</div>

	<aside class="w-checkout__summary" aria-label="<?php esc_attr_e( 'Your stay', 'marclie-booking' ); ?>" data-cf-state="done" hidden>
		<div class="w-checkout__media"><div class="w-photo w-photo--4x3 w-photo--image"><img class="w-photo__image" data-cf-thumb alt="" decoding="async" hidden></div><span class="w-checkout__badge" data-cf="house" hidden></span></div>
		<div class="w-checkout__stay">
			<p class="w-checkout__stay-title" data-cf="title"></p>
			<p class="w-checkout__stay-location" data-cf-place><i data-icon="map-pin" class="w-icon--small"></i> <span class="w-checkout__stay-location-text" data-cf="place"></span></p>
		</div>
		<div class="w-checkout__dates">
			<div class="w-checkout__date"><span class="w-checkout__date-label"><?php esc_html_e( 'Check-in', 'marclie-booking' ); ?></span><span class="w-checkout__date-day" data-cf="arrival"></span><span class="w-checkout__date-time" data-cf="checkin-window"></span></div>
			<span class="w-checkout__nights" data-cf="nights"></span>
			<div class="w-checkout__date w-checkout__date--end"><span class="w-checkout__date-label"><?php esc_html_e( 'Check-out', 'marclie-booking' ); ?></span><span class="w-checkout__date-day" data-cf="departure"></span><span class="w-checkout__date-time" data-cf="checkout-until"></span></div>
		</div>
		<dl class="w-checkout__price">
			<div class="w-checkout__line w-checkout__line--total"><dt class="w-checkout__price-term"><?php esc_html_e( 'Total', 'marclie-booking' ); ?></dt><dd class="w-checkout__price-value" data-cf="total"></dd></div>
			<div class="w-checkout__line w-checkout__line--due"><dt class="w-checkout__price-term" data-cf="paid-label"><?php esc_html_e( 'Paid today', 'marclie-booking' ); ?></dt><dd class="w-checkout__price-value" data-cf="paid-amount"></dd></div>
			<div class="w-checkout__line" data-cf-balance><dt class="w-checkout__price-term" data-cf="balance-label"></dt><dd class="w-checkout__price-value" data-cf="balance-amount"></dd></div>
		</dl>
		<div class="w-checkout__cancel-box">
			<i data-icon="shield-check"></i>
			<div class="w-checkout__cancel-body">
				<p class="w-checkout__cancel-title"><?php esc_html_e( 'Cancellation', 'marclie-booking' ); ?></p>
				<p class="w-checkout__cancel-text"><span data-cf="cancel"></span> <a href="<?php echo esc_url( $args['terms'] . '#otkazivanje' ); ?>"><?php esc_html_e( 'Booking terms', 'marclie-booking' ); ?></a></p>
			</div>
		</div>
	</aside>
</div>
