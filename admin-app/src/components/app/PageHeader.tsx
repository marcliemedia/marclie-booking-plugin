import * as React from 'react';

import { cn } from '@/lib/utils';

export function PageHeader( {
	title,
	description,
	actions,
	children,
	className,
}: {
	title: React.ReactNode;
	description?: React.ReactNode;
	actions?: React.ReactNode;
	/** Rendered under the title row (tabs, filters). */
	children?: React.ReactNode;
	className?: string;
} ) {
	return (
		<div className={ cn( 'mb-5 flex flex-col gap-4', className ) }>
			<div className="flex flex-wrap items-end justify-between gap-3">
				<div className="min-w-0">
					<h1 className="text-[22px] font-semibold leading-tight tracking-tight text-ink">{ title }</h1>
					{ description && <p className="mt-1 text-sm text-muted-foreground">{ description }</p> }
				</div>
				{ actions && <div className="flex flex-wrap items-center gap-2">{ actions }</div> }
			</div>
			{ children }
		</div>
	);
}

/** Definition list row used in detail panes. */

export function DetailRow( { label, children }: { label: React.ReactNode; children: React.ReactNode } ) {
	return (
		<div className="flex items-start justify-between gap-4 py-2 text-sm">
			<dt className="shrink-0 text-muted-foreground">{ label }</dt>
			<dd className="min-w-0 text-right text-ink-soft">{ children }</dd>
		</div>
	);
}
