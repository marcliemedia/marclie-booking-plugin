import { CaretLeft, CaretRight } from '@phosphor-icons/react';
import { hr } from 'date-fns/locale/hr';
import * as React from 'react';
import { DayPicker } from 'react-day-picker';

import { buttonVariants } from '@/components/ui/button';
import { getFormatLocale } from '@/lib/format';
import { cn } from '@/lib/utils';

export type CalendarProps = React.ComponentProps< typeof DayPicker >;

export function Calendar( { className, classNames, showOutsideDays = true, ...props }: CalendarProps ) {
	const locale = getFormatLocale().startsWith( 'hr' ) ? hr : undefined;
	return (
		<DayPicker
			showOutsideDays={ showOutsideDays }
			weekStartsOn={ 1 }
			locale={ locale }
			className={ cn( 'p-3', className ) }
			classNames={ {
				months: 'flex flex-col gap-4 sm:flex-row sm:gap-6',
				month: 'flex flex-col gap-3',
				caption: 'relative flex items-center justify-center pt-1',
				caption_label: 'text-sm font-semibold capitalize text-ink',
				nav: 'flex items-center gap-1',
				nav_button: cn( buttonVariants( { variant: 'ghost', size: 'icon-sm' } ), 'h-7 w-7' ),
				nav_button_previous: 'absolute left-0',
				nav_button_next: 'absolute right-0',
				table: 'w-full border-collapse',
				head_row: 'flex',
				head_cell: 'w-9 text-[11px] font-medium uppercase text-muted-foreground',
				row: 'mt-1 flex w-full',
				cell: cn(
					'relative h-9 w-9 p-0 text-center text-sm focus-within:relative focus-within:z-20',
					'[&:has([aria-selected].day-range-middle)]:bg-surface-muted',
					'[&:has([aria-selected].day-range-start)]:rounded-l-md [&:has([aria-selected].day-range-end)]:rounded-r-md [&:has([aria-selected].day-range-start)]:bg-surface-muted [&:has([aria-selected].day-range-end)]:bg-surface-muted'
				),
				day: cn(
					buttonVariants( { variant: 'ghost' } ),
					'h-9 w-9 p-0 font-normal tabular aria-selected:opacity-100'
				),
				day_range_start: 'day-range-start',
				day_range_end: 'day-range-end',
				day_selected: '!bg-ink-soft !text-white hover:!bg-ink-soft focus:!bg-ink-soft',
				day_today: 'font-semibold text-accent',
				day_outside: 'day-outside text-muted-foreground/50 aria-selected:!bg-surface-muted aria-selected:!text-muted-foreground',
				day_disabled: 'text-muted-foreground/40 line-through cursor-not-allowed hover:!bg-transparent',
				day_range_middle: 'day-range-middle aria-selected:!bg-surface-muted aria-selected:!text-ink-soft',
				day_hidden: 'invisible',
				...classNames,
			} }
			components={ {
				IconLeft: () => <CaretLeft className="h-4 w-4" />,
				IconRight: () => <CaretRight className="h-4 w-4" />,
			} }
			{ ...props }
		/>
	);
}
