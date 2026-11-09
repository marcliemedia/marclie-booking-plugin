import { __ } from '@wordpress/i18n';

import type { BookingSource, BookingStatus, PaymentStatus } from '@/api/types';
import { Badge, type BadgeProps } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

type Variant = NonNullable< BadgeProps[ 'variant' ] >;

export const STATUS_VARIANT: Record< BookingStatus, Variant > = {
	confirmed: 'success',
	paid: 'success-strong',
	pending_payment: 'warning',
	balance_failed: 'danger',
	cancelled: 'muted',
	expired: 'muted',
	completed: 'ink',
};

export function statusLabel( status: BookingStatus ): string {
	switch ( status ) {
		case 'confirmed':
			return __( 'Confirmed', 'marclie-booking' );
		case 'paid':
			return __( 'Paid', 'marclie-booking' );
		case 'pending_payment':
			return __( 'Awaiting payment', 'marclie-booking' );
		case 'balance_failed':
			return __( 'Payment failed', 'marclie-booking' );
		case 'cancelled':
			return __( 'Cancelled', 'marclie-booking' );
		case 'expired':
			return __( 'Expired', 'marclie-booking' );
		case 'completed':
			return __( 'Completed', 'marclie-booking' );
	}
}

const DOT: Record< BookingStatus, string > = {
	confirmed: 'bg-success',
	paid: 'bg-white',
	pending_payment: 'bg-warning',
	balance_failed: 'bg-danger',
	cancelled: 'bg-muted-foreground/60',
	expired: 'bg-muted-foreground/60',
	completed: 'bg-white',
};

export function StatusBadge( { status, className }: { status: BookingStatus; className?: string } ) {
	return (
		<Badge variant={ STATUS_VARIANT[ status ] } className={ className }>
			<span aria-hidden="true" className={ cn( 'h-1.5 w-1.5 rounded-full', DOT[ status ] ) } />
			{ statusLabel( status ) }
		</Badge>
	);
}

export function sourceLabel( source: BookingSource ): string {
	switch ( source ) {
		case 'direct':
			return __( 'Website', 'marclie-booking' );
		case 'admin':
			return __( 'Admin', 'marclie-booking' );
		case 'airbnb':
			return 'Airbnb';
		case 'booking':
			return 'Booking.com';
	}
}

export function SourceBadge( { source, className }: { source: BookingSource; className?: string } ) {
	const variant: Variant = source === 'direct' ? 'accent' : source === 'admin' ? 'default' : 'outline';
	return (
		<Badge variant={ variant } className={ cn( ( source === 'airbnb' || source === 'booking' ) && 'border-dashed', className ) }>
			{ sourceLabel( source ) }
		</Badge>
	);
}

export function paymentStatusLabel( status: PaymentStatus ): string {
	switch ( status ) {
		case 'scheduled':
			return __( 'Scheduled', 'marclie-booking' );
		case 'pending':
			return __( 'Pending', 'marclie-booking' );
		case 'succeeded':
			return __( 'Succeeded', 'marclie-booking' );
		case 'failed':
			return __( 'Failed', 'marclie-booking' );
		case 'refunded':
			return __( 'Refunded', 'marclie-booking' );
	}
}

const PAYMENT_VARIANT: Record< PaymentStatus, Variant > = {
	scheduled: 'outline',
	pending: 'warning',
	succeeded: 'success',
	failed: 'danger',
	refunded: 'muted',
};

export function PaymentStatusBadge( { status, className }: { status: PaymentStatus; className?: string } ) {
	return (
		<Badge variant={ PAYMENT_VARIANT[ status ] } className={ className }>
			{ paymentStatusLabel( status ) }
		</Badge>
	);
}
