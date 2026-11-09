import { ClockCounterClockwise } from '@phosphor-icons/react';
import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { useActivity } from '@/api/hooks/activity';
import type { EventItem } from '@/api/types';
import type { PageProps } from '@/app/routes';
import { setQuery, useRoute } from '@/app/router';
import type { ColumnDef } from '@/components/app/DataTable';
import { DataTable } from '@/components/app/DataTable';
import { EmptyState } from '@/components/app/EmptyState';
import { ErrorState } from '@/components/app/ErrorState';
import { PageHeader } from '@/components/app/PageHeader';
import { formatDateTime } from '@/lib/format';

const PER_PAGE = 25;

export default function ActivityPage( _props: PageProps ) {
	const { query } = useRoute();
	const page = Math.max( 1, Number( query.get( 'page' ) ) || 1 );
	const activity = useActivity( page, PER_PAGE );

	const columns = useMemo< ColumnDef< EventItem >[] >(
		() => [
			{
				id: 'created_at',
				header: __( 'Date', 'marclie-booking' ),
				meta: { label: __( 'Date', 'marclie-booking' ), className: 'whitespace-nowrap' },
				cell: ( { row } ) => formatDateTime( row.original.created_at ),
			},
			{
				id: 'booking',
				header: __( 'Booking', 'marclie-booking' ),
				meta: { label: __( 'Booking', 'marclie-booking' ), className: 'whitespace-nowrap' },
				cell: ( { row } ) =>
					row.original.booking_ref ? <span className="font-mono text-xs font-medium text-ink">{ row.original.booking_ref }</span> : null,
			},
			{
				id: 'summary',
				header: __( 'Event', 'marclie-booking' ),
				meta: { label: __( 'Event', 'marclie-booking' ), className: 'w-full' },
				cell: ( { row } ) => row.original.summary,
			},
			{
				id: 'actor',
				header: __( 'By', 'marclie-booking' ),
				meta: { label: __( 'By', 'marclie-booking' ), className: 'whitespace-nowrap text-muted-foreground' },
				cell: ( { row } ) => row.original.actor,
			},
		],
		[]
	);

	return (
		<>
			<PageHeader
				title={ __( 'Activity', 'marclie-booking' ) }
				description={ __( 'Every change to bookings, guests, payments, prices and settings, newest first.', 'marclie-booking' ) }
			/>
			{ activity.isError ? (
				<ErrorState error={ activity.error } onRetry={ () => activity.refetch() } />
			) : (
				<DataTable
					label={ __( 'Activity', 'marclie-booking' ) }
					columns={ columns }
					data={ activity.data?.items ?? [] }
					total={ activity.data?.total ?? 0 }
					page={ page }
					perPage={ PER_PAGE }
					onPageChange={ ( p ) => setQuery( { page: p > 1 ? p : null } ) }
					loading={ activity.isPending }
					fetching={ activity.isPlaceholderData }
					getRowId={ ( e ) => String( e.id ) }
					empty={ <EmptyState compact icon={ ClockCounterClockwise } title={ __( 'No activity yet', 'marclie-booking' ) } /> }
				/>
			) }
		</>
	);
}
