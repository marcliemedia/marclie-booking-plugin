import type { Icon } from '@phosphor-icons/react';
import * as React from 'react';

import { cn } from '@/lib/utils';

export function EmptyState( {
	icon: IconComponent,
	title,
	description,
	action,
	className,
	compact = false,
}: {
	icon?: Icon;
	title: React.ReactNode;
	description?: React.ReactNode;
	action?: React.ReactNode;
	className?: string;
	compact?: boolean;
} ) {
	return (
		<div
			className={ cn(
				'flex flex-col items-center justify-center gap-2 text-center',
				compact ? 'px-4 py-8' : 'px-6 py-16',
				className
			) }
		>
			{ IconComponent && (
				<div className="mb-1 flex h-11 w-11 items-center justify-center rounded-full bg-surface-muted text-muted-foreground">
					<IconComponent className="h-5 w-5" />
				</div>
			) }
			<p className="text-sm font-semibold text-ink">{ title }</p>
			{ description && <p className="max-w-sm text-sm text-muted-foreground">{ description }</p> }
			{ action && <div className="mt-2">{ action }</div> }
		</div>
	);
}
