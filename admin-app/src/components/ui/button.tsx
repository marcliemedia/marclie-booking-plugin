import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

export const buttonVariants = cva(
	'inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-md text-sm font-medium leading-none transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0',
	{
		variants: {
			variant: {
				// Rose accent: reserved for the primary action of a screen/dialog.
				default: 'bg-accent text-accent-foreground shadow-card hover:bg-accent-hover',
				ink: 'bg-ink-soft text-white shadow-card hover:bg-ink',
				secondary: 'border border-border bg-surface text-ink-soft shadow-card hover:bg-surface-muted',
				outline: 'border border-border bg-surface text-ink-soft hover:bg-surface-muted',
				ghost: 'text-ink-soft hover:bg-surface-muted',
				destructive: 'bg-danger text-white shadow-card hover:bg-danger/90',
				link: 'text-accent underline-offset-4 hover:underline',
			},
			size: {
				default: 'h-9 px-3.5',
				sm: 'h-8 px-2.5 text-[13px]',
				lg: 'h-10 px-5',
				icon: 'h-9 w-9',
				'icon-sm': 'h-8 w-8',
			},
		},
		defaultVariants: {
			variant: 'default',
			size: 'default',
		},
	}
);

export interface ButtonProps
	extends React.ButtonHTMLAttributes< HTMLButtonElement >,
		VariantProps< typeof buttonVariants > {
	asChild?: boolean;
}

export const Button = React.forwardRef< HTMLButtonElement, ButtonProps >(
	( { className, variant, size, asChild = false, type, ...props }, ref ) => {
		const Comp = asChild ? Slot : 'button';
		return (
			<Comp
				className={ cn( buttonVariants( { variant, size, className } ) ) }
				ref={ ref }
				{ ...( asChild ? {} : { type: type ?? 'button' } ) }
				{ ...props }
			/>
		);
	}
);
Button.displayName = 'Button';
