import * as React from 'react';

import { CommandPalette } from '@/components/app/CommandPalette';
import { BottomTabBar, Sidebar } from '@/components/app/Sidebar';
import { Topbar } from '@/components/app/Topbar';
import { BookingSheetHost } from '@/features/bookings/sheet';
import { BookingWizardHost } from '@/features/bookings/wizard';

const COLLAPSE_KEY = 'mbk-sidebar-collapsed';

export function AppShell( { children }: { children: React.ReactNode } ) {
	const [ collapsed, setCollapsed ] = React.useState( () => window.localStorage.getItem( COLLAPSE_KEY ) === '1' );

	const toggle = () => {
		setCollapsed( ( prev ) => {
			window.localStorage.setItem( COLLAPSE_KEY, prev ? '0' : '1' );
			return ! prev;
		} );
	};

	return (
		<div className="flex min-h-[calc(100vh-32px)] bg-canvas">
			<Sidebar collapsed={ collapsed } onToggle={ toggle } />
			<div className="flex min-w-0 flex-1 flex-col">
				<Topbar />
				<main className="mx-auto w-full max-w-[1600px] flex-1 px-4 pb-24 pt-6 sm:px-6 min-[783px]:pb-10">
					{ children }
				</main>
			</div>
			<BottomTabBar />
			<CommandPalette />
			<BookingSheetHost />
			<BookingWizardHost />
		</div>
	);
}
