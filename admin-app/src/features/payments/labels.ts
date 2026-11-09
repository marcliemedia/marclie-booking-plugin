import { __ } from '@wordpress/i18n';

import type { PaymentKind, PaymentMethod } from '@/api/types';

export function paymentKindLabel( kind: PaymentKind ): string {
	switch ( kind ) {
		case 'deposit':
			return __( 'Deposit', 'marclie-booking' );
		case 'balance':
			return __( 'Balance', 'marclie-booking' );
		case 'full':
			return __( 'Full payment', 'marclie-booking' );
		default:
			return __( 'Refund', 'marclie-booking' );
	}
}

export function paymentMethodLabel( method: PaymentMethod ): string {
	switch ( method ) {
		case 'stripe':
			return __( 'Card (Stripe)', 'marclie-booking' );
		case 'cash':
			return __( 'Cash', 'marclie-booking' );
		case 'bank':
			return __( 'Bank transfer', 'marclie-booking' );
		default:
			return __( 'Other', 'marclie-booking' );
	}
}

export const PAYMENT_KINDS: PaymentKind[] = [ 'deposit', 'balance', 'full', 'refund' ];
export const PAYMENT_METHODS: PaymentMethod[] = [ 'stripe', 'cash', 'bank', 'other' ];
