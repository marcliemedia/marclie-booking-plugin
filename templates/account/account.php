<?php
/**
 * My account (/moj-racun/): `head` (h1 + "Signed in as") and/or `board` (tabs Pregled ·
 * Rezervacije · Plaćanja · Kuponi · Članstvo · Vaši podaci, mockup `my-account.html`).
 * Markup = mockup `my-account.html`: head = `.w-page-title__text` (the page builds section, container,
 * breadcrumb and `.w-page-title__body`), board = `.w-account-board__grid` (the page builds section +
 * container). Data comes from the account GET routes (AccountPages::data()); account.js runs the tabs
 * (#overview … #profile), the forms, the card update, the cancel request and account deletion.
 *
 * Override: {child-theme}/marclie-booking/account/account.php
 *
 * @var array<string, mixed> $args
 */

use Marclie\Booking\Container;
use Marclie\Booking\Frontend\AccountPages as A;
use Marclie\Booking\I18n\Formatter;

defined( 'ABSPATH' ) || exit;

$part = (string) $args['part'];
$data = $args['data'];
$lang = (string) $args['lang'];
$attr = $args['lang_attr']; // Renderer::langAttr(), safe.

if ( 'board' !== $part ) :
	$profile = is_array( $data ) ? $data['profile'] : null;
	$name    = null === $profile ? '' : trim( $profile['first_name'] . ' ' . $profile['last_name'] );
	?>
<div class="w-page-title__text" data-mbk-widget<?php echo $attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> data-mbk-account="head">
	<h1 class="w-page-title__heading w-heading-xl"><?php esc_html_e( 'My account', 'marclie-booking' ); ?></h1>
	<?php if ( null !== $profile ) : ?>
		<p class="w-page-title__lede w-text-l">
			<?php
			/* translators: 1: guest name, 2: email address. */
			echo esc_html( sprintf( __( 'Signed in as %1$s · %2$s', 'marclie-booking' ), '' !== $name ? $name : $profile['email'], $profile['email'] ) );
			?>
		</p>
	<?php endif; ?>
</div>
	<?php
endif;

if ( 'head' === $part || ! is_array( $data ) ) {
	return;
}

$profile    = $data['profile'];
$upcoming   = $data['bookings']['upcoming'];
$past       = $data['bookings']['past'];
$payments   = $data['payments']['items'];
$card       = $data['payments']['card'];
$coupons    = $data['coupons'];
$membership = $data['membership'];
$urls       = $data['urls'];
$tier       = (string) ( $membership['tier'] ?? 'clan' );
$progress   = A::progress( $membership );
$price      = static fn( $cents ): string => Formatter::price( (int) $cents, $lang );
$tel        = preg_replace( '/[^\d+]/', '', (string) $urls['phone'] );

$tabs = array(
	'overview'   => array( 'squares-four', __( 'Overview', 'marclie-booking' ) ),
	'bookings'   => array( 'calendar-blank', __( 'Bookings', 'marclie-booking' ) ),
	'payments'   => array( 'credit-card', __( 'Payments', 'marclie-booking' ) ),
	'coupons'    => array( 'ticket', __( 'Coupons', 'marclie-booking' ) ),
	'membership' => array( 'star', __( 'Membership', 'marclie-booking' ) ),
	'profile'    => array( 'user', __( 'Your details', 'marclie-booking' ) ),
);

// Ready coupons first; member coupons (welcome / silver / gold) marked.
usort( $coupons, static fn( array $a, array $b ): int => (int) ! A::couponReady( $a ) <=> (int) ! A::couponReady( $b ) );
$ready       = array_values( array_filter( $coupons, array( A::class, 'couponReady' ) ) );
$memberReady = array_values( array_filter( $ready, static fn( array $c ): bool => in_array( $c['kind'] ?? '', array( 'welcome', 'silver', 'gold' ), true ) && ! empty( $c['valid_to'] ) ) );
usort( $memberReady, static fn( array $a, array $b ): int => strcmp( (string) $a['valid_to'], (string) $b['valid_to'] ) );

// Card update: offered while a booking still has a balance to charge.
$owing     = array_filter( $upcoming, static fn( array $b ): bool => (int) ( $b['balance_cents'] ?? 0 ) > 0 && in_array( $b['status'] ?? '', array( 'confirmed', 'balance_failed' ), true ) );
$scheduled = array_values( array_filter( $payments, static fn( array $p ): bool => 'scheduled' === ( $p['status'] ?? '' ) ) );
usort( $scheduled, static fn( array $a, array $b ): int => strcmp( (string) $a['date'], (string) $b['date'] ) );

// Unit photo as the mockup `.w-photo` (attachment image with srcset; REST thumb URL as fallback).
$img = static function ( array $unit ): string {
	$model = empty( $unit['id'] ) ? null : Container::units()->get( (int) $unit['id'] );
	$html  = '';
	if ( null !== $model && $model->thumbnailId ) {
		$html = (string) wp_get_attachment_image(
			$model->thumbnailId,
			'medium_large',
			false,
			array(
				'class'    => 'w-photo__image',
				'sizes'    => '(max-width: 767px) 100vw, 50vw',
				'loading'  => 'lazy',
				'decoding' => 'async',
			)
		);
	}
	if ( '' === $html && ! empty( $unit['thumb'] ) ) {
		$html = '<img class="w-photo__image" src="' . esc_url( (string) $unit['thumb'] ) . '" alt="" loading="lazy" decoding="async">';
	}

	return '' === $html
		? '<div class="w-photo w-photo--4x3"></div>'
		: '<div class="w-photo w-photo--4x3 w-photo--image">' . $html . '</div>';
};
?>
<div class="w-account-board__grid brxe-block" data-mbk-widget<?php echo $attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> data-mbk-account="board" data-home="<?php echo esc_url( $urls['home'] ); ?>">
	<div class="w-account-board__side brxe-block">
		<nav class="w-account-board__nav" role="tablist" aria-label="<?php esc_attr_e( 'Account', 'marclie-booking' ); ?>" data-acct-tabs>
			<?php foreach ( $tabs as $id => [ $icon, $label ] ) : ?>
				<a class="w-account-board__tab" href="#<?php echo esc_attr( $id ); ?>" id="acct-tab-<?php echo esc_attr( $id ); ?>" role="tab" aria-controls="<?php echo esc_attr( $id ); ?>"><i data-icon="<?php echo esc_attr( $icon ); ?>" class="w-icon--small"></i> <span class="w-account-board__tab-text"><?php echo esc_html( $label ); ?></span></a>
			<?php endforeach; ?>
		</nav>
		<a class="w-account-board__logout" href="<?php echo esc_url( $urls['logout'] ); ?>" data-acct-logout><i data-icon="sign-out" class="w-icon--small"></i> <span class="w-account-board__logout-text"><?php esc_html_e( 'Log out', 'marclie-booking' ); ?></span></a>
	</div>

	<div class="w-account-board__panels">
		<?php /* Pregled -------------------------------------------------------------------- */ ?>
		<div class="w-account-board__panel" id="overview" role="tabpanel" aria-labelledby="acct-tab-overview" tabindex="-1">
			<h2 class="w-account-board__heading w-heading-l">
				<?php
				/* translators: %s: first name. */
				echo esc_html( '' !== (string) $profile['first_name'] ? sprintf( __( 'Welcome back, %s', 'marclie-booking' ), $profile['first_name'] ) : __( 'Welcome back', 'marclie-booking' ) );
				?>
			</h2>
			<?php if ( $data['verified'] ) : ?>
				<p class="w-note" role="status"><?php esc_html_e( 'Your email address is confirmed. Welcome!', 'marclie-booking' ); ?></p>
			<?php endif; ?>
			<div class="w-account-board__tiles brxe-block">
				<?php if ( $upcoming ) : ?>
					<?php $next = $upcoming[0]; ?>
					<article class="w-account-board__tile w-account-board__tile--stay brxe-block">
						<?php echo $img( (array) $next['unit'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $img. ?>
						<p class="w-account-board__tile-label"><?php esc_html_e( 'Next stay', 'marclie-booking' ); ?></p>
						<p class="w-account-board__tile-title"><?php echo esc_html( (string) $next['unit']['title'] ); ?></p>
						<p class="w-account-board__tile-text"><?php echo esc_html( implode( ' · ', array_filter( array( A::dateRange( $next['arrival'], $next['departure'], $lang ), A::nights( (int) $next['nights'] ), (string) ( $next['unit']['house'] ?? '' ) ) ) ) ); ?></p>
						<a class="w-account-board__tile-link" href="#bookings"><?php esc_html_e( 'See booking', 'marclie-booking' ); ?></a>
					</article>
				<?php else : ?>
					<article class="w-account-board__tile w-account-board__tile--stay brxe-block">
						<p class="w-account-board__tile-label"><?php esc_html_e( 'Next stay', 'marclie-booking' ); ?></p>
						<p class="w-account-board__tile-title"><?php esc_html_e( 'No upcoming stay yet', 'marclie-booking' ); ?></p>
						<p class="w-account-board__tile-text"><?php esc_html_e( 'See which apartments are free on your dates.', 'marclie-booking' ); ?></p>
						<a class="w-account-board__tile-link" href="<?php echo esc_url( $urls['search'] ); ?>"><?php esc_html_e( 'Find a free apartment', 'marclie-booking' ); ?></a>
					</article>
				<?php endif; ?>
				<article class="w-account-board__tile brxe-block">
					<p class="w-account-board__tile-label"><?php esc_html_e( 'Membership', 'marclie-booking' ); ?></p>
					<p class="w-account-board__tile-title"><?php echo esc_html( A::tierMember( $tier ) ); ?></p>
					<?php if ( null !== $progress ) : ?>
						<progress class="w-account-board__meter" value="<?php echo esc_attr( (string) $progress['done'] ); ?>" max="<?php echo esc_attr( (string) $progress['max'] ); ?>" aria-label="<?php echo esc_attr( A::meterLabel( $progress['next'] ) ); ?>"><?php echo esc_html( A::meterText( $progress['done'], $progress['max'] ) ); ?></progress>
						<p class="w-account-board__tile-text"><?php echo esc_html( A::staysLeft( $progress['left'], $progress['next'] ) ); ?></p>
					<?php else : ?>
						<p class="w-account-board__tile-text"><?php esc_html_e( 'You have reached the highest membership level.', 'marclie-booking' ); ?></p>
					<?php endif; ?>
					<a class="w-account-board__tile-link" href="#membership"><?php esc_html_e( 'See your benefits', 'marclie-booking' ); ?></a>
				</article>
				<article class="w-account-board__tile brxe-block">
					<p class="w-account-board__tile-label"><?php esc_html_e( 'Coupons', 'marclie-booking' ); ?></p>
					<p class="w-account-board__tile-title">
						<?php
						/* translators: %d: number of usable coupons. */
						echo esc_html( sprintf( _n( '%d ready to use', '%d ready to use', count( $ready ), 'marclie-booking' ), count( $ready ) ) );
						?>
					</p>
					<p class="w-account-board__tile-text">
						<?php
						if ( $memberReady ) {
							$until = Formatter::date( (string) $memberReady[0]['valid_to'], $lang );
							echo esc_html(
								match ( $memberReady[0]['kind'] ) {
									/* translators: %s: date. */
									'gold'   => sprintf( __( 'Your Gold coupon is valid until %s.', 'marclie-booking' ), $until ),
									/* translators: %s: date. */
									'silver' => sprintf( __( 'Your Silver coupon is valid until %s.', 'marclie-booking' ), $until ),
									/* translators: %s: date. */
									default  => sprintf( __( 'Your welcome coupon is valid until %s.', 'marclie-booking' ), $until ),
								}
							);
						} else {
							esc_html_e( 'Enter the code in the price summary when you book.', 'marclie-booking' );
						}
						?>
					</p>
					<a class="w-account-board__tile-link" href="#coupons"><?php esc_html_e( 'See coupons', 'marclie-booking' ); ?></a>
				</article>
			</div>
			<?php if ( '' !== $urls['phone'] || '' !== $urls['email'] ) : ?>
				<div class="w-account-board__help">
					<p class="w-account-board__help-text"><?php esc_html_e( 'Questions about a booking? Call or email us — we answer every day in season.', 'marclie-booking' ); ?></p>
					<p class="w-account-board__help-links">
						<?php if ( '' !== $urls['phone'] ) : ?>
							<a class="w-account-board__help-link" href="tel:<?php echo esc_attr( $tel ); ?>"><i data-icon="phone" class="w-icon--small"></i> <span class="w-account-board__help-link-text"><?php echo esc_html( $urls['phone'] ); ?></span></a>
						<?php endif; ?>
						<?php if ( '' !== $urls['email'] ) : ?>
							<a class="w-account-board__help-link" href="mailto:<?php echo esc_attr( antispambot( $urls['email'] ) ); ?>"><i data-icon="envelope-simple" class="w-icon--small"></i> <span class="w-account-board__help-link-text"><?php echo esc_html( antispambot( $urls['email'] ) ); ?></span></a>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>
		</div>

		<?php /* Rezervacije ----------------------------------------------------------------- */ ?>
		<div class="w-account-board__panel" id="bookings" role="tabpanel" aria-labelledby="acct-tab-bookings" tabindex="-1">
			<h2 class="w-account-board__heading w-heading-l"><?php esc_html_e( 'Your bookings', 'marclie-booking' ); ?></h2>
			<h3 class="w-account-board__subheading"><?php esc_html_e( 'Upcoming', 'marclie-booking' ); ?></h3>
			<?php if ( ! $upcoming ) : ?>
				<p class="w-account-board__fine"><?php esc_html_e( 'You have no upcoming bookings.', 'marclie-booking' ); ?> <a href="<?php echo esc_url( $urls['search'] ); ?>"><?php esc_html_e( 'Find a free apartment', 'marclie-booking' ); ?></a></p>
			<?php endif; ?>
			<?php
			foreach ( $upcoming as $b ) :
				[ $statusLabel, $statusClass ] = A::status( (string) $b['status'] );
				$unit    = $b['unit'];
				$actions = (array) ( $b['actions'] ?? array() );
				$paid    = (int) ( $b['paid_cents'] ?? 0 );
				$balance = (int) ( $b['balance_cents'] ?? 0 );
				if ( $balance <= 0 ) {
					/* translators: %s: amount paid. */
					$paidText = sprintf( __( '%s, paid in full', 'marclie-booking' ), $price( $paid ) );
				} else {
					/* translators: %s: amount. */
					$parts = $paid > 0 ? array( sprintf( __( '%s deposit', 'marclie-booking' ), $price( $paid ) ) ) : array();
					$parts[] = empty( $b['balance_due'] )
						/* translators: %s: amount. */
						? sprintf( __( '%s outstanding', 'marclie-booking' ), $price( $balance ) )
						/* translators: 1: amount, 2: date. */
						: sprintf( __( '%1$s on %2$s', 'marclie-booking' ), $price( $balance ), Formatter::date( (string) $b['balance_due'], $lang ) );
					$paidText = implode( ' · ', $parts );
				}
				$freeCancel = ! empty( $b['free_cancel_until'] ) && (string) $b['free_cancel_until'] >= current_time( 'Y-m-d' );
				$requested  = ! empty( $b['cancel_requested'] );
				?>
				<article class="w-account-board__booking brxe-block" data-acct-booking data-ref="<?php echo esc_attr( (string) $b['ref'] ); ?>">
					<?php echo $img( (array) $unit ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $img. ?>
					<div class="w-account-board__booking-body">
						<p class="w-account-board__booking-kicker"><?php echo esc_html( (string) ( $unit['place'] ?? $unit['house'] ?? '' ) ); ?> <span class="<?php echo esc_attr( trim( 'w-account-board__status ' . $statusClass ) ); ?>"><?php echo esc_html( $statusLabel ); ?></span></p>
						<p class="w-account-board__booking-title"><a class="w-account-board__booking-link" data-acct-booking-link href="<?php echo esc_url( (string) $unit['permalink'] ); ?>"><?php echo esc_html( (string) $unit['title'] ); ?></a></p>
						<dl class="w-account-board__facts">
							<div class="w-account-board__fact"><dt class="w-account-board__fact-term"><?php esc_html_e( 'Dates', 'marclie-booking' ); ?></dt><dd class="w-account-board__fact-value"><?php echo esc_html( A::dateRange( $b['arrival'], $b['departure'], $lang ) . ' · ' . A::nights( (int) $b['nights'] ) ); ?></dd></div>
							<div class="w-account-board__fact"><dt class="w-account-board__fact-term"><?php esc_html_e( 'Guests', 'marclie-booking' ); ?></dt><dd class="w-account-board__fact-value"><?php echo esc_html( A::party( (int) $b['adults'], (int) $b['children'], (int) ( $b['infants'] ?? 0 ) ) ); ?></dd></div>
							<div class="w-account-board__fact"><dt class="w-account-board__fact-term"><?php esc_html_e( 'Booking', 'marclie-booking' ); ?></dt><dd class="w-account-board__fact-value"><?php echo esc_html( (string) $b['ref'] ); ?></dd></div>
							<div class="w-account-board__fact"><dt class="w-account-board__fact-term"><?php esc_html_e( 'Paid', 'marclie-booking' ); ?></dt><dd class="w-account-board__fact-value"><?php echo esc_html( $paidText ); ?></dd></div>
						</dl>
						<?php if ( $freeCancel ) : ?>
							<p class="w-account-board__cancel"><i data-icon="shield-check" class="w-icon--small"></i> <span class="w-account-board__cancel-text">
								<?php
								/* translators: %s: date. */
								echo esc_html( sprintf( __( 'Free cancellation until %s.', 'marclie-booking' ), Formatter::date( (string) $b['free_cancel_until'], $lang ) ) );
								?>
							</span></p>
						<?php endif; ?>
						<p class="w-account-board__cancel" data-acct-requested<?php echo $requested ? '' : ' hidden'; ?>><i data-icon="clock" class="w-icon--small"></i> <span class="w-account-board__cancel-text"><?php esc_html_e( 'Cancellation requested. We will reply by email.', 'marclie-booking' ); ?></span></p>
						<div class="w-account-board__actions brxe-block">
							<a class="w-button-primary w-button-primary--small" href="<?php echo esc_url( (string) $unit['permalink'] ); ?>"><?php esc_html_e( 'View apartment', 'marclie-booking' ); ?></a>
							<?php if ( ! empty( $actions['manage_url'] ) ) : ?>
								<a class="w-button-secondary w-button-secondary--small" href="<?php echo esc_url( (string) $actions['manage_url'] ); ?>"><?php esc_html_e( 'Booking details', 'marclie-booking' ); ?></a>
							<?php endif; ?>
							<?php if ( '' !== $urls['guide'] ) : ?>
								<a class="w-button-secondary w-button-secondary--small" href="<?php echo esc_url( $urls['guide'] ); ?>"><?php esc_html_e( 'Getting here', 'marclie-booking' ); ?></a>
							<?php endif; ?>
							<a class="w-button-secondary w-button-secondary--small" href="<?php echo esc_url( $urls['contact'] ); ?>"><?php esc_html_e( 'Ask about this booking', 'marclie-booking' ); ?></a>
							<?php if ( ! empty( $actions['can_request_cancel'] ) && ! $requested ) : ?>
								<button class="w-button-secondary w-button-secondary--small" type="button" data-acct-cancel="<?php echo esc_attr( (string) $b['ref'] ); ?>" data-title="<?php echo esc_attr( (string) $unit['title'] . ' · ' . A::dateRange( $b['arrival'], $b['departure'], $lang ) ); ?>" aria-haspopup="dialog"><?php esc_html_e( 'Request cancellation', 'marclie-booking' ); ?></button>
							<?php endif; ?>
						</div>
					</div>
				</article>
			<?php endforeach; ?>

			<?php if ( $past ) : ?>
				<h3 class="w-account-board__subheading"><?php esc_html_e( 'Past and cancelled', 'marclie-booking' ); ?></h3>
				<ul class="w-account-board__history">
					<?php
					foreach ( $past as $b ) :
						[ $statusLabel, $statusClass ] = A::status( (string) $b['status'] );
						$unit      = $b['unit'];
						$completed = 'completed' === $b['status'];
						$amount    = '';
						if ( $completed ) {
							$amount = empty( $b['coupon_code'] )
								? $price( $b['total_cents'] ?? 0 )
								/* translators: 1: amount, 2: coupon code. */
								: sprintf( __( '%1$s with coupon %2$s', 'marclie-booking' ), $price( $b['total_cents'] ?? 0 ), (string) $b['coupon_code'] );
						} elseif ( (int) ( $b['refunded_cents'] ?? 0 ) > 0 ) {
							/* translators: %s: amount. */
							$amount = sprintf( __( '%s refunded', 'marclie-booking' ), $price( $b['refunded_cents'] ) );
						}
						$rebook = (string) ( $b['actions']['rebook_url'] ?? '' );
						?>
						<li class="w-account-board__history-item">
							<?php echo $img( (array) $unit ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $img. ?>
							<div class="w-account-board__history-body">
								<p class="w-account-board__history-title"><a class="w-account-board__history-title-link" href="<?php echo esc_url( (string) $unit['permalink'] ); ?>"><?php echo esc_html( (string) $unit['title'] ); ?></a></p>
								<p class="w-account-board__history-text"><?php echo esc_html( implode( ' · ', array_filter( array( A::dateRange( $b['arrival'], $b['departure'], $lang ), A::nights( (int) $b['nights'] ), (string) $b['ref'], $amount ) ) ) ); ?></p>
							</div>
							<span class="<?php echo esc_attr( trim( 'w-account-board__status ' . $statusClass ) ); ?>"><?php echo esc_html( $statusLabel ); ?></span>
							<?php if ( $completed && '' !== $rebook ) : ?>
								<a class="w-account-board__history-link" href="<?php echo esc_url( $rebook ); ?>"><?php esc_html_e( 'Book again', 'marclie-booking' ); ?></a>
							<?php else : ?>
								<a class="w-account-board__history-link" href="<?php echo esc_url( (string) $unit['permalink'] ); ?>"><?php esc_html_e( 'View apartment', 'marclie-booking' ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<?php /* Plaćanja -------------------------------------------------------------------- */ ?>
		<div class="w-account-board__panel" id="payments" role="tabpanel" aria-labelledby="acct-tab-payments" tabindex="-1">
			<h2 class="w-account-board__heading w-heading-l"><?php esc_html_e( 'Payments', 'marclie-booking' ); ?></h2>
			<?php if ( ! $payments ) : ?>
				<p class="w-account-board__fine"><?php esc_html_e( 'No payments yet.', 'marclie-booking' ); ?></p>
			<?php else : ?>
				<div class="w-account-board__table-wrap">
					<table class="w-account-board__table">
						<caption class="w-visually-hidden"><?php esc_html_e( 'Payments and refunds, newest first', 'marclie-booking' ); ?></caption>
						<thead class="w-account-board__table-head"><tr class="w-account-board__table-row">
							<th class="w-account-board__table-heading" scope="col"><?php esc_html_e( 'Date', 'marclie-booking' ); ?></th>
							<th class="w-account-board__table-heading" scope="col"><?php esc_html_e( 'Apartment', 'marclie-booking' ); ?></th>
							<th class="w-account-board__table-heading" scope="col"><?php esc_html_e( 'Booking', 'marclie-booking' ); ?></th>
							<th class="w-account-board__table-heading" scope="col"><?php esc_html_e( 'Card', 'marclie-booking' ); ?></th>
							<th class="w-account-board__table-heading" scope="col"><?php esc_html_e( 'Amount', 'marclie-booking' ); ?></th>
							<th class="w-account-board__table-heading" scope="col"><?php esc_html_e( 'Status', 'marclie-booking' ); ?></th>
						</tr></thead>
						<tbody class="w-account-board__table-body">
							<?php
							foreach ( $payments as $p ) :
								[ $pLabel, $pClass ] = A::paymentStatus( (string) $p['status'] );
								$amount = (int) $p['amount_cents'];
								$amount = 'refund' === $p['kind'] ? -abs( $amount ) : $amount;
								$cardText = A::card( is_array( $p['card'] ?? null ) ? $p['card'] : null );
								?>
								<tr class="w-account-board__table-row">
									<td class="w-account-board__table-cell" data-label="<?php esc_attr_e( 'Date', 'marclie-booking' ); ?>"><?php echo esc_html( A::shortDate( (string) $p['date'], $lang ) ); ?></td>
									<td class="w-account-board__table-cell" data-label="<?php esc_attr_e( 'Apartment', 'marclie-booking' ); ?>"><?php echo esc_html( (string) $p['unit_title'] . ' · ' . A::paymentKind( (string) $p['kind'] ) ); ?></td>
									<td class="w-account-board__table-cell" data-label="<?php esc_attr_e( 'Booking', 'marclie-booking' ); ?>"><?php echo esc_html( (string) $p['ref'] ); ?></td>
									<td class="w-account-board__table-cell" data-label="<?php esc_attr_e( 'Card', 'marclie-booking' ); ?>"><?php echo esc_html( '' !== $cardText ? $cardText : '—' ); ?></td>
									<td class="w-account-board__table-cell" data-label="<?php esc_attr_e( 'Amount', 'marclie-booking' ); ?>"><?php echo esc_html( $price( $amount ) ); ?></td>
									<td class="w-account-board__table-cell" data-label="<?php esc_attr_e( 'Status', 'marclie-booking' ); ?>"><span class="<?php echo esc_attr( trim( 'w-account-board__status ' . $pClass ) ); ?>"><?php echo esc_html( $pLabel ); ?></span></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<h3 class="w-account-board__subheading"><?php esc_html_e( 'Saved card', 'marclie-booking' ); ?></h3>
			<?php if ( null !== $card && '' !== A::card( $card ) ) : ?>
				<ul class="w-account-board__saved">
					<li class="w-account-board__saved-card"><i data-icon="credit-card"></i><span class="w-account-board__saved-number" data-acct-card-label><?php echo esc_html( A::card( $card ) ); ?></span><span class="w-account-board__saved-expiry">
						<?php
						$exp = array();
						if ( ! empty( $card['exp_month'] ) && ! empty( $card['exp_year'] ) ) {
							/* translators: %s: card expiry, e.g. "09/29". */
							$exp[] = sprintf( __( 'Expires %s', 'marclie-booking' ), sprintf( '%02d/%02d', (int) $card['exp_month'], (int) $card['exp_year'] % 100 ) );
						}
						if ( $scheduled ) {
							/* translators: %s: date. */
							$exp[] = sprintf( __( 'used for the balance on %s', 'marclie-booking' ), Formatter::date( (string) $scheduled[0]['date'], $lang ) );
						}
						echo esc_html( implode( ' · ', $exp ) );
						?>
					</span></li>
				</ul>
			<?php else : ?>
				<p class="w-account-board__fine"><?php esc_html_e( 'No card saved.', 'marclie-booking' ); ?></p>
			<?php endif; ?>
			<?php if ( $owing ) : ?>
				<div data-acct-card>
					<p class="w-account-board__fine"><?php esc_html_e( 'New card? Save it here and we charge the balance of your upcoming bookings to it.', 'marclie-booking' ); ?></p>
					<button class="w-button-secondary w-button-secondary--small" type="button" data-acct-card-open aria-expanded="false" aria-controls="acct-card-form"><i data-icon="credit-card" class="w-icon--small"></i> <span class="w-button-secondary__label"><?php echo esc_html( null !== $card ? __( 'Change card', 'marclie-booking' ) : __( 'Add a card', 'marclie-booking' ) ); ?></span></button>
					<form class="w-account-board__form" id="acct-card-form" data-acct-form="card" novalidate hidden>
						<p class="w-note" role="alert" data-acct-alert hidden></p>
						<div data-acct-card-element data-stripe-element aria-busy="true"><span class="mbk-spinner" aria-hidden="true"></span><span class="w-visually-hidden"><?php esc_html_e( 'Loading the secure card form…', 'marclie-booking' ); ?></span></div>
						<div class="w-account-board__actions brxe-block">
							<button class="w-button-primary w-button-primary--small" type="submit" disabled><?php esc_html_e( 'Save card', 'marclie-booking' ); ?></button>
							<button class="w-button-secondary w-button-secondary--small" type="button" data-acct-card-close><?php esc_html_e( 'Close', 'marclie-booking' ); ?></button>
						</div>
					</form>
					<div class="w-note" data-acct-card-offline tabindex="-1" hidden>
						<p><strong class="w-note__title"><?php esc_html_e( 'Card changes are not available online right now.', 'marclie-booking' ); ?></strong></p>
						<p>
							<?php
							esc_html_e( 'Your booking is safe. Please contact us and we will update the card with you.', 'marclie-booking' );
							if ( '' !== $urls['phone'] ) {
								echo ' <a href="tel:' . esc_attr( $tel ) . '">' . esc_html( $urls['phone'] ) . '</a>';
							}
							?>
						</p>
					</div>
					<p class="w-note" role="status" data-acct-done="card" tabindex="-1" hidden><?php esc_html_e( 'Your new card is saved. We charge the balance to it.', 'marclie-booking' ); ?></p>
				</div>
			<?php endif; ?>
			<p class="w-account-board__fine"><?php esc_html_e( 'Card payments run over an encrypted connection. Prices in EUR; tourist tax is paid on site.', 'marclie-booking' ); ?></p>
		</div>

		<?php /* Kuponi ---------------------------------------------------------------------- */ ?>
		<div class="w-account-board__panel" id="coupons" role="tabpanel" aria-labelledby="acct-tab-coupons" tabindex="-1">
			<h2 class="w-account-board__heading w-heading-l"><?php esc_html_e( 'Coupons', 'marclie-booking' ); ?></h2>
			<p class="w-account-board__fine"><?php esc_html_e( 'One coupon per booking. Enter the code in the price summary when you book.', 'marclie-booking' ); ?></p>
			<?php if ( $coupons ) : ?>
				<div class="w-account-board__coupons brxe-block">
					<?php
					foreach ( $coupons as $c ) :
						[ $cText, $cMeta ] = A::couponTexts( $c, $lang );
						$isReady  = A::couponReady( $c );
						$isMember = in_array( $c['kind'] ?? '', array( 'welcome', 'silver', 'gold' ), true );
						$classes  = 'w-account-board__coupon brxe-block' . ( $isReady ? ( $isMember ? ' w-account-board__coupon--member' : '' ) : ' w-account-board__coupon--used' );
						/* translators: %s: coupon code. */
						$label = sprintf( __( 'Coupon %s', 'marclie-booking' ), (string) $c['code'] );
						?>
						<div class="<?php echo esc_attr( $classes ); ?>" role="group" aria-label="<?php echo esc_attr( $label ); ?>">
							<p class="w-account-board__coupon-code"><?php echo esc_html( (string) $c['code'] ); ?></p>
							<p class="w-account-board__coupon-text"><?php echo esc_html( $cText ); ?></p>
							<p class="w-account-board__coupon-meta"><?php echo esc_html( $cMeta ); ?></p>
							<?php if ( $isReady ) : ?>
								<button class="w-button-secondary w-button-secondary--small" type="button" data-copy="<?php echo esc_attr( (string) $c['code'] ); ?>" data-copy-done="<?php esc_attr_e( 'Code copied', 'marclie-booking' ); ?>"><i data-icon="copy" class="w-icon--small"></i> <span class="w-button-secondary__label"><?php esc_html_e( 'Copy code', 'marclie-booking' ); ?></span></button>
								<p class="w-account-board__coupon-status" data-copy-status aria-live="polite"></p>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<?php /* Članstvo -------------------------------------------------------------------- */ ?>
		<div class="w-account-board__panel" id="membership" role="tabpanel" aria-labelledby="acct-tab-membership" tabindex="-1">
			<h2 class="w-account-board__heading w-heading-l"><?php esc_html_e( 'Membership', 'marclie-booking' ); ?></h2>
			<div class="w-account-board__member brxe-block">
				<p class="w-account-board__member-brand"><?php echo esc_html( mb_strtoupper( \Marclie\Booking\Email\BusinessInfo::get()['name'] ) ); ?></p>
				<p class="w-account-board__member-tier"><?php echo esc_html( A::tierName( $tier ) ); ?></p>
				<p class="w-account-board__member-name">
					<?php
					$memberName = trim( $profile['first_name'] . ' ' . $profile['last_name'] );
					/* translators: %s: month and year, e.g. "March 2026". */
					$since = empty( $membership['member_since'] ) ? '' : sprintf( __( 'member since %s', 'marclie-booking' ), A::monthYear( (string) $membership['member_since'], $lang ) );
					echo esc_html( implode( ' · ', array_filter( array( $memberName, $since ) ) ) );
					?>
				</p>
				<?php if ( null !== $progress ) : ?>
					<progress class="w-account-board__meter w-account-board__meter--light" value="<?php echo esc_attr( (string) $progress['done'] ); ?>" max="<?php echo esc_attr( (string) $progress['max'] ); ?>" aria-label="<?php echo esc_attr( A::meterLabel( $progress['next'] ) ); ?>"><?php echo esc_html( A::meterText( $progress['done'], $progress['max'] ) ); ?></progress>
					<p class="w-account-board__member-next">
						<?php
						/* translators: 1: completed stays, 2: stays needed for the next tier. */
						$stays = sprintf( _n( '%1$d of %2$d completed stay', '%1$d of %2$d completed stays', $progress['max'], 'marclie-booking' ), $progress['done'], $progress['max'] );
						$more  = 'zlatni' === $progress['next']
							/* translators: %d: completed stays still needed. */
							? sprintf( __( '%d more to reach Gold', 'marclie-booking' ), $progress['left'] )
							/* translators: %d: completed stays still needed. */
							: sprintf( __( '%d more to reach Silver', 'marclie-booking' ), $progress['left'] );
						echo esc_html( $stays . ' · ' . $more );
						?>
					</p>
				<?php else : ?>
					<p class="w-account-board__member-next"><?php esc_html_e( 'You have reached the highest membership level.', 'marclie-booking' ); ?></p>
				<?php endif; ?>
			</div>
			<ol class="w-account-board__tiers">
				<?php
				$tiers = array(
					'clan'    => array(
						__( 'When you create your account', 'marclie-booking' ),
						array(
							__( 'All bookings and payments in your account', 'marclie-booking' ),
							__( 'Welcome coupon: 5% off your first booking', 'marclie-booking' ),
						),
					),
					'srebrni' => array(
						__( 'After your first completed stay', 'marclie-booking' ),
						array(
							__( 'Everything in Member', 'marclie-booking' ),
							__( 'A Silver coupon each year: 5% off a stay of 7 nights or more', 'marclie-booking' ),
							__( 'Early check-in when the apartment is free', 'marclie-booking' ),
						),
					),
					'zlatni'  => array(
						__( 'After 3 completed stays', 'marclie-booking' ),
						array(
							__( 'Everything in Silver', 'marclie-booking' ),
							__( 'A Gold coupon each year: 10% off one stay', 'marclie-booking' ),
							__( 'Late check-out when the apartment is free', 'marclie-booking' ),
						),
					),
				);
				foreach ( $tiers as $key => [ $rule, $perks ] ) :
					$current = $key === $tier;
					?>
					<li class="w-account-board__tier<?php echo $current ? ' is-current' : ''; ?>"<?php echo $current ? ' aria-current="true"' : ''; ?>>
						<p class="w-account-board__tier-name"><?php echo esc_html( A::tierName( $key ) ); ?>
							<?php if ( $current ) : ?>
								<span class="w-account-board__tier-badge"><?php esc_html_e( 'Your level', 'marclie-booking' ); ?></span>
							<?php endif; ?>
						</p>
						<p class="w-account-board__tier-rule"><?php echo esc_html( $rule ); ?></p>
						<ul class="w-account-board__tier-perks">
							<?php foreach ( $perks as $perk ) : ?>
								<li class="w-account-board__tier-perk"><?php echo esc_html( $perk ); ?></li>
							<?php endforeach; ?>
						</ul>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>

		<?php /* Vaši podaci ----------------------------------------------------------------- */ ?>
		<div class="w-account-board__panel" id="profile" role="tabpanel" aria-labelledby="acct-tab-profile" tabindex="-1">
			<h2 class="w-account-board__heading w-heading-l"><?php esc_html_e( 'Your details', 'marclie-booking' ); ?></h2>
			<form class="w-account-board__form" data-acct-form="profile" novalidate>
				<p class="w-note" role="alert" data-acct-alert hidden></p>
				<div class="w-account-board__row">
					<div class="w-field" data-acct-field><label class="w-field__label" for="acc-first"><?php esc_html_e( 'First name', 'marclie-booking' ); ?></label><input class="w-field__input" id="acc-first" name="first_name" type="text" autocomplete="given-name" maxlength="100" value="<?php echo esc_attr( (string) $profile['first_name'] ); ?>" required data-field="first_name"></div>
					<div class="w-field" data-acct-field><label class="w-field__label" for="acc-last"><?php esc_html_e( 'Last name', 'marclie-booking' ); ?></label><input class="w-field__input" id="acc-last" name="last_name" type="text" autocomplete="family-name" maxlength="100" value="<?php echo esc_attr( (string) $profile['last_name'] ); ?>" required data-field="last_name"></div>
				</div>
				<div class="w-account-board__row">
					<div class="w-field" data-acct-field><label class="w-field__label" for="acc-email"><?php esc_html_e( 'Email', 'marclie-booking' ); ?></label><input class="w-field__input" id="acc-email" name="email" type="email" autocomplete="email" value="<?php echo esc_attr( (string) $profile['email'] ); ?>" readonly aria-describedby="acc-email-hint"><span class="w-field__hint" id="acc-email-hint"><?php esc_html_e( 'To change your email address, please contact us.', 'marclie-booking' ); ?></span></div>
					<div class="w-field" data-acct-field><label class="w-field__label" for="acc-phone"><?php esc_html_e( 'Phone, with country code', 'marclie-booking' ); ?> <span class="w-field__optional"><?php esc_html_e( '(optional)', 'marclie-booking' ); ?></span></label><input class="w-field__input" id="acc-phone" name="phone" type="tel" autocomplete="tel" maxlength="40" placeholder="<?php echo esc_attr_x( '+44 20 7946 0958', 'phone number example', 'marclie-booking' ); ?>" value="<?php echo esc_attr( (string) ( $profile['phone'] ?? '' ) ); ?>" data-field="phone"></div>
				</div>
				<div class="w-account-board__row">
					<div class="w-field" data-acct-field><label class="w-field__label" for="acc-country"><?php esc_html_e( 'Country', 'marclie-booking' ); ?> <span class="w-field__optional"><?php esc_html_e( '(optional)', 'marclie-booking' ); ?></span></label>
						<select class="w-field__select" id="acc-country" name="country" autocomplete="country" data-field="country">
							<option value=""><?php esc_html_e( 'Choose a country', 'marclie-booking' ); ?></option>
							<?php foreach ( A::countries( $lang ) as $code => $countryName ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>"<?php selected( (string) ( $profile['country'] ?? '' ), $code ); ?>><?php echo esc_html( $countryName ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="w-field" data-acct-field><label class="w-field__label" for="acc-lang"><?php esc_html_e( 'Language of our emails', 'marclie-booking' ); ?></label>
						<select class="w-field__select" id="acc-lang" name="lang" data-field="lang">
							<option value="hr"<?php selected( (string) ( $profile['lang'] ?? $lang ), 'hr' ); ?> lang="hr">Hrvatski</option>
							<option value="en"<?php selected( (string) ( $profile['lang'] ?? $lang ), 'en' ); ?> lang="en">English</option>
						</select>
					</div>
				</div>
				<label class="w-checkbox" data-acct-check><input class="w-checkbox__input" type="checkbox" name="newsletter" value="1"<?php checked( ! empty( $profile['newsletter'] ) ); ?>> <span class="w-checkbox__text"><?php esc_html_e( 'Send me news and member offers by email', 'marclie-booking' ); ?></span></label>
				<button class="w-button-primary" type="submit"><?php esc_html_e( 'Save changes', 'marclie-booking' ); ?></button>
				<p class="w-note" role="status" data-acct-done="profile" hidden><?php esc_html_e( 'Your details are saved.', 'marclie-booking' ); ?></p>
			</form>

			<h3 class="w-account-board__subheading"><?php esc_html_e( 'Password', 'marclie-booking' ); ?></h3>
			<form class="w-account-board__form" data-acct-form="password" novalidate>
				<p class="w-note" role="alert" data-acct-alert hidden></p>
				<input type="text" name="username" autocomplete="username" value="<?php echo esc_attr( (string) $profile['email'] ); ?>" hidden>
				<div class="w-account-board__row">
					<div class="w-field" data-acct-field><label class="w-field__label" for="acc-pass"><?php esc_html_e( 'Current password', 'marclie-booking' ); ?></label><input class="w-field__input" id="acc-pass" name="current" type="password" autocomplete="current-password" required data-field="current"></div>
					<div class="w-field" data-acct-field><label class="w-field__label" for="acc-new"><?php esc_html_e( 'New password', 'marclie-booking' ); ?></label><input class="w-field__input" id="acc-new" name="new" type="password" autocomplete="new-password" minlength="8" aria-describedby="acc-new-hint" required data-field="new"><span class="w-field__hint" id="acc-new-hint"><?php esc_html_e( 'At least 8 characters.', 'marclie-booking' ); ?></span></div>
				</div>
				<button class="w-button-secondary" type="submit"><?php esc_html_e( 'Change password', 'marclie-booking' ); ?></button>
				<p class="w-note" role="status" data-acct-done="password" hidden><?php esc_html_e( 'Your password is changed.', 'marclie-booking' ); ?></p>
			</form>

			<h3 class="w-account-board__subheading"><?php esc_html_e( 'Delete account', 'marclie-booking' ); ?></h3>
			<p class="w-account-board__fine"><?php esc_html_e( 'Your account, membership and coupons are deleted for good. We keep your bookings as the law requires, but they are no longer linked to an account.', 'marclie-booking' ); ?></p>
			<p class="w-account-board__fine"><button class="w-button-secondary w-button-secondary--small" type="button" data-acct-dialog-open="delete" aria-haspopup="dialog"><?php esc_html_e( 'Delete account', 'marclie-booking' ); ?></button></p>
		</div>
	</div>

	<dialog data-acct-dialog="cancel" aria-labelledby="acct-cancel-title">
		<form data-acct-form="cancel" novalidate>
			<h2 class="w-account-board__heading w-heading-l" id="acct-cancel-title"><?php esc_html_e( 'Request cancellation', 'marclie-booking' ); ?></h2>
			<p class="w-account-board__fine" data-acct-cancel-booking></p>
			<p class="w-account-board__help-text"><?php esc_html_e( 'Send us your request and we will reply by email. The booking stays active until we cancel it; the cancellation terms depend on the date of your request.', 'marclie-booking' ); ?></p>
			<p class="w-note" role="alert" data-acct-alert hidden></p>
			<input type="hidden" name="ref" value="">
			<div class="w-field" data-acct-field><label class="w-field__label" for="acct-cancel-reason"><?php esc_html_e( 'Reason', 'marclie-booking' ); ?> <span class="w-field__optional"><?php esc_html_e( '(optional)', 'marclie-booking' ); ?></span></label><textarea class="w-field__textarea" id="acct-cancel-reason" name="reason" rows="3" maxlength="2000" data-field="reason"></textarea></div>
			<div class="w-account-board__actions brxe-block">
				<button class="w-button-primary w-button-primary--small" type="submit"><?php esc_html_e( 'Send request', 'marclie-booking' ); ?></button>
				<button class="w-button-secondary w-button-secondary--small" type="button" data-acct-dialog-close><?php esc_html_e( 'Close', 'marclie-booking' ); ?></button>
			</div>
		</form>
		<div data-acct-done="cancel" hidden>
			<h2 class="w-account-board__heading w-heading-l" tabindex="-1"><?php esc_html_e( 'Request sent', 'marclie-booking' ); ?></h2>
			<p class="w-account-board__help-text"><?php esc_html_e( 'We have your cancellation request and will reply by email.', 'marclie-booking' ); ?></p>
			<div class="w-account-board__actions brxe-block"><button class="w-button-primary w-button-primary--small" type="button" data-acct-dialog-close><?php esc_html_e( 'Close', 'marclie-booking' ); ?></button></div>
		</div>
	</dialog>

	<dialog data-acct-dialog="delete" aria-labelledby="acct-delete-title">
		<form data-acct-form="delete" novalidate>
			<h2 class="w-account-board__heading w-heading-l" id="acct-delete-title"><?php esc_html_e( 'Delete account', 'marclie-booking' ); ?></h2>
			<p class="w-account-board__help-text"><?php esc_html_e( 'Your account, membership and coupons are deleted for good. We keep your bookings as the law requires, but they are no longer linked to an account.', 'marclie-booking' ); ?></p>
			<p class="w-note" role="alert" data-acct-alert hidden></p>
			<input type="text" name="username" autocomplete="username" value="<?php echo esc_attr( (string) $profile['email'] ); ?>" hidden>
			<div class="w-field" data-acct-field><label class="w-field__label" for="acct-delete-password"><?php esc_html_e( 'Password', 'marclie-booking' ); ?></label><input class="w-field__input" id="acct-delete-password" name="password" type="password" autocomplete="current-password" required data-field="password"></div>
			<div class="w-field" data-acct-field><label class="w-field__label" for="acct-delete-confirm"><?php esc_html_e( 'Type DELETE to confirm', 'marclie-booking' ); ?></label><input class="w-field__input" id="acct-delete-confirm" name="confirm" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" required data-field="confirm"></div>
			<div class="w-account-board__actions brxe-block">
				<button class="w-button-primary w-button-primary--small" type="submit"><?php esc_html_e( 'Delete my account', 'marclie-booking' ); ?></button>
				<button class="w-button-secondary w-button-secondary--small" type="button" data-acct-dialog-close><?php esc_html_e( 'Close', 'marclie-booking' ); ?></button>
			</div>
		</form>
	</dialog>
</div>
