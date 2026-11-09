<?php
/**
 * Email layout: table-based, inline CSS, 600 px card on the #F5F5F7 canvas
 * (MSO conditional wrapper for Outlook desktop), hidden preheader, logo or
 * brand name on top, accent colour and business contact from the ACF options.
 *
 * Override: {child-theme}/marclie-booking/emails/layout.php
 *
 * @var array{lang: string, subject: string, preheader: string, heading: string, body: string, business: array{name: string, phone: string, email: string, address: string, maps_url: string, logo_url: string, accent: string}} $args
 */

defined( 'ABSPATH' ) || exit;

$business = $args['business'];
$font     = \Marclie\Booking\Email\Renderer::FONT;
$accent   = $business['accent'];
$tel      = preg_replace( '/[^\d+]/', '', $business['phone'] );
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $args['lang'] ); ?>" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<title><?php echo esc_html( $args['subject'] ); ?></title>
<style>
	body { margin: 0; padding: 0; -webkit-text-size-adjust: 100%; }
	a { color: <?php echo esc_html( $accent ); ?>; }
	@media (max-width: 620px) {
		.mbk-card { padding: 28px 20px 24px !important; border-radius: 0 !important; }
		.mbk-outer { padding: 16px 0 !important; }
		.mbk-h1 { font-size: 22px !important; }
	}
</style>
</head>
<body style="margin:0;padding:0;background-color:#F5F5F7;">
<?php if ( '' !== $args['preheader'] ) : ?>
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;color:#F5F5F7;"><?php echo esc_html( $args['preheader'] ); ?><?php echo str_repeat( '&#847;&zwnj;&nbsp;', 40 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static filler that keeps body text out of the inbox preview. ?></div>
<?php endif; ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F5F5F7;">
	<tr>
		<td class="mbk-outer" align="center" style="padding:32px 12px;">
			<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td><![endif]-->
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">
				<tr>
					<td style="padding:0 8px 18px;font-family:<?php echo esc_attr( $font ); ?>;font-size:17px;line-height:1.3;font-weight:700;letter-spacing:-0.01em;color:#1D1D1F;">
						<?php if ( '' !== $business['logo_url'] ) : ?>
							<img src="<?php echo esc_url( $business['logo_url'] ); ?>" alt="<?php echo esc_attr( $business['name'] ); ?>" height="40" style="display:block;height:40px;width:auto;border:0;outline:none;text-decoration:none;">
						<?php else : ?>
							<?php echo esc_html( $business['name'] ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td class="mbk-card" style="background-color:#FFFFFF;border:1px solid #E8E8ED;border-radius:16px;padding:40px 40px 32px;font-family:<?php echo esc_attr( $font ); ?>;font-size:16px;line-height:1.6;color:#1D1D1F;">
						<?php if ( '' !== $args['heading'] ) : ?>
							<h1 class="mbk-h1" style="margin:0 0 20px;font-family:<?php echo esc_attr( $font ); ?>;font-size:26px;line-height:1.25;font-weight:700;letter-spacing:-0.02em;color:#1D1D1F;"><?php echo esc_html( $args['heading'] ); ?></h1>
						<?php endif; ?>
						<?php echo $args['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kses-filtered template body with escaped merge values and plugin blocks. ?>
					</td>
				</tr>
				<tr>
					<td style="padding:24px 8px 8px;font-family:<?php echo esc_attr( $font ); ?>;font-size:13px;line-height:1.6;color:#6E6E73;">
						<strong style="color:#1D1D1F;"><?php echo esc_html( $business['name'] ); ?></strong><br>
						<?php if ( '' !== $business['address'] ) : ?>
							<?php if ( '' !== $business['maps_url'] ) : ?>
								<a href="<?php echo esc_url( $business['maps_url'] ); ?>" style="color:#6E6E73;text-decoration:underline;"><?php echo esc_html( $business['address'] ); ?></a><br>
							<?php else : ?>
								<?php echo esc_html( $business['address'] ); ?><br>
							<?php endif; ?>
						<?php endif; ?>
						<?php if ( '' !== $business['phone'] ) : ?>
							<a href="tel:<?php echo esc_attr( $tel ); ?>" style="color:#6E6E73;text-decoration:none;"><?php echo esc_html( $business['phone'] ); ?></a>
						<?php endif; ?>
						<?php if ( '' !== $business['phone'] && '' !== $business['email'] ) : ?>
							&nbsp;·&nbsp;
						<?php endif; ?>
						<?php if ( '' !== $business['email'] ) : ?>
							<a href="mailto:<?php echo esc_attr( $business['email'] ); ?>" style="color:#6E6E73;text-decoration:none;"><?php echo esc_html( $business['email'] ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<!--[if mso]></td></tr></table><![endif]-->
		</td>
	</tr>
</table>
</body>
</html>
