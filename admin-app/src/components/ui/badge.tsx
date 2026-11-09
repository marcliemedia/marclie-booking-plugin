import { cva, type VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

export const badgeVariants = cva(
	'inline-flex items-center gap-1 whitespace-nowrap rounded-full border px-2 py-0.5 text-xs font-medium leading-4',
	{
		variants: {
			variant: {
				default: 'border-transparent bg-surface-muted text-ink-soft',
				outline: 'border-border bg-surface text-ink-soft',
				success: 'border-transparent bg-success-soft text-success',
				'success-strong': 'border-transparent bg-success text-white',
				warning: 'border-transparent bg-warning-soft text-warning',
				danger: 'border-transparent bg-danger-soft text-danger',
				muted: 'border-transparent bg-surface-muted text-muted-foreground',
				ink: 'border-transparent bg-ink-soft text-white',
				accent: 'border-transparent bg-accent-soft text-accent',
			},
		},
		defaultVariants: {
			variant: 'default',
		},
	}
);

export interface BadgeProps extends React.HTMLAttributes< HTMLSpanElement >, VariantProps< typeof badgeVariants > {}

export function Badge( { className, variant, ...props }: BadgeProps ) {
	return <span className={ cn( badgeVariants( { variant } ), className ) } { ...props } />;
}
