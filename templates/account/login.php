<?php
/**
 * Log in (/prijava/): the form part of the mockup `login.html` `.w-auth-split__panel` — heading,
 * lede, form, "Forgot your password?" accordion, sign-up link (the page builds the section,
 * container, grid, panel, breadcrumb and the photo/note column around it). account.js posts
 * POST /account/login and goes to the validated redirect; the accordion posts
 * POST /account/password/forgot.
 *
 * Override: {child-theme}/marclie-booking/account/login.php
 *
 * @var array<string, mixed> $args
 */

defined( 'ABSPATH' ) || exit;
?>
<div data-mbk-widget<?php echo $args['lang_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::langAttr(). ?> data-mbk-account="login" data-redirect="<?php echo esc_url( $args['redirect'] ); ?>">
	<h1 class="w-auth-split__heading w-heading-xl"><?php esc_html_e( 'Log in', 'marclie-booking' ); ?></h1>
	<p class="w-auth-split__lede w-text-l"><?php esc_html_e( 'Welcome back. Your bookings, payments and member coupons are waiting in your account.', 'marclie-booking' ); ?></p>
	<?php if ( $args['reset'] ) : ?>
		<p class="w-note" role="status"><?php esc_html_e( 'Your password is changed. Log in with your new password.', 'marclie-booking' ); ?></p>
	<?php endif; ?>
	<form class="w-auth-split__form" data-acct-form="login" novalidate>
		<p class="w-note" role="alert" data-acct-alert hidden></p>
		<div class="w-field" data-acct-field><label class="w-field__label" for="login-email"><?php esc_html_e( 'Email', 'marclie-booking' ); ?></label><input class="w-field__input" id="login-email" name="email" type="email" autocomplete="email" maxlength="190" required data-field="email"></div>
		<div class="w-field" data-acct-field><label class="w-field__label" for="login-password"><?php esc_html_e( 'Password', 'marclie-booking' ); ?></label><input class="w-field__input" id="login-password" name="password" type="password" autocomplete="current-password" required data-field="password"></div>
		<label class="w-checkbox" data-acct-check><input class="w-checkbox__input" type="checkbox" name="remember" value="1" checked> <span class="w-checkbox__text"><?php esc_html_e( 'Keep me logged in on this device', 'marclie-booking' ); ?></span></label>
		<button class="w-button-primary w-button-primary--block w-button-primary--large" type="submit"><i data-icon="sign-in" class="w-icon--small"></i> <span class="w-button-primary__label"><?php esc_html_e( 'Log in', 'marclie-booking' ); ?></span></button>
	</form>
	<details class="w-accordion">
		<summary class="w-accordion__summary"><span class="w-accordion__question"><?php esc_html_e( 'Forgot your password?', 'marclie-booking' ); ?></span><span class="w-accordion__icon" aria-hidden="true"><i data-icon="plus" class="w-icon--small w-accordion__glyph"></i></span></summary>
		<form class="w-accordion__answer" data-acct-form="forgot" novalidate>
			<p class="w-note" role="alert" data-acct-alert hidden></p>
			<div class="w-field" data-acct-field><label class="w-field__label" for="reset-email"><?php esc_html_e( 'Email', 'marclie-booking' ); ?></label><input class="w-field__input" id="reset-email" name="email" type="email" autocomplete="email" maxlength="190" required data-field="email"></div>
			<button class="w-button-secondary w-button-secondary--block" type="submit"><?php esc_html_e( 'Send reset link', 'marclie-booking' ); ?></button>
		</form>
		<p class="w-note w-accordion__answer" role="status" data-acct-done="forgot" tabindex="-1" hidden><?php esc_html_e( 'We have sent you a link to choose a new password. Please check your inbox.', 'marclie-booking' ); ?></p>
	</details>
	<p class="w-auth-split__switch"><?php esc_html_e( 'New here?', 'marclie-booking' ); ?> <a href="<?php echo esc_url( $args['register'] ); ?>"><?php esc_html_e( 'Create an account', 'marclie-booking' ); ?></a></p>
</div>
