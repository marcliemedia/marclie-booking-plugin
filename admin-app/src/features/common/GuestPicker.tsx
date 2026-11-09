import { MagnifyingGlass, X } from '@phosphor-icons/react';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { useGuests } from '@/api/hooks/guests';
import type { Guest } from '@/api/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

export interface PickedGuest {
	id: number;
	name: string;
	email: string;
}

export function guestName( g: Pick< Guest, 'first_name' | 'last_name' | 'email' > ): string {
	return `${ g.first_name } ${ g.last_name }`.trim() || g.email;
}

/** Debounced value (search inputs). */
export function useDebounced< T >( value: T, ms = 300 ): T {
	const [ debounced, setDebounced ] = useState( value );
	useEffect( () => {
		const t = window.setTimeout( () => setDebounced( value ), ms );
		return () => window.clearTimeout( t );
	}, [ value, ms ] );
	return debounced;
}

/** Inline guest search (name / e-mail) with a result list; no nested popover so it works inside dialogs. */
export function GuestPicker( {
	id,
	value,
	onChange,
	excludeId,
	placeholder = __( 'Search by name or e-mail…', 'marclie-booking' ),
}: {
	id: string;
	value: PickedGuest | null;
	onChange: ( guest: PickedGuest | null ) => void;
	excludeId?: number;
	placeholder?: string;
} ) {
	const [ search, setSearch ] = useState( '' );
	const term = useDebounced( search.trim() );
	const results = useGuests( { search: term, per_page: 8, orderby: 'name', order: 'asc' }, term.length >= 2 );
	const items = ( results.data?.items ?? [] ).filter( ( g ) => g.id !== excludeId && ! g.anonymized );

	if ( value ) {
		return (
			<div className="flex items-center justify-between gap-3 rounded-md border border-border bg-surface-muted px-3 py-2">
				<div className="min-w-0 text-sm">
					<p className="truncate font-medium">{ value.name }</p>
					<p className="truncate text-xs text-muted-foreground">{ value.email }</p>
				</div>
				<Button variant="ghost" size="icon-sm" onClick={ () => onChange( null ) } aria-label={ __( 'Clear selected guest', 'marclie-booking' ) }>
					<X className="h-4 w-4" />
				</Button>
			</div>
		);
	}

	return (
		<div className="flex flex-col gap-1.5">
			<div className="relative">
				<MagnifyingGlass className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
				<Input
					id={ id }
					type="search"
					value={ search }
					onChange={ ( e ) => setSearch( e.target.value ) }
					placeholder={ placeholder }
					className="pl-8"
					autoComplete="off"
					aria-controls={ `${ id }-results` }
				/>
			</div>
			{ term.length >= 2 && (
				<ul id={ `${ id }-results` } className="max-h-52 overflow-y-auto rounded-md border border-border" aria-live="polite">
					{ results.isFetching && items.length === 0 && (
						<li className="px-3 py-2 text-sm text-muted-foreground">{ __( 'Searching…', 'marclie-booking' ) }</li>
					) }
					{ ! results.isFetching && items.length === 0 && (
						<li className="px-3 py-2 text-sm text-muted-foreground">{ __( 'No guests found.', 'marclie-booking' ) }</li>
					) }
					{ items.map( ( g ) => (
						<li key={ g.id }>
							<button
								type="button"
								className={ cn(
									'flex w-full flex-col items-start px-3 py-2 text-left text-sm hover:bg-surface-muted focus-visible:bg-surface-muted focus-visible:outline-none'
								) }
								onClick={ () => {
									onChange( { id: g.id, name: guestName( g ), email: g.email } );
									setSearch( '' );
								} }
							>
								<span className="font-medium">{ guestName( g ) }</span>
								<span className="text-xs text-muted-foreground">
									{ g.email }
									{ g.country ? ` · ${ g.country }` : '' }
								</span>
							</button>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}
