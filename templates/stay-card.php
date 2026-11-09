<?php
/**
 * One unit card in the search results (mockup `Card: Stay`); data-* attributes feed the client facets/sort.
 * With a stay (URL dates) the priced variant `w-card-stay--priced`: stay total + "Book now" (pretraga); without
 * one the plain card: "from X / person / night", no actions (apartmani). The photo slider ([data-slider]: featured image +
 * gallery, prev/next, dots) is driven by the theme script.
 * `$args['eager']`: first card on the page — its first photo is not lazy-loaded.
 *
 * Override: {child-theme}/marclie-booking/stay-card.php
 *
 * @var array<string, mixed> $args
 */

defined( 'ABSPATH' ) || exit;

/** @var \Marclie\Booking\Units\Unit $unit */
$unit   = $args['unit'];
$priced = null !== $args['total'];
$class  = $priced ? 'w-card-stay w-card-stay--priced' : 'w-card-stay';
$photos = $args['photos'] ?? ( $unit->thumbnailId ? array( $unit->thumbnailId ) : array() );
$attrs  = '';
foreach ( $args['data'] as $name => $value ) {
	$attrs .= ' data-' . $name . '="' . esc_attr( (string) $value ) . '"';
}
?>
<article class="<?php echo esc_attr( $class ); ?>" data-product<?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>>
	<div class="w-card-stay__media w-slider" data-slider>
		<div class="w-slider__track"><?php foreach ( $photos as $i => $photo ) : ?><a class="w-slide<?php echo 0 === $i ? ' is-active' : ''; ?> w-card-stay__slide" href="<?php echo esc_url( $args['permalink'] ); ?>" tabindex="-1"><?php
			$img = wp_get_attachment_image(
				$photo,
				'medium_large',
				false,
				array(
					'class'    => 'w-photo__image',
					'loading'  => 0 === $i && ! empty( $args['eager'] ) ? false : 'lazy',
					'decoding' => 'async',
					'sizes'    => '(max-width: 478px) 100vw, (max-width: 767px) 50vw, (max-width: 991px) 33vw, 25vw',
				)
			);
			?><span class="w-photo w-photo--4x3 w-card-stay__photo<?php echo '' !== $img ? ' w-photo--image' : ''; ?>"><?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image markup. ?></span></a><?php endforeach; ?></div>
		<?php if ( $args['badges'] ) : ?>
			<p class="w-card-stay__badges"><?php foreach ( $args['badges'] as $badge ) : ?><span class="w-card-stay__badge"><?php echo esc_html( $badge ); ?></span><?php endforeach; ?></p>
		<?php endif; ?>
		<?php if ( count( $photos ) > 1 ) : ?>
			<button aria-label="<?php /* translators: %s: apartment name. */ echo esc_attr( sprintf( __( 'Previous photo: %s', 'marclie-booking' ), $unit->title ) ); ?>" class="w-slider__nav w-card-stay__nav w-card-stay__nav--prev" data-slider-prev type="button"><i data-icon="caret-left" class="w-icon--small"></i></button>
			<button aria-label="<?php /* translators: %s: apartment name. */ echo esc_attr( sprintf( __( 'Next photo: %s', 'marclie-booking' ), $unit->title ) ); ?>" class="w-slider__nav w-card-stay__nav w-card-stay__nav--next" data-slider-next type="button"><i data-icon="caret-right" class="w-icon--small"></i></button>
			<div class="w-card-stay__dots"><div class="w-slider__dots w-slider__dots--overlay"></div><span class="w-visually-hidden" data-slider-counter><?php echo esc_html( '1 / ' . count( $photos ) ); ?></span></div>
		<?php endif; ?>
	</div>
	<div class="w-card-stay__body">
		<?php if ( '' !== $args['place'] ) : ?>
			<p class="w-card-stay__place"><?php echo esc_html( $args['place'] ); ?></p>
		<?php endif; ?>
		<h3 class="w-card-stay__title"><a class="w-card-stay__link" href="<?php echo esc_url( $args['permalink'] ); ?>"><?php echo esc_html( $unit->title ); ?></a></h3>
		<p class="w-card-stay__meta"><span class="w-card-stay__fact"><i data-icon="users" aria-label="<?php esc_attr_e( 'Guests', 'marclie-booking' ); ?>" class="w-icon--small w-card-stay__fact-icon"></i><span class="w-card-stay__fact-text"><?php echo (int) $args['guests']; ?></span></span><span class="w-card-stay__fact"><i data-icon="bed" class="w-icon--small w-card-stay__fact-icon"></i><span class="w-card-stay__fact-text"><?php
		if ( $args['bedrooms'] > 0 ) {
			/* translators: %d: number of bedrooms (short card fact, e.g. "2 rooms"). */
			echo esc_html( sprintf( _n( '%d room', '%d rooms', $args['bedrooms'], 'marclie-booking' ), $args['bedrooms'] ) );
		} else {
			/* translators: card fact of a unit without a separate bedroom. */
			esc_html_e( 'Studio', 'marclie-booking' );
		}
		?></span></span><?php if ( $args['size'] > 0 ) : ?><span class="w-card-stay__fact"><i data-icon="frame-corners" class="w-icon--small w-card-stay__fact-icon"></i><span class="w-card-stay__fact-text"><?php echo esc_html( $args['size'] . ' m²' ); ?></span></span><?php endif; ?></p>
		<?php if ( null !== $args['total'] ) : ?>
			<p class="w-card-stay__price"><b class="w-card-stay__amount"><?php echo esc_html( $args['total'] ); ?></b> <span class="w-card-stay__unit"><?php
				/* translators: %d: number of nights. */
				echo esc_html( sprintf( _n( 'for %d night', 'for %d nights', $args['nights'], 'marclie-booking' ), $args['nights'] ) );
			?></span></p>
		<?php elseif ( null !== $args['from'] ) : ?>
			<?php // Cards show the price only; the minimum of paying guests is in the bookbox and the price line. ?>
			<p class="w-card-stay__price"><b class="w-card-stay__amount"><?php
				/* translators: %s: lowest price per person per night, e.g. "50 €". */
				echo esc_html( sprintf( __( 'from %s', 'marclie-booking' ), $args['from'] ) );
			?></b> <span class="w-card-stay__unit"><?php esc_html_e( '/ person / night', 'marclie-booking' ); ?></span></p>
		<?php endif; ?>
		<?php if ( $priced ) : ?>
			<div class="w-card-stay__actions"><a class="w-button-primary w-button-primary--small" href="<?php echo esc_url( $args['book_url'] ); ?>"><?php esc_html_e( 'Book now', 'marclie-booking' ); ?></a></div>
		<?php endif; ?>
	</div>
</article>
