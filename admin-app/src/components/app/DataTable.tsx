/**
 * Server-driven table: TanStack Table in manual mode (pagination + sorting by the API).
 *
 * Column meta: `{ label?: string; align?: 'left' | 'right' | 'center'; className?: string; hideable?: boolean }`.
 * Sorting: pass `sorting` + `onSortingChange`; only columns with `enableSorting: true` sort.
 */
import { ArrowDown, ArrowUp, CaretLeft, CaretRight, Columns } from '@phosphor-icons/react';
import {
	flexRender,
	getCoreRowModel,
	useReactTable,
	type ColumnDef,
	type RowData,
	type SortingState,
	type VisibilityState,
} from '@tanstack/react-table';
import { __, sprintf } from '@wordpress/i18n';
import * as React from 'react';

import { Button } from '@/components/ui/button';
import {
	DropdownMenu,
	DropdownMenuCheckboxItem,
	DropdownMenuContent,
	DropdownMenuLabel,
	DropdownMenuSeparator,
	DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

declare module '@tanstack/react-table' {
	// eslint-disable-next-line @typescript-eslint/no-unused-vars
	interface ColumnMeta< TData extends RowData, TValue > {
		label?: string;
		align?: 'left' | 'right' | 'center';
		className?: string;
		hideable?: boolean;
	}
}

export type { ColumnDef, SortingState, VisibilityState };

export interface DataTableProps< T > {
	columns: ColumnDef< T, any >[]; // eslint-disable-line @typescript-eslint/no-explicit-any
	data: T[];
	total: number;
	page: number;
	perPage: number;
	onPageChange: ( page: number ) => void;
	sorting?: SortingState;
	onSortingChange?: ( sorting: SortingState ) => void;
	columnVisibility?: VisibilityState;
	onColumnVisibilityChange?: ( visibility: VisibilityState ) => void;
	loading?: boolean;
	/** Data is a placeholder from the previous page while the next loads. */
	fetching?: boolean;
	onRowClick?: ( row: T ) => void;
	getRowId?: ( row: T ) => string;
	/** Rendered instead of rows when there is no data. */
	empty?: React.ReactNode;
	className?: string;
	/** Accessible label for the table. */
	label?: string;
}

const alignClass = ( align?: 'left' | 'right' | 'center' ) =>
	align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left';

export function DataTable< T >( {
	columns,
	data,
	total,
	page,
	perPage,
	onPageChange,
	sorting = [],
	onSortingChange,
	columnVisibility,
	onColumnVisibilityChange,
	loading = false,
	fetching = false,
	onRowClick,
	getRowId,
	empty,
	className,
	label,
}: DataTableProps< T > ) {
	const table = useReactTable( {
		data,
		columns,
		getCoreRowModel: getCoreRowModel(),
		manualPagination: true,
		manualSorting: true,
		enableSortingRemoval: false,
		pageCount: Math.max( 1, Math.ceil( total / perPage ) ),
		state: {
			sorting,
			columnVisibility: columnVisibility ?? {},
			pagination: { pageIndex: page - 1, pageSize: perPage },
		},
		onSortingChange: ( updater ) => {
			const next = typeof updater === 'function' ? updater( sorting ) : updater;
			onSortingChange?.( next );
		},
		onColumnVisibilityChange: ( updater ) => {
			const current = columnVisibility ?? {};
			const next = typeof updater === 'function' ? updater( current ) : updater;
			onColumnVisibilityChange?.( next );
		},
		getRowId: getRowId ? ( row ) => getRowId( row ) : undefined,
	} );

	const pageCount = Math.max( 1, Math.ceil( total / perPage ) );
	const from = total === 0 ? 0 : ( page - 1 ) * perPage + 1;
	const to = Math.min( total, page * perPage );
	const visibleColumns = table.getVisibleLeafColumns().length;

	return (
		<div className={ cn( 'overflow-hidden rounded-lg border border-border bg-surface shadow-card', className ) }>
			<Table aria-label={ label } aria-busy={ loading || fetching }>
				<TableHeader>
					{ table.getHeaderGroups().map( ( group ) => (
						<TableRow key={ group.id } className="hover:bg-transparent">
							{ group.headers.map( ( header ) => {
								const meta = header.column.columnDef.meta;
								const canSort = header.column.getCanSort();
								const sorted = header.column.getIsSorted();
								const content = header.isPlaceholder
									? null
									: flexRender( header.column.columnDef.header, header.getContext() );
								return (
									<TableHead
										key={ header.id }
										className={ cn( alignClass( meta?.align ), meta?.className ) }
										aria-sort={ sorted === 'asc' ? 'ascending' : sorted === 'desc' ? 'descending' : undefined }
									>
										{ canSort ? (
											<button
												type="button"
												onClick={ header.column.getToggleSortingHandler() }
												className={ cn(
													'-mx-1 inline-flex items-center gap-1 rounded px-1 py-0.5 hover:text-ink-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
													sorted && 'text-ink-soft',
													meta?.align === 'right' && 'flex-row-reverse'
												) }
											>
												{ content }
												{ sorted === 'asc' && <ArrowUp className="h-3 w-3" /> }
												{ sorted === 'desc' && <ArrowDown className="h-3 w-3" /> }
											</button>
										) : (
											content
										) }
									</TableHead>
								);
							} ) }
						</TableRow>
					) ) }
				</TableHeader>
				<TableBody className={ cn( fetching && ! loading && 'opacity-60 transition-opacity' ) }>
					{ loading &&
						Array.from( { length: Math.min( perPage, 8 ) } ).map( ( _, rowIndex ) => (
							<TableRow key={ `skeleton-${ rowIndex }` }>
								{ table.getVisibleLeafColumns().map( ( column ) => (
									<TableCell key={ column.id }>
										<Skeleton className="h-4 w-full max-w-[140px]" />
									</TableCell>
								) ) }
							</TableRow>
						) ) }
					{ ! loading && data.length === 0 && (
						<TableRow className="hover:bg-transparent">
							<TableCell colSpan={ visibleColumns } className="p-0">
								{ empty ?? (
									<p className="py-12 text-center text-sm text-muted-foreground">
										{ __( 'No results.', 'marclie-booking' ) }
									</p>
								) }
							</TableCell>
						</TableRow>
					) }
					{ ! loading &&
						table.getRowModel().rows.map( ( row ) => (
							<TableRow
								key={ row.id }
								className={ cn( onRowClick && 'cursor-pointer hover:bg-surface-muted/60' ) }
								onClick={ onRowClick ? () => onRowClick( row.original ) : undefined }
								onKeyDown={
									onRowClick
										? ( event ) => {
												if ( event.key === 'Enter' && event.target === event.currentTarget ) {
													onRowClick( row.original );
												}
										  }
										: undefined
								}
								tabIndex={ onRowClick ? 0 : undefined }
							>
								{ row.getVisibleCells().map( ( cell ) => {
									const meta = cell.column.columnDef.meta;
									return (
										<TableCell key={ cell.id } className={ cn( alignClass( meta?.align ), meta?.className ) }>
											{ flexRender( cell.column.columnDef.cell, cell.getContext() ) }
										</TableCell>
									);
								} ) }
							</TableRow>
						) ) }
				</TableBody>
			</Table>
			{ total > 0 && (
				<div className="flex flex-wrap items-center justify-between gap-2 border-t border-border px-4 py-2.5 text-[13px] text-muted-foreground">
					<span className="tabular">
						{ sprintf(
							/* translators: 1: first row, 2: last row, 3: total rows. */
							__( '%1$s–%2$s of %3$s', 'marclie-booking' ),
							formatNumber( from ),
							formatNumber( to ),
							formatNumber( total )
						) }
					</span>
					<div className="flex items-center gap-1">
						<span className="mr-2 tabular">
							{ sprintf(
								/* translators: 1: current page, 2: page count. */
								__( 'Page %1$d of %2$d', 'marclie-booking' ),
								page,
								pageCount
							) }
						</span>
						<Button
							variant="outline"
							size="icon-sm"
							onClick={ () => onPageChange( page - 1 ) }
							disabled={ page <= 1 }
							aria-label={ __( 'Previous page', 'marclie-booking' ) }
						>
							<CaretLeft className="h-4 w-4" />
						</Button>
						<Button
							variant="outline"
							size="icon-sm"
							onClick={ () => onPageChange( page + 1 ) }
							disabled={ page >= pageCount }
							aria-label={ __( 'Next page', 'marclie-booking' ) }
						>
							<CaretRight className="h-4 w-4" />
						</Button>
					</div>
				</div>
			) }
		</div>
	);
}

/** "Columns" dropdown toggling `meta.hideable` columns. */
export function ColumnVisibilityMenu< T >( {
	columns,
	value,
	onChange,
}: {
	columns: ColumnDef< T, any >[]; // eslint-disable-line @typescript-eslint/no-explicit-any
	value: VisibilityState;
	onChange: ( value: VisibilityState ) => void;
} ) {
	const hideable = columns.filter( ( column ) => column.meta?.hideable && column.id );
	return (
		<DropdownMenu>
			<DropdownMenuTrigger asChild>
				<Button variant="outline">
					<Columns className="h-4 w-4" />
					{ __( 'Columns', 'marclie-booking' ) }
				</Button>
			</DropdownMenuTrigger>
			<DropdownMenuContent align="end" className="w-48">
				<DropdownMenuLabel>{ __( 'Visible columns', 'marclie-booking' ) }</DropdownMenuLabel>
				<DropdownMenuSeparator />
				{ hideable.map( ( column ) => {
					const id = column.id as string;
					return (
						<DropdownMenuCheckboxItem
							key={ id }
							checked={ value[ id ] !== false }
							onSelect={ ( event ) => event.preventDefault() }
							onCheckedChange={ ( checked ) => onChange( { ...value, [ id ]: !! checked } ) }
						>
							{ column.meta?.label ?? id }
						</DropdownMenuCheckboxItem>
					);
				} ) }
			</DropdownMenuContent>
		</DropdownMenu>
	);
}
