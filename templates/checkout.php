<?php
/**
 * Checkout (/rezervacija/?apartman&dolazak&odlazak&odrasli&djeca): two steps + aside summary.
 * States: `free` (wizard), `booked` (zauzeto, two CTAs), `dates` (inline picker), `no_unit`.
 * checkout.js re-quotes, validates, mounts the Stripe Payment Element and submits POST /bookings.
 *
 * Markup = mockup `rezervacija-apartman-1.html` `.w-booking-checkout__container > .w-checkout` (booked:
 * `rezervacija-apartman-102.html`). The page document supplies section.w-booking-checkout >
 * div.w-booking-checkout__container, the page title (h1 + optional [data-co-lede="free|booked"] ledes)
 * and the help line.
 *
 * Override: {child-theme}/marclie-booking/checkout.php
 *
 * @var array<string, mixed> $args
 */

defined( 'ABSPATH' ) || exit;

$state = (string) $args['state'];

if ( 'no_unit' === $state ) : ?>
<div class="w-checkout"<?php echo $args['lang_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::langAttr(). ?> data-mbk-widget data-mbk-checkout data-state="no_unit">
	<div class="w-checkout__form w-checkout__form--unavailable">
		<span class="w-checkout__done-icon w-checkout__done-icon--off" aria-hidden="true"><i data-icon="house"></i></span>
		<h2 class="w-checkout__unavailable-title"><?php esc_html_e( 'Choose an apartment first', 'marclie-booking' ); ?></h2>
		<p class="w-checkout__unavailable-text"><?php esc_html_e( 'This booking link is incomplete. Find a free apartment for your dates and book it from there.', 'marclie-booking' ); ?></p>
		<div class="w-checkout__done-actions">
			<a class="w-button-primary w-button-primary--large" href="<?php echo esc_url( $args['search'] ); ?>"><?php esc_html_e( 'Find a free apartment', 'marclie-booking' ); ?></a>
		</div>
	</div>
</div>
	<?php
	return;
endif;

/** @var \Marclie\Booking\Units\Unit $unit */
$unit   = $args['unit'];
$card   = $args['card'];
$policy = $args['policy'];
$free   = 'free' === $state;
$nights = (int) $args['nights'];
$name   = $unit->title;
$phone  = (string) $args['phone'];
$tel    = preg_replace( '/[^\d+]/', '', $phone );
$link   = static fn( string $url, string $text, string $class = '' ): string => '<a' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . ' href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>';
$hide   = static fn( bool $hidden ): string => $hidden ? ' hidden' : '';
?>
<div class="w-checkout"<?php echo $args['lang_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::langAttr(). ?> data-mbk-widget data-mbk-checkout
	data-unit="<?php echo esc_attr( (string) $unit->id ); ?>"
	data-name="<?php echo esc_attr( $name ); ?>"
	data-code="<?php echo esc_attr( $unit->code ); ?>"
	data-deposit-pct="<?php echo esc_attr( \Marclie\Booking\I18n\Formatter::percent( (int) $policy->depositPct, (string) $args['lang'] ) ); ?>"
	data-state="<?php echo esc_attr( $state ); ?>"
	data-slug="<?php echo esc_attr( $args['slug'] ); ?>"
	data-arrival="<?php echo esc_attr( $args['arrival'] ); ?>"
	data-departure="<?php echo esc_attr( $args['departure'] ); ?>"
	data-checkout-url="<?php echo esc_url( $args['checkout_url'] ); ?>">
	<?php if ( 'dates' === $state ) : ?>
		<div class="w-checkout__form" data-co-pick>
			<header class="w-checkout__head">
				<h2 class="w-checkout__heading"><?php esc_html_e( 'Choose your dates', 'marclie-booking' ); ?></h2>
				<p class="w-checkout__intro"><?php esc_html_e( 'Pick your check-in, then your check-out. Struck-through days are booked or closed.', 'marclie-booking' ); ?></p>
			</header>
			<?php if ( '' !== $args['notice'] ) : ?>
				<p class="w-note" role="alert"><?php echo esc_html( $args['notice'] ); ?></p>
			<?php endif; ?>
			<p class="w-unit-body__nights"><b class="w-unit-body__nights-title" data-nights-title="co-dates" data-name="<?php echo esc_attr( $name ); ?>"><?php esc_html_e( 'Select your check-in date', 'marclie-booking' ); ?></b> <span class="w-unit-body__nights-range" data-nights-range="co-dates"><?php esc_html_e( 'Add your travel dates', 'marclie-booking' ); ?></span></p>
			<div class="w-unit-body__calendar" data-mbk-calendar data-date-inline="co-dates" data-unit="<?php echo esc_attr( (string) $unit->id ); ?>" data-months="2"<?php echo $args['view'] ? ' data-date-view="' . esc_attr( $args['view'] ) . '"' : ''; ?>></div>
			<div class="w-checkout__nav">
				<div class="w-checkout__back"></div>
				<button class="w-button-primary w-button-primary--large" type="button" data-co-dates-go disabled><?php echo esc_html_x( 'Continue', 'guest booking flow button', 'marclie-booking' ); ?></button>
			</div>
		</div>
	<?php else : ?>
		<form class="w-checkout__form" method="post" action="#" novalidate data-co-form<?php echo $hide( ! $free ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal. ?>>
			<ol class="w-checkout__steps" aria-label="<?php esc_attr_e( 'Booking steps', 'marclie-booking' ); ?>">
				<li class="w-checkout__step is-active" data-co-step aria-current="step"><span class="w-checkout__step-badge" aria-hidden="true"><span class="w-checkout__step-number">1</span><i data-icon="check" class="w-checkout__step-check"></i></span><span class="w-checkout__step-text"><span class="w-checkout__step-title"><?php esc_html_e( 'Your details', 'marclie-booking' ); ?></span></span></li>
				<li class="w-checkout__step" data-co-step><span class="w-checkout__step-badge" aria-hidden="true"><span class="w-checkout__step-number">2</span><i data-icon="check" class="w-checkout__step-check"></i></span><span class="w-checkout__step-text"><span class="w-checkout__step-title"><?php esc_html_e( 'Payment', 'marclie-booking' ); ?></span></span></li>
				<li class="w-checkout__step"><span class="w-checkout__step-badge" aria-hidden="true"><span class="w-checkout__step-number">3</span><i data-icon="check" class="w-checkout__step-check"></i></span><span class="w-checkout__step-text"><span class="w-checkout__step-title"><?php esc_html_e( 'Confirmation', 'marclie-booking' ); ?></span></span></li>
			</ol>

			<p class="w-note" data-co-offline role="status"<?php echo $hide( (bool) $args['stripe'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal. ?>><strong class="w-note__title"><?php esc_html_e( 'Online payment is currently unavailable.', 'marclie-booking' ); ?></strong>
				<?php if ( '' !== $phone ) : ?>
					<span class="w-note__text">
						<?php
						printf(
							/* translators: %s: phone number link. */
							esc_html__( 'Call us on %s and we will complete your booking by phone.', 'marclie-booking' ),
							'<a href="tel:' . esc_attr( $tel ) . '">' . esc_html( $phone ) . '</a>'
						);
						?>
					</span>
				<?php endif; ?>
			</p>

			<div class="w-checkout__panel" data-co-panel>
				<header class="w-checkout__head">
					<h2 class="w-checkout__heading" tabindex="-1"><?php esc_html_e( 'Enter your details', 'marclie-booking' ); ?></h2>
					<?php if ( '' !== $args['login_url'] ) : ?>
						<p class="w-checkout__intro">
							<?php
							printf(
								/* translators: %s: "Log in" link. */
								esc_html__( 'Have an account? %s and we fill in your details.', 'marclie-booking' ),
								'<a href="' . esc_url( $args['login_url'] ) . '" data-mbk-login-link>' . esc_html_x( 'Log in', 'checkout: link to the login page', 'marclie-booking' ) . '</a>'
							);
							?>
						</p>
					<?php elseif ( $args['profile'] ) : ?>
						<p class="w-checkout__intro"><?php esc_html_e( 'We have filled in your details from your account.', 'marclie-booking' ); ?></p>
					<?php endif; ?>
				</header>
				<fieldset class="w-checkout__group">
					<legend class="w-checkout__legend"><i data-icon="user" class="w-icon--small"></i> <span class="w-checkout__legend-text"><?php esc_html_e( 'Lead guest', 'marclie-booking' ); ?></span></legend>
					<div class="w-checkout__row">
						<div class="w-field w-field--loose" data-field-box><label class="w-field__label" for="b-first"><?php esc_html_e( 'First name', 'marclie-booking' ); ?></label><input class="w-field__input" id="b-first" name="ime" type="text" autocomplete="given-name" maxlength="100" value="<?php echo esc_attr( $args['profile']['first_name'] ?? '' ); ?>" required data-field="guest.first_name"></div>
						<div class="w-field w-field--loose" data-field-box><label class="w-field__label" for="b-last"><?php esc_html_e( 'Last name', 'marclie-booking' ); ?></label><input class="w-field__input" id="b-last" name="prezime" type="text" autocomplete="family-name" maxlength="100" value="<?php echo esc_attr( $args['profile']['last_name'] ?? '' ); ?>" required data-field="guest.last_name"></div>
					</div>
					<div class="w-checkout__row">
						<div class="w-field w-field--loose" data-field-box><label class="w-field__label" for="b-email"><?php esc_html_e( 'Email', 'marclie-booking' ); ?></label><input class="w-field__input" id="b-email" name="email" type="email" autocomplete="email" maxlength="190" value="<?php echo esc_attr( $args['profile']['email'] ?? '' ); ?>" required aria-describedby="b-email-hint" data-field="guest.email"><span class="w-field__hint" id="b-email-hint"><?php esc_html_e( 'We send the booking confirmation here.', 'marclie-booking' ); ?></span></div>
						<div class="w-field w-field--loose" data-field-box><label class="w-field__label" for="b-phone"><?php esc_html_e( 'Phone', 'marclie-booking' ); ?></label><input class="w-field__input" id="b-phone" name="telefon" type="tel" autocomplete="tel" maxlength="40" value="<?php echo esc_attr( $args['profile']['phone'] ?? '' ); ?>" required data-field="guest.phone"></div>
					</div>
				</fieldset>
				<fieldset class="w-checkout__group">
					<legend class="w-checkout__legend"><i data-icon="house" class="w-icon--small"></i> <span class="w-checkout__legend-text"><?php esc_html_e( 'Your stay', 'marclie-booking' ); ?></span></legend>
					<div class="w-checkout__row">
						<div class="w-field w-field--loose" data-field-box><label class="w-field__label" for="b-adults"><?php echo esc_html( \Marclie\Booking\Frontend\Renderer::adultsLabel() ); ?></label>
							<div class="w-stepper" data-qty>
								<button class="w-stepper__button" type="button" data-step="-1" aria-label="<?php esc_attr_e( 'One adult fewer', 'marclie-booking' ); ?>"><i data-icon="minus" class="w-icon--small"></i></button>
								<input class="w-stepper__input" id="b-adults" name="odrasli" type="number" inputmode="numeric" min="1" max="<?php echo esc_attr( (string) $args['cap'] ); ?>" value="<?php echo esc_attr( (string) $args['adults'] ); ?>" data-cap="<?php echo esc_attr( (string) $args['cap'] ); ?>" required data-field="adults">
								<button class="w-stepper__button" type="button" data-step="1" aria-label="<?php esc_attr_e( 'One adult more', 'marclie-booking' ); ?>"><i data-icon="plus" class="w-icon--small"></i></button>
							</div>
							<span class="w-field__hint">
								<?php
								/* translators: %d: maximum number of guests. */
								echo esc_html( sprintf( _n( 'Sleeps up to %d', 'Sleeps up to %d', (int) $args['cap'], 'marclie-booking' ), (int) $args['cap'] ) . ' · ' . $args['rule'] );
								?>
							</span>
						</div>
						<div class="w-field w-field--loose" data-field-box><label class="w-field__label" for="b-children"><?php echo esc_html( \Marclie\Booking\Frontend\Renderer::childrenLabel() ); ?></label>
							<div class="w-stepper" data-qty>
								<button class="w-stepper__button" type="button" data-step="-1" aria-label="<?php esc_attr_e( 'One child fewer', 'marclie-booking' ); ?>"><i data-icon="minus" class="w-icon--small"></i></button>
								<input class="w-stepper__input" id="b-children" name="djeca" type="number" inputmode="numeric" min="0" max="<?php echo esc_attr( (string) $args['kids_max'] ); ?>" value="<?php echo esc_attr( (string) $args['children'] ); ?>" data-max="<?php echo esc_attr( (string) $args['kids_max'] ); ?>" data-field="children">
								<button class="w-stepper__button" type="button" data-step="1" aria-label="<?php esc_attr_e( 'One child more', 'marclie-booking' ); ?>"><i data-icon="plus" class="w-icon--small"></i></button>
							</div>
						</div>
					</div>
					<?php $infantsCount = \Marclie\Booking\Frontend\Renderer::infantsCountLabel(); ?>
					<div class="w-field w-field--loose">
						<label class="w-checkbox w-checkbox--flush"><input class="w-checkbox__input" type="checkbox" aria-controls="b-infants-box" data-mbk-reveal<?php checked( $args['infants'] > 0 ); ?>> <span class="w-checkbox__text"><?php echo esc_html( \Marclie\Booking\Frontend\Renderer::infantsToggleLabel() ); ?></span></label>
					</div>
					<div class="w-checkout__row">
						<div class="w-field w-field--loose" id="b-infants-box" data-field-box<?php echo $args['infants'] > 0 ? '' : ' hidden'; ?>><label class="w-field__label" for="b-infants"><?php echo esc_html( $infantsCount['visible'] ); ?> <span class="w-visually-hidden"><?php echo esc_html( $infantsCount['hidden'] ); ?></span></label>
							<div class="w-stepper" data-qty>
								<button class="w-stepper__button" type="button" data-step="-1" aria-label="<?php esc_attr_e( 'One young child fewer', 'marclie-booking' ); ?>"><i data-icon="minus" class="w-icon--small"></i></button>
								<input class="w-stepper__input" id="b-infants" name="bebe" type="number" inputmode="numeric" min="<?php echo $args['infants'] > 0 ? '1' : '0'; ?>" max="<?php echo esc_attr( (string) \Marclie\Booking\Frontend\Renderer::MAX_INFANTS ); ?>" value="<?php echo esc_attr( (string) $args['infants'] ); ?>" data-field="infants">
								<button class="w-stepper__button" type="button" data-step="1" aria-label="<?php esc_attr_e( 'One young child more', 'marclie-booking' ); ?>"><i data-icon="plus" class="w-icon--small"></i></button>
							</div>
							<span class="w-field__hint"><?php esc_html_e( 'Cot and high chair free on request', 'marclie-booking' ); ?></span>
						</div>
						<div class="w-field w-field--loose" data-field-box><label class="w-field__label" for="b-arrival"><?php esc_html_e( 'Expected arrival time', 'marclie-booking' ); ?> <span class="w-field__optional"><?php esc_html_e( '(optional)', 'marclie-booking' ); ?></span></label><input class="w-field__input" id="b-arrival" name="vrijeme-dolaska" type="time" step="900" min="<?php echo esc_attr( $unit->checkinFrom ); ?>" max="<?php echo esc_attr( $unit->checkinTo ); ?>" data-field="arrival_time"><span class="w-field__hint">
							<?php
							/* translators: %s: check-in window, e.g. "15:00–22:00". */
							echo esc_html( sprintf( __( 'Check-in %s', 'marclie-booking' ), $args['checkin_window'] ) );
							?>
						</span></div>
					</div>
					<div class="w-field w-field--loose" data-field-box><label class="w-field__label" for="b-msg"><?php esc_html_e( 'Message for us', 'marclie-booking' ); ?> <span class="w-field__optional"><?php esc_html_e( '(optional)', 'marclie-booking' ); ?></span></label><textarea class="w-field__textarea" id="b-msg" name="napomena" rows="3" maxlength="2000" data-field="message"></textarea></div>
				</fieldset>
			</div>

			<div class="w-checkout__panel" data-co-panel hidden>
				<header class="w-checkout__head">
					<h2 class="w-checkout__heading" tabindex="-1"><?php esc_html_e( 'Review and pay', 'marclie-booking' ); ?></h2>
				</header>
				<div class="w-checkout__recap">
					<div class="w-checkout__recap-head">
						<p class="w-checkout__legend"><i data-icon="user" class="w-icon--small"></i> <span class="w-checkout__legend-text"><?php esc_html_e( 'Booking for', 'marclie-booking' ); ?></span></p>
						<button class="w-checkout__edit" type="button" data-co-goto="0"><?php echo esc_html_x( 'Edit', 'guest booking flow button', 'marclie-booking' ); ?></button>
					</div>
					<dl class="w-checkout__recap-list">
						<?php
						// The party is listed in the stay summary next to it; the recap keeps the lead guest's details.
						$recap = array(
							array( __( 'Lead guest', 'marclie-booking' ), 'b-first b-last' ),
							array( __( 'Email', 'marclie-booking' ), 'b-email' ),
							array( __( 'Phone', 'marclie-booking' ), 'b-phone' ),
							array( __( 'Arrival time', 'marclie-booking' ), 'b-arrival' ),
						);
						foreach ( $recap as [ $term, $mirror ] ) :
							?>
							<div class="w-checkout__fact"><dt class="w-checkout__fact-term"><?php echo esc_html( $term ); ?></dt><dd class="w-checkout__fact-value" data-mirror="<?php echo esc_attr( $mirror ); ?>"></dd></div>
						<?php endforeach; ?>
					</dl>
				</div>
				<fieldset class="w-checkout__group" data-co-pay<?php echo $hide( ! $args['stripe'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal. ?>>
					<legend class="w-checkout__legend"><i data-icon="credit-card" class="w-icon--small"></i> <span class="w-checkout__legend-text"><?php esc_html_e( 'Card details', 'marclie-booking' ); ?></span></legend>
					<div data-co-element data-stripe-element></div>
					<p class="w-field__hint" data-field-error data-co-pay-error role="alert" hidden></p>
					<p class="w-checkout__secure"><i data-icon="lock-key" class="w-icon--small"></i> <span class="w-checkout__secure-text"><?php esc_html_e( 'Payment over an encrypted connection.', 'marclie-booking' ); ?></span></p>
				</fieldset>
				<p class="w-checkout__deposit" data-co-deposit aria-live="polite"></p>
				<label class="w-checkbox w-checkbox--flush" data-co-terms data-field-box><input class="w-checkbox__input" type="checkbox" name="uvjeti" value="1" required data-field="terms"> <span class="w-checkbox__text">
					<?php
					printf(
						/* translators: 1: "booking terms and house rules" link, 2: "privacy policy" link. */
						esc_html__( 'I accept the %1$s and the cancellation conditions, and I have read the %2$s.', 'marclie-booking' ),
						$link( $args['terms'], __( 'booking terms and house rules', 'marclie-booking' ), 'w-checkbox__link' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $link.
						$link( $args['privacy'], __( 'privacy policy', 'marclie-booking' ), 'w-checkbox__link' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $link.
					);
					?>
				</span></label>
			</div>

			<div class="mbk-hp" aria-hidden="true"><label for="b-website">Website</label><input id="b-website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
			<p class="w-note" data-co-alert role="alert" hidden></p>

			<div class="w-checkout__nav">
				<div class="w-checkout__back"><button class="w-button-secondary" type="button" data-co-prev hidden><i data-icon="arrow-left" class="w-icon--small"></i> <span class="w-button-secondary__label"><?php esc_html_e( 'Back', 'marclie-booking' ); ?></span></button></div>
				<button class="w-button-primary w-button-primary--large" type="button" data-co-next><span class="w-button-primary__label"><?php esc_html_e( 'Continue to payment', 'marclie-booking' ); ?></span> <i data-icon="arrow-right" class="w-icon--small"></i></button>
				<button class="w-button-primary w-button-primary--large" type="submit" data-co-submit hidden<?php echo $args['stripe'] ? '' : ' disabled'; ?>><i data-icon="lock-key" class="w-icon--small"></i> <span class="w-button-primary__label"><?php esc_html_e( 'Confirm and pay', 'marclie-booking' ); ?></span> <span class="w-checkout__amount" data-co-submit-amount></span></button>
			</div>
		</form>

		<div class="w-checkout__form w-checkout__form--unavailable" data-co-booked data-co-unavail<?php echo $hide( $free ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal. ?>>
			<span class="w-checkout__done-icon w-checkout__done-icon--off" aria-hidden="true"><i data-icon="calendar-blank"></i></span>
			<h2 class="w-checkout__unavailable-title" data-co-unavail-title><?php esc_html_e( 'These dates are already booked', 'marclie-booking' ); ?></h2>
			<p class="w-checkout__unavailable-text" data-co-unavail-text>
				<?php
				/* translators: 1: apartment name, 2: arrival date, 3: departure date. */
				echo esc_html( sprintf( __( '%1$s is already booked between %2$s and %3$s. Choose other dates in its calendar, or see which apartments are free on these dates.', 'marclie-booking' ), $name, $args['arrival_long'], $args['departure_long'] ) );
				?>
			</p>
			<div class="w-checkout__done-actions">
				<a class="w-button-primary w-button-primary--large" href="<?php echo esc_url( $args['dates_url'] ); ?>"><?php esc_html_e( 'Choose other dates', 'marclie-booking' ); ?></a>
				<a class="w-button-secondary w-button-secondary--large" href="<?php echo esc_url( $args['search'] ); ?>" data-co-unavail-search><?php esc_html_e( 'Apartments free on these dates', 'marclie-booking' ); ?></a>
			</div>
			<p class="w-checkout__unavailable-note">
				<?php
				printf(
					/* translators: %s: "Send us an enquiry" link. */
					esc_html__( 'Flexible dates or a larger group? %s and we will suggest what is free.', 'marclie-booking' ),
					$link( $args['contact'], __( 'Send us an enquiry', 'marclie-booking' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $link.
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<aside class="w-checkout__summary" aria-label="<?php esc_attr_e( 'Your stay', 'marclie-booking' ); ?>" data-co-summary>
		<?php if ( $nights && 'dates' !== $state ) : ?>
			<?php // Phones: the summary starts as this one row and opens on tap (checkout.js); wider screens never show it. ?>
			<button class="w-checkout__summary-toggle" type="button" aria-expanded="false" aria-controls="co-summary-body co-summary-more" data-co-summary-toggle hidden>
				<span class="w-checkout__summary-toggle-text">
					<span class="w-checkout__summary-toggle-title"><span class="w-visually-hidden"><?php esc_html_e( 'Booking details:', 'marclie-booking' ); ?> </span><?php echo esc_html( $name ); ?></span>
					<span class="w-checkout__summary-toggle-meta">
						<?php
						/* translators: 1: arrival date, 2: departure date, 3: "7 nights". */
						echo esc_html( sprintf( __( '%1$s – %2$s · %3$s', 'marclie-booking' ), $args['arrival_fmt'], $args['departure_fmt'], sprintf( _n( '%d night', '%d nights', $nights, 'marclie-booking' ), $nights ) ) );
						?>
					</span>
				</span>
				<span class="w-checkout__summary-toggle-total" data-co-mini-total></span>
				<?php // Open: the details below show the apartment, dates and total; the row only offers to close. ?>
				<span class="w-checkout__summary-toggle-hide"><?php esc_html_e( 'Hide details', 'marclie-booking' ); ?></span>
				<i data-icon="caret-down" class="w-icon--small w-checkout__summary-toggle-icon"></i>
			</button>
		<?php endif; ?>
		<div class="w-checkout__summary-body" id="co-summary-body" data-co-summary-body>
		<div class="w-checkout__media">
			<?php if ( '' !== $args['photo'] ) : ?>
				<div class="w-photo w-photo--4x3 w-photo--image"><?php echo $args['photo']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image() markup. ?></div>
			<?php else : ?>
				<div class="w-photo w-photo--4x3"></div>
			<?php endif; ?>
			<?php if ( '' !== $card['house'] ) : ?>
				<span class="w-checkout__badge"><?php echo esc_html( $card['house'] ); ?></span>
			<?php endif; ?>
		</div>
		<div class="w-checkout__stay">
			<p class="w-checkout__stay-title"><?php echo esc_html( $name ); ?></p>
			<?php if ( '' !== $card['place'] ) : ?>
				<p class="w-checkout__stay-location"><i data-icon="map-pin" class="w-icon--small"></i> <span class="w-checkout__stay-location-text"><?php echo esc_html( $card['place'] ); ?></span></p>
			<?php endif; ?>
		</div>
		<?php if ( $nights ) : ?>
			<div class="w-checkout__dates">
				<div class="w-checkout__date"><span class="w-checkout__date-label"><?php esc_html_e( 'Check-in', 'marclie-booking' ); ?></span><span class="w-checkout__date-day"><?php echo esc_html( $args['arrival_fmt'] ); ?></span><span class="w-checkout__date-time"><?php echo esc_html( $args['checkin_window'] ); ?></span></div>
				<span class="w-checkout__nights">
					<?php
					/* translators: %d: number of nights. */
					echo esc_html( sprintf( _n( '%d night', '%d nights', $nights, 'marclie-booking' ), $nights ) );
					?>
				</span>
				<div class="w-checkout__date w-checkout__date--end"><span class="w-checkout__date-label"><?php esc_html_e( 'Check-out', 'marclie-booking' ); ?></span><span class="w-checkout__date-day"><?php echo esc_html( $args['departure_fmt'] ); ?></span><span class="w-checkout__date-time">
					<?php
					/* translators: %s: latest check-out time, e.g. "10:00". */
					echo esc_html( sprintf( __( 'by %s', 'marclie-booking' ), $unit->checkoutUntil ) );
					?>
				</span></div>
			</div>
		<?php endif; ?>
		<?php if ( 'dates' !== $state ) : ?>
			<dl class="w-checkout__guests" data-co-free<?php echo $hide( ! $free ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal. ?>>
				<div class="w-checkout__line"><dt class="w-checkout__guest-term"><i data-icon="users" class="w-icon--small"></i> <span class="w-checkout__guest-term-text"><?php echo esc_html( \Marclie\Booking\Frontend\Renderer::adultsLabel() ); ?></span></dt><dd class="w-checkout__guest-value" data-mirror="b-adults"><?php echo esc_html( (string) $args['adults'] ); ?></dd></div>
				<div class="w-checkout__line"><dt class="w-checkout__guest-term"><i data-icon="baby" class="w-icon--small"></i> <span class="w-checkout__guest-term-text"><?php echo esc_html( \Marclie\Booking\Frontend\Renderer::childrenLabel() ); ?></span></dt><dd class="w-checkout__guest-value" data-mirror="b-children"><?php echo esc_html( (string) $args['children'] ); ?></dd></div>
				<div class="w-checkout__line" data-co-infants<?php echo $args['infants'] > 0 ? '' : ' hidden'; ?>><dt class="w-checkout__guest-term"><i data-icon="baby" class="w-icon--small"></i> <span class="w-checkout__guest-term-text"><?php echo esc_html( \Marclie\Booking\Frontend\Renderer::infantsLabel() ); ?></span></dt><dd class="w-checkout__guest-value" data-mirror="b-infants"><?php echo esc_html( (string) $args['infants'] ); ?></dd></div>
			</dl>
			<p class="w-checkout__check" data-co-free<?php echo $hide( ! $free ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal. ?>><i data-icon="check" class="w-icon--small w-checkout__check-icon"></i> <span class="w-checkout__check-text"><strong><?php esc_html_e( 'Available.', 'marclie-booking' ); ?></strong> <?php esc_html_e( 'Dates and price confirmed with our live calendar.', 'marclie-booking' ); ?></span></p>
			<p class="w-checkout__check w-checkout__check--off" data-co-booked data-co-check-off<?php echo $hide( $free ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal. ?>><i data-icon="info" class="w-icon--small w-checkout__check-icon"></i> <span class="w-checkout__check-text"><strong><?php esc_html_e( 'Booked for these dates.', 'marclie-booking' ); ?></strong> <?php esc_html_e( 'Checked with our live calendar.', 'marclie-booking' ); ?></span></p>
			<dl class="w-checkout__price" data-co-lines aria-live="polite" aria-busy="true">
				<div class="w-checkout__line"><dt class="w-checkout__price-term"><?php esc_html_e( 'Loading the price…', 'marclie-booking' ); ?></dt><dd class="w-checkout__price-value"></dd></div>
			</dl>
			</div><?php // .w-checkout__summary-body: the coupon below stays visible on phones while the summary is closed ?>
			<div class="w-checkout__coupon" data-coupon data-co-free<?php echo $hide( ! $free ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal. ?>>
				<p class="w-visually-hidden" data-coupon-live aria-live="polite"></p>
				<div class="w-checkout__coupon-entry" data-coupon-entry>
					<label class="w-checkout__coupon-label" for="b-coupon"><i data-icon="ticket" class="w-icon--small"></i> <span class="w-checkout__coupon-label-text"><?php esc_html_e( 'Coupon code', 'marclie-booking' ); ?> <span class="w-field__optional"><?php esc_html_e( '(optional)', 'marclie-booking' ); ?></span></span></label>
					<div class="w-checkout__coupon-row">
						<input class="w-checkout__coupon-input" id="b-coupon" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="40" aria-describedby="b-coupon-msg">
						<button class="w-button-secondary" type="button" data-coupon-apply><?php echo esc_html_x( 'Apply', 'guest booking flow button', 'marclie-booking' ); ?></button>
					</div>
					<p class="w-checkout__coupon-message" id="b-coupon-msg" data-coupon-msg aria-live="polite"></p>
				</div>
				<p class="w-checkout__coupon-applied" data-coupon-applied hidden><i data-icon="check" class="w-icon--small w-checkout__coupon-icon"></i> <span class="w-checkout__coupon-applied-text">
					<?php
					/* translators: %s: coupon code. */
					printf( esc_html__( '%s applied', 'marclie-booking' ), '<strong data-coupon-code></strong>' );
					?>
				</span> <button class="w-checkout__coupon-remove" type="button" data-coupon-remove><?php echo esc_html_x( 'Remove', 'guest booking flow button', 'marclie-booking' ); ?></button></p>
			</div>
			<div class="w-checkout__summary-body" id="co-summary-more" data-co-summary-body>
		<?php endif; ?>
		<div class="w-checkout__cancel-box">
			<i data-icon="shield-check"></i>
			<div class="w-checkout__cancel-body">
				<p class="w-checkout__cancel-title"><?php esc_html_e( 'Cancellation', 'marclie-booking' ); ?></p>
				<p class="w-checkout__cancel-text">
					<?php
					printf(
						/* translators: 1: free-cancellation days before arrival, 2: "Booking terms" link. */
						esc_html__( 'Free up to %1$d days before arrival; after that the deposit is non-refundable. %2$s', 'marclie-booking' ),
						(int) $policy->freeCancelDays,
						$link( $args['terms'] . '#otkazivanje', __( 'Booking terms', 'marclie-booking' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $link.
					);
					?>
				</p>
			</div>
		</div>
		</div><?php // .w-checkout__summary-body ?>
	</aside>
</div>
