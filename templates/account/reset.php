<?php
/**
 * New password from the reset email (/moj-racun/zaboravljena-lozinka/?login&key). No mockup page:
 * composed from the `login.html` auth form part. account.js removes login/key from the address
 * bar, posts POST /account/password/reset and goes to /prijava/?reset=1.
 *
 * Override: {child-theme}/marclie-booking/account/reset.php
 *
 * @var array<string, mixed> $args
 */

defined( 'ABSPATH' ) || exit;
?>
<div data-mbk-widget<?php echo $args['lang_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::langAttr(). ?> data-mbk-account="reset" data-done="<?php echo esc_url( $args['done'] ); ?>">
	<h1 class="w-auth-split__heading w-heading-xl"><?php esc_html_e( 'Choose a new password', 'marclie-booking' ); ?></h1>
	<p class="w-auth-split__lede w-text-l"><?php esc_html_e( 'Your new password replaces the old one on every device.', 'marclie-booking' ); ?></p>
	<form class="w-auth-split__form" data-acct-form="reset" novalidate>
		<p class="w-note" role="alert" data-acct-alert hidden></p>
		<input type="hidden" name="login" value="<?php echo esc_attr( $args['login'] ); ?>">
		<input type="hidden" name="key" value="<?php echo esc_attr( $args['key'] ); ?>">
		<div class="w-field" data-acct-field><label class="w-field__label" for="reset-password"><?php esc_html_e( 'New password', 'marclie-booking' ); ?></label><input class="w-field__input" id="reset-password" name="password" type="password" autocomplete="new-password" minlength="8" aria-describedby="reset-password-hint" required data-field="password"><span class="w-field__hint" id="reset-password-hint"><?php esc_html_e( 'At least 8 characters.', 'marclie-booking' ); ?></span></div>
		<button class="w-button-primary w-button-primary--block w-button-primary--large" type="submit"><?php esc_html_e( 'Save new password', 'marclie-booking' ); ?></button>
	</form>
	<p class="w-auth-split__switch" data-acct-expired hidden><a href="<?php echo esc_url( $args['forgot'] ); ?>"><?php esc_html_e( 'Request a new link', 'marclie-booking' ); ?></a></p>
</div>
