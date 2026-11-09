import { __, _n, sprintf } from '@wordpress/i18n';

import type { QuoteLine } from '@/api/types';
import { formatMoney } from '@/lib/format';

/** "2 adults × €70 + 1 child × €35", or "at least 2 guests × €70" when the unit minimum applies. */
function nightsBreakdown( line: QuoteLine ): string {
	const person = formatMoney( line.person_cents );
	if ( line.min_guests > 0 ) {
		return sprintf(
			/* translators: 1: minimum paying guests of the apartment, 2: price per person per night. */
			_n( 'at least %1$d guest × %2$s', 'at least %1$d guests × %2$s', line.min_guests, 'marclie-booking' ),
			line.min_guests,
			person
		);
	}
	const adults = sprintf(
		/* translators: 1: number of adults, 2: price per person per night. */
		_n( '%1$d adult × %2$s', '%1$d adults × %2$s', line.adults, 'marclie-booking' ),
		line.adults,
		person
	);
	if ( line.children === 0 ) {
		return adults;
	}
	return sprintf(
		/* translators: 1: adults part, e.g. "2 adults × €70", 2: children part, e.g. "1 child × €35". */
		__( '%1$s + %2$s', 'marclie-booking' ),
		adults,
		sprintf(
			/* translators: 1: number of children aged 5–10, 2: child price per night (half the person price). */
			_n( '%1$d child × %2$s', '%1$d children × %2$s', line.children, 'marclie-booking' ),
			line.children,
			formatMoney( line.child_cents )
		)
	);
}

/** Human label of a quote line (booking sheet and new-booking wizard). */
export function quoteLineLabel( line: QuoteLine ): string {
	switch ( line.type ) {
		case 'nights':
			return sprintf(
				/* translators: 1: apartment price per night, 2: number of nights, 3: how the price is made, e.g. "2 adults × €70 + 1 child × €35". */
				_n( '%1$s × %2$d night (%3$s)', '%1$s × %2$d nights (%3$s)', line.nights, 'marclie-booking' ),
				formatMoney( line.unit_cents ),
				line.nights,
				nightsBreakdown( line )
			);
		case 'cleaning':
			return __( 'Cleaning', 'marclie-booking' );
		case 'tourist_tax':
			return __( 'Tourist tax', 'marclie-booking' );
		case 'coupon':
			return __( 'Coupon discount', 'marclie-booking' );
	}
}
