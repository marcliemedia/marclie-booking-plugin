import {
	ArrowSquareIn,
	ArrowSquareOut,
	CalendarCheck,
	CalendarDots,
	CalendarX,
	ChartBar,
	ClockCountdown,
	CurrencyEur,
	Info,
	Lightning,
	Plus,
	Receipt,
	Warning,
	WarningCircle,
} from '@phosphor-icons/react';
import { __, _n, sprintf } from '@wordpress/i18n';
import * as React from 'react';
import { lazy, Suspense } from 'react';

import { navigate } from '@/app/router';
import type { PageProps } from '@/app/routes';
import { useBootstrapData, useUnitMap } from '@/api/hooks/bootstrap';
import { useDashboard } from '@/api/hooks/dashboard';
import type { DashboardAlert, DashboardStay } from '@/api/types';
import { EmptyState } from '@/components/app/EmptyState';
import { ErrorState } from '@/components/app/ErrorState';
import { KpiCard } from '@/components/app/KpiCard';
import { Money } from '@/components/app/Money';
import { PageHeader } from '@/components/app/PageHeader';
import { StatusBadge } from '@/components/app/StatusBadge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useBookingSheet } from '@/features/bookings/sheet';
import { useBookingWizard } from '@/features/bookings/wizard';
import { addDays, addMonths } from '@/lib/dates';
import { formatDate, formatMoney, formatPercent, formatRelative, guestsLabel, nightsLabel } from '@/lib/format';
import { cn } from '@/lib/utils';

const OccupancyChart = lazy( () => import( /* webpackChunkName: "chart" */ './OccupancyChart' ) );

function StayList( {
	items,
	kind,
	loading,
}: {
	items: DashboardStay[] | undefined;
	kind: 'arrivals' | 'departures';
	loading: boolean;
} ) {
	const sheet = useBookingSheet();
	const unitMap = useUnitMap();
	if ( loading ) {
		return (
			<div className="flex flex-col gap-3 px-5 pb-5">
				<Skeleton className="h-12" />
				<Skeleton className="h-12" />
			</div>
		);
	}
	if ( ! items || items.length === 0 ) {
		return (
			<EmptyState
				compact
				icon={ kind === 'arrivals' ? ArrowSquareIn : ArrowSquareOut }
				title={ kind === 'arrivals' ? __( 'No arrivals today', 'marclie-booking' ) : __( 'No departures today', 'marclie-booking' ) }
			/>
		);
	}
	return (
		<ul className="divide-y divide-border border-t border-border">
			{ items.map( ( stay ) => (
				<li key={ stay.id }>
					<button
						type="button"
						onClick={ () => sheet.open( stay.id ) }
						className="flex w-full items-start gap-3 px-5 py-3 text-left transition-colors hover:bg-surface-muted/50 focus-visible:bg-surface-muted focus-visible:outline-none"
					>
						<div className="flex h-9 w-12 shrink-0 flex-col items-center justify-center rounded-md bg-surface-muted text-xs font-semibold tabular text-ink">
							{ kind === 'arrivals' ? stay.arrival_time || '—' : unitMap.get( stay.unit_id )?.code ?? '—' }
						</div>
						<div className="min-w-0 flex-1">
							<div className="flex items-center gap-2">
								<span className="truncate font-medium text-ink">{ stay.guest.name }</span>
								{ stay.balance_cents > 0 && kind === 'arrivals' && (
									<span className="shrink-0 text-xs font-medium text-warning">
										{ sprintf(
											/* translators: %s: amount. */
											__( '%s due', 'marclie-booking' ),
											formatMoney( stay.balance_cents )
										) }
									</span>
								) }
							</div>
							<div className="text-xs text-muted-foreground">
								{ stay.unit_title } ·{ ' ' }
								{ guestsLabel( stay.adults, stay.children, stay.infants ) }{ ' ' }
								·{ ' ' }
								{ nightsLabel( stay.nights ) }
							</div>
							{ stay.message && <p className="mt-1 line-clamp-2 text-xs italic text-ink-soft">“{ stay.message }”</p> }
						</div>
						<StatusBadge status={ stay.status } />
					</button>
				</li>
			) ) }
		</ul>
	);
}

function alertIcon( alert: DashboardAlert ) {
	if ( alert.level === 'info' ) {
		return Info;
	}
	if ( alert.type === 'cancel_request' ) {
		return CalendarX;
	}
	if ( alert.type === 'ical_conflict' ) {
		return CalendarDots;
	}
	return alert.type === 'balance_failed' || alert.type === 'dispute' || alert.type === 'overpayment' || alert.stalled
		? WarningCircle
		: alert.type === 'stale_hold'
		? ClockCountdown
		: Warning;
}

function alertTone( alert: DashboardAlert ): string {
	if ( alert.level === 'info' ) {
		return 'text-muted-foreground';
	}
	if ( alert.type === 'ical_feed_failing' ) {
		return alert.stalled ? 'text-danger' : 'text-warning';
	}
	return alert.type === 'stale_hold' || alert.type === 'webhook_silent' || alert.type === 'cancel_request' ? 'text-warning' : 'text-danger';
}

function Alerts( { alerts }: { alerts: DashboardAlert[] } ) {
	const sheet = useBookingSheet();
	const { caps } = useBootstrapData();
	if ( alerts.length === 0 ) {
		return null;
	}
	return (
		<Card className="mb-6 border-danger/20">
			<CardHeader className="pb-2">
				<div>
					<CardTitle className="flex items-center gap-2">
						<Lightning className="h-4 w-4 text-danger" weight="fill" />
						{ __( 'Needs attention', 'marclie-booking' ) }
					</CardTitle>
				</div>
			</CardHeader>
			<ul className="divide-y divide-border border-t border-border">
				{ alerts.map( ( alert, index ) => {
					const IconComponent = alertIcon( alert );
					const action =
						alert.booking_id !== undefined
							? () => sheet.open( alert.booking_id as number )
							: ( alert.type === 'stripe_not_configured' || alert.type === 'webhook_silent' ) && caps.manage_settings
							? () => navigate( '/settings/stripe' )
							: alert.type === 'ical_feed_failing' && caps.manage_settings
							? () => navigate( alert.unit_id ? `/settings/ical?unit=${ alert.unit_id }` : '/settings/ical' )
							: undefined;
					const calendarDate = alert.type === 'ical_conflict' || alert.type === 'ical_stale_echo' ? alert.date : undefined;
					return (
						<li key={ index } className="flex flex-wrap items-center gap-3 px-5 py-2.5 text-sm">
							<IconComponent className={ cn( 'h-4 w-4 shrink-0', alertTone( alert ) ) } />
							<span className="min-w-0 flex-1 basis-56">
								{ alert.booking_ref && ! alert.message.startsWith( alert.booking_ref ) && (
									<span className="mr-2 font-mono text-xs text-muted-foreground">{ alert.booking_ref }</span>
								) }
								{ alert.message }
								{ alert.type === 'cancel_request' && ( alert.reason || alert.requested_at ) && (
									<span className="mt-0.5 block text-xs text-muted-foreground">
										{ alert.reason && <q className="italic">{ alert.reason }</q> }
										{ alert.reason && alert.requested_at && ' · ' }
										{ alert.requested_at && formatRelative( alert.requested_at ) }
									</span>
								) }
							</span>
							{ alert.url && (
								<Button size="sm" variant="ghost" asChild>
									<a href={ alert.url } target="_blank" rel="noreferrer">
										{ alert.type === 'dispute' ? __( 'View in Stripe', 'marclie-booking' ) : __( 'Details', 'marclie-booking' ) }
										<ArrowSquareOut className="h-3.5 w-3.5" />
									</a>
								</Button>
							) }
							{ calendarDate && (
								<Button size="sm" variant="ghost" onClick={ () => navigate( `/calendar?from=${ addDays( calendarDate, -2 ) }` ) }>
									<CalendarCheck className="h-3.5 w-3.5" aria-hidden="true" />
									{ __( 'Show in calendar', 'marclie-booking' ) }
								</Button>
							) }
							{ action && (
								<Button size="sm" variant="secondary" onClick={ action }>
									{ alert.type === 'webhook_silent'
										? __( 'Check Stripe settings', 'marclie-booking' )
										: alert.type === 'cancel_request' || alert.type === 'ical_conflict'
										? __( 'Review booking', 'marclie-booking' )
										: alert.type === 'ical_feed_failing'
										? __( 'Open iCal settings', 'marclie-booking' )
										: __( 'Open', 'marclie-booking' ) }
								</Button>
							) }
						</li>
					);
				} ) }
			</ul>
		</Card>
	);
}

export default function DashboardPage( _props: PageProps ) {
	const { today } = useBootstrapData();
	const unitMap = useUnitMap();
	const wizard = useBookingWizard();
	const dashboard = useDashboard();
	const data = dashboard.data;
	const loading = dashboard.isPending;
	const nextMonth = addMonths( today, 1 );

	const revenue = React.useMemo( () => {
		const rows = ( data?.revenue_by_unit ?? [] ).filter( ( row ) => row.cents > 0 ).sort( ( a, b ) => b.cents - a.cents );
		const max = rows.reduce( ( acc, row ) => Math.max( acc, row.cents ), 0 );
		return { rows, max };
	}, [ data ] );

	if ( dashboard.isError && ! data ) {
		return <ErrorState error={ dashboard.error } onRetry={ () => dashboard.refetch() } />;
	}

	return (
		<>
			<PageHeader
				title={ __( 'Overview', 'marclie-booking' ) }
				description={ formatDate( today, 'long' ) }
				actions={
					<>
						<Button variant="secondary" onClick={ () => navigate( '/calendar' ) }>
							<CalendarCheck className="h-4 w-4" />
							{ __( 'Open calendar', 'marclie-booking' ) }
						</Button>
						<Button onClick={ () => wizard.open() }>
							<Plus className="h-4 w-4" weight="bold" />
							{ __( 'New booking', 'marclie-booking' ) }
						</Button>
					</>
				}
			/>

			{ data && <Alerts alerts={ data.alerts } /> }

			<div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
				<KpiCard
					label={ __( 'Occupancy this month', 'marclie-booking' ) }
					icon={ ChartBar }
					loading={ loading }
					value={ data ? formatPercent( data.kpis.occupancy_pct ) : '' }
					hint={ formatDate( today, 'month' ) }
				/>
				<KpiCard
					label={ __( 'Occupancy next month', 'marclie-booking' ) }
					icon={ ChartBar }
					loading={ loading }
					value={ data ? formatPercent( data.kpis.occupancy_next_pct ) : '' }
					hint={ formatDate( nextMonth, 'month' ) }
				/>
				<KpiCard
					label={ __( 'Collected', 'marclie-booking' ) }
					icon={ CurrencyEur }
					loading={ loading }
					value={ data ? <Money cents={ data.kpis.collected_cents } /> : '' }
					hint={ __( 'This month', 'marclie-booking' ) }
					onClick={ () => navigate( '/payments' ) }
				/>
				<KpiCard
					label={ __( 'Scheduled', 'marclie-booking' ) }
					icon={ Receipt }
					loading={ loading }
					value={ data ? <Money cents={ data.kpis.scheduled_cents } /> : '' }
					hint={ __( 'Balances still to collect', 'marclie-booking' ) }
				/>
				<KpiCard
					label={ __( 'New bookings', 'marclie-booking' ) }
					icon={ Plus }
					loading={ loading }
					value={ data?.kpis.new_bookings_7d ?? '' }
					hint={ __( 'Last 7 days', 'marclie-booking' ) }
					onClick={ () => navigate( '/bookings?orderby=created_at&order=desc' ) }
				/>
				<KpiCard
					label={ __( 'Due in 14 days', 'marclie-booking' ) }
					icon={ ClockCountdown }
					loading={ loading }
					value={ data ? <Money cents={ data.kpis.balances_due_14d_cents } /> : '' }
					hint={ __( 'Upcoming balances', 'marclie-booking' ) }
				/>
			</div>

			<div className="mb-6 grid gap-4 lg:grid-cols-2">
				<Card>
					<CardHeader>
						<div>
							<CardTitle>{ __( 'Arriving today', 'marclie-booking' ) }</CardTitle>
							<CardDescription>
								{ data
									? sprintf(
											/* translators: %d: number of arrivals. */
											_n( '%d arrival', '%d arrivals', data.today.arrivals.length, 'marclie-booking' ),
											data.today.arrivals.length
									  )
									: '\u00a0' }
							</CardDescription>
						</div>
						<ArrowSquareIn className="h-5 w-5 text-success" />
					</CardHeader>
					<StayList items={ data?.today.arrivals } kind="arrivals" loading={ loading } />
				</Card>
				<Card>
					<CardHeader>
						<div>
							<CardTitle>{ __( 'Leaving today', 'marclie-booking' ) }</CardTitle>
							<CardDescription>
								{ data
									? sprintf(
											/* translators: %d: number of departures. */
											_n( '%d departure', '%d departures', data.today.departures.length, 'marclie-booking' ),
											data.today.departures.length
									  )
									: '\u00a0' }
							</CardDescription>
						</div>
						<ArrowSquareOut className="h-5 w-5 text-muted-foreground" />
					</CardHeader>
					<StayList items={ data?.today.departures } kind="departures" loading={ loading } />
				</Card>
			</div>

			<div className="grid gap-4 xl:grid-cols-3">
				<Card className="xl:col-span-2">
					<CardHeader>
						<div>
							<CardTitle>{ __( 'Occupancy — next 12 months', 'marclie-booking' ) }</CardTitle>
							<CardDescription>{ __( 'Booked nights across all apartments', 'marclie-booking' ) }</CardDescription>
						</div>
					</CardHeader>
					<CardContent>
						{ loading || ! data ? (
							<Skeleton className="h-[240px]" />
						) : (
							<Suspense fallback={ <Skeleton className="h-[240px]" /> }>
								<OccupancyChart data={ data.occupancy_by_month } />
							</Suspense>
						) }
					</CardContent>
				</Card>
				<Card>
					<CardHeader>
						<div>
							<CardTitle>{ __( 'Revenue by apartment', 'marclie-booking' ) }</CardTitle>
							<CardDescription>{ today.slice( 0, 4 ) }</CardDescription>
						</div>
					</CardHeader>
					<CardContent>
						{ loading && <Skeleton className="h-[240px]" /> }
						{ ! loading && revenue.rows.length === 0 && (
							<EmptyState compact icon={ CurrencyEur } title={ __( 'No revenue yet this year', 'marclie-booking' ) } />
						) }
						{ ! loading && revenue.rows.length > 0 && (
							<ul className="flex max-h-[240px] flex-col gap-2.5 overflow-y-auto pr-1">
								{ revenue.rows.map( ( row ) => (
									<li key={ row.unit_id } className="text-sm">
										<div className="mb-1 flex justify-between gap-2">
											<span className="truncate text-ink-soft">{ unitMap.get( row.unit_id )?.title ?? `#${ row.unit_id }` }</span>
											<Money cents={ row.cents } className="text-xs font-medium" />
										</div>
										<div className="h-1.5 rounded-full bg-surface-muted">
											<div
												className="h-1.5 rounded-full bg-ink-soft"
												style={ { width: `${ revenue.max ? ( row.cents / revenue.max ) * 100 : 0 }%` } }
											/>
										</div>
									</li>
								) ) }
							</ul>
						) }
					</CardContent>
				</Card>
			</div>
		</>
	);
}
