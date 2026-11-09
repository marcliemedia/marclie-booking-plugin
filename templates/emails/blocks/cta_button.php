<?php
/**
 * Email block {cta_button}: accent pill button (bulletproof table markup).
 *
 * Override: {child-theme}/marclie-booking/emails/blocks/cta_button.php
 *
 * @var array{lang: string, data: array{url: string, label: string}, font: string, accent: string} $args
 */

defined( 'ABSPATH' ) || exit;

$url    = $args['data']['url'];
$label  = $args['data']['label'];
$accent = $args['accent'];
?>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 24px;">
	<tr>
		<td align="center" bgcolor="<?php echo esc_attr( $accent ); ?>" style="border-radius:999px;background-color:<?php echo esc_attr( $accent ); ?>;">
			<a href="<?php echo esc_url( $url ); ?>" style="font-family:<?php echo esc_attr( $args['font'] ); ?>;display:inline-block;padding:14px 28px;font-size:16px;line-height:1.2;font-weight:600;color:#FFFFFF;text-decoration:none;border-radius:999px;"><?php echo esc_html( $label ); ?></a>
		</td>
	</tr>
</table>
