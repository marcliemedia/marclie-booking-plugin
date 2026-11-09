import * as TabsPrimitive from '@radix-ui/react-tabs';
import * as React from 'react';

import { cn } from '@/lib/utils';

export const Tabs = TabsPrimitive.Root;

export const TabsList = React.forwardRef<
	React.ElementRef< typeof TabsPrimitive.List >,
	React.ComponentPropsWithoutRef< typeof TabsPrimitive.List > & { variant?: 'pill' | 'underline' }
>( ( { className, variant = 'pill', ...props }, ref ) => (
	<TabsPrimitive.List
		ref={ ref }
		data-variant={ variant }
		className={ cn(
			'group/tabs inline-flex items-center text-muted-foreground',
			variant === 'pill' && 'h-9 gap-0.5 rounded-lg bg-surface-muted p-1',
			variant === 'underline' && 'h-10 w-full gap-4 border-b border-border',
			className
		) }
		{ ...props }
	/>
) );
TabsList.displayName = TabsPrimitive.List.displayName;

export const TabsTrigger = React.forwardRef<
	React.ElementRef< typeof TabsPrimitive.Trigger >,
	React.ComponentPropsWithoutRef< typeof TabsPrimitive.Trigger >
>( ( { className, ...props }, ref ) => (
	<TabsPrimitive.Trigger
		ref={ ref }
		className={ cn(
			'inline-flex items-center justify-center gap-1.5 whitespace-nowrap text-[13px] font-medium transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50',
			'group-data-[variant=pill]/tabs:h-7 group-data-[variant=pill]/tabs:rounded-md group-data-[variant=pill]/tabs:px-3 group-data-[variant=pill]/tabs:data-[state=active]:bg-surface group-data-[variant=pill]/tabs:data-[state=active]:text-ink-soft group-data-[variant=pill]/tabs:data-[state=active]:shadow-card',
			'group-data-[variant=underline]/tabs:-mb-px group-data-[variant=underline]/tabs:h-10 group-data-[variant=underline]/tabs:border-b-2 group-data-[variant=underline]/tabs:border-transparent group-data-[variant=underline]/tabs:data-[state=active]:border-ink-soft group-data-[variant=underline]/tabs:data-[state=active]:text-ink-soft hover:text-ink-soft',
			className
		) }
		{ ...props }
	/>
) );
TabsTrigger.displayName = TabsPrimitive.Trigger.displayName;

export const TabsContent = React.forwardRef<
	React.ElementRef< typeof TabsPrimitive.Content >,
	React.ComponentPropsWithoutRef< typeof TabsPrimitive.Content >
>( ( { className, ...props }, ref ) => (
	<TabsPrimitive.Content
		ref={ ref }
		className={ cn( 'mt-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring', className ) }
		{ ...props }
	/>
) );
TabsContent.displayName = TabsPrimitive.Content.displayName;
