<?php
/**
 * Sign up (/registracija/[?email=]): the form part of the mockup `signup.html` `.w-auth-split__panel`
 * (the page builds section, container, grid, panel, breadcrumb and the photo/note column).
 * account.js posts POST /account/register; the 201 answer (also for an email that already has an
 * account — no enumeration) swaps the form step for the "check your inbox" step.
 *
 * Override: {child-theme}/marclie-booking/account/register.php
 *
 * @var array<string, mixed> $args
 */

defined( 'ABSPATH' ) || exit;

$link = static fn( string $url, string $text ): string => '<a class="w-checkbox__link" href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>';
?>
<div data-mbk-widget<?php echo $args['lang_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::langAttr(). ?> data-mbk-account="register">
	<div data-acct-step="form">
		<h1 class="w-auth-split__heading w-heading-xl" data-state-title><?php esc_html_e( 'Create your account', 'marclie-booking' ); ?></h1>
		<p class="w-auth-split__lede w-text-l"><?php esc_html_e( 'Keep your bookings and payments in one place, get a welcome coupon for your first booking and become a member from your first stay.', 'marclie-booking' ); ?></p>
		<form class="w-auth-split__form" data-acct-form="register" novalidate>
			<p class="w-note" role="alert" data-acct-alert hidden></p>
			<div class="w-auth-split__row">
				<div class="w-field" data-acct-field><label class="w-field__label" for="signup-first"><?php esc_html_e( 'First name', 'marclie-booking' ); ?></label><input class="w-field__input" id="signup-first" name="first_name" type="text" autocomplete="given-name" maxlength="100" required data-field="first_name"></div>
				<div class="w-field" data-acct-field><label class="w-field__label" for="signup-last"><?php esc_html_e( 'Last name', 'marclie-booking' ); ?></label><input class="w-field__input" id="signup-last" name="last_name" type="text" autocomplete="family-name" maxlength="100" required data-field="last_name"></div>
			</div>
			<div class="w-field" data-acct-field><label class="w-field__label" for="signup-email"><?php esc_html_e( 'Email', 'marclie-booking' ); ?></label><input class="w-field__input" id="signup-email" name="email" type="email" autocomplete="email" maxlength="190" value="<?php echo esc_attr( $args['email'] ); ?>" required data-field="email"></div>
			<div class="w-field" data-acct-field><label class="w-field__label" for="signup-password"><?php esc_html_e( 'Password', 'marclie-booking' ); ?></label><input class="w-field__input" id="signup-password" name="password" type="password" autocomplete="new-password" minlength="8" aria-describedby="signup-password-hint" required data-field="password"><span class="w-field__hint" id="signup-password-hint"><?php esc_html_e( 'At least 8 characters.', 'marclie-booking' ); ?></span></div>
			<div class="mbk-hp" aria-hidden="true"><label for="signup-website">Website</label><input id="signup-website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
			<label class="w-checkbox" data-acct-check><input class="w-checkbox__input" type="checkbox" name="terms" value="1" required data-field="terms"> <span class="w-checkbox__text">
				<?php
				printf(
					/* translators: 1: "booking terms" link, 2: "privacy policy" link. */
					esc_html__( 'I accept the %1$s and I have read the %2$s.', 'marclie-booking' ),
					$link( $args['terms'], __( 'booking terms', 'marclie-booking' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $link.
					$link( $args['privacy'], __( 'privacy policy', 'marclie-booking' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $link.
				);
				?>
			</span></label>
			<label class="w-checkbox" data-acct-check><input class="w-checkbox__input" type="checkbox" name="newsletter" value="1"> <span class="w-checkbox__text"><?php esc_html_e( 'Send me news and member offers by email (you can unsubscribe at any time)', 'marclie-booking' ); ?></span></label>
			<button class="w-button-primary w-button-primary--block w-button-primary--large" type="submit"><?php esc_html_e( 'Create account', 'marclie-booking' ); ?></button>
		</form>
		<p class="w-auth-split__switch"><?php esc_html_e( 'Already have an account?', 'marclie-booking' ); ?> <a href="<?php echo esc_url( $args['login'] ); ?>"><?php esc_html_e( 'Log in', 'marclie-booking' ); ?></a></p>
	</div>
	<div data-acct-step="sent" hidden>
		<h2 class="w-auth-split__heading w-heading-xl" data-state-title tabindex="-1"><?php esc_html_e( 'Check your inbox', 'marclie-booking' ); ?></h2>
		<p class="w-auth-split__lede w-text-l" data-acct-sent-text data-template="<?php /* translators: %s: email address. */ echo esc_attr( __( 'We have sent a confirmation link to %s. Open it to activate your account and receive your welcome coupon.', 'marclie-booking' ) ); ?>"></p>
		<p class="w-note"><?php esc_html_e( 'No email after a few minutes? Check your spam folder or sign up again with the same address.', 'marclie-booking' ); ?></p>
		<p class="w-auth-split__switch"><?php esc_html_e( 'Already confirmed?', 'marclie-booking' ); ?> <a href="<?php echo esc_url( $args['login'] ); ?>"><?php esc_html_e( 'Log in', 'marclie-booking' ); ?></a></p>
	</div>
</div>
