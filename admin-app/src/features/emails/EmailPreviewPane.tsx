import { CaretUpDown, CircleNotch, Desktop, DeviceMobile, Flask, Receipt } from '@phosphor-icons/react';
import { __, sprintf } from '@wordpress/i18n';
import * as React from 'react';

import { useSearch } from '@/api/hooks/dashboard';
import { useEmailPreview } from '@/api/hooks/emails';
import type { EmailContent, EmailLang } from '@/api/types';
import { ErrorState } from '@/components/app/ErrorState';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { formatDate } from '@/lib/format';
import { useDebounced } from '@/lib/useDebounced';
import { cn } from '@/lib/utils';

export interface PreviewBooking {
	id: number;
	ref: string;
	guest_name: string;
}

/** Sample data (null) or a real booking for the preview and test e-mails. */
function BookingPicker( { value, onChange }: { value: PreviewBooking | null; onChange: ( booking: PreviewBooking | null ) => void } ) {
	const [ open, setOpen ] = React.useState( false );
	const [ q, setQ ] = React.useState( '' );
	const search = useSearch( useDebounced( q, 250 ) );
	const bookings = q.trim().length >= 2 ? search.data?.bookings ?? [] : [];

	const choose = ( booking: PreviewBooking | null ) => {
		onChange( booking );
		setOpen( false );
		setQ( '' );
	};

	return (
		<Popover open={ open } onOpenChange={ setOpen }>
			<PopoverTrigger asChild>
				<Button
					size="sm"
					variant="outline"
					className="min-w-0 max-w-full justify-between"
					aria-label={ __( 'Preview data', 'marclie-booking' ) }
				>
					{ value ? <Receipt className="h-4 w-4" aria-hidden="true" /> : <Flask className="h-4 w-4" aria-hidden="true" /> }
					<span className="truncate">{ value ? `${ value.ref } · ${ value.guest_name }` : __( 'Sample data', 'marclie-booking' ) }</span>
					<CaretUpDown className="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />
				</Button>
			</PopoverTrigger>
			<PopoverContent align="end" className="w-80 p-0">
				<Command shouldFilter={ false } className="[&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:py-1.5 [&_[cmdk-group-heading]]:text-xs [&_[cmdk-group-heading]]:font-medium [&_[cmdk-group-heading]]:text-muted-foreground [&_[cmdk-group]]:px-1.5">
					<CommandInput
						value={ q }
						onValueChange={ setQ }
						placeholder={ __( 'Search booking ref or guest…', 'marclie-booking' ) }
						className="h-10"
					/>
					<CommandList className="max-h-72 py-1">
						<CommandGroup>
							<CommandItem value="__sample" onSelect={ () => choose( null ) }>
								<Flask aria-hidden="true" />
								{ __( 'Sample data', 'marclie-booking' ) }
							</CommandItem>
						</CommandGroup>
						{ q.trim().length >= 2 && (
							<CommandGroup heading={ __( 'Bookings', 'marclie-booking' ) }>
								{ search.isFetching && bookings.length === 0 && (
									<div className="flex justify-center py-3 text-muted-foreground">
										<CircleNotch className="h-4 w-4 animate-spin" aria-label={ __( 'Loading…', 'marclie-booking' ) } />
									</div>
								) }
								{ bookings.map( ( booking ) => (
									<CommandItem
										key={ booking.id }
										value={ String( booking.id ) }
										onSelect={ () => choose( { id: booking.id, ref: booking.ref, guest_name: booking.guest_name } ) }
									>
										<Receipt aria-hidden="true" />
										<span className="flex min-w-0 flex-1 flex-col">
											<span className="truncate">{ booking.guest_name }</span>
											<span className="truncate text-xs text-muted-foreground">
												{ booking.ref } · { booking.unit_title } · { formatDate( booking.arrival, 'medium' ) }
											</span>
										</span>
									</CommandItem>
								) ) }
							</CommandGroup>
						) }
						{ q.trim().length >= 2 && ! search.isFetching && bookings.length === 0 && (
							<CommandEmpty>{ __( 'No bookings found.', 'marclie-booking' ) }</CommandEmpty>
						) }
					</CommandList>
				</Command>
			</PopoverContent>
		</Popover>
	);
}

/**
 * Live preview of the draft: the server renders the full e-mail (layout, merge tags,
 * blocks) and the HTML is shown in a sandboxed iframe (no scripts, no same-origin).
 */
export function EmailPreviewPane( {
	code,
	lang,
	draft,
	booking,
	onBookingChange,
}: {
	code: string;
	lang: EmailLang;
	draft: EmailContent;
	booking: PreviewBooking | null;
	onBookingChange: ( booking: PreviewBooking | null ) => void;
} ) {
	const [ device, setDevice ] = React.useState< 'desktop' | 'mobile' >( 'desktop' );
	const [ view, setView ] = React.useState< 'html' | 'text' >( 'html' );
	const input = React.useMemo( () => ( { lang, draft, ...( booking ? { booking_id: booking.id } : {} ) } ), [ lang, draft, booking ] );
	const debounced = useDebounced( input, 400 );
	const preview = useEmailPreview( code, debounced );
	const data = preview.data;

	return (
		<Card className="flex min-w-0 flex-col overflow-hidden">
			<div className="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-3">
				<h2 className="flex items-center gap-2 text-sm font-semibold text-ink">
					{ __( 'Preview', 'marclie-booking' ) }
					{ preview.isFetching && <CircleNotch className="h-3.5 w-3.5 animate-spin text-muted-foreground" aria-label={ __( 'Updating preview…', 'marclie-booking' ) } /> }
				</h2>
				<div className="flex min-w-0 flex-wrap items-center gap-2">
					<BookingPicker value={ booking } onChange={ onBookingChange } />
					<div role="group" aria-label={ __( 'Preview width', 'marclie-booking' ) } className="inline-flex rounded-md border border-border p-0.5">
						{ ( [ 'desktop', 'mobile' ] as const ).map( ( key ) => {
							const IconComponent = key === 'desktop' ? Desktop : DeviceMobile;
							const label = key === 'desktop' ? __( 'Desktop', 'marclie-booking' ) : __( 'Mobile', 'marclie-booking' );
							return (
								<button
									key={ key }
									type="button"
									aria-pressed={ device === key }
									aria-label={ label }
									title={ label }
									onClick={ () => setDevice( key ) }
									className={ cn(
										'inline-flex h-6 w-7 items-center justify-center rounded text-muted-foreground transition-colors hover:text-ink-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
										device === key && 'bg-surface-muted text-ink-soft'
									) }
								>
									<IconComponent className="h-4 w-4" aria-hidden="true" />
								</button>
							);
						} ) }
					</div>
				</div>
			</div>
			<div className="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-2">
				<p className="min-w-0 truncate text-sm text-ink-soft">
					<span className="text-muted-foreground">{ __( 'Subject:', 'marclie-booking' ) }</span>{ ' ' }
					<span className="font-medium">{ data?.subject ?? '…' }</span>
				</p>
				{ /* Toggle group, not tabs: both views share one preview area (no tabpanel to point at). */ }
				<div role="group" aria-label={ __( 'Preview format', 'marclie-booking' ) } className="inline-flex rounded-md border border-border p-0.5">
					{ ( [ [ 'html', __( 'HTML', 'marclie-booking' ) ], [ 'text', __( 'Plain text', 'marclie-booking' ) ] ] as const ).map( ( [ key, label ] ) => (
						<button
							key={ key }
							type="button"
							aria-pressed={ view === key }
							onClick={ () => setView( key ) }
							className={ cn(
								'inline-flex h-7 items-center justify-center rounded px-2.5 text-sm text-muted-foreground transition-colors hover:text-ink-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
								view === key && 'bg-surface-muted font-medium text-ink-soft'
							) }
						>
							{ label }
						</button>
					) ) }
				</div>
			</div>
			<div className="flex justify-center bg-surface-muted p-3">
				{ preview.isError && ! data ? (
					<ErrorState error={ preview.error } onRetry={ () => preview.refetch() } className="py-10" />
				) : view === 'html' ? (
					<iframe
						title={ sprintf(
							/* translators: %s: language code (HR/EN). */
							__( 'E-mail preview (%s)', 'marclie-booking' ),
							lang.toUpperCase()
						) }
						sandbox=""
						srcDoc={ data?.html ?? '' }
						className={ cn(
							'h-[640px] max-h-[75vh] rounded-md border border-border bg-white transition-[width] duration-200',
							device === 'desktop' ? 'w-full' : 'w-[375px] max-w-full'
						) }
					/>
				) : (
					<pre className="h-[640px] max-h-[75vh] w-full overflow-auto whitespace-pre-wrap rounded-md border border-border bg-white p-4 font-mono text-xs leading-relaxed text-ink-soft">
						{ data?.text ?? '' }
					</pre>
				) }
			</div>
			{ preview.isError && data && (
				<p className="border-t border-border px-4 py-2 text-xs text-danger" role="alert">
					{ __( 'The preview could not be updated. Showing the last version.', 'marclie-booking' ) }
				</p>
			) }
		</Card>
	);
}
