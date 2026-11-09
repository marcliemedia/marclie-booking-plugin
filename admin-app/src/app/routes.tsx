import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

import type { Bootstrap } from '@/api/types';

export interface PageProps {
	params: Record< string, string >;
}

export interface RouteDef {
	pattern: string;
	cap: keyof Bootstrap[ 'caps' ];
	component: LazyExoticComponent< ComponentType< PageProps > >;
}

const Dashboard = lazy( () => import( /* webpackChunkName: "dashboard" */ '@/features/dashboard' ) );
const Calendar = lazy( () => import( /* webpackChunkName: "calendar" */ '@/features/calendar' ) );
const Bookings = lazy( () => import( /* webpackChunkName: "bookings" */ '@/features/bookings' ) );
const Guests = lazy( () => import( /* webpackChunkName: "guests" */ '@/features/guests' ) );
const Payments = lazy( () => import( /* webpackChunkName: "payments" */ '@/features/payments' ) );
const Rates = lazy( () => import( /* webpackChunkName: "rates" */ '@/features/rates' ) );
const Coupons = lazy( () => import( /* webpackChunkName: "coupons" */ '@/features/coupons' ) );
const Activity = lazy( () => import( /* webpackChunkName: "activity" */ '@/features/activity' ) );
const Settings = lazy( () => import( /* webpackChunkName: "settings" */ '@/features/settings' ) );
const Emails = lazy( () => import( /* webpackChunkName: "emails" */ '@/features/emails' ) );

/** Hash routes, first match wins. */
export const routes: RouteDef[] = [
	{ pattern: '/', cap: 'manage_bookings', component: Dashboard },
	{ pattern: '/calendar', cap: 'manage_bookings', component: Calendar },
	{ pattern: '/bookings/:id?', cap: 'manage_bookings', component: Bookings },
	{ pattern: '/guests/:id?', cap: 'manage_bookings', component: Guests },
	{ pattern: '/payments', cap: 'manage_bookings', component: Payments },
	{ pattern: '/rates', cap: 'manage_rates', component: Rates },
	{ pattern: '/coupons', cap: 'manage_rates', component: Coupons },
	{ pattern: '/activity', cap: 'manage_bookings', component: Activity },
	{ pattern: '/settings/:tab?', cap: 'manage_settings', component: Settings },
	{ pattern: '/emails/:code?', cap: 'manage_settings', component: Emails },
];
