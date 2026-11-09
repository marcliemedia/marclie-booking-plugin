<?php
/**
 * Forgotten password (/moj-racun/zaboravljena-lozinka/): request a reset link. No mockup page:
 * composed from the `login.html` auth form part (`.w-auth-split__*`, `w-field`, `w-note`). account.js
 * posts POST /account/password/forgot, which always answers 200 (no account enumeration).
 *
 * Override: {child-theme}/marclie-booking/account/forgot.php
 *
 * @var array<string, mixed> $args
 */

defined( 'ABSPATH' ) || exit;
?>
<div data-mbk-widget<?php echo $args['lang_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::langAttr(). ?> data-mbk-account="forgot">
	<h1 class="w-auth-split__heading w-heading-xl"><?php esc_html_e( 'Forgot your password?', 'marclie-booking' ); ?></h1>
	<p class="w-auth-split__lede w-text-l"><?php esc_html_e( 'Enter the email address of your account and we will send you a link to choose a new password.', 'marclie-booking' ); ?></p>
	<form class="w-auth-split__form" data-acct-form="forgot" novalidate>
		<p class="w-note" role="alert" data-acct-alert hidden></p>
		<div class="w-field" data-acct-field><label class="w-field__label" for="forgot-email"><?php esc_html_e( 'Email', 'marclie-booking' ); ?></label><input class="w-field__input" id="forgot-email" name="email" type="email" autocomplete="email" maxlength="190" required data-field="email"></div>
		<button class="w-button-primary w-button-primary--block w-button-primary--large" type="submit"><?php esc_html_e( 'Send reset link', 'marclie-booking' ); ?></button>
	</form>
	<p class="w-note" role="status" data-acct-done="forgot" tabindex="-1" hidden><?php esc_html_e( 'We have sent you a link to choose a new password. Please check your inbox.', 'marclie-booking' ); ?></p>
	<p class="w-auth-split__switch"><a href="<?php echo esc_url( $args['login'] ); ?>"><?php esc_html_e( 'Back to log in', 'marclie-booking' ); ?></a></p>
</div>
