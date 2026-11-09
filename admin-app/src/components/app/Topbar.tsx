import { MagnifyingGlass, Warning } from '@phosphor-icons/react';
import { __ } from '@wordpress/i18n';

import { useBootstrapData } from '@/api/hooks/bootstrap';
import { paletteStore } from '@/components/app/CommandPalette';
import { Link } from '@/app/router';
import { Avatar, AvatarFallback, initials } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { SimpleTooltip } from '@/components/ui/tooltip';

const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test( navigator.platform );

function StripeModeBadge() {
	const { stripe, caps } = useBootstrapData();
	if ( stripe.configured && stripe.mode === 'live' ) {
		return null;
	}
	const badge = ! stripe.configured ? (
		<Badge variant="danger" className="gap-1">
			<Warning className="h-3 w-3" weight="bold" />
			<span className="hidden sm:inline">{ __( 'Stripe not configured', 'marclie-booking' ) }</span>
			<span className="sm:hidden">Stripe</span>
		</Badge>
	) : (
		<Badge variant="warning" className="font-semibold tracking-wide">
			{ __( 'TEST MODE', 'marclie-booking' ) }
		</Badge>
	);
	const tooltip = ! stripe.configured
		? __( 'Stripe keys are missing in wp-config.php — online payments are disabled.', 'marclie-booking' )
		: __( 'Stripe test keys are active — no real money is charged.', 'marclie-booking' );
	return (
		<SimpleTooltip label={ tooltip }>
			{ caps.manage_settings ? (
				<Link to="/settings/stripe" className="rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
					{ badge }
				</Link>
			) : (
				<span tabIndex={ 0 }>{ badge }</span>
			) }
		</SimpleTooltip>
	);
}

export function Topbar() {
	const { user } = useBootstrapData();

	return (
		<header className="sticky top-[32px] z-30 flex h-14 items-center gap-3 border-b border-border bg-canvas/90 px-4 backdrop-blur max-[782px]:top-[46px] max-[600px]:top-0 sm:px-6">
			<div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-ink-soft text-sm font-bold text-white min-[783px]:hidden">
				K
			</div>
			<button
				type="button"
				onClick={ () => paletteStore.set( true ) }
				// min-w-0 lets the trigger shrink below its text width on phones (the label truncates).
				className="flex h-9 min-w-0 flex-1 items-center gap-2 rounded-md border border-border bg-surface px-3 text-left text-sm text-muted-foreground shadow-card transition-colors hover:border-border-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring sm:max-w-md"
				aria-keyshortcuts={ isMac ? 'Meta+K' : 'Control+K' }
				aria-label={ __( 'Search booking ref, guest, email…', 'marclie-booking' ) }
			>
				<MagnifyingGlass className="h-4 w-4 shrink-0" aria-hidden="true" />
				<span className="hidden min-w-0 flex-1 truncate min-[400px]:inline" aria-hidden="true">
					{ __( 'Search booking ref, guest, email…', 'marclie-booking' ) }
				</span>
				<span className="min-w-0 flex-1 truncate min-[400px]:hidden" aria-hidden="true">
					{ __( 'Search…', 'marclie-booking' ) }
				</span>
				<kbd className="hidden rounded border border-border bg-surface-muted px-1.5 py-0.5 font-sans text-2xs font-medium sm:inline-block">
					{ isMac ? '⌘K' : 'Ctrl K' }
				</kbd>
			</button>
			<div className="ml-auto flex shrink-0 items-center gap-2 sm:gap-3">
				<StripeModeBadge />
				<div className="flex items-center gap-2">
					<Avatar className="h-8 w-8">
						<AvatarFallback>{ initials( user.name ) }</AvatarFallback>
					</Avatar>
					<span className="hidden max-w-[160px] truncate text-sm font-medium text-ink-soft lg:inline">{ user.name }</span>
				</div>
			</div>
		</header>
	);
}
