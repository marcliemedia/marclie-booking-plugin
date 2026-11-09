import { DotsThree, SidebarSimple } from '@phosphor-icons/react';
import { __ } from '@wordpress/i18n';

import { allowedNavItems, type NavItem } from '@/app/nav';
import { isActivePath, Link, useLocation } from '@/app/router';
import { useBootstrapData } from '@/api/hooks/bootstrap';
import {
	DropdownMenu,
	DropdownMenuContent,
	DropdownMenuItem,
	DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SimpleTooltip } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

function SidebarLink( { item, active, collapsed }: { item: NavItem; active: boolean; collapsed: boolean } ) {
	const IconComponent = item.icon;
	const link = (
		<Link
			to={ item.to }
			aria-current={ active ? 'page' : undefined }
			className={ cn(
				'group flex h-9 items-center gap-3 rounded-md px-2.5 text-[13.5px] font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
				active ? 'bg-surface text-ink shadow-card' : 'text-muted-foreground hover:bg-surface/70 hover:text-ink-soft',
				collapsed && 'justify-center px-0'
			) }
		>
			<IconComponent className={ cn( 'h-[18px] w-[18px] shrink-0', active && 'text-accent' ) } weight={ active ? 'fill' : 'regular' } />
			{ ! collapsed && <span className="truncate">{ item.label }</span> }
		</Link>
	);
	return collapsed ? (
		<SimpleTooltip label={ item.label } side="right">
			{ link }
		</SimpleTooltip>
	) : (
		link
	);
}

export function Sidebar( { collapsed, onToggle }: { collapsed: boolean; onToggle: () => void } ) {
	const { caps, version, brand } = useBootstrapData();
	const { path } = useLocation();
	const items = allowedNavItems( caps );
	const main = items.filter( ( item ) => ! item.footer );
	const footer = items.filter( ( item ) => item.footer );

	return (
		<aside
			className={ cn(
				'sticky top-[32px] hidden h-[calc(100vh-32px)] shrink-0 flex-col border-r border-border bg-canvas transition-[width] duration-200 min-[783px]:flex',
				collapsed ? 'w-[64px]' : 'w-[232px]'
			) }
			aria-label={ __( 'Booking admin', 'marclie-booking' ) }
		>
			<div className={ cn( 'flex h-14 items-center gap-2.5 px-4', collapsed && 'justify-center px-0' ) }>
				<div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-ink-soft text-sm font-bold text-white" aria-hidden="true">
					{ brand.trim().charAt( 0 ).toUpperCase() }
				</div>
				{ ! collapsed && (
					<div className="min-w-0 leading-tight">
						<div className="truncate text-sm font-semibold text-ink">{ brand }</div>
						<div className="text-2xs text-muted-foreground">{ __( 'Booking', 'marclie-booking' ) }</div>
					</div>
				) }
			</div>
			<nav className="flex flex-1 flex-col gap-0.5 overflow-y-auto px-3 py-2">
				{ main.map( ( item ) => (
					<SidebarLink key={ item.to } item={ item } active={ isActivePath( path, item.to ) } collapsed={ collapsed } />
				) ) }
				{ footer.length > 0 && <div className="my-2 h-px bg-border" /> }
				{ footer.map( ( item ) => (
					<SidebarLink key={ item.to } item={ item } active={ isActivePath( path, item.to ) } collapsed={ collapsed } />
				) ) }
			</nav>
			<div className={ cn( 'flex items-center justify-between gap-2 border-t border-border px-3 py-2', collapsed && 'justify-center' ) }>
				{ ! collapsed && <span className="px-1 text-2xs text-muted-foreground">v{ version }</span> }
				<SimpleTooltip label={ collapsed ? __( 'Expand sidebar', 'marclie-booking' ) : __( 'Collapse sidebar', 'marclie-booking' ) } side="right">
					<button
						type="button"
						onClick={ onToggle }
						aria-label={ collapsed ? __( 'Expand sidebar', 'marclie-booking' ) : __( 'Collapse sidebar', 'marclie-booking' ) }
						aria-expanded={ ! collapsed }
						className="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:bg-surface hover:text-ink-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
					>
						<SidebarSimple className="h-[18px] w-[18px]" />
					</button>
				</SimpleTooltip>
			</div>
		</aside>
	);
}

/** Mobile (< 783px) bottom tab bar: four main sections + "More". */
export function BottomTabBar() {
	const { caps } = useBootstrapData();
	const { path } = useLocation();
	const items = allowedNavItems( caps );
	const tabs = items.filter( ( item ) => item.mobile );
	const more = items.filter( ( item ) => ! item.mobile );

	return (
		<nav
			aria-label={ __( 'Booking admin', 'marclie-booking' ) }
			className="fixed inset-x-0 bottom-0 z-[9995] flex h-16 items-stretch border-t border-border bg-surface/95 pb-[env(safe-area-inset-bottom)] backdrop-blur min-[783px]:hidden"
		>
			{ tabs.map( ( item ) => {
				const active = isActivePath( path, item.to );
				const IconComponent = item.icon;
				return (
					<Link
						key={ item.to }
						to={ item.to }
						aria-current={ active ? 'page' : undefined }
						className={ cn(
							'flex flex-1 flex-col items-center justify-center gap-0.5 text-[11px] font-medium',
							active ? 'text-accent' : 'text-muted-foreground'
						) }
					>
						<IconComponent className="h-[22px] w-[22px]" weight={ active ? 'fill' : 'regular' } />
						{ item.label }
					</Link>
				);
			} ) }
			{ more.length > 0 && (
				<DropdownMenu>
					<DropdownMenuTrigger
						className={ cn(
							'flex flex-1 flex-col items-center justify-center gap-0.5 text-[11px] font-medium text-muted-foreground',
							more.some( ( item ) => isActivePath( path, item.to ) ) && 'text-accent'
						) }
					>
						<DotsThree className="h-[22px] w-[22px]" weight="bold" />
						{ __( 'More', 'marclie-booking' ) }
					</DropdownMenuTrigger>
					<DropdownMenuContent side="top" align="end" className="w-48">
						{ more.map( ( item ) => {
							const IconComponent = item.icon;
							return (
								<DropdownMenuItem key={ item.to } asChild>
									<Link to={ item.to }>
										<IconComponent />
										{ item.label }
									</Link>
								</DropdownMenuItem>
							);
						} ) }
					</DropdownMenuContent>
				</DropdownMenu>
			) }
		</nav>
	);
}
