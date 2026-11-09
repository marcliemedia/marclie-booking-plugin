import { PaintBucket } from '@phosphor-icons/react';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import type { ClipboardEvent, KeyboardEvent } from 'react';

import { ApiError } from '@/api/client';
import type { Unit, UnitPrices } from '@/api/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { centsToInput, formatMoney, formatMoneyShort, monthNames, parseMoneyInput } from '@/lib/format';
import { cn } from '@/lib/utils';

/** Column 0 = minimum nights, 1 = minimum paying guests, columns 2–13 = January…December. */
const MIN_COL = 0;
const GUESTS_COL = 1;
const FIRST_MONTH_COL = 2;
const COLUMNS = Array.from( { length: FIRST_MONTH_COL + 12 }, ( _, col ) => col );
const MONTH_COLUMNS = COLUMNS.slice( FIRST_MONTH_COL );

interface Props {
	units: Unit[];
	prices: UnitPrices[];
	saving: boolean;
	/** Rejects with the API error so the grid can map field errors back to cells. */
	onSave: ( changes: UnitPrices[] ) => Promise< unknown >;
	onDirtyChange?: ( dirty: boolean ) => void;
}

interface ParsedCell {
	value: number | null;
	invalid: boolean;
	dirty: boolean;
}

const cellKey = ( unitId: number, col: number ) => `${ unitId }:${ col }`;

/** Required whole number 1…max (minimum nights: 365, minimum guests: the unit capacity). */
function parseWhole( raw: string, max: number ): { value: number | null; invalid: boolean } {
	const trimmed = raw.trim();
	if ( ! /^\d+$/.test( trimmed ) ) {
		return { value: null, invalid: true };
	}
	const n = Number( trimmed );
	return { value: n, invalid: n < 1 || n > max };
}

/** Person price: empty = month closed, otherwise a positive amount. */
function parsePrice( raw: string ): { value: number | null; invalid: boolean } {
	if ( raw.trim() === '' ) {
		return { value: null, invalid: false };
	}
	const cents = parseMoneyInput( raw );
	return { value: cents, invalid: cents === null || cents <= 0 };
}

/** `capacity` bounds the minimum paying guests (1…capacity). */
function parseValue( col: number, raw: string, capacity: number ) {
	if ( col === MIN_COL ) {
		return parseWhole( raw, 365 );
	}
	return col === GUESTS_COL ? parseWhole( raw, capacity ) : parsePrice( raw );
}

/**
 * Spreadsheet-like editor of the monthly price table, one tab per house: rows = units,
 * columns = minimum nights + minimum paying guests + the 12 months (€ per person per night, empty = closed).
 * Drafts of both tabs are kept and saved together.
 */
export function PriceGrid( { units, prices, saving, onSave, onDirtyChange }: Props ) {
	const [ drafts, setDrafts ] = useState< Record< string, string > >( {} );
	const [ serverErrors, setServerErrors ] = useState< Record< string, string > >( {} );
	const tableRef = useRef< HTMLTableElement >( null );
	const shortMonths = useMemo( () => monthNames( 'short' ), [] );
	const longMonths = useMemo( () => monthNames( 'long' ), [] );

	const groups = useMemo( () => {
		const map = new Map< string, Unit[] >();
		for ( const unit of units ) {
			map.set( unit.house, [ ...( map.get( unit.house ) ?? [] ), unit ] );
		}
		return [ ...map.entries() ];
	}, [ units ] );

	const allUnits = useMemo( () => groups.flatMap( ( [ , list ] ) => list ), [ groups ] );
	const [ house, setHouse ] = useState( () => groups[ 0 ]?.[ 0 ] ?? '' );
	const activeHouse = groups.some( ( [ name ] ) => name === house ) ? house : groups[ 0 ]?.[ 0 ] ?? '';
	/** Rows of the active tab (keyboard navigation, paste and column fill stay inside it). */
	const rowUnits = useMemo( () => groups.find( ( [ name ] ) => name === activeHouse )?.[ 1 ] ?? [], [ groups, activeHouse ] );

	const rows = useMemo( () => {
		const map = new Map< number, UnitPrices >();
		for ( const row of prices ) {
			map.set( row.unit_id, row );
		}
		return map;
	}, [ prices ] );

	/** Saved value of a cell; a unit without a row has the server defaults (min 3, 1 guest, all closed). */
	const original = ( unitId: number, col: number ): number | null => {
		const row = rows.get( unitId );
		if ( col === MIN_COL ) {
			return row?.min_stay ?? 3;
		}
		if ( col === GUESTS_COL ) {
			return row?.min_guests ?? 1;
		}
		return row?.months[ col - FIRST_MONTH_COL ] ?? null;
	};

	const parseCell = ( unitId: number, col: number ): ParsedCell => {
		const saved = original( unitId, col );
		const draft = drafts[ cellKey( unitId, col ) ];
		if ( draft === undefined ) {
			return { value: saved, invalid: false, dirty: false };
		}
		const parsed = parseValue( col, draft, allUnits.find( ( unit ) => unit.id === unitId )?.capacity ?? 1 );
		return { ...parsed, dirty: parsed.invalid || parsed.value !== saved };
	};

	const parsedDrafts = Object.keys( drafts ).map( ( key ) => {
		const [ unitId, col ] = key.split( ':' ).map( Number );
		return { unitId, ...parseCell( unitId, col ) };
	} );
	const dirtyCount = parsedDrafts.filter( ( c ) => c.dirty ).length;
	const invalidCount = parsedDrafts.filter( ( c ) => c.invalid ).length;
	const dirtyUnitIds = new Set( parsedDrafts.filter( ( c ) => c.dirty ).map( ( c ) => c.unitId ) );
	const dirty = dirtyCount > 0;

	useEffect( () => {
		onDirtyChange?.( dirty );
	}, [ dirty, onDirtyChange ] );

	/** Apply drafts (cell key → raw text) and forget server errors of the touched cells. */
	const applyDrafts = ( entries: [ string, string ][] ) => {
		setDrafts( ( prev ) => ( { ...prev, ...Object.fromEntries( entries ) } ) );
		setServerErrors( ( prev ) => {
			const next = { ...prev };
			entries.forEach( ( [ key ] ) => delete next[ key ] );
			return next;
		} );
	};

	const focusCell = ( row: number, col: number ) => {
		const el = tableRef.current?.querySelector< HTMLInputElement >( `input[data-row="${ row }"][data-col="${ col }"]` );
		if ( el ) {
			el.focus();
			el.select();
		}
	};

	const onKeyDown = ( e: KeyboardEvent< HTMLInputElement >, row: number, col: number ) => {
		const input = e.currentTarget;
		const atStart = input.selectionStart === 0 && input.selectionEnd === 0;
		const atEnd = input.selectionStart === input.value.length && input.selectionEnd === input.value.length;
		const allSelected = input.selectionStart === 0 && input.selectionEnd === input.value.length;
		switch ( e.key ) {
			case 'Enter':
				e.preventDefault();
				focusCell( row + ( e.shiftKey ? -1 : 1 ), col );
				break;
			case 'ArrowDown':
				e.preventDefault();
				focusCell( row + 1, col );
				break;
			case 'ArrowUp':
				e.preventDefault();
				focusCell( row - 1, col );
				break;
			case 'ArrowRight':
				if ( atEnd || allSelected ) {
					e.preventDefault();
					focusCell( row, col + 1 );
				}
				break;
			case 'ArrowLeft':
				if ( atStart || allSelected ) {
					e.preventDefault();
					focusCell( row, col - 1 );
				}
				break;
			case 'Escape': {
				const key = cellKey( rowUnits[ row ].id, col );
				setDrafts( ( prev ) => {
					const next = { ...prev };
					delete next[ key ];
					return next;
				} );
				// The reverted cell is no longer sent: its server error goes too.
				setServerErrors( ( prev ) => {
					const next = { ...prev };
					delete next[ key ];
					return next;
				} );
				break;
			}
		}
	};

	/** Paste a TSV block (copied from Excel/Sheets) starting at this cell. */
	const onPaste = ( e: ClipboardEvent< HTMLInputElement >, row: number, col: number ) => {
		const text = e.clipboardData.getData( 'text/plain' );
		if ( ! /[\t\n]/.test( text.trim() ) ) {
			return;
		}
		e.preventDefault();
		const entries: [ string, string ][] = [];
		text.replace( /\r/g, '' )
			.replace( /\n$/, '' )
			.split( '\n' )
			.forEach( ( line, r ) => {
				line.split( '\t' ).forEach( ( value, c ) => {
					const unit = rowUnits[ row + r ];
					if ( unit && col + c < COLUMNS.length ) {
						entries.push( [ cellKey( unit.id, col + c ), value.trim() ] );
					}
				} );
			} );
		applyDrafts( entries );
	};

	const discard = () => {
		setDrafts( {} );
		setServerErrors( {} );
	};

	const save = async () => {
		const sentDrafts = drafts;
		const sentUnits = allUnits.filter( ( unit ) => dirtyUnitIds.has( unit.id ) );
		const changes: UnitPrices[] = sentUnits.map( ( unit ) => ( {
			unit_id: unit.id,
			min_stay: parseCell( unit.id, MIN_COL ).value as number,
			min_guests: parseCell( unit.id, GUESTS_COL ).value as number,
			months: MONTH_COLUMNS.map( ( col ) => parseCell( unit.id, col ).value ),
		} ) );
		try {
			await onSave( changes );
			// Keep only what was typed while the request was running.
			setDrafts( ( prev ) =>
				Object.fromEntries( Object.entries( prev ).filter( ( [ key, value ] ) => sentDrafts[ key ] !== value ) )
			);
			setServerErrors( {} );
		} catch ( error ) {
			// The caller reports the error; keep the drafts and mark the cells the server rejected.
			if ( error instanceof ApiError ) {
				const errors: Record< string, string > = {};
				for ( const [ field, message ] of Object.entries( error.fields ) ) {
					const match = /^changes\.(\d+)\.(min_stay|min_guests|months\.(\d+))$/.exec( field );
					const unit = match ? sentUnits[ Number( match[ 1 ] ) ] : undefined;
					if ( match && unit ) {
						let col = MIN_COL;
						if ( match[ 2 ] === 'min_guests' ) {
							col = GUESTS_COL;
						} else if ( match[ 3 ] !== undefined ) {
							col = Number( match[ 3 ] ) + FIRST_MONTH_COL;
						}
						errors[ cellKey( unit.id, col ) ] = message;
					}
				}
				setServerErrors( errors );
			}
		}
	};

	return (
		<div className="flex flex-col gap-3">
			<Tabs value={ activeHouse } onValueChange={ setHouse }>
				<TabsList>
					{ groups.map( ( [ name, list ] ) => (
						<TabsTrigger key={ name } value={ name }>
							{ name }
							{ list.some( ( unit ) => dirtyUnitIds.has( unit.id ) ) && (
								<span className="h-1.5 w-1.5 rounded-full bg-warning" title={ __( 'Unsaved price changes', 'marclie-booking' ) } />
							) }
						</TabsTrigger>
					) ) }
				</TabsList>
			</Tabs>
			<div className="overflow-x-auto rounded-lg border border-border bg-background">
				<table ref={ tableRef } className="w-full min-w-[900px] table-fixed border-separate border-spacing-0 text-sm">
					<caption className="sr-only">
						{ sprintf(
							/* translators: %s: house name. */
							__( 'Prices per person per night by apartment and month, %s', 'marclie-booking' ),
							activeHouse
						) }
					</caption>
					<colgroup>
						<col className="w-[176px]" />
						<col className="w-[48px]" />
						<col className="w-[52px]" />
						{ MONTH_COLUMNS.map( ( col ) => (
							<col key={ col } />
						) ) }
					</colgroup>
					<thead>
						<tr>
							<th
								scope="col"
								className="sticky left-0 z-30 border-b border-r border-border bg-surface-muted px-3 py-2 text-left font-medium"
							>
								{ __( 'Apartment', 'marclie-booking' ) }
							</th>
							{ COLUMNS.map( ( col ) => {
								const isMonth = col >= FIRST_MONTH_COL;
								let label: string;
								let fullLabel: string;
								if ( col === MIN_COL ) {
									label = __( 'Min.', 'marclie-booking' );
									fullLabel = __( 'Min. nights', 'marclie-booking' );
								} else if ( col === GUESTS_COL ) {
									/* translators: Short column header of the minimum paying guests; keep it short. */
									label = __( 'Guests', 'marclie-booking' );
									fullLabel = __( 'Minimum paying guests', 'marclie-booking' );
								} else {
									label = shortMonths[ col - FIRST_MONTH_COL ];
									fullLabel = longMonths[ col - FIRST_MONTH_COL ];
								}
								let kind: FillKind = 'price';
								if ( col === MIN_COL ) {
									kind = 'min';
								} else if ( col === GUESTS_COL ) {
									kind = 'guests';
								}
								return (
									<th
										key={ col }
										scope="col"
										abbr={ fullLabel }
										className={ cn(
											'border-b border-border bg-surface-muted py-1.5 pl-0.5 pr-0 text-left font-medium',
											col < COLUMNS.length - 1 && 'border-r',
											col === GUESTS_COL && 'border-r-border-strong'
										) }
									>
										{ /* Leading number columns are narrow: the fill button wraps under a long label. */ }
										<div className={ cn( 'flex items-center justify-between', ! isMonth && 'flex-wrap' ) }>
											<span className={ cn( 'capitalize', isMonth && 'truncate' ) } title={ fullLabel }>
												{ label }
											</span>
											<FillPopover
												kind={ kind }
												maxGuests={ Math.max( 1, ...rowUnits.map( ( unit ) => unit.capacity ) ) }
												title={ __( 'Fill column…', 'marclie-booking' ) }
												description={ sprintf(
													/* translators: 1: month name, "Min. nights" or "Minimum paying guests", 2: house name. */
													__( '%1$s for every apartment in %2$s.', 'marclie-booking' ),
													fullLabel,
													activeHouse
												) }
												onApply={ ( value ) => applyDrafts( rowUnits.map( ( unit ) => [ cellKey( unit.id, col ), value ] ) ) }
											/>
										</div>
									</th>
								);
							} ) }
						</tr>
					</thead>
					<tbody>
						{ rowUnits.map( ( unit, row ) => (
							<tr key={ unit.id } className="group">
								<th
									scope="row"
									className="sticky left-0 z-10 border-b border-r border-border bg-background py-1.5 pl-3 pr-1 text-left font-medium group-hover:bg-surface-muted"
								>
									<div className="flex items-center justify-between gap-1">
										<div className="min-w-0">
											<span className="block leading-tight" title={ unit.title }>
												{ unit.title }
											</span>
											<span className="block text-xs font-normal text-muted-foreground">
												{ sprintf(
													/* translators: %d: apartment capacity (persons). */
													_n( 'sleeps %d', 'sleeps %d', unit.capacity, 'marclie-booking' ),
													unit.capacity
												) }
											</span>
										</div>
										<FillPopover
											kind="price"
											title={ __( 'Fill row…', 'marclie-booking' ) }
											description={ sprintf(
												/* translators: %s: apartment. */
												__( '%s, all 12 months.', 'marclie-booking' ),
												unit.title
											) }
											onApply={ ( value ) => applyDrafts( MONTH_COLUMNS.map( ( col ) => [ cellKey( unit.id, col ), value ] ) ) }
										/>
									</div>
								</th>
								{ COLUMNS.map( ( col ) => {
									const key = cellKey( unit.id, col );
									const cell = parseCell( unit.id, col );
									const serverError = serverErrors[ key ];
									const saved = original( unit.id, col );
									const isNumber = col < FIRST_MONTH_COL;
									// Whole euros without decimals ("50") keep the narrow columns readable.
									const savedText = saved !== null && saved % 100 === 0 ? String( saved / 100 ) : centsToInput( saved );
									const value = drafts[ key ] ?? ( isNumber ? String( saved ?? '' ) : savedText );
									// Minimum nightly apartment price = person price × minimum paying guests (typed or saved).
									const guests = parseCell( unit.id, GUESTS_COL );
									const minGuests = guests.invalid ? original( unit.id, GUESTS_COL ) ?? 1 : guests.value ?? 1;
									const apartmentCents = ! isNumber && ! cell.invalid && cell.value !== null ? cell.value * minGuests : null;
									const hintId = `mbk-price-${ key.replace( ':', '-' ) }`;
									let ariaLabel: string;
									if ( col === MIN_COL ) {
										ariaLabel = sprintf(
											/* translators: %s: apartment. */
											__( 'Minimum nights, %s', 'marclie-booking' ),
											unit.title
										);
									} else if ( col === GUESTS_COL ) {
										ariaLabel = sprintf(
											/* translators: 1: apartment, 2: apartment capacity (persons). */
											__( 'Minimum paying guests (1–%2$d), %1$s', 'marclie-booking' ),
											unit.title,
											unit.capacity
										);
									} else {
										ariaLabel = sprintf(
											/* translators: 1: apartment, 2: month. */
											__( 'Price per person per night, %1$s, %2$s', 'marclie-booking' ),
											unit.title,
											longMonths[ col - FIRST_MONTH_COL ]
										);
									}
									return (
										<td
											key={ col }
											className={ cn(
												'border-b border-border px-0.5 py-1 align-top',
												col < COLUMNS.length - 1 && 'border-r',
												col === GUESTS_COL && 'border-r-border-strong',
												! isNumber && cell.value === null && ! cell.invalid && 'bg-surface-muted',
												cell.dirty && 'bg-warning-soft',
												( cell.invalid || serverError ) && 'bg-danger-soft'
											) }
											title={ serverError ?? ( ! isNumber && cell.value === null ? __( 'Closed', 'marclie-booking' ) : undefined ) }
										>
											<div className="relative">
												<input
													type="text"
													inputMode={ isNumber ? 'numeric' : 'decimal' }
													value={ value }
													placeholder={ isNumber ? '' : '—' }
													data-row={ row }
													data-col={ col }
													aria-label={ ariaLabel }
													aria-invalid={ cell.invalid || !! serverError || undefined }
													aria-describedby={ apartmentCents !== null ? hintId : undefined }
													onChange={ ( e ) => applyDrafts( [ [ key, e.target.value ] ] ) }
													onKeyDown={ ( e ) => onKeyDown( e, row, col ) }
													onPaste={ ( e ) => onPaste( e, row, col ) }
													onFocus={ ( e ) => e.currentTarget.select() }
													className={ cn(
														'h-8 w-full rounded-md border border-transparent bg-transparent py-1 text-right tabular-nums outline-none placeholder:text-[11px] placeholder:italic placeholder:text-muted-foreground/60 hover:border-border focus:border-ring focus:bg-background focus:ring-2 focus:ring-ring/30',
														isNumber ? 'px-1.5' : 'pl-1 pr-4'
													) }
												/>
												{ ! isNumber && value.trim() !== '' && (
													<span
														aria-hidden="true"
														className="pointer-events-none absolute right-1 top-1/2 -translate-y-1/2 text-xs text-muted-foreground"
													>
														€
													</span>
												) }
											</div>
											{ apartmentCents !== null && (
												<div id={ hintId } className="truncate px-1 text-right text-[11px] leading-4 text-muted-foreground tabular-nums" title={ __( 'Lowest apartment price per night (minimum guests)', 'marclie-booking' ) }>
													<span className="sr-only">
														{ __( 'Lowest apartment price per night:', 'marclie-booking' ) }{ ' ' }
													</span>
													{ apartmentCents % 100 === 0 ? formatMoneyShort( apartmentCents ) : formatMoney( apartmentCents ) }
												</div>
											) }
										</td>
									);
								} ) }
							</tr>
						) ) }
					</tbody>
				</table>
			</div>

			<div
				className={ cn(
					'sticky bottom-0 z-20 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-background px-4 py-3 shadow-sm',
					! dirty && 'hidden'
				) }
				role="region"
				aria-label={ __( 'Unsaved price changes', 'marclie-booking' ) }
			>
				<p className="text-sm" aria-live="polite">
					{ sprintf(
						/* translators: %d: number of changed cells. */
						_n( '%d unsaved change', '%d unsaved changes', dirtyCount, 'marclie-booking' ),
						dirtyCount
					) }
					{ invalidCount > 0 && (
						<span className="ml-2 text-danger">
							{ sprintf(
								/* translators: %d: number of invalid cells. */
								_n( '%d invalid cell', '%d invalid cells', invalidCount, 'marclie-booking' ),
								invalidCount
							) }
						</span>
					) }
				</p>
				<div className="flex gap-2">
					<Button variant="outline" size="sm" onClick={ discard } disabled={ saving }>
						{ __( 'Discard', 'marclie-booking' ) }
					</Button>
					<Button size="sm" onClick={ save } disabled={ saving || invalidCount > 0 }>
						{ saving ? __( 'Saving…', 'marclie-booking' ) : __( 'Save changes', 'marclie-booking' ) }
					</Button>
				</div>
			</div>
			<p className="text-xs text-muted-foreground">
				{ __(
					'Tip: Tab, Enter and arrow keys move between cells; Esc reverts a cell. Paste a block copied from Excel to fill a range. Minimum nights must be 1–365; minimum paying guests 1 up to the apartment’s capacity; prices are in € per person per night.',
					'marclie-booking'
				) }
			</p>
		</div>
	);
}

type FillKind = 'price' | 'min' | 'guests';

/** Header action: one value for a whole column/row, applied as drafts (empty price = close). */
function FillPopover( {
	kind,
	maxGuests = 1,
	title,
	description,
	onApply,
}: {
	kind: FillKind;
	/** Largest capacity of the filled rows (upper bound of the minimum paying guests). */
	maxGuests?: number;
	title: string;
	description: string;
	onApply: ( value: string ) => void;
} ) {
	const [ open, setOpen ] = useState( false );
	const [ value, setValue ] = useState( '' );
	let col = FIRST_MONTH_COL;
	if ( kind === 'min' ) {
		col = MIN_COL;
	} else if ( kind === 'guests' ) {
		col = GUESTS_COL;
	}
	const invalid = parseValue( col, value, maxGuests ).invalid;
	let inputLabel: string = __( '€ per person per night', 'marclie-booking' );
	let help: string = __( '€ per person per night. Leave empty to close the months.', 'marclie-booking' );
	let placeholder: string = __( 'Closed', 'marclie-booking' );
	if ( kind === 'min' ) {
		inputLabel = __( 'Min. nights', 'marclie-booking' );
		help = __( 'Whole number of nights, 1–365.', 'marclie-booking' );
		placeholder = '3';
	} else if ( kind === 'guests' ) {
		inputLabel = __( 'Minimum paying guests', 'marclie-booking' );
		help = sprintf(
			/* translators: %d: largest apartment capacity in the column. */
			__( 'Whole number of guests, 1 up to the apartment’s capacity (at most %d).', 'marclie-booking' ),
			maxGuests
		);
		placeholder = '2';
	}
	const apply = () => {
		if ( invalid ) {
			return;
		}
		onApply( value.trim() );
		setOpen( false );
	};

	return (
		<Popover
			open={ open }
			onOpenChange={ ( next ) => {
				setOpen( next );
				if ( next ) {
					setValue( '' );
				}
			} }
		>
			<PopoverTrigger asChild>
				<Button
					variant="ghost"
					size="icon-sm"
					className="h-5 w-5 shrink-0 text-muted-foreground"
					aria-label={ `${ title } ${ description }` }
					title={ title }
				>
					<PaintBucket className="h-3.5 w-3.5" aria-hidden="true" />
				</Button>
			</PopoverTrigger>
			<PopoverContent className="w-64" align="start">
				<form
					className="flex flex-col gap-2"
					onSubmit={ ( e ) => {
						e.preventDefault();
						apply();
					} }
				>
					<div>
						<div className="text-sm font-semibold text-ink">{ title }</div>
						<div className="text-xs text-muted-foreground">{ description }</div>
					</div>
					<Input
						autoFocus
						inputMode={ kind === 'price' ? 'decimal' : 'numeric' }
						value={ value }
						onChange={ ( e ) => setValue( e.target.value ) }
						placeholder={ placeholder }
						aria-label={ inputLabel }
						aria-invalid={ ( value !== '' && invalid ) || undefined }
					/>
					<p className={ cn( 'text-xs', value !== '' && invalid ? 'text-danger' : 'text-muted-foreground' ) }>
						{ help }
					</p>
					<div className="flex justify-end gap-2">
						<Button type="button" variant="outline" size="sm" onClick={ () => setOpen( false ) }>
							{ __( 'Cancel', 'marclie-booking' ) }
						</Button>
						<Button type="submit" size="sm" disabled={ invalid }>
							{ __( 'Apply', 'marclie-booking' ) }
						</Button>
					</div>
				</form>
			</PopoverContent>
		</Popover>
	);
}
