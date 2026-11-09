<?php
/**
 * Bookbox: price from, dates, guests, live quote lines, CTA to the booking page.
 * Markup = mockup unit page booking box (aside.w-unit-body__booking inside .w-unit-body__grid).
 *
 * Override: {child-theme}/marclie-booking/bookbox.php
 *
 * @var array<string, mixed> $args
 */

defined( 'ABSPATH' ) || exit;

/** @var \Marclie\Booking\Units\Unit $unit */
$unit   = $args['unit'];
$key    = (string) $args['key'];
$end    = 'bb-odlazak';
$policy = $args['policy'];
$price  = $args['price'];
?>
<aside class="w-unit-body__booking" id="rezerviraj"<?php echo $args['lang_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::langAttr(). ?> aria-label="<?php esc_attr_e( 'Booking', 'marclie-booking' ); ?>" data-mbk-widget data-mbk-bookbox="<?php echo esc_attr( $key ); ?>" data-unit="<?php echo esc_attr( (string) $unit->id ); ?>">
	<form class="w-unit-body__form" action="<?php echo esc_url( $args['action'] ); ?>" method="get">
		<?php foreach ( $args['hidden'] as $name => $value ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
		<?php endforeach; ?>
		<?php if ( null !== $price ) : ?>
			<p class="w-unit-body__price">
				<?php
				/* translators: %s: lowest price per person per night, e.g. "50 €". */
				printf( esc_html__( 'from %s', 'marclie-booking' ), esc_html( $price ) );
				?>
				<span class="w-unit-body__price-unit"><?php echo esc_html( $args['per'] ); ?></span>
			</p>
		<?php endif; ?>
		<?php if ( $args['direct'] ) : ?>
			<p class="w-unit-body__direct"><i data-icon="ticket" class="w-icon--small"></i> <span class="w-unit-body__direct-text"><b class="w-unit-body__direct-label"><?php esc_html_e( 'Book direct:', 'marclie-booking' ); ?></b>
				<?php
				printf(
					/* translators: 1: discount, e.g. "5%", 2: coupon code. */
					esc_html__( '%1$s off with code %2$s at checkout', 'marclie-booking' ),
					esc_html( $args['direct']['amount'] ),
					'<b class="w-unit-body__direct-code">' . esc_html( $args['direct']['code'] ) . '</b>'
				);
				?>
			</span></p>
		<?php endif; ?>
		<div class="w-unit-body__dates brxe-block">
			<div class="w-field"><label class="w-field__label" for="<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Check-in', 'marclie-booking' ); ?></label><input class="w-field__input" id="<?php echo esc_attr( $key ); ?>" name="dolazak" type="date" data-date-end="<?php echo esc_attr( $end ); ?>" data-unit="<?php echo esc_attr( (string) $unit->id ); ?>" value="<?php echo esc_attr( (string) $args['arrival'] ); ?>" required></div>
			<div class="w-field"><label class="w-field__label" for="<?php echo esc_attr( $end ); ?>"><?php esc_html_e( 'Check-out', 'marclie-booking' ); ?></label><input class="w-field__input" id="<?php echo esc_attr( $end ); ?>" name="odlazak" type="date" value="<?php echo esc_attr( (string) $args['departure'] ); ?>" required></div>
		</div>
		<div class="w-unit-body__guests brxe-block">
			<div class="w-field w-field--flush"><label class="w-field__label" for="bb-odrasli"><?php echo esc_html( \Marclie\Booking\Frontend\Renderer::adultsLabel() ); ?></label>
				<div class="w-stepper" data-qty>
					<button class="w-stepper__button" type="button" data-step="-1" aria-label="<?php esc_attr_e( 'One adult fewer', 'marclie-booking' ); ?>"><i data-icon="minus" class="w-icon--small"></i></button>
					<input class="w-stepper__input" id="bb-odrasli" name="odrasli" type="number" inputmode="numeric" min="1" max="<?php echo esc_attr( (string) $args['cap'] ); ?>" value="<?php echo esc_attr( (string) $args['adults'] ); ?>" data-cap="<?php echo esc_attr( (string) $args['cap'] ); ?>">
					<button class="w-stepper__button" type="button" data-step="1" aria-label="<?php esc_attr_e( 'One adult more', 'marclie-booking' ); ?>"><i data-icon="plus" class="w-icon--small"></i></button>
				</div></div>
			<div class="w-field w-field--flush"><label class="w-field__label" for="bb-djeca"><?php echo esc_html( \Marclie\Booking\Frontend\Renderer::childrenLabel() ); ?></label>
				<div class="w-stepper" data-qty>
					<button class="w-stepper__button" type="button" data-step="-1" aria-label="<?php esc_attr_e( 'One child fewer', 'marclie-booking' ); ?>"><i data-icon="minus" class="w-icon--small"></i></button>
					<input class="w-stepper__input" id="bb-djeca" name="djeca" type="number" inputmode="numeric" min="0" max="<?php echo esc_attr( (string) $args['kids_max'] ); ?>" value="<?php echo esc_attr( (string) $args['children'] ); ?>" data-max="<?php echo esc_attr( (string) $args['kids_max'] ); ?>">
					<button class="w-stepper__button" type="button" data-step="1" aria-label="<?php esc_attr_e( 'One child more', 'marclie-booking' ); ?>"><i data-icon="plus" class="w-icon--small"></i></button>
				</div></div>
		</div>
		<p class="w-unit-body__cap">
			<?php
			/* translators: %d: maximum number of guests. */
			echo esc_html( sprintf( _n( 'Sleeps up to %d', 'Sleeps up to %d', $args['cap'], 'marclie-booking' ), $args['cap'] ) . ' · ' . $args['rule'] );
			?>
		</p>
		<?php $infantsCount = \Marclie\Booking\Frontend\Renderer::infantsCountLabel(); ?>
		<div class="w-unit-body__infants brxe-block">
			<label class="w-checkbox w-checkbox--flush"><input class="w-checkbox__input" type="checkbox" aria-controls="bb-bebe-box" data-mbk-reveal<?php checked( $args['infants'] > 0 ); ?>> <span class="w-checkbox__text"><?php echo esc_html( \Marclie\Booking\Frontend\Renderer::infantsToggleLabel() ); ?></span></label>
			<div class="w-unit-body__guests brxe-block" id="bb-bebe-box"<?php echo $args['infants'] > 0 ? '' : ' hidden'; ?>>
				<div class="w-field w-field--flush"><label class="w-field__label" for="bb-bebe"><?php echo esc_html( $infantsCount['visible'] ); ?> <span class="w-visually-hidden"><?php echo esc_html( $infantsCount['hidden'] ); ?></span></label>
					<div class="w-stepper" data-qty>
						<button class="w-stepper__button" type="button" data-step="-1" aria-label="<?php esc_attr_e( 'One young child fewer', 'marclie-booking' ); ?>"><i data-icon="minus" class="w-icon--small"></i></button>
						<input class="w-stepper__input" id="bb-bebe" name="bebe" type="number" inputmode="numeric" min="<?php echo $args['infants'] > 0 ? '1' : '0'; ?>" max="<?php echo esc_attr( (string) \Marclie\Booking\Frontend\Renderer::MAX_INFANTS ); ?>" value="<?php echo esc_attr( (string) $args['infants'] ); ?>">
						<button class="w-stepper__button" type="button" data-step="1" aria-label="<?php esc_attr_e( 'One young child more', 'marclie-booking' ); ?>"><i data-icon="plus" class="w-icon--small"></i></button>
					</div></div>
			</div>
		</div>
		<dl class="w-unit-body__lines" aria-live="polite" data-mbk-lines>
			<div class="w-unit-body__line"><dt class="w-unit-body__line-label"><?php esc_html_e( 'Choose dates for the price', 'marclie-booking' ); ?></dt><dd class="w-unit-body__line-value"></dd></div>
		</dl>
		<p class="w-note" role="alert" data-mbk-error hidden></p>
		<div class="w-unit-body__actions brxe-block">
			<button class="w-button-primary w-button-primary--block" type="submit"><?php esc_html_e( 'Book now', 'marclie-booking' ); ?></button>
			<?php if ( $args['inquiry'] ?? true ) : ?>
				<a class="w-button-secondary w-button-secondary--block" href="<?php echo esc_url( $args['contact'] ); ?>"><?php esc_html_e( 'Send an enquiry', 'marclie-booking' ); ?></a>
			<?php endif; ?>
		</div>
		<?php if ( $args['note'] ?? true ) : ?>
			<p class="w-unit-body__note">
				<?php
				printf(
					/* translators: 1: deposit percent, 2: days before arrival the balance is due, 3: free-cancellation days before arrival. */
					esc_html__( '%1$d%% deposit by card, the balance %2$d days before arrival. Free cancellation up to %3$d days ahead.', 'marclie-booking' ),
					(int) $policy->depositPct,
					(int) $policy->balanceDays,
					(int) $policy->freeCancelDays
				);
				?>
			</p>
		<?php endif; ?>
	</form>
</aside>
