import { CalendarBlank, X } from '@phosphor-icons/react';
import { __, _n, sprintf } from '@wordpress/i18n';
import * as React from 'react';
import type { DateRange, Matcher } from 'react-day-picker';

import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { diffDays, parseYmd, toYmd } from '@/lib/dates';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';

export interface YmdRange {
	from?: string;
	to?: string;
}

export interface DateRangePickerProps {
	value: YmdRange;
	onChange: ( range: YmdRange ) => void;
	placeholder?: string;
	/** Show "N nights" in the trigger (stay ranges). */
	showNights?: boolean;
	/** Days that cannot be picked at all. */
	isDisabled?: ( ymd: string ) => boolean;
	/** Days styled as booked (struck through) but still selectable, e.g. a check-out day. */
	isBooked?: ( ymd: string ) => boolean;
	/** Minimum selectable date (Y-m-d). */
	min?: string;
	numberOfMonths?: number;
	clearable?: boolean;
	align?: 'start' | 'center' | 'end';
	className?: string;
	/** Render the calendar inline instead of in a popover. */
	inline?: boolean;
	/** Month displayed initially (Y-m-d). */
	defaultMonth?: string;
	onMonthChange?: ( month: string ) => void;
	id?: string;
	/** Allow a single-day range (from === to), e.g. for date filters. Stays need ≥ 1 night. */
	allowSameDay?: boolean;
}

function toRange( value: YmdRange ): DateRange | undefined {
	if ( ! value.from ) {
		return undefined;
	}
	return { from: parseYmd( value.from ), to: value.to ? parseYmd( value.to ) : undefined };
}

export function DateRangePicker( {
	value,
	onChange,
	placeholder,
	showNights = false,
	isDisabled,
	isBooked,
	min,
	numberOfMonths = 2,
	clearable = false,
	align = 'start',
	className,
	inline = false,
	defaultMonth,
	onMonthChange,
	id,
	allowSameDay = false,
}: DateRangePickerProps ) {
	const [ open, setOpen ] = React.useState( false );
	// Popover mode keeps a half-picked range locally and only reports complete ranges.
	const [ draft, setDraft ] = React.useState< YmdRange | null >( null );
	const shown = draft ?? value;

	const disabled = React.useMemo( () => {
		const matchers: Matcher[] = [];
		if ( min ) {
			matchers.push( { before: parseYmd( min ) } );
		}
		if ( isDisabled ) {
			matchers.push( ( date: Date ) => isDisabled( toYmd( date ) ) );
		}
		return matchers;
	}, [ min, isDisabled ] );

	const modifiers = React.useMemo(
		() => ( isBooked ? { booked: ( date: Date ) => isBooked( toYmd( date ) ) } : undefined ),
		[ isBooked ]
	);

	const nights = value.from && value.to ? diffDays( value.from, value.to ) : 0;

	const label =
		value.from && value.to
			? `${ formatDate( value.from, 'day' ) } – ${ formatDate( value.to, 'medium' ) }`
			: value.from
			? `${ formatDate( value.from, 'medium' ) } – …`
			: placeholder ?? __( 'Pick dates', 'marclie-booking' );

	const calendar = (
		<Calendar
			mode="range"
			numberOfMonths={ numberOfMonths }
			defaultMonth={ shown.from ? parseYmd( shown.from ) : defaultMonth ? parseYmd( defaultMonth ) : undefined }
			onMonthChange={ ( month ) => onMonthChange?.( toYmd( month ) ) }
			selected={ toRange( inline ? value : shown ) }
			disabled={ disabled }
			modifiers={ modifiers }
			modifiersClassNames={ { booked: 'line-through decoration-danger/70 text-muted-foreground' } }
			onSelect={ ( range ) => {
				const next: YmdRange = {
					from: range?.from ? toYmd( range.from ) : undefined,
					to: range?.to ? toYmd( range.to ) : undefined,
				};
				if ( next.from && next.to && next.from === next.to && ! allowSameDay ) {
					next.to = undefined;
				}
				if ( inline ) {
					onChange( next );
					return;
				}
				if ( next.from && next.to ) {
					setDraft( null );
					onChange( next );
					setOpen( false );
				} else {
					setDraft( next );
				}
			} }
		/>
	);

	if ( inline ) {
		return <div className={ cn( 'rounded-lg border border-border bg-surface', className ) }>{ calendar }</div>;
	}

	return (
		<Popover
			open={ open }
			onOpenChange={ ( next ) => {
				setOpen( next );
				if ( ! next ) {
					setDraft( null );
				}
			} }
		>
			<div className={ cn( 'relative inline-flex', className ) }>
				<PopoverTrigger asChild>
					<Button
						id={ id }
						variant="outline"
						className={ cn(
							'w-full justify-start gap-2 font-normal',
							! value.from && 'text-muted-foreground',
							clearable && value.from && 'pr-8'
						) }
					>
						<CalendarBlank className="h-4 w-4 text-muted-foreground" />
						<span className="truncate tabular">{ label }</span>
						{ showNights && nights > 0 && (
							<span className="ml-auto text-xs text-muted-foreground">
								{ sprintf(
									/* translators: %d: number of nights. */
									_n( '%d night', '%d nights', nights, 'marclie-booking' ),
									nights
								) }
							</span>
						) }
					</Button>
				</PopoverTrigger>
				{ clearable && value.from && (
					<button
						type="button"
						aria-label={ __( 'Clear dates', 'marclie-booking' ) }
						className="absolute inset-y-0 right-2 my-auto flex h-5 w-5 items-center justify-center rounded-sm text-muted-foreground hover:bg-surface-muted hover:text-ink-soft"
						onClick={ () => onChange( {} ) }
					>
						<X className="h-3.5 w-3.5" />
					</button>
				) }
			</div>
			<PopoverContent className="w-auto p-0" align={ align }>
				{ calendar }
			</PopoverContent>
		</Popover>
	);
}
