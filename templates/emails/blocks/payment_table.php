<?php
/**
 * Email block {payment_table}: price lines of the booking quote, then total,
 * paid, balance (with how/when it is collected) and refund.
 *
 * Override: {child-theme}/marclie-booking/emails/blocks/payment_table.php
 *
 * @var array{lang: string, data: array{lines: list<array{label: string, amount: string}>, totals: list<array{label: string, amount: string, note?: string, strong?: bool}>}, font: string, accent: string} $args
 */

defined( 'ABSPATH' ) || exit;

$lines  = $args['data']['lines'];
$totals = $args['data']['totals'];
$font   = 'font-family:' . $args['font'] . ';';
?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 24px;border:1px solid #E8E8ED;border-radius:12px;">
	<tr>
		<td style="padding:12px 20px;">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
				<?php foreach ( $lines as $line ) : ?>
					<tr>
						<td style="<?php echo esc_attr( $font ); ?>padding:6px 12px 6px 0;font-size:14px;line-height:1.45;color:#424245;"><?php echo esc_html( $line['label'] ); ?></td>
						<td align="right" style="<?php echo esc_attr( $font ); ?>padding:6px 0;font-size:14px;line-height:1.45;color:#424245;white-space:nowrap;"><?php echo esc_html( $line['amount'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php foreach ( $totals as $i => $row ) : ?>
					<?php
					$strong = ! empty( $row['strong'] ) ? 'font-weight:700;' : '';
					$border = 0 === $i && $lines ? 'border-top:1px solid #E8E8ED;' : '';
					$pad    = 0 === $i && $lines ? '12px' : '6px';
					?>
					<tr>
						<td valign="top" style="<?php echo esc_attr( $font . $border . $strong ); ?>padding:<?php echo esc_attr( $pad ); ?> 12px 6px 0;font-size:15px;line-height:1.45;color:#1D1D1F;">
							<?php echo esc_html( $row['label'] ); ?>
							<?php if ( ! empty( $row['note'] ) ) : ?>
								<br><span style="<?php echo esc_attr( $font ); ?>font-size:13px;font-weight:400;color:#6E6E73;"><?php echo esc_html( $row['note'] ); ?></span>
							<?php endif; ?>
						</td>
						<td align="right" valign="top" style="<?php echo esc_attr( $font . $border . $strong ); ?>padding:<?php echo esc_attr( $pad ); ?> 0 6px;font-size:15px;line-height:1.45;color:#1D1D1F;white-space:nowrap;"><?php echo esc_html( $row['amount'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		</td>
	</tr>
</table>
