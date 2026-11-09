import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/api/client';
import type { ISO } from '@/api/types';

export interface PolicySettings {
	deposit_pct: number;
	balance_days: number;
	free_cancel_days: number;
	hold_minutes: number;
	default_coupon: string;
	checkin_from: string;
	checkin_to: string;
	checkout_until: string;
	ref_prefix: string;
}

export type PageKey =
	| 'rezervacija'
	| 'hvala'
	| 'placanje'
	| 'pretraga'
	| 'uvjeti'
	| 'prijava'
	| 'registracija'
	| 'moj_racun'
	| 'zaboravljena_lozinka';

export interface NotificationSettings {
	admin_emails: string[];
	from_name: string;
	from_email: string;
	reply_to: string;
}

export interface AdvancedSettings {
	delete_data_on_uninstall: boolean;
	debug_log: boolean;
}

export interface SettingsResponse {
	policy: PolicySettings;
	pages: Record< PageKey, number >;
	notifications: NotificationSettings;
	advanced: AdvancedSettings;
	page_options: { id: number; title: string }[];
}

export type SettingsSection = 'policy' | 'pages' | 'notifications' | 'advanced';

export interface StripeVerify {
	ok: boolean;
	at: ISO;
	account?: {
		id: string;
		name: string;
		country: string;
		charges_enabled: boolean;
		payouts_enabled: boolean;
	};
	error?: string;
}

export interface StripeKeyState {
	set: boolean;
	last4: string | null;
	mode?: 'test' | 'live' | null;
}

export interface StripeStatus {
	mode: 'test' | 'live' | null;
	keys: { publishable: StripeKeyState; secret: StripeKeyState; webhook: StripeKeyState };
	mismatch: boolean;
	last_verify: StripeVerify | null;
	webhook: {
		url: string;
		events: string[];
		last_received_at: ISO | null;
		last_error: string | null;
	};
	dashboard_url: string;
}

export interface RoleUser {
	id: number;
	name: string;
	email: string;
	role: 'booking_manager';
}

export const settingsKeys = {
	settings: [ 'settings' ] as const,
	stripe: [ 'stripe', 'status' ] as const,
	roles: [ 'roles' ] as const,
};

export function useSettings( enabled = true ) {
	return useQuery( {
		queryKey: settingsKeys.settings,
		queryFn: () => api.get< SettingsResponse >( '/settings' ),
		enabled,
	} );
}

export function useUpdateSettings( section: SettingsSection ) {
	const qc = useQueryClient();
	return useMutation( {
		mutationFn: ( values: Record< string, unknown > ) =>
			api.patch< SettingsResponse >( `/settings/${ section }`, values ),
		onSuccess: ( data ) => {
			qc.setQueryData( settingsKeys.settings, data );
			if ( section === 'policy' ) {
				qc.invalidateQueries( { queryKey: [ 'bootstrap' ] } );
			}
		},
	} );
}

export function useStripeStatus( enabled = true ) {
	return useQuery( {
		queryKey: settingsKeys.stripe,
		queryFn: () => api.get< StripeStatus >( '/stripe/status' ),
		enabled,
	} );
}

export function useVerifyStripe() {
	const qc = useQueryClient();
	return useMutation( {
		mutationFn: () => api.post< StripeVerify >( '/stripe/verify' ),
		onSuccess: ( data ) => {
			qc.setQueryData< StripeStatus >( settingsKeys.stripe, ( old ) =>
				old ? { ...old, last_verify: data } : old
			);
		},
	} );
}

export function useRoles( enabled = true ) {
	return useQuery( {
		queryKey: settingsKeys.roles,
		queryFn: () => api.get< { users: RoleUser[] } >( '/roles' ),
		enabled,
	} );
}

export function useAddRole() {
	const qc = useQueryClient();
	return useMutation( {
		mutationFn: ( email: string ) => api.post< unknown >( '/roles', { email } ),
		onSuccess: () => qc.invalidateQueries( { queryKey: settingsKeys.roles } ),
	} );
}

export function useRemoveRole() {
	const qc = useQueryClient();
	return useMutation( {
		mutationFn: ( userId: number ) => api.del< unknown >( `/roles/${ userId }` ),
		onSuccess: () => qc.invalidateQueries( { queryKey: settingsKeys.roles } ),
	} );
}
