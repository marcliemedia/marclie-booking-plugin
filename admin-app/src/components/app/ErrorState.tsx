import { ArrowClockwise, Warning } from '@phosphor-icons/react';
import { __ } from '@wordpress/i18n';

import { errorMessage } from '@/api/client';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export function ErrorState( {
	error,
	onRetry,
	className,
	title,
}: {
	error: unknown;
	onRetry?: () => void;
	className?: string;
	title?: string;
} ) {
	return (
		<div role="alert" className={ cn( 'flex flex-col items-center justify-center gap-2 px-6 py-12 text-center', className ) }>
			<div className="mb-1 flex h-11 w-11 items-center justify-center rounded-full bg-danger-soft text-danger">
				<Warning className="h-5 w-5" />
			</div>
			<p className="text-sm font-semibold text-ink">{ title ?? __( 'Something went wrong', 'marclie-booking' ) }</p>
			<p className="max-w-md text-sm text-muted-foreground">{ errorMessage( error ) }</p>
			{ onRetry && (
				<Button variant="secondary" size="sm" className="mt-2" onClick={ onRetry }>
					<ArrowClockwise className="h-4 w-4" />
					{ __( 'Try again', 'marclie-booking' ) }
				</Button>
			) }
		</div>
	);
}
