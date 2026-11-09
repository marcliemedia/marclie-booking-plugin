import { CircleNotch, Plus, User } from '@phosphor-icons/react';
import { __ } from '@wordpress/i18n';
import * as React from 'react';

import { allowedNavItems } from '@/app/nav';
import { navigate } from '@/app/router';
import { useBootstrapData } from '@/api/hooks/bootstrap';
import { useSearch } from '@/api/hooks/dashboard';
import { StatusBadge } from '@/components/app/StatusBadge';
import {
	CommandDialog,
	CommandEmpty,
	CommandGroup,
	CommandInput,
	CommandItem,
	CommandList,
	CommandSeparator,
} from '@/components/ui/command';
import { useBookingSheet } from '@/features/bookings/sheet';
import { useBookingWizard } from '@/features/bookings/wizard';
import { formatDate } from '@/lib/format';
import { createStore, useStore } from '@/lib/store';
import { useDebounced } from '@/lib/useDebounced';

export const paletteStore = createStore( false );

export function CommandPalette() {
	const open = useStore( paletteStore );
	const { caps } = useBootstrapData();
	const [ query, setQuery ] = React.useState( '' );
	const term = useDebounced( query, 200 );
	const search = useSearch( term );
	const sheet = useBookingSheet();
	const wizard = useBookingWizard();

	React.useEffect( () => {
		const onKeyDown = ( event: KeyboardEvent ) => {
			if ( ( event.metaKey || event.ctrlKey ) && event.key.toLowerCase() === 'k' ) {
				// Capture phase + stop: wp-admin's own command palette also binds ⌘K/Ctrl+K.
				event.preventDefault();
				event.stopImmediatePropagation();
				paletteStore.set( ( prev ) => ! prev );
			}
		};
		window.addEventListener( 'keydown', onKeyDown, true );
		return () => window.removeEventListener( 'keydown', onKeyDown, true );
	}, [] );

	const setOpen = ( next: boolean ) => {
		paletteStore.set( next );
		if ( ! next ) {
			setQuery( '' );
		}
	};

	const run = ( action: () => void ) => {
		setOpen( false );
		action();
	};

	const hasTerm = term.trim().length >= 2;
	const bookings = hasTerm ? search.data?.bookings ?? [] : [];
	const guests = hasTerm ? search.data?.guests ?? [] : [];
	const loweredQuery = query.trim().toLowerCase();
	const navigation = allowedNavItems( caps ).filter(
		( item ) => loweredQuery === '' || item.label.toLowerCase().includes( loweredQuery )
	);
	const showNewBooking =
		caps.manage_bookings && ( loweredQuery === '' || __( 'New booking', 'marclie-booking' ).toLowerCase().includes( loweredQuery ) );

	return (
		<CommandDialog
			open={ open }
			onOpenChange={ setOpen }
			shouldFilter={ false }
			title={ __( 'Search', 'marclie-booking' ) }
			description={ __( 'Search bookings and guests, or jump to a section.', 'marclie-booking' ) }
		>
			<CommandInput
				value={ query }
				onValueChange={ setQuery }
				placeholder={ __( 'Search booking ref, guest, email…', 'marclie-booking' ) }
			/>
			<CommandList>
				{ hasTerm && search.isFetching && bookings.length === 0 && guests.length === 0 ? (
					<div className="flex items-center justify-center gap-2 py-8 text-sm text-muted-foreground">
						<CircleNotch className="h-4 w-4 animate-spin" />
						{ __( 'Searching…', 'marclie-booking' ) }
					</div>
				) : (
					<CommandEmpty>{ __( 'No results found.', 'marclie-booking' ) }</CommandEmpty>
				) }
				{ bookings.length > 0 && (
					<CommandGroup heading={ __( 'Bookings', 'marclie-booking' ) }>
						{ bookings.map( ( booking ) => (
							<CommandItem
								key={ `b-${ booking.id }` }
								value={ `booking-${ booking.id }` }
								onSelect={ () => run( () => sheet.open( booking.id ) ) }
							>
								<span className="font-mono text-xs text-muted-foreground">{ booking.ref }</span>
								<span className="min-w-0 flex-1 truncate font-medium">{ booking.guest_name }</span>
								<span className="hidden text-xs text-muted-foreground sm:inline">
									{ booking.unit_title } · { formatDate( booking.arrival, 'day' ) }
								</span>
								<StatusBadge status={ booking.status } />
							</CommandItem>
						) ) }
					</CommandGroup>
				) }
				{ guests.length > 0 && (
					<CommandGroup heading={ __( 'Guests', 'marclie-booking' ) }>
						{ guests.map( ( guest ) => (
							<CommandItem
								key={ `g-${ guest.id }` }
								value={ `guest-${ guest.id }` }
								onSelect={ () => run( () => navigate( `/guests/${ guest.id }` ) ) }
							>
								<User />
								<span className="font-medium">{ guest.name }</span>
								<span className="truncate text-xs text-muted-foreground">{ guest.email }</span>
							</CommandItem>
						) ) }
					</CommandGroup>
				) }
				{ ( bookings.length > 0 || guests.length > 0 ) && ( navigation.length > 0 || showNewBooking ) && <CommandSeparator /> }
				{ showNewBooking && (
					<CommandGroup heading={ __( 'Actions', 'marclie-booking' ) }>
						<CommandItem value="action-new-booking" onSelect={ () => run( () => wizard.open() ) }>
							<Plus />
							{ __( 'New booking', 'marclie-booking' ) }
						</CommandItem>
					</CommandGroup>
				) }
				{ navigation.length > 0 && (
					<CommandGroup heading={ __( 'Go to', 'marclie-booking' ) }>
						{ navigation.map( ( item ) => {
							const IconComponent = item.icon;
							return (
								<CommandItem key={ item.to } value={ `nav-${ item.to }` } onSelect={ () => run( () => navigate( item.to ) ) }>
									<IconComponent />
									{ item.label }
								</CommandItem>
							);
						} ) }
					</CommandGroup>
				) }
			</CommandList>
		</CommandDialog>
	);
}
