import { cva, type VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

const alertVariants = cva(
	'relative flex w-full gap-3 rounded-lg border px-4 py-3 text-sm [&>svg]:mt-0.5 [&>svg]:h-4 [&>svg]:w-4 [&>svg]:shrink-0',
	{
		variants: {
			variant: {
				default: 'border-border bg-surface text-ink-soft',
				info: 'border-border bg-surface-muted text-ink-soft',
				success: 'border-success/20 bg-success-soft text-success',
				warning: 'border-warning/20 bg-warning-soft text-warning',
				danger: 'border-danger/20 bg-danger-soft text-danger',
			},
		},
		defaultVariants: {
			variant: 'default',
		},
	}
);

export const Alert = React.forwardRef<
	HTMLDivElement,
	React.HTMLAttributes< HTMLDivElement > & VariantProps< typeof alertVariants >
>( ( { className, variant, ...props }, ref ) => (
	<div ref={ ref } role="alert" className={ cn( alertVariants( { variant } ), className ) } { ...props } />
) );
Alert.displayName = 'Alert';

export const AlertTitle = React.forwardRef< HTMLParagraphElement, React.HTMLAttributes< HTMLHeadingElement > >(
	( { className, ...props }, ref ) => (
		<h5 ref={ ref } className={ cn( 'm-0 font-medium leading-tight', className ) } { ...props } />
	)
);
AlertTitle.displayName = 'AlertTitle';

export const AlertDescription = React.forwardRef< HTMLParagraphElement, React.HTMLAttributes< HTMLParagraphElement > >(
	( { className, ...props }, ref ) => (
		<div ref={ ref } className={ cn( 'text-[13px] opacity-90 [&_p]:leading-relaxed', className ) } { ...props } />
	)
);
AlertDescription.displayName = 'AlertDescription';
