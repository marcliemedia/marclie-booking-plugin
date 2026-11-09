import type { Icon } from '@phosphor-icons/react';
import * as React from 'react';

import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

export function KpiCard( {
	label,
	value,
	hint,
	icon: IconComponent,
	loading = false,
	className,
	onClick,
}: {
	label: React.ReactNode;
	value: React.ReactNode;
	hint?: React.ReactNode;
	icon?: Icon;
	loading?: boolean;
	className?: string;
	onClick?: () => void;
} ) {
	const content = (
		<>
			<div className="flex items-center justify-between gap-2">
				<span className="text-[13px] font-medium text-muted-foreground">{ label }</span>
				{ IconComponent && <IconComponent className="h-4 w-4 text-muted-foreground" /> }
			</div>
			{ loading ? (
				<Skeleton className="mt-3 h-7 w-24" />
			) : (
				<div className="mt-2 text-2xl font-semibold tracking-tight text-ink tabular">{ value }</div>
			) }
			{ hint && ! loading && <div className="mt-1 text-xs text-muted-foreground">{ hint }</div> }
		</>
	);
	if ( onClick ) {
		return (
			<Card className={ cn( 'p-0', className ) }>
				<button
					type="button"
					onClick={ onClick }
					className="block w-full rounded-lg px-5 py-4 text-left transition-colors hover:bg-surface-muted/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
				>
					{ content }
				</button>
			</Card>
		);
	}
	return <Card className={ cn( 'px-5 py-4', className ) }>{ content }</Card>;
}
