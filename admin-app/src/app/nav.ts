import {
	CalendarBlank,
	ClockCounterClockwise,
	CreditCard,
	EnvelopeSimple,
	Gear,
	ListBullets,
	SquaresFour,
	Tag,
	Ticket,
	Users,
	type Icon,
} from '@phosphor-icons/react';
import { __ } from '@wordpress/i18n';

import type { Bootstrap } from '@/api/types';

export interface NavItem {
	to: string;
	label: string;
	icon: Icon;
	cap: keyof Bootstrap[ 'caps' ];
	/** Shown in the mobile bottom tab bar. */
	mobile?: boolean;
	/** Rendered below the divider. */
	footer?: boolean;
}

export function navItems(): NavItem[] {
	return [
		{ to: '/', label: __( 'Overview', 'marclie-booking' ), icon: SquaresFour, cap: 'manage_bookings', mobile: true },
		{ to: '/calendar', label: __( 'Calendar', 'marclie-booking' ), icon: CalendarBlank, cap: 'manage_bookings', mobile: true },
		{ to: '/bookings', label: __( 'Bookings', 'marclie-booking' ), icon: ListBullets, cap: 'manage_bookings', mobile: true },
		{ to: '/guests', label: __( 'Guests', 'marclie-booking' ), icon: Users, cap: 'manage_bookings', mobile: true },
		{ to: '/payments', label: __( 'Payments', 'marclie-booking' ), icon: CreditCard, cap: 'manage_bookings' },
		{ to: '/rates', label: __( 'Rates', 'marclie-booking' ), icon: Tag, cap: 'manage_rates' },
		{ to: '/coupons', label: __( 'Coupons', 'marclie-booking' ), icon: Ticket, cap: 'manage_rates' },
		{ to: '/emails', label: __( 'Emails', 'marclie-booking' ), icon: EnvelopeSimple, cap: 'manage_settings' },
		{ to: '/activity', label: __( 'Activity', 'marclie-booking' ), icon: ClockCounterClockwise, cap: 'manage_bookings' },
		{ to: '/settings', label: __( 'Settings', 'marclie-booking' ), icon: Gear, cap: 'manage_settings', footer: true },
	];
}

export function allowedNavItems( caps: Bootstrap[ 'caps' ] ): NavItem[] {
	return navItems().filter( ( item ) => caps[ item.cap ] );
}
