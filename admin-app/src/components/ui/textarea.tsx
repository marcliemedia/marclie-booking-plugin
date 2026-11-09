import * as React from 'react';

import { cn } from '@/lib/utils';

export const Textarea = React.forwardRef< HTMLTextAreaElement, React.TextareaHTMLAttributes< HTMLTextAreaElement > >(
	( { className, ...props }, ref ) => (
		<textarea
			className={ cn(
				'flex min-h-[80px] w-full rounded-md border border-input bg-surface px-3 py-2 text-sm text-ink-soft placeholder:text-muted-foreground focus-visible:border-ink-soft/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/25 disabled:cursor-not-allowed disabled:opacity-50',
				className
			) }
			ref={ ref }
			{ ...props }
		/>
	)
);
Textarea.displayName = 'Textarea';
