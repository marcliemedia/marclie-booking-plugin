import * as React from 'react';

import { cn } from '@/lib/utils';

export const Input = React.forwardRef< HTMLInputElement, React.InputHTMLAttributes< HTMLInputElement > >(
	( { className, type = 'text', ...props }, ref ) => (
		<input
			type={ type }
			className={ cn(
				'flex h-9 w-full rounded-md border border-input bg-surface px-3 py-1.5 text-sm text-ink-soft shadow-none transition-colors placeholder:text-muted-foreground focus-visible:border-ink-soft/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/25 disabled:cursor-not-allowed disabled:opacity-50 aria-[invalid=true]:border-danger',
				className
			) }
			ref={ ref }
			{ ...props }
		/>
	)
);
Input.displayName = 'Input';
