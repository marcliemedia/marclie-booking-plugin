/**
 * Timeline grid: apartments (grouped by house) × days of the current window.
 *
 * The page fetches and renders exactly the window shown by the zoom (14 days, a month or
 * 90 days; ≤ 17 × 90 cells). Rows are memoized and receive only primitive/row-local props, so
 * a drag, a selection or a focus move re-renders only the rows it touches. Cells are nights
 * (`day` → `day + 1`). Interactions:
 * - drag across empty nights of one row (or Shift+Arrow) → popover "New booking" / "Block dates";
 * - click a booking → booking sheet; drag it to other days/apartment → move dry run + confirm
 *   (stays that already started cannot be dragged);
 * - arrow keys move focus between cells; Enter/Space opens the booking, the block (remove) or
 *   the action popover.
 */
import { CalendarPlus, CalendarX, Trash, WarningCircle } from '@phosphor-icons/react';
import { __, _x, sprintf } from '@wordpress/i18n';
import * as React from 'react';

import { errorMessage } from '@/api/client';
import { useBootstrapData } from '@/api/hooks/bootstrap';
import { useDeleteBlock } from '@/api/hooks/bookings';
import type { CalendarBlock, CalendarBooking, CalendarData, CalendarExternal, CalendarPrice, Unit } from '@/api/types';
import { sourceLabel, statusLabel } from '@/components/app/StatusBadge';
import { Button } from '@/components/ui/button';
import { Popover, PopoverAnchor, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Skeleton } from '@/components/ui/skeleton';
import { toast } from '@/components/ui/sonner';
import { BlockDialog } from '@/features/bookings/dialogs';
import { MoveConfirmDialog, type MoveTarget } from '@/features/bookings/MoveDialog';
import { useBookingSheet } from '@/features/bookings/sheet';
import { useBookingWizard } from '@/features/bookings/wizard';
import type { Zoom } from '@/features/calendar';
import { OCCUPYING } from '@/features/calendar/occupancy';
import { channelLabel } from '@/features/common/icalChannels';
import { addDays, diffDays, parseYmd, weekdayIndex } from '@/lib/dates';
import { formatDate, formatMoney, formatMoneyShort, getFormatLocale, minStayLabel, nightsLabel } from '@/lib/format';
import { groupByHouse } from '@/lib/units';
import { cn } from '@/lib/utils';

const ROW_HEIGHT = 40;
const MIN_CELL: Record< Zoom, number > = { '14': 44, month: 30, '90': 14 };

type Occupant =
	| { kind: 'booking'; item: CalendarBooking }
	| { kind: 'block'; item: CalendarBlock }
	| { kind: 'external'; item: CalendarExternal };

interface Selection {
	unitId: number;
	anchor: number;
	head: number;
}

interface DragState {
	booking: CalendarBooking;
	startX: number;
	pointerId: number;
	moved: boolean;
	offset: number;
	unitId: number;
}

interface RowData {
	cells: ( Occupant | undefined )[];
	bookings: CalendarBooking[];
	blocks: CalendarBlock[];
	external: CalendarExternal[];
	/** Nights where an imported block overlaps a booking (`external.conflict`). */
	conflict: boolean[];
}

interface Span {
	left: number;
	width: number;
	clippedLeft: boolean;
	clippedRight: boolean;
}

const BAR_STATUS: Record< CalendarBooking[ 'status' ], string > = {
	paid: 'bg-success text-white',
	confirmed: 'bg-success-soft text-success border border-success/30',
	pending_payment: 'bg-warning-soft text-warning border border-warning/30',
	balance_failed: 'bg-danger-soft text-danger border border-danger/40',
	completed: 'bg-ink-soft text-white',
	cancelled: 'bg-surface-muted text-muted-foreground',
	expired: 'bg-surface-muted text-muted-foreground',
};

const SOURCE_STRIPE: Record< CalendarBooking[ 'source' ], string > = {
	direct: 'before:bg-accent',
	admin: 'before:bg-ink-soft',
	airbnb: 'before:bg-transparent border-dashed',
	booking: 'before:bg-transparent border-dashed',
};

/** Imported (iCal) blocks: dashed bars tinted per channel. */
const EXTERNAL_CHANNEL: Record< string, string > = {
	airbnb: 'border-[#ff5a5f]/60 bg-[#ff5a5f]/10 text-[#c13515]',
	booking: 'border-[#003580]/50 bg-[#003580]/10 text-[#003580]',
	other: 'border-ink-soft/40 bg-surface-muted text-muted-foreground',
};

function externalLabel( item: CalendarExternal ): string {
	const channel = channelLabel( item.channel );
	if ( item.origin === 'echo' ) {
		return sprintf(
			/* translators: %s: channel name (Airbnb, Booking.com…). */
			__( 'Our own booking, as read back from %s', 'marclie-booking' ),
			channel
		);
	}
	return item.conflict
		? sprintf(
				/* translators: %s: channel name (Airbnb, Booking.com…). */
				__( 'Conflict: also booked on %s', 'marclie-booking' ),
				channel
		  )
		: sprintf(
				/* translators: %s: channel name (Airbnb, Booking.com…). */
				__( 'Booked on %s', 'marclie-booking' ),
				channel
		  );
}

/** Stable function identity that always calls the latest implementation. */
function useStableCallback< A extends unknown[], R >( fn: ( ...args: A ) => R ): ( ...args: A ) => R {
	const ref = React.useRef( fn );
	ref.current = fn;
	return React.useCallback( ( ...args: A ) => ref.current( ...args ), [] );
}

function useWidth< T extends HTMLElement >(): [ React.RefObject< T >, number ] {
	const ref = React.useRef< T >( null );
	const [ width, setWidth ] = React.useState( 0 );
	React.useLayoutEffect( () => {
		const element = ref.current;
		if ( ! element ) {
			return;
		}
		const observer = new ResizeObserver( ( [ entry ] ) => setWidth( entry.contentRect.width ) );
		observer.observe( element );
		setWidth( element.getBoundingClientRect().width );
		return () => observer.disconnect();
	}, [] );
	return [ ref, width ];
}

function spanFor( from: string, days: number, cellWidth: number, start: string, end: string ): Span | null {
	const startIndex = diffDays( from, start );
	const endIndex = diffDays( from, end );
	if ( endIndex <= 0 || startIndex >= days ) {
		return null;
	}
	const clippedLeft = startIndex < 0;
	const clippedRight = endIndex > days;
	const left = Math.max( startIndex, 0 ) * cellWidth + ( clippedLeft ? 0 : 2 );
	const right = Math.min( endIndex, days ) * cellWidth - ( clippedRight ? 0 : 2 );
	return { left, width: Math.max( right - left, 4 ), clippedLeft, clippedRight };
}

/* ---- rows ----------------------------------------------------------------- */

interface RowHandlers {
	cellPointerDown: ( unitId: number, row: number, col: number ) => void;
	cellPointerEnter: ( unitId: number, col: number ) => void;
	cellFocus: ( row: number, col: number ) => void;
	barPointerDown: ( event: React.PointerEvent< HTMLButtonElement >, booking: CalendarBooking ) => void;
	barPointerMove: ( event: React.PointerEvent< HTMLButtonElement > ) => void;
	barPointerUp: ( event: React.PointerEvent< HTMLButtonElement > ) => void;
	barPointerCancel: () => void;
	barClick: ( booking: CalendarBooking ) => void;
	blockOpenChange: ( blockId: number, open: boolean ) => void;
}

interface CalendarRowProps {
	unit: Unit;
	rowIndex: number;
	from: string;
	days: number;
	dayList: string[];
	cellWidth: number;
	labelWidth: number;
	todayIndex: number;
	today: string;
	row: RowData;
	prices: Record< string, CalendarPrice > | undefined;
	/** Selected night range on this row, or -1. */
	selStart: number;
	selEnd: number;
	/** Column holding the roving tabindex on this row, or -1. */
	focusCol: number;
	/** Ghost of a dragged booking landing on this row. */
	ghostStart: string | null;
	ghostEnd: string | null;
	/** Booking (on this row) currently being dragged. */
	draggingId: number | null;
	openBlockId: number | null;
	handlers: RowHandlers;
}

const CalendarRow = React.memo( function CalendarRow( {
	unit,
	rowIndex,
	from,
	days,
	dayList,
	cellWidth,
	labelWidth,
	todayIndex,
	today,
	row,
	prices,
	selStart,
	selEnd,
	focusCol,
	ghostStart,
	ghostEnd,
	draggingId,
	openBlockId,
	handlers,
}: CalendarRowProps ) {
	const trackWidth = cellWidth * days;
	const ghost = ghostStart && ghostEnd ? spanFor( from, days, cellWidth, ghostStart, ghostEnd ) : null;
	const capacityLabel = sprintf(
		/* translators: %d: max number of guests (short label next to the apartment name). */
		_x( '%dp', 'max guests, short', 'marclie-booking' ),
		unit.capacity
	);

	return (
		<div role="row" className="flex border-b border-border last:border-b-0" style={ { height: ROW_HEIGHT } } data-row-unit={ unit.id }>
			<div
				role="rowheader"
				className="sticky left-0 z-[5] flex shrink-0 items-center gap-2 border-r border-border bg-surface px-3"
				style={ { width: labelWidth } }
			>
				<span className="truncate text-[13px] font-medium text-ink" title={ unit.title }>
					{ unit.title }
				</span>
				<span
					className="ml-auto hidden shrink-0 text-2xs text-muted-foreground sm:inline"
					title={ sprintf(
						/* translators: %d: max number of guests. */
						__( 'Sleeps up to %d', 'marclie-booking' ),
						unit.capacity
					) }
				>
					{ capacityLabel }
				</span>
			</div>
			<div className="relative shrink-0" style={ { width: trackWidth } }>
				<div className="flex h-full">
					{ dayList.map( ( day, col ) => {
						const occupant = row.cells[ col ];
						const price = prices?.[ day ];
						const weekend = weekdayIndex( day ) >= 5;
						const selected = selStart !== -1 && col >= selStart && col <= selEnd;
						const priceText = price
							? sprintf(
									/* translators: 1: apartment price per night, 2: price per person per night, 3: minimum stay (e.g. "min 3 nights"). */
									__( '%1$s per night (%2$s per person), %3$s', 'marclie-booking' ),
									formatMoney( price.cents ),
									formatMoney( price.person_cents ),
									minStayLabel( price.min_stay )
							  )
							: '';
						const label = [
							unit.title,
							formatDate( day, 'long' ),
							occupant?.kind === 'booking'
								? `${ occupant.item.guest_name }, ${ statusLabel( occupant.item.status ) }`
								: occupant?.kind === 'block'
								? `${ __( 'Blocked', 'marclie-booking' ) }${ occupant.item.reason ? `: ${ occupant.item.reason }` : '' }`
								: occupant?.kind === 'external'
								? externalLabel( occupant.item )
								: __( 'Available', 'marclie-booking' ),
							row.conflict[ col ] && occupant?.kind === 'booking' ? __( 'Conflict with an imported calendar booking', 'marclie-booking' ) : '',
							priceText,
						]
							.filter( Boolean )
							.join( ', ' );
						return (
							<div
								key={ day }
								role="gridcell"
								data-cell={ `${ rowIndex }-${ col }` }
								tabIndex={ focusCol === col ? 0 : -1 }
								aria-label={ label }
								aria-selected={ selected || undefined }
								title={ price && ! occupant ? priceText : undefined }
								onFocus={ () => focusCol !== col && handlers.cellFocus( rowIndex, col ) }
								onPointerDown={ ( event ) => {
									if ( event.button === 0 && ! occupant ) {
										handlers.cellPointerDown( unit.id, rowIndex, col );
									}
								} }
								onPointerEnter={ () => handlers.cellPointerEnter( unit.id, col ) }
								className={ cn(
									'relative flex shrink-0 select-none items-end justify-center border-r border-border/70 pb-0.5 outline-none last:border-r-0',
									weekend && 'bg-surface-muted/60',
									col === todayIndex && 'bg-accent-soft/60',
									! occupant && 'cursor-cell hover:bg-surface-muted',
									selected && '!bg-ink-soft/10',
									'focus-visible:z-[4] focus-visible:shadow-[inset_0_0_0_2px_hsl(var(--ring))]'
								) }
								style={ { width: cellWidth } }
							>
								{ price && ! occupant && cellWidth >= 40 && (
									<span className="pointer-events-none flex min-w-0 flex-col items-center px-0.5 text-[10px] leading-tight text-muted-foreground tabular">
										<span className="max-w-full truncate">{ formatMoneyShort( price.cents ) }</span>
										<span className="max-w-full truncate text-[9px] opacity-80">
											{ sprintf(
												/* translators: %s: price per person per night, very short (calendar cell). */
												__( '%s/p', 'marclie-booking' ),
												formatMoneyShort( price.person_cents )
											) }
										</span>
									</span>
								) }
							</div>
						);
					} ) }
				</div>

				{ todayIndex >= 0 && todayIndex < days && (
					<div
						className="pointer-events-none absolute inset-y-0 z-[3] w-0.5 bg-accent/70"
						style={ { left: todayIndex * cellWidth } }
						aria-hidden="true"
					/>
				) }

				{ row.blocks.map( ( block ) => {
					const position = spanFor( from, days, cellWidth, block.date_from, block.date_to );
					return (
						position && (
							<BlockBar
								key={ `bl-${ block.id }` }
								block={ block }
								position={ position }
								open={ openBlockId === block.id }
								onOpenChange={ handlers.blockOpenChange }
							/>
						)
					);
				} ) }

				{ row.external.map( ( item ) => {
					const position = spanFor( from, days, cellWidth, item.date_from, item.date_to );
					return (
						position && (
							<div
								key={ `ex-${ item.id }` }
								aria-hidden="true"
								title={ [ externalLabel( item ), item.summary ].filter( Boolean ).join( ' · ' ) }
								className={ cn(
									'pointer-events-none absolute top-1.5 flex h-7 items-center gap-1 overflow-hidden rounded-md border-dashed px-1.5 text-[11px] font-medium',
									item.conflict
										? 'z-[4] justify-end border-2 border-danger bg-danger/10 text-danger'
										: cn( 'z-[2] border', EXTERNAL_CHANNEL[ item.channel ] ?? EXTERNAL_CHANNEL.other, item.origin === 'echo' && 'opacity-60' )
								) }
								style={ { left: position.left, width: position.width } }
							>
								{ item.conflict ? (
									position.width > 24 && <WarningCircle className="h-4 w-4 shrink-0 rounded-full bg-surface" weight="fill" />
								) : (
									position.width > 50 && <span className="truncate">{ channelLabel( item.channel ) }</span>
								) }
							</div>
						)
					);
				} ) }

				{ row.bookings.map( ( booking ) => {
					const position = spanFor( from, days, cellWidth, booking.arrival, booking.departure );
					if ( ! position ) {
						return null;
					}
					const dragging = draggingId === booking.id;
					const started = booking.arrival < today;
					return (
						<button
							key={ `bk-${ booking.id }` }
							type="button"
							tabIndex={ -1 }
							aria-hidden="true"
							title={ `${ booking.ref } · ${ booking.guest_name } · ${ nightsLabel( booking.nights ) } · ${ statusLabel( booking.status ) } · ${ sourceLabel( booking.source ) }` }
							onPointerDown={ ( event ) => handlers.barPointerDown( event, booking ) }
							onPointerMove={ handlers.barPointerMove }
							onPointerUp={ handlers.barPointerUp }
							onPointerCancel={ handlers.barPointerCancel }
							onClick={ () => handlers.barClick( booking ) }
							className={ cn(
								'absolute top-1.5 z-[3] flex h-7 touch-pan-y items-center gap-1 overflow-hidden pl-2 pr-1.5 text-left text-[12px] font-medium leading-none shadow-card transition-[opacity,box-shadow] hover:shadow-pop',
								"before:absolute before:inset-y-0 before:left-0 before:w-[3px] before:content-['']",
								BAR_STATUS[ booking.status ],
								SOURCE_STRIPE[ booking.source ],
								position.clippedLeft ? 'rounded-l-none' : 'rounded-l-md',
								position.clippedRight ? 'rounded-r-none' : 'rounded-r-md',
								started ? 'cursor-pointer' : 'cursor-grab',
								dragging && 'opacity-40'
							) }
							style={ { left: position.left, width: position.width } }
						>
							{ position.width > 36 && <span className="truncate">{ booking.guest_name }</span> }
							{ position.width > 110 && (
								<span className="shrink-0 opacity-75">
									·{ ' ' }
									{ sprintf(
										/* translators: %d: number of nights (short label on calendar bars). */
										_x( '%dn', 'nights, short', 'marclie-booking' ),
										booking.nights
									) }
								</span>
							) }
						</button>
					);
				} ) }

				{ ghost && (
					<div
						aria-hidden="true"
						className="pointer-events-none absolute top-1 z-[6] h-8 rounded-md border-2 border-dashed border-ink-soft bg-ink-soft/10"
						style={ { left: ghost.left, width: ghost.width } }
					/>
				) }

				{ selStart !== -1 && (
					<PopoverAnchor asChild>
						<div
							aria-hidden="true"
							className="pointer-events-none absolute inset-y-1 z-[4] rounded-md border-2 border-ink-soft/60"
							style={ { left: selStart * cellWidth, width: ( selEnd - selStart + 1 ) * cellWidth } }
						/>
					</PopoverAnchor>
				) }
			</div>
		</div>
	);
} );

function BlockBar( {
	block,
	position,
	open,
	onOpenChange,
}: {
	block: CalendarBlock;
	position: Span;
	open: boolean;
	onOpenChange: ( blockId: number, open: boolean ) => void;
} ) {
	const remove = useDeleteBlock();
	return (
		<Popover open={ open } onOpenChange={ ( next ) => onOpenChange( block.id, next ) }>
			<PopoverTrigger asChild>
				<button
					type="button"
					tabIndex={ -1 }
					aria-hidden="true"
					className={ cn(
						'hatched absolute top-1.5 z-[2] flex h-7 items-center overflow-hidden border border-border-strong bg-surface px-1.5 text-left text-[11px] font-medium text-muted-foreground',
						position.clippedLeft ? 'rounded-l-none border-l-0' : 'rounded-l-md',
						position.clippedRight ? 'rounded-r-none border-r-0' : 'rounded-r-md'
					) }
					style={ { left: position.left, width: position.width } }
					title={ block.reason || __( 'Blocked', 'marclie-booking' ) }
				>
					{ position.width > 60 && <span className="truncate rounded bg-surface/90 px-1">{ block.reason || __( 'Blocked', 'marclie-booking' ) }</span> }
				</button>
			</PopoverTrigger>
			<PopoverContent className="w-64" align="start">
				<div className="text-sm font-semibold text-ink">{ __( 'Blocked dates', 'marclie-booking' ) }</div>
				<div className="mt-1 text-sm text-muted-foreground tabular">
					{ formatDate( block.date_from, 'day' ) } – { formatDate( block.date_to, 'medium' ) } · { nightsLabel( diffDays( block.date_from, block.date_to ) ) }
				</div>
				{ block.reason && <p className="mt-2 text-sm">{ block.reason }</p> }
				<Button
					variant="secondary"
					size="sm"
					className="mt-3 w-full text-danger"
					disabled={ remove.isPending }
					onClick={ () =>
						remove.mutate( block.id, {
							onSuccess: () => {
								toast.success( __( 'Block removed.', 'marclie-booking' ) );
								onOpenChange( block.id, false );
							},
							onError: ( error ) => toast.error( errorMessage( error ) ),
						} )
					}
				>
					<Trash className="h-4 w-4" />
					{ __( 'Remove block', 'marclie-booking' ) }
				</Button>
			</PopoverContent>
		</Popover>
	);
}

/* ---- grid ----------------------------------------------------------------- */

export function CalendarGrid( {
	from,
	days,
	zoom,
	data,
	loading,
	fetching,
	showPrices,
	onPage,
}: {
	from: string;
	days: number;
	zoom: Zoom;
	data: CalendarData | undefined;
	loading: boolean;
	fetching: boolean;
	showPrices: boolean;
	/** PageUp/PageDown in the grid: show the previous/next visible range. */
	onPage: ( direction: 1 | -1 ) => void;
} ) {
	const { units, today } = useBootstrapData();
	const sheet = useBookingSheet();
	const wizard = useBookingWizard();
	const [ containerRef, width ] = useWidth< HTMLDivElement >();
	const gridRef = React.useRef< HTMLDivElement >( null );

	const labelWidth = width > 0 && width < 640 ? 112 : 176;
	const cellWidth = Math.max( MIN_CELL[ zoom ], width > 0 ? Math.floor( ( width - labelWidth ) / days ) : MIN_CELL[ zoom ] );
	const trackWidth = cellWidth * days;

	const dayList = React.useMemo( () => Array.from( { length: days }, ( _, index ) => addDays( from, index ) ), [ from, days ] );
	const todayIndex = diffDays( from, today );

	const groups = React.useMemo( () => groupByHouse( units ), [ units ] );
	const orderedUnits = React.useMemo( () => groups.flatMap( ( group ) => group.units ), [ groups ] );

	// Per-unit row data for the visible window (stable between renders of the same data).
	const rows = React.useMemo( () => {
		const map = new Map< number, RowData >();
		const ensure = ( unitId: number ) => {
			let row = map.get( unitId );
			if ( ! row ) {
				row = { cells: new Array( days ).fill( undefined ), bookings: [], blocks: [], external: [], conflict: new Array( days ).fill( false ) };
				map.set( unitId, row );
			}
			return row;
		};
		const fill = ( row: RowData, start: string, stop: string, occupant: Occupant ) => {
			const first = Math.max( 0, diffDays( from, start ) );
			const last = Math.min( days, diffDays( from, stop ) );
			for ( let col = first; col < last; col++ ) {
				row.cells[ col ] = occupant;
			}
		};
		// Every apartment gets a row object so memoized rows stay referentially stable.
		units.forEach( ( unit ) => ensure( unit.id ) );
		data?.blocks.forEach( ( item ) => {
			const row = ensure( item.unit_id );
			row.blocks.push( item );
			fill( row, item.date_from, item.date_to, { kind: 'block', item } );
		} );
		data?.external.forEach( ( item ) => {
			const row = ensure( item.unit_id );
			row.external.push( item );
			fill( row, item.date_from, item.date_to, { kind: 'external', item } );
			if ( item.conflict ) {
				const first = Math.max( 0, diffDays( from, item.date_from ) );
				const last = Math.min( days, diffDays( from, item.date_to ) );
				for ( let col = first; col < last; col++ ) {
					row.conflict[ col ] = true;
				}
			}
		} );
		data?.bookings.forEach( ( item ) => {
			if ( OCCUPYING[ item.status ] ) {
				const row = ensure( item.unit_id );
				row.bookings.push( item );
				fill( row, item.arrival, item.departure, { kind: 'booking', item } );
			}
		} );
		return map;
	}, [ data, from, days, units ] );

	const occupantAt = ( unitId: number, col: number ) => rows.get( unitId )?.cells[ col ];

	/* ---- selection --------------------------------------------------------- */

	const [ selection, setSelection ] = React.useState< Selection | null >( null );
	const [ actionsOpen, setActionsOpen ] = React.useState( false );
	const [ blockRequest, setBlockRequest ] = React.useState< { unitId: number; from: string; to: string } | null >( null );
	const [ openBlockId, setOpenBlockId ] = React.useState< number | null >( null );
	const selecting = React.useRef( false );
	// When the last drag-select started; outside-interaction dismissals right after it are ignored.
	const selectStartedAt = React.useRef( 0 );

	/** Extend from `anchor` toward `col`, stopping before the first occupied night. */
	const clampHead = ( unitId: number, anchor: number, col: number ) => {
		const step = col >= anchor ? 1 : -1;
		let head = anchor;
		for ( let index = anchor + step; step > 0 ? index <= col : index >= col; index += step ) {
			if ( index < 0 || index >= days || occupantAt( unitId, index ) ) {
				break;
			}
			head = index;
		}
		return head;
	};

	const selStart = selection ? Math.min( selection.anchor, selection.head ) : -1;
	const selEnd = selection ? Math.max( selection.anchor, selection.head ) : -1;

	React.useEffect( () => {
		const finish = () => {
			if ( selecting.current ) {
				selecting.current = false;
				setActionsOpen( true );
			}
		};
		const cancel = () => {
			if ( selecting.current ) {
				selecting.current = false;
				setSelection( null );
			}
		};
		window.addEventListener( 'pointerup', finish );
		window.addEventListener( 'pointercancel', cancel );
		return () => {
			window.removeEventListener( 'pointerup', finish );
			window.removeEventListener( 'pointercancel', cancel );
		};
	}, [] );

	/* ---- focus (roving tabindex) ------------------------------------------- */

	const [ focus, setFocus ] = React.useState< { row: number; col: number } >( () => ( {
		row: 0,
		col: Math.min( Math.max( todayIndex, 0 ), days - 1 ),
	} ) );

	// A new window invalidates the selection; keep the roving tabindex inside the grid.
	React.useEffect( () => {
		setSelection( null );
		setActionsOpen( false );
		setFocus( ( prev ) => ( {
			row: Math.min( prev.row, Math.max( orderedUnits.length - 1, 0 ) ),
			col: Math.min( Math.max( prev.col, 0 ), days - 1 ),
		} ) );
	}, [ from, days, orderedUnits.length ] );

	const focusCell = ( row: number, col: number ) => {
		const nextRow = Math.min( Math.max( row, 0 ), orderedUnits.length - 1 );
		const nextCol = Math.min( Math.max( col, 0 ), days - 1 );
		setFocus( { row: nextRow, col: nextCol } );
		gridRef.current?.querySelector< HTMLElement >( `[data-cell="${ nextRow }-${ nextCol }"]` )?.focus();
	};

	const activate = ( row: number, col: number ) => {
		const unit = orderedUnits[ row ];
		const occupant = occupantAt( unit.id, col );
		if ( occupant?.kind === 'booking' ) {
			sheet.open( occupant.item.id );
			return;
		}
		if ( occupant?.kind === 'block' ) {
			setOpenBlockId( occupant.item.id );
			return;
		}
		if ( occupant ) {
			return;
		}
		if ( ! selection || selection.unitId !== unit.id || col < selStart || col > selEnd ) {
			setSelection( { unitId: unit.id, anchor: col, head: col } );
		}
		setActionsOpen( true );
	};

	const onGridKeyDown = ( event: React.KeyboardEvent< HTMLDivElement > ) => {
		const target = event.target as HTMLElement;
		if ( ! target.dataset.cell ) {
			return;
		}
		const [ row, col ] = target.dataset.cell.split( '-' ).map( Number );
		const unit = orderedUnits[ row ];
		const moves: Record< string, [ number, number ] > = {
			ArrowLeft: [ 0, -1 ],
			ArrowRight: [ 0, 1 ],
			ArrowUp: [ -1, 0 ],
			ArrowDown: [ 1, 0 ],
		};
		if ( event.key in moves ) {
			event.preventDefault();
			const [ dRow, dCol ] = moves[ event.key ];
			if ( event.shiftKey && dRow === 0 && ! occupantAt( unit.id, col ) ) {
				const anchor = selection && selection.unitId === unit.id ? selection.anchor : col;
				const head = clampHead( unit.id, anchor, col + dCol );
				setSelection( { unitId: unit.id, anchor, head } );
				focusCell( row, head );
				return;
			}
			if ( ! event.shiftKey ) {
				setSelection( null );
			}
			focusCell( row + dRow, col + dCol );
		} else if ( event.key === 'Home' ) {
			event.preventDefault();
			focusCell( row, 0 );
		} else if ( event.key === 'End' ) {
			event.preventDefault();
			focusCell( row, days - 1 );
		} else if ( event.key === 'PageUp' || event.key === 'PageDown' ) {
			event.preventDefault();
			setSelection( null );
			onPage( event.key === 'PageDown' ? 1 : -1 );
			// Same row and column in the new range once it has rendered.
			window.requestAnimationFrame( () => focusCell( row, col ) );
		} else if ( event.key === 'Enter' || event.key === ' ' ) {
			event.preventDefault();
			activate( row, col );
		} else if ( event.key === 'Escape' ) {
			setSelection( null );
		}
	};

	/* ---- drag a booking ---------------------------------------------------- */

	const [ drag, setDrag ] = React.useState< DragState | null >( null );
	const suppressClick = React.useRef( false );
	const [ moveRequest, setMoveRequest ] = React.useState< { booking: CalendarBooking; target: MoveTarget } | null >( null );

	/* ---- stable row handlers ----------------------------------------------- */

	const cellPointerDown = useStableCallback( ( unitId: number, row: number, col: number ) => {
		setFocus( { row, col } );
		selecting.current = true;
		selectStartedAt.current = Date.now();
		setActionsOpen( false );
		setSelection( { unitId, anchor: col, head: col } );
	} );
	const cellPointerEnter = useStableCallback( ( unitId: number, col: number ) => {
		if ( selecting.current && selection && selection.unitId === unitId ) {
			const head = clampHead( unitId, selection.anchor, col );
			if ( head !== selection.head ) {
				setSelection( { ...selection, head } );
			}
		}
	} );
	const cellFocus = useStableCallback( ( row: number, col: number ) => setFocus( { row, col } ) );
	const barPointerDown = useStableCallback( ( event: React.PointerEvent< HTMLButtonElement >, booking: CalendarBooking ) => {
		// Touch scrolls; stays already in progress cannot be moved (the server re-prices from today).
		if ( event.button !== 0 || event.pointerType === 'touch' || booking.arrival < today ) {
			return;
		}
		event.currentTarget.setPointerCapture( event.pointerId );
		setDrag( { booking, startX: event.clientX, pointerId: event.pointerId, moved: false, offset: 0, unitId: booking.unit_id } );
	} );
	const barPointerMove = useStableCallback( ( event: React.PointerEvent< HTMLButtonElement > ) => {
		if ( ! drag || drag.pointerId !== event.pointerId ) {
			return;
		}
		const dx = event.clientX - drag.startX;
		const offset = Math.round( dx / cellWidth );
		const rowEl = document.elementFromPoint( event.clientX, event.clientY )?.closest< HTMLElement >( '[data-row-unit]' );
		const unitId = rowEl ? Number( rowEl.dataset.rowUnit ) : drag.unitId;
		const moved = drag.moved || Math.abs( dx ) > 4 || unitId !== drag.booking.unit_id;
		if ( offset !== drag.offset || unitId !== drag.unitId || moved !== drag.moved ) {
			setDrag( { ...drag, offset, unitId, moved } );
		}
	} );
	const barPointerUp = useStableCallback( ( event: React.PointerEvent< HTMLButtonElement > ) => {
		if ( ! drag || drag.pointerId !== event.pointerId ) {
			return;
		}
		event.currentTarget.releasePointerCapture( event.pointerId );
		const { booking, offset, unitId, moved } = drag;
		setDrag( null );
		if ( ! moved ) {
			// Plain click: the click handler opens the sheet.
			return;
		}
		suppressClick.current = true;
		if ( offset === 0 && unitId === booking.unit_id ) {
			return;
		}
		setMoveRequest( {
			booking,
			target: { unit_id: unitId, arrival: addDays( booking.arrival, offset ), departure: addDays( booking.departure, offset ) },
		} );
	} );
	const barPointerCancel = useStableCallback( () => setDrag( null ) );
	const barClick = useStableCallback( ( booking: CalendarBooking ) => {
		// A finished drag also emits a click; only plain clicks/taps open the sheet.
		if ( suppressClick.current ) {
			suppressClick.current = false;
			return;
		}
		sheet.open( booking.id );
	} );
	const blockOpenChange = useStableCallback( ( blockId: number, open: boolean ) => {
		if ( open ) {
			setOpenBlockId( blockId );
			return;
		}
		setOpenBlockId( null );
		focusCell( focus.row, focus.col );
	} );

	const handlers = React.useMemo< RowHandlers >(
		() => ( {
			cellPointerDown,
			cellPointerEnter,
			cellFocus,
			barPointerDown,
			barPointerMove,
			barPointerUp,
			barPointerCancel,
			barClick,
			blockOpenChange,
		} ),
		[ cellPointerDown, cellPointerEnter, cellFocus, barPointerDown, barPointerMove, barPointerUp, barPointerCancel, barClick, blockOpenChange ]
	);

	/* ---- header ------------------------------------------------------------ */

	const monthSegments = React.useMemo( () => {
		const segments: { label: string; span: number }[] = [];
		dayList.forEach( ( day ) => {
			const label = formatDate( day, 'month' );
			const last = segments[ segments.length - 1 ];
			if ( last && last.label === label ) {
				last.span++;
			} else {
				segments.push( { label, span: 1 } );
			}
		} );
		return segments;
	}, [ dayList ] );

	const showDayNumbers = cellWidth >= 22;
	const weekdayFormat = React.useMemo( () => new Intl.DateTimeFormat( getFormatLocale(), { weekday: 'narrow' } ), [] );
	const prices = showPrices ? data?.prices : undefined;

	const selectedUnit = selection ? units.find( ( unit ) => unit.id === selection.unitId ) : undefined;
	const selectedFrom = selection ? dayList[ selStart ] : undefined;
	const selectedTo = selection ? addDays( dayList[ selEnd ], 1 ) : undefined;
	const selectedNights = selection ? selEnd - selStart + 1 : 0;
	// Header row + one row per house + one per apartment.
	const rowCount = 1 + groups.length + orderedUnits.length;
	let rowCounter = -1;

	return (
		<div ref={ containerRef } className="w-full">
			<Popover
				open={ actionsOpen && selection !== null }
				onOpenChange={ ( next ) => {
					setActionsOpen( next );
					if ( ! next ) {
						setSelection( null );
					}
				} }
			>
				<div
					className={ cn( 'overflow-x-auto rounded-lg border border-border bg-surface shadow-card', fetching && 'opacity-70 transition-opacity' ) }
				>
					<div
						ref={ gridRef }
						role="grid"
						aria-label={ __( 'Availability calendar', 'marclie-booking' ) }
						aria-rowcount={ rowCount }
						aria-colcount={ days + 1 }
						aria-busy={ loading || fetching }
						onKeyDown={ onGridKeyDown }
						style={ { width: labelWidth + trackWidth } }
						className="relative"
					>
						{ /* Header */ }
						<div role="row" className="sticky top-0 z-[6] flex border-b border-border bg-surface">
							<div
								role="columnheader"
								className="sticky left-0 z-[7] flex shrink-0 items-end border-r border-border bg-surface px-3 pb-1.5 text-xs font-medium text-muted-foreground"
								style={ { width: labelWidth } }
							>
								{ __( 'Apartment', 'marclie-booking' ) }
							</div>
							<div className="shrink-0" style={ { width: trackWidth } }>
								<div className="flex h-6 border-b border-border/70" aria-hidden="true">
									{ monthSegments.map( ( segment, index ) => (
										<div
											key={ index }
											className="truncate border-r border-border/70 px-2 text-[11px] font-semibold capitalize leading-6 text-ink-soft last:border-r-0"
											style={ { width: segment.span * cellWidth } }
										>
											{ segment.span * cellWidth > 60 ? segment.label : '' }
										</div>
									) ) }
								</div>
								<div className="flex h-9">
									{ dayList.map( ( day, col ) => {
										const weekend = weekdayIndex( day ) >= 5;
										const isToday = col === todayIndex;
										const showNumber = showDayNumbers || weekdayIndex( day ) === 0;
										return (
											<div
												key={ day }
												role="columnheader"
												aria-label={ formatDate( day, 'long' ) }
												className={ cn(
													'flex shrink-0 flex-col items-center justify-center border-r border-border/70 leading-none last:border-r-0',
													weekend && 'bg-surface-muted/60',
													isToday && 'bg-accent-soft'
												) }
												style={ { width: cellWidth } }
											>
												{ cellWidth >= 30 && (
													<span className={ cn( 'text-[10px] uppercase text-muted-foreground', isToday && 'text-accent' ) }>
														{ weekdayFormat.format( parseYmd( day ) ) }
													</span>
												) }
												{ showNumber && (
													<span
														className={ cn(
															'mt-0.5 text-[12px] font-medium tabular text-ink-soft',
															isToday && 'flex h-5 min-w-5 items-center justify-center rounded-full bg-accent px-1 text-white'
														) }
													>
														{ Number( day.slice( 8 ) ) }
													</span>
												) }
											</div>
										);
									} ) }
								</div>
							</div>
						</div>

						{ /* Body */ }
						{ loading
							? Array.from( { length: 8 } ).map( ( _, index ) => (
									<div key={ index } className="flex border-b border-border" style={ { height: ROW_HEIGHT } }>
										<div className="sticky left-0 flex shrink-0 items-center border-r border-border bg-surface px-3" style={ { width: labelWidth } }>
											<Skeleton className="h-4 w-24" />
										</div>
										<div className="flex items-center px-3" style={ { width: trackWidth } }>
											<Skeleton className="h-6" style={ { width: `${ 20 + ( ( index * 37 ) % 50 ) }%`, marginLeft: `${ ( index * 13 ) % 30 }%` } } />
										</div>
									</div>
							  ) )
							: groups.map( ( group ) => (
									<div key={ group.house } role="rowgroup">
										<div role="row" className="flex border-b border-border bg-surface-muted/70">
											<div
												role="rowheader"
												className="sticky left-0 z-[5] shrink-0 bg-surface-muted px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"
												style={ { width: labelWidth } }
											>
												{ group.units[ 0 ].house }
											</div>
											<div style={ { width: trackWidth } } />
										</div>
										{ group.units.map( ( unit ) => {
											rowCounter++;
											const row = rows.get( unit.id ) as RowData;
											const onRow = selection?.unitId === unit.id;
											const ghostHere = drag && drag.moved && drag.unitId === unit.id;
											return (
												<CalendarRow
													key={ unit.id }
													unit={ unit }
													rowIndex={ rowCounter }
													from={ from }
													days={ days }
													dayList={ dayList }
													cellWidth={ cellWidth }
													labelWidth={ labelWidth }
													todayIndex={ todayIndex }
													today={ today }
													row={ row }
													prices={ prices?.[ String( unit.id ) ] }
													selStart={ onRow ? selStart : -1 }
													selEnd={ onRow ? selEnd : -1 }
													focusCol={ focus.row === rowCounter ? focus.col : -1 }
													ghostStart={ ghostHere ? addDays( drag.booking.arrival, drag.offset ) : null }
													ghostEnd={ ghostHere ? addDays( drag.booking.departure, drag.offset ) : null }
													draggingId={ drag && drag.moved && drag.booking.unit_id === unit.id ? drag.booking.id : null }
													openBlockId={ openBlockId !== null && row.blocks.some( ( block ) => block.id === openBlockId ) ? openBlockId : null }
													handlers={ handlers }
												/>
											);
										} ) }
									</div>
							  ) ) }
					</div>
				</div>
				<PopoverContent
					className="w-64 p-3"
					side="bottom"
					align="start"
					onOpenAutoFocus={ ( event ) => event.preventDefault() }
					onInteractOutside={ ( event ) => {
						// Starting a new drag-select must not be undone by this popover's dismissal.
						if ( selecting.current || Date.now() - selectStartedAt.current < 1000 ) {
							event.preventDefault();
						}
					} }
					onEscapeKeyDown={ () => focusCell( focus.row, focus.col ) }
				>
					{ selectedUnit && selectedFrom && selectedTo && (
						<>
							<div className="text-sm font-semibold text-ink">{ selectedUnit.title }</div>
							<div className="mb-3 text-xs text-muted-foreground tabular">
								{ formatDate( selectedFrom, 'day' ) } – { formatDate( selectedTo, 'medium' ) } · { nightsLabel( selectedNights ) }
							</div>
							<div className="flex flex-col gap-2">
								<Button
									size="sm"
									autoFocus
									onClick={ () => {
										setActionsOpen( false );
										setSelection( null );
										wizard.open( { unitId: selectedUnit.id, arrival: selectedFrom, departure: selectedTo } );
									} }
								>
									<CalendarPlus className="h-4 w-4" />
									{ __( 'New booking', 'marclie-booking' ) }
								</Button>
								<Button
									size="sm"
									variant="secondary"
									onClick={ () => {
										setActionsOpen( false );
										setSelection( null );
										setBlockRequest( { unitId: selectedUnit.id, from: selectedFrom, to: selectedTo } );
									} }
								>
									<CalendarX className="h-4 w-4" />
									{ __( 'Block dates', 'marclie-booking' ) }
								</Button>
							</div>
						</>
					) }
				</PopoverContent>
			</Popover>

			<BlockDialog
				unitId={ blockRequest?.unitId ?? orderedUnits[ 0 ]?.id ?? 0 }
				from={ blockRequest?.from }
				to={ blockRequest?.to }
				open={ blockRequest !== null }
				onOpenChange={ ( next ) => ! next && setBlockRequest( null ) }
			/>
			{ moveRequest && (
				<MoveConfirmDialog
					booking={ moveRequest.booking }
					target={ moveRequest.target }
					open
					onOpenChange={ ( next ) => ! next && setMoveRequest( null ) }
				/>
			) }
		</div>
	);
}
