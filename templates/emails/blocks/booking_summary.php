<?php
/**
 * Email block {booking_summary}: reference, apartment, check-in/out, nights, guests, free cancellation.
 *
 * Override: {child-theme}/marclie-booking/emails/blocks/booking_summary.php
 *
 * @var array{lang: string, data: list<array{label: string, value: string}>, font: string, accent: string} $args
 */

defined( 'ABSPATH' ) || exit;

$rows = $args['data'];
if ( ! $rows ) {
	return;
}
$last = count( $rows ) - 1;
$font = 'font-family:' . $args['font'] . ';';
?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 24px;background-color:#F5F5F7;border-radius:12px;">
	<tr>
		<td style="padding:8px 20px;">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
				<?php foreach ( $rows as $i => $row ) : ?>
					<?php $line = $i < $last ? 'border-bottom:1px solid #E8E8ED;' : ''; ?>
					<tr>
						<td valign="top" style="<?php echo esc_attr( $font . $line ); ?>padding:10px 12px 10px 0;width:40%;font-size:14px;line-height:1.45;color:#6E6E73;"><?php echo esc_html( $row['label'] ); ?></td>
						<td valign="top" style="<?php echo esc_attr( $font . $line ); ?>padding:10px 0;font-size:15px;line-height:1.45;font-weight:600;color:#1D1D1F;"><?php echo esc_html( $row['value'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		</td>
	</tr>
</table>
