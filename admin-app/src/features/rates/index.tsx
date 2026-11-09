import { Info, LockKey } from '@phosphor-icons/react';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { useBootstrapData } from '@/api/hooks/bootstrap';
import { usePrices, useSavePrices } from '@/api/hooks/prices';
import type { UnitPrices } from '@/api/types';
import { useNavigationGuard } from '@/app/router';
import { EmptyState } from '@/components/app/EmptyState';
import { ErrorState } from '@/components/app/ErrorState';
import { PageHeader } from '@/components/app/PageHeader';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Skeleton } from '@/components/ui/skeleton';
import { toast } from '@/components/ui/sonner';
import { toastError } from '@/features/common/toastError';

import { PriceGrid } from './PriceGrid';

export default function PricesPage( { params }: { params: Record< string, string > } ) {
	void params;
	const { caps, units } = useBootstrapData();
	const prices = usePrices();
	const savePrices = useSavePrices();
	const [ gridDirty, setGridDirty ] = useState( false );
	useNavigationGuard( gridDirty, __( 'You have unsaved price changes. Leave and discard them?', 'marclie-booking' ) );

	if ( ! caps.manage_rates ) {
		return (
			<>
				<PageHeader title={ __( 'Prices', 'marclie-booking' ) } />
				<EmptyState
					icon={ LockKey }
					title={ __( 'No access', 'marclie-booking' ) }
					description={ __( 'Your role cannot change rates.', 'marclie-booking' ) }
				/>
			</>
		);
	}

	const onSave = async ( changes: UnitPrices[] ) => {
		try {
			await savePrices.mutateAsync( changes );
			toast.success(
				sprintf(
					/* translators: %d: number of apartments. */
					_n( 'Prices of %d apartment saved.', 'Prices of %d apartments saved.', changes.length, 'marclie-booking' ),
					changes.length
				)
			);
		} catch ( e ) {
			toastError( e );
			throw e;
		}
	};

	return (
		<>
			<PageHeader
				title={ __( 'Prices', 'marclie-booking' ) }
				description={ __( 'Price per person per night for every apartment and month. Every change is recorded in the activity log.', 'marclie-booking' ) }
			/>

			<div className="flex flex-col gap-5">
				<Alert variant="default" role="note">
					<Info aria-hidden="true" />
					<AlertDescription>
						<ul className="m-0 list-disc space-y-1 pl-4">
							<li>
								{ __(
									'Prices are per person per night. The apartment is charged for the guests staying, but at least for its minimum paying guests (column “Guests”).',
									'marclie-booking'
								) }
							</li>
							<li>{ __( 'Children aged 5–10 pay half the person price; children aged 0–4 are free and do not count toward the minimum.', 'marclie-booking' ) }</li>
							<li>
								{ __(
									'An empty month is closed: it cannot be booked (no arrival and no night of a stay in that month).',
									'marclie-booking'
								) }
							</li>
							<li>
								{ __(
									'The table applies to every year. To close single days (e.g. before the season) block the dates in the calendar.',
									'marclie-booking'
								) }
							</li>
						</ul>
					</AlertDescription>
				</Alert>

				{ prices.isPending && <Skeleton className="h-96 w-full" /> }
				{ prices.isError && <ErrorState error={ prices.error } onRetry={ () => prices.refetch() } /> }
				{ prices.data && (
					<PriceGrid
						units={ units }
						prices={ prices.data.units }
						saving={ savePrices.isPending }
						onSave={ onSave }
						onDirtyChange={ setGridDirty }
					/>
				) }
			</div>
		</>
	);
}
