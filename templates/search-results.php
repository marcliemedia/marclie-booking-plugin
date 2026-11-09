<?php
/**
 * Search results: filter panel + stay cards of the bookable units only; units that are booked,
 * too small or below the minimum stay are summarised in one note under the grid.
 * Facets and sorting run client-side (search.js) over the data-* attributes of each card.
 * Markup = mockup `w-catalog` inside `.w-stay-catalogue__catalog` (the page document provides
 * section.w-stay-catalogue > .w-stay-catalogue__container > the element carrying .w-stay-catalogue__catalog).
 *
 * Override: {child-theme}/marclie-booking/search-results.php
 *
 * @var array<string, mixed> $args
 */

defined( 'ABSPATH' ) || exit;

use Marclie\Booking\Frontend\Renderer;

$withDates = null !== $args['stay'];
$free      = count( $args['cards'] );

if ( $withDates ) {
	$countLabel    = __( '{total} apartments for your dates', 'marclie-booking' );
	$countLabelOne = __( '1 apartment for your dates', 'marclie-booking' );
	$applyLabel    = __( 'Show {total} apartments for your dates', 'marclie-booking' );
	$applyLabelOne = __( 'Show 1 apartment for your dates', 'marclie-booking' );
} else {
	$countLabel    = __( '{total} apartments', 'marclie-booking' );
	$countLabelOne = __( '1 apartment', 'marclie-booking' );
	$applyLabel    = __( 'Show {total} apartments', 'marclie-booking' );
	$applyLabelOne = __( 'Show 1 apartment', 'marclie-booking' );
}
$fill = static fn( string $tpl, string $one ): string => 1 === $free ? $one : str_replace( '{total}', (string) $free, $tpl );
?>
<div class="w-catalog"<?php echo $args['lang_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::langAttr(). ?> data-mbk-widget data-mbk-catalog><div class="w-catalog__shell">
<aside aria-labelledby="filtri-naslov" class="w-catalog__filters" data-filter-panel id="filtri" role="region"><div class="w-filters">
	<div class="w-filters__head">
		<p class="w-filters__title" id="filtri-naslov"><?php esc_html_e( 'Filters', 'marclie-booking' ); ?></p>
		<button class="w-filters__clear" data-clear-filters hidden type="button"><?php esc_html_e( 'Clear all', 'marclie-booking' ); ?></button>
		<button aria-label="<?php esc_attr_e( 'Close filters', 'marclie-booking' ); ?>" class="w-filters__close" data-filter-close type="button"><i data-icon="x"></i></button>
	</div>
	<div class="w-filters__body">
		<?php foreach ( $args['facets'] as $group ) : ?>
			<fieldset class="w-filters__group">
				<legend class="w-filters__legend"><?php echo esc_html( $group['legend'] ); ?></legend>
				<?php if ( 'check' === $group['type'] ) : ?>
					<?php foreach ( $group['options'] as $option ) : ?>
						<?php /* translators: 1: facet group, 2: option. */ $chip = sprintf( __( '%1$s: %2$s', 'marclie-booking' ), $group['legend'], $option['label'] ); ?>
						<label class="w-filters__check"><input class="w-filters__check-input" data-facet="<?php echo esc_attr( $group['facet'] ); ?>" data-label="<?php echo esc_attr( $chip ); ?>" type="checkbox" value="<?php echo esc_attr( $option['value'] ); ?>"> <span class="w-filters__check-text"><?php echo esc_html( $option['label'] ); ?></span> <span class="w-filters__number"><?php echo esc_html( (string) $option['count'] ); ?></span></label>
					<?php endforeach; ?>
				<?php else : ?>
					<div class="w-filters__pills">
						<?php if ( 'radio' === $group['type'] ) : ?>
							<label class="w-filters__pill"><input checked class="w-filters__pill-input" name="<?php echo esc_attr( $group['facet'] ); ?>" type="radio" value=""><span class="w-filters__pill-label"><?php esc_html_e( 'Any', 'marclie-booking' ); ?></span></label>
						<?php endif; ?>
						<?php foreach ( $group['options'] as $option ) : ?>
							<?php /* translators: 1: facet group, 2: option. */ $chip = sprintf( __( '%1$s: %2$s', 'marclie-booking' ), $group['legend'], $option['label'] ); ?>
							<label class="w-filters__pill"><input class="w-filters__pill-input" data-facet="<?php echo esc_attr( $group['facet'] ); ?>" data-label="<?php echo esc_attr( $chip ); ?>"<?php echo 'radio' === $group['type'] ? ' name="' . esc_attr( $group['facet'] ) . '"' : ''; ?> type="<?php echo 'radio' === $group['type'] ? 'radio' : 'checkbox'; ?>" value="<?php echo esc_attr( $option['value'] ); ?>"><span class="w-filters__pill-label"><?php echo esc_html( $option['label'] ); ?></span></label>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</fieldset>
		<?php endforeach; ?>
	</div>
	<div class="w-filters__foot">
		<button class="w-filters__clear" data-clear-filters hidden type="button"><?php esc_html_e( 'Clear all', 'marclie-booking' ); ?></button>
		<div class="w-filters__apply"><button class="w-button-primary w-button-primary--block w-button-primary--wrap" data-filter-close data-result-label="<?php echo esc_attr( $applyLabel ); ?>" data-result-label-one="<?php echo esc_attr( $applyLabelOne ); ?>" type="button"><?php echo esc_html( $fill( $applyLabel, $applyLabelOne ) ); ?></button></div>
	</div>
</div></aside>
<div class="w-catalog__scrim" data-filter-scrim hidden></div>
<div class="w-catalog__results">
	<div class="w-catalog__head">
		<h2 class="w-catalog__count" data-result-label="<?php echo esc_attr( $countLabel ); ?>" data-result-label-one="<?php echo esc_attr( $countLabelOne ); ?>"><?php echo esc_html( $fill( $countLabel, $countLabelOne ) ); ?></h2>
		<div class="w-catalog__toolbar">
			<button aria-controls="filtri" aria-expanded="false" class="w-catalog__filter-button" data-filter-open type="button"><i data-icon="sliders-horizontal" class="w-icon--small"></i> <span class="w-catalog__filter-button-text"><?php esc_html_e( 'Filters', 'marclie-booking' ); ?></span> <span class="w-catalog__filter-count" data-active-count hidden></span></button>
			<label class="w-catalog__sort"><span class="w-catalog__sort-label"><?php esc_html_e( 'Sort by', 'marclie-booking' ); ?></span><select class="w-catalog__sort-select" data-sort>
				<option class="w-catalog__sort-option" value=""><?php esc_html_e( 'Recommended', 'marclie-booking' ); ?></option><option class="w-catalog__sort-option" value="price-asc"><?php esc_html_e( 'Price: low to high', 'marclie-booking' ); ?></option><option class="w-catalog__sort-option" value="price-desc"><?php esc_html_e( 'Price: high to low', 'marclie-booking' ); ?></option><option class="w-catalog__sort-option" value="sold"><?php esc_html_e( 'Capacity: largest first', 'marclie-booking' ); ?></option>
			</select></label>
		</div>
	</div>
	<div class="w-catalog__chips" data-active-filters></div>
	<div class="w-stay-catalogue__grid brxe-block" data-mbk-grid>
		<?php
		foreach ( $args['cards'] as $i => $card ) {
			echo Renderer::render( 'stay-card', $card + array( 'eager' => 0 === $i ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template escapes its output.
		}
		?>
	</div>
	<p class="w-catalog__empty" data-empty<?php echo $free ? ' hidden' : ''; ?>><span class="w-catalog__empty-text"><?php esc_html_e( 'No available apartment matches the filters you’ve chosen. Remove a filter or change your dates above.', 'marclie-booking' ); ?></span> <button class="w-button-secondary w-button-secondary--small" data-clear-filters hidden type="button"><?php esc_html_e( 'Clear all', 'marclie-booking' ); ?></button> <a href="<?php echo esc_url( $args['groups_url'] ); ?>"><?php esc_html_e( 'Stays for groups', 'marclie-booking' ); ?></a></p>
	<?php if ( $args['unavailable'] > 0 ) : ?>
		<p class="w-note" data-mbk-unavailable>
			<?php
			/* translators: %d: number of apartments left out of the results. */
			echo esc_html( sprintf( _n( '%d apartment isn’t available for your dates or number of guests', '%d apartments aren’t available for your dates or number of guests', $args['unavailable'], 'marclie-booking' ), $args['unavailable'] ) );
			?>
		</p>
	<?php endif; ?>
</div>
</div></div>
