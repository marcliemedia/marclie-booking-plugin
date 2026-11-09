<?php
/**
 * Email confirmation link (/moj-racun/?verify=TOKEN). A pending token gets the password form:
 * account.js removes the token from the address bar and posts POST account/verify
 * {token, password}, which signs in (→ /moj-racun/?verified=1). An unknown, used or expired
 * token — or one invalidated after too many wrong passwords — shows the invalid-link state.
 * No mockup state: composed from the `my-account.html` `.w-account-board` panel classes (it sits
 * where the board sits).
 *
 * Override: {child-theme}/marclie-booking/account/verify.php
 *
 * @var array<string, mixed> $args
 */

defined( 'ABSPATH' ) || exit;

$pending = '' !== $args['token'];
$first   = $args['with_h1'] ? 'h1' : 'h2';
?>
<div class="w-account-board__panel" data-mbk-widget<?php echo $args['lang_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::langAttr(). ?> data-mbk-account="verify" data-done="<?php echo esc_url( $args['done'] ); ?>">
	<div data-acct-step="form"<?php echo $pending ? '' : ' hidden'; ?>>
		<<?php echo esc_html( $pending ? $first : 'h2' ); ?> class="w-account-board__heading w-heading-l" data-state-title tabindex="-1"><?php esc_html_e( 'Confirm your email address', 'marclie-booking' ); ?></<?php echo esc_html( $pending ? $first : 'h2' ); ?>>
		<p class="w-account-board__fine"><?php esc_html_e( 'Enter the password you chose when signing up.', 'marclie-booking' ); ?></p>
		<form class="w-account-board__form" data-acct-form="verify" novalidate>
			<p class="w-note" role="alert" data-acct-alert hidden></p>
			<input type="hidden" name="token" value="<?php echo esc_attr( $args['token'] ); ?>">
			<div class="w-field" data-acct-field><label class="w-field__label" for="verify-password"><?php esc_html_e( 'Password', 'marclie-booking' ); ?></label><input class="w-field__input" id="verify-password" name="password" type="password" autocomplete="current-password" required data-field="password"></div>
			<button class="w-button-primary" type="submit"><?php esc_html_e( 'Confirm and log in', 'marclie-booking' ); ?></button>
		</form>
	</div>
	<div class="w-account-board__help" data-acct-step="invalid"<?php echo $pending ? ' hidden' : ''; ?>>
		<<?php echo esc_html( $pending ? 'h2' : $first ); ?> class="w-account-board__heading w-heading-l" data-state-title tabindex="-1"><?php esc_html_e( 'This confirmation link is not valid', 'marclie-booking' ); ?></<?php echo esc_html( $pending ? 'h2' : $first ); ?>>
		<p class="w-account-board__help-text"><?php esc_html_e( 'The link has expired or was already used. Log in if you have already confirmed your address, or sign up again to get a new link.', 'marclie-booking' ); ?></p>
		<div class="w-account-board__actions brxe-block">
			<a class="w-button-primary w-button-primary--small" href="<?php echo esc_url( $args['login'] ); ?>"><?php esc_html_e( 'Log in', 'marclie-booking' ); ?></a>
			<a class="w-button-secondary w-button-secondary--small" href="<?php echo esc_url( $args['register'] ); ?>"><?php esc_html_e( 'Sign up again', 'marclie-booking' ); ?></a>
		</div>
	</div>
</div>
