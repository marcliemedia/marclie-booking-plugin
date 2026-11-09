import { LockKey } from '@phosphor-icons/react';
import { __ } from '@wordpress/i18n';

import { useBootstrapData } from '@/api/hooks/bootstrap';
import { useSettings } from '@/api/hooks/settings';
import type { Bootstrap } from '@/api/types';
import { navigate } from '@/app/router';
import { EmptyState } from '@/components/app/EmptyState';
import { ErrorState } from '@/components/app/ErrorState';
import { PageHeader } from '@/components/app/PageHeader';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';

import { AdvancedTab } from './AdvancedTab';
import { GeneralTab } from './GeneralTab';
import { IcalTab } from './IcalTab';
import { NotificationsTab } from './NotificationsTab';
import { PagesTab } from './PagesTab';
import { RolesTab } from './RolesTab';
import { StripeTab } from './StripeTab';

/** Each tab lists the capability it needs (the REST routes check the same caps). */
const TABS: { id: string; label: () => string; cap: keyof Bootstrap[ 'caps' ] }[] = [
	{ id: 'general', label: () => __( 'General', 'marclie-booking' ), cap: 'manage_settings' },
	{ id: 'stripe', label: () => __( 'Stripe', 'marclie-booking' ), cap: 'manage_settings' },
	{ id: 'pages', label: () => __( 'Pages', 'marclie-booking' ), cap: 'manage_settings' },
	{ id: 'ical', label: () => __( 'iCal', 'marclie-booking' ), cap: 'manage_settings' },
	{ id: 'notifications', label: () => __( 'Notifications', 'marclie-booking' ), cap: 'manage_settings' },
	{ id: 'roles', label: () => __( 'Users & roles', 'marclie-booking' ), cap: 'manage_settings' },
	{ id: 'advanced', label: () => __( 'Advanced', 'marclie-booking' ), cap: 'manage_settings' },
];

export default function SettingsPage( { params }: { params: Record< string, string > } ) {
	const { caps } = useBootstrapData();
	const tabs = TABS.filter( ( t ) => caps[ t.cap ] );
	const active = tabs.find( ( t ) => t.id === params.tab )?.id ?? tabs[ 0 ]?.id;
	const settings = useSettings( tabs.length > 0 );

	if ( tabs.length === 0 ) {
		return (
			<>
				<PageHeader title={ __( 'Settings', 'marclie-booking' ) } />
				<EmptyState
					icon={ LockKey }
					title={ __( 'No access', 'marclie-booking' ) }
					description={ __( 'Only administrators can change booking settings.', 'marclie-booking' ) }
				/>
			</>
		);
	}

	const body = ( id: string ) => {
		if ( id === 'stripe' ) {
			return <StripeTab />;
		}
		if ( id === 'roles' ) {
			return <RolesTab />;
		}
		if ( id === 'ical' ) {
			return <IcalTab />;
		}
		if ( settings.isPending ) {
			return <Skeleton className="h-72 w-full" />;
		}
		if ( settings.isError ) {
			return <ErrorState error={ settings.error } onRetry={ () => settings.refetch() } />;
		}
		const s = settings.data;
		switch ( id ) {
			case 'general':
				return <GeneralTab policy={ s.policy } />;
			case 'pages':
				return <PagesTab settings={ s } />;
			case 'notifications':
				return <NotificationsTab notifications={ s.notifications } />;
			default:
				return <AdvancedTab advanced={ s.advanced } />;
		}
	};

	return (
		<div className={ cn( 'mx-auto w-full', active === 'ical' ? 'max-w-6xl' : 'max-w-4xl' ) }>
			<PageHeader title={ __( 'Settings', 'marclie-booking' ) } />
			<Tabs value={ active } onValueChange={ ( v ) => navigate( `/settings/${ v }`, { replace: true } ) }>
				<div className="-mx-1 overflow-x-auto px-1 pb-1">
					<TabsList variant="underline" className="min-w-max">
						{ tabs.map( ( t ) => (
							<TabsTrigger key={ t.id } value={ t.id }>
								{ t.label() }
							</TabsTrigger>
						) ) }
					</TabsList>
				</div>
				{ tabs.map( ( t ) => (
					<TabsContent key={ t.id } value={ t.id }>
						{ t.id === active && body( t.id ) }
					</TabsContent>
				) ) }
			</Tabs>
		</div>
	);
}
