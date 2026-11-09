import { __ } from '@wordpress/i18n';

import type { IcalChannel } from '@/api/types';

export function channelLabel( channel: IcalChannel | string ): string {
	switch ( channel ) {
		case 'airbnb':
			return 'Airbnb';
		case 'booking':
			return 'Booking.com';
		default:
			return __( 'Other calendar', 'marclie-booking' );
	}
}
