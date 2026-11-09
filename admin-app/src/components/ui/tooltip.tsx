import * as TooltipPrimitive from '@radix-ui/react-tooltip';
import * as React from 'react';

import { usePortalContainer } from '@/lib/portal';
import { cn } from '@/lib/utils';

export const TooltipProvider = TooltipPrimitive.Provider;
export const Tooltip = TooltipPrimitive.Root;
export const TooltipTrigger = TooltipPrimitive.Trigger;

export const TooltipContent = React.forwardRef<
	React.ElementRef< typeof TooltipPrimitive.Content >,
	React.ComponentPropsWithoutRef< typeof TooltipPrimitive.Content >
>( ( { className, sideOffset = 6, ...props }, ref ) => (
	<TooltipPrimitive.Portal container={ usePortalContainer() }>
		<TooltipPrimitive.Content
			ref={ ref }
			sideOffset={ sideOffset }
			className={ cn(
				'z-[100060] overflow-hidden rounded-md bg-ink-soft px-2.5 py-1.5 text-xs text-white shadow-pop animate-in fade-in-0 zoom-in-95 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95',
				className
			) }
			{ ...props }
		/>
	</TooltipPrimitive.Portal>
) );
TooltipContent.displayName = TooltipPrimitive.Content.displayName;

/** Shorthand: `<SimpleTooltip label="…"><button/></SimpleTooltip>`. */
export function SimpleTooltip( {
	label,
	children,
	side,
}: {
	label: React.ReactNode;
	children: React.ReactNode;
	side?: 'top' | 'right' | 'bottom' | 'left';
} ) {
	return (
		<Tooltip>
			<TooltipTrigger asChild>{ children }</TooltipTrigger>
			<TooltipContent side={ side }>{ label }</TooltipContent>
		</Tooltip>
	);
}
