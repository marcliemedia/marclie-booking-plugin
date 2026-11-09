<?php
/**
 * Email block {account_invite} (guest_confirmed): invitation to create a guest
 * account with the booking email. Not rendered when that email already has an
 * account (Context passes null data).
 *
 * Override: {child-theme}/marclie-booking/emails/blocks/account_invite.php
 *
 * @var array{lang: string, data: array{url: string, title: string, text: string, label: string}, font: string, accent: string} $args
 */

defined( 'ABSPATH' ) || exit;

$invite = $args['data'];
$font   = 'font-family:' . $args['font'] . ';';
$accent = $args['accent'];
?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 24px;background-color:#F5F5F7;border-radius:12px;">
	<tr>
		<td style="<?php echo esc_attr( $font ); ?>padding:20px 24px;">
			<p style="<?php echo esc_attr( $font ); ?>margin:0 0 8px;font-size:17px;line-height:1.35;font-weight:700;color:#1D1D1F;"><?php echo esc_html( $invite['title'] ); ?></p>
			<p style="<?php echo esc_attr( $font ); ?>margin:0 0 16px;font-size:15px;line-height:1.5;color:#1D1D1F;"><?php echo esc_html( $invite['text'] ); ?></p>
			<a href="<?php echo esc_url( $invite['url'] ); ?>" style="<?php echo esc_attr( $font ); ?>display:inline-block;padding:10px 22px;font-size:15px;line-height:1.2;font-weight:600;color:<?php echo esc_attr( $accent ); ?>;text-decoration:none;border:2px solid <?php echo esc_attr( $accent ); ?>;border-radius:999px;"><?php echo esc_html( $invite['label'] ); ?></a>
		</td>
	</tr>
</table>
