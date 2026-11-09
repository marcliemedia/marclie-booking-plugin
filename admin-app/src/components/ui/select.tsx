import { CaretDown, CaretUp, Check } from '@phosphor-icons/react';
import * as SelectPrimitive from '@radix-ui/react-select';
import * as React from 'react';

import { usePortalContainer } from '@/lib/portal';
import { cn } from '@/lib/utils';

export const Select = SelectPrimitive.Root;
export const SelectGroup = SelectPrimitive.Group;
export const SelectValue = SelectPrimitive.Value;

export const SelectTrigger = React.forwardRef<
	React.ElementRef< typeof SelectPrimitive.Trigger >,
	React.ComponentPropsWithoutRef< typeof SelectPrimitive.Trigger >
>( ( { className, children, ...props }, ref ) => (
	<SelectPrimitive.Trigger
		ref={ ref }
		className={ cn(
			'flex h-9 w-full items-center justify-between gap-2 whitespace-nowrap rounded-md border border-input bg-surface px-3 py-1.5 text-sm text-ink-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-ring/25 disabled:cursor-not-allowed disabled:opacity-50 data-[placeholder]:text-muted-foreground [&>span]:line-clamp-1 [&>span]:text-left',
			className
		) }
		{ ...props }
	>
		{ children }
		<SelectPrimitive.Icon asChild>
			<CaretDown className="h-3.5 w-3.5 opacity-60" />
		</SelectPrimitive.Icon>
	</SelectPrimitive.Trigger>
) );
SelectTrigger.displayName = SelectPrimitive.Trigger.displayName;

export const SelectContent = React.forwardRef<
	React.ElementRef< typeof SelectPrimitive.Content >,
	React.ComponentPropsWithoutRef< typeof SelectPrimitive.Content >
>( ( { className, children, position = 'popper', ...props }, ref ) => (
	<SelectPrimitive.Portal container={ usePortalContainer() }>
		<SelectPrimitive.Content
			ref={ ref }
			className={ cn(
				'relative z-[100050] max-h-[--radix-select-content-available-height] min-w-[8rem] overflow-y-auto overflow-x-hidden rounded-md border border-border bg-popover text-popover-foreground shadow-pop data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95',
				position === 'popper' &&
					'data-[side=bottom]:translate-y-1 data-[side=left]:-translate-x-1 data-[side=right]:translate-x-1 data-[side=top]:-translate-y-1',
				className
			) }
			position={ position }
			{ ...props }
		>
			<SelectPrimitive.ScrollUpButton className="flex cursor-default items-center justify-center py-1">
				<CaretUp className="h-3.5 w-3.5" />
			</SelectPrimitive.ScrollUpButton>
			<SelectPrimitive.Viewport
				className={ cn(
					'p-1',
					position === 'popper' &&
						'h-[var(--radix-select-trigger-height)] w-full min-w-[var(--radix-select-trigger-width)]'
				) }
			>
				{ children }
			</SelectPrimitive.Viewport>
			<SelectPrimitive.ScrollDownButton className="flex cursor-default items-center justify-center py-1">
				<CaretDown className="h-3.5 w-3.5" />
			</SelectPrimitive.ScrollDownButton>
		</SelectPrimitive.Content>
	</SelectPrimitive.Portal>
) );
SelectContent.displayName = SelectPrimitive.Content.displayName;

export const SelectLabel = React.forwardRef<
	React.ElementRef< typeof SelectPrimitive.Label >,
	React.ComponentPropsWithoutRef< typeof SelectPrimitive.Label >
>( ( { className, ...props }, ref ) => (
	<SelectPrimitive.Label
		ref={ ref }
		className={ cn( 'px-2 py-1.5 text-xs font-medium text-muted-foreground', className ) }
		{ ...props }
	/>
) );
SelectLabel.displayName = SelectPrimitive.Label.displayName;

export const SelectItem = React.forwardRef<
	React.ElementRef< typeof SelectPrimitive.Item >,
	React.ComponentPropsWithoutRef< typeof SelectPrimitive.Item >
>( ( { className, children, ...props }, ref ) => (
	<SelectPrimitive.Item
		ref={ ref }
		className={ cn(
			'relative flex w-full cursor-default select-none items-center rounded-sm py-1.5 pl-2 pr-8 text-sm outline-none focus:bg-surface-muted data-[disabled]:pointer-events-none data-[disabled]:opacity-50',
			className
		) }
		{ ...props }
	>
		<span className="absolute right-2 flex h-3.5 w-3.5 items-center justify-center">
			<SelectPrimitive.ItemIndicator>
				<Check className="h-3.5 w-3.5" weight="bold" />
			</SelectPrimitive.ItemIndicator>
		</span>
		<SelectPrimitive.ItemText>{ children }</SelectPrimitive.ItemText>
	</SelectPrimitive.Item>
) );
SelectItem.displayName = SelectPrimitive.Item.displayName;

export const SelectSeparator = React.forwardRef<
	React.ElementRef< typeof SelectPrimitive.Separator >,
	React.ComponentPropsWithoutRef< typeof SelectPrimitive.Separator >
>( ( { className, ...props }, ref ) => (
	<SelectPrimitive.Separator ref={ ref } className={ cn( '-mx-1 my-1 h-px bg-border', className ) } { ...props } />
) );
SelectSeparator.displayName = SelectPrimitive.Separator.displayName;
