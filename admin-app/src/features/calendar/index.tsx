import { CaretLeft, CaretRight, Plus } from '@phosphor-icons/react';
import { __ } from '@wordpress/i18n';
import * as React from 'react';

import { setQuery, useRoute } from '@/app/router';
import type { PageProps } from '@/app/routes';
import { useBootstrapData } from '@/api/hooks/bootstrap';
import { useCalendar } from '@/api/hooks/dashboard';
import { ErrorState } from '@/components/app/ErrorState';
import { PageHeader } from '@/components/app/PageHeader';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useBookingWizard } from '@/features/bookings/wizard';
import { CalendarGrid } from '@/features/calendar/CalendarGrid';
import { addDays, addMonths, daysInMonth, startOfMonth } from '@/lib/dates';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';

export type Zoom = '14' | 'month' | '90';

function windowFor( zoom: Zoom, from: string ): { from: string; days: number } {
	if ( zoom === 'month' ) {
		const start = startOfMonth( from );
		return { from: start, days: daysInMonth( start ) };
	}
	return { from, days: zoom === '14' ? 14 : 90 };
}

function defaultFrom( zoom: Zoom, today: string ): string {
	return zoom === 'month' ? startOfMonth( today ) : addDays( today, zoom === '14' ? -2 : -3 );
}

function Legend() {
	const items = [
		{ label: __( 'Paid', 'marclie-booking' ), className: 'bg-success' },
		{ label: __( 'Confirmed', 'marclie-booking' ), className: 'bg-success-soft border border-success/30' },
		{ label: __( 'Awaiting payment', 'marclie-booking' ), className: 'bg-warning-soft border border-warning/30' },
		{ label: __( 'Payment failed', 'marclie-booking' ), className: 'bg-danger-soft border border-danger/30' },
		{ label: __( 'Completed', 'marclie-booking' ), className: 'bg-ink-soft' },
		{ label: __( 'Blocked', 'marclie-booking' ), className: 'hatched border border-border-strong bg-surface' },
		{ label: 'Airbnb', className: 'border border-dashed border-[#ff5a5f]/60 bg-[#ff5a5f]/10' },
		{ label: 'Booking.com', className: 'border border-dashed border-[#003580]/50 bg-[#003580]/10' },
		{ label: __( 'Other calendar', 'marclie-booking' ), className: 'border border-dashed border-ink-soft/40 bg-surface-muted' },
		{ label: __( 'Double booking (iCal conflict)', 'marclie-booking' ), className: 'border-2 border-dashed border-danger bg-danger/10' },
	];
	return (
		<ul className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
			{ items.map( ( item ) => (
				<li key={ item.label } className="flex items-center gap-1.5">
					<span className={ cn( 'h-3 w-4 rounded-[3px]', item.className ) } />
					{ item.label }
				</li>
			) ) }
			<li className="flex items-center gap-1.5">
				<span className="h-3 w-1 rounded-sm bg-accent" />
				{ __( 'Website booking', 'marclie-booking' ) }
			</li>
		</ul>
	);
}

export default function CalendarPage( _props: PageProps ) {
	const { today } = useBootstrapData();
	const route = useRoute();
	const wizard = useBookingWizard();
	const zoomParam = route.query.get( 'view' );
	const zoom: Zoom = zoomParam === 'month' || zoomParam === '90' ? zoomParam : '14';
	const fromParam = route.query.get( 'from' );
	const { from, days } = windowFor( zoom, fromParam && /^\d{4}-\d{2}-\d{2}$/.test( fromParam ) ? fromParam : defaultFrom( zoom, today ) );
	const to = addDays( from, days );
	const [ showPrices, setShowPrices ] = React.useState( () => window.localStorage.getItem( 'mbk-cal-prices' ) === '1' );
	const calendar = useCalendar( from, to, showPrices );

	const shift = ( direction: 1 | -1 ) => {
		const next = zoom === 'month' ? addMonths( from, direction ) : addDays( from, direction * ( zoom === '14' ? 7 : 30 ) );
		setQuery( { from: next } );
	};
	/** Keyboard paging in the grid moves by the whole visible range (buttons step by half a range). */
	const page = ( direction: 1 | -1 ) => {
		setQuery( { from: zoom === 'month' ? addMonths( from, direction ) : addDays( from, direction * days ) } );
	};

	const zooms: { value: Zoom; label: string }[] = [
		{ value: '14', label: __( '2 weeks', 'marclie-booking' ) },
		{ value: 'month', label: __( 'Month', 'marclie-booking' ) },
		{ value: '90', label: __( '90 days', 'marclie-booking' ) },
	];

	return (
		<>
			<PageHeader
				title={ __( 'Calendar', 'marclie-booking' ) }
				actions={
					<Button onClick={ () => wizard.open() }>
						<Plus className="h-4 w-4" weight="bold" />
						{ __( 'New booking', 'marclie-booking' ) }
					</Button>
				}
			/>
			<div className="mb-3 flex flex-wrap items-center gap-2">
				<div className="flex items-center gap-1">
					<Button variant="outline" size="icon" onClick={ () => shift( -1 ) } aria-label={ __( 'Previous period', 'marclie-booking' ) }>
						<CaretLeft className="h-4 w-4" />
					</Button>
					<Button variant="outline" onClick={ () => setQuery( { from: null } ) }>
						{ __( 'Today', 'marclie-booking' ) }
					</Button>
					<Button variant="outline" size="icon" onClick={ () => shift( 1 ) } aria-label={ __( 'Next period', 'marclie-booking' ) }>
						<CaretRight className="h-4 w-4" />
					</Button>
				</div>
				<div className="min-w-[180px] px-2 text-sm font-semibold capitalize text-ink tabular" aria-live="polite">
					{ zoom === 'month' ? formatDate( from, 'month' ) : `${ formatDate( from, 'day' ) } – ${ formatDate( addDays( to, -1 ), 'medium' ) }` }
				</div>
				<div
					role="radiogroup"
					aria-label={ __( 'Zoom', 'marclie-booking' ) }
					className="flex h-9 items-center gap-0.5 rounded-lg bg-surface-muted p-1"
					onKeyDown={ ( event ) => {
						const step = event.key === 'ArrowRight' || event.key === 'ArrowDown' ? 1 : event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? -1 : 0;
						if ( step === 0 ) {
							return;
						}
						event.preventDefault();
						const index = zooms.findIndex( ( item ) => item.value === zoom );
						const next = zooms[ ( index + step + zooms.length ) % zooms.length ].value;
						setQuery( { view: next === '14' ? null : next, from: null } );
						event.currentTarget.querySelector< HTMLElement >( `[data-zoom="${ next }"]` )?.focus();
					} }
				>
					{ zooms.map( ( item ) => (
						<button
							key={ item.value }
							type="button"
							role="radio"
							data-zoom={ item.value }
							aria-checked={ zoom === item.value }
							tabIndex={ zoom === item.value ? 0 : -1 }
							onClick={ () => setQuery( { view: item.value === '14' ? null : item.value, from: null } ) }
							className={ cn(
								'h-7 rounded-md px-3 text-[13px] font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
								zoom === item.value ? 'bg-surface text-ink-soft shadow-card' : 'text-muted-foreground hover:text-ink-soft'
							) }
						>
							{ item.label }
						</button>
					) ) }
				</div>
				<div className="ml-auto flex items-center gap-2">
					<Switch
						id="mbk-cal-prices"
						checked={ showPrices }
						onCheckedChange={ ( checked ) => {
							setShowPrices( checked );
							window.localStorage.setItem( 'mbk-cal-prices', checked ? '1' : '0' );
						} }
					/>
					<Label htmlFor="mbk-cal-prices" className="font-normal">
						{ __( 'Prices & min stay', 'marclie-booking' ) }
					</Label>
				</div>
			</div>
			{ calendar.isError && ! calendar.data ? (
				<ErrorState error={ calendar.error } onRetry={ () => calendar.refetch() } />
			) : (
				<CalendarGrid
					from={ from }
					days={ days }
					zoom={ zoom }
					data={ calendar.data }
					loading={ calendar.isPending }
					fetching={ calendar.isPlaceholderData }
					showPrices={ showPrices }
					onPage={ page }
				/>
			) }
			<div className="mt-3">
				<Legend />
			</div>
			<p className="mt-2 text-xs text-muted-foreground">
				{ __( 'Drag across empty days to block them or start a booking. Drag a booking to move it. Arrow keys move between days, Enter opens.', 'marclie-booking' ) }
			</p>
		</>
	);
}
