import { CaretRight, Check } from '@phosphor-icons/react';
import * as DropdownMenuPrimitive from '@radix-ui/react-dropdown-menu';
import * as React from 'react';

import { usePortalContainer } from '@/lib/portal';
import { cn } from '@/lib/utils';

export const DropdownMenu = DropdownMenuPrimitive.Root;
export const DropdownMenuTrigger = DropdownMenuPrimitive.Trigger;
export const DropdownMenuGroup = DropdownMenuPrimitive.Group;
export const DropdownMenuSub = DropdownMenuPrimitive.Sub;
export const DropdownMenuRadioGroup = DropdownMenuPrimitive.RadioGroup;

const itemClass =
	'relative flex cursor-default select-none items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-none transition-colors focus:bg-surface-muted data-[disabled]:pointer-events-none data-[disabled]:opacity-50 [&_svg]:h-4 [&_svg]:w-4 [&_svg]:shrink-0';

export const DropdownMenuSubTrigger = React.forwardRef<
	React.ElementRef< typeof DropdownMenuPrimitive.SubTrigger >,
	React.ComponentPropsWithoutRef< typeof DropdownMenuPrimitive.SubTrigger >
>( ( { className, children, ...props }, ref ) => (
	<DropdownMenuPrimitive.SubTrigger ref={ ref } className={ cn( itemClass, 'data-[state=open]:bg-surface-muted', className ) } { ...props }>
		{ children }
		<CaretRight className="ml-auto" />
	</DropdownMenuPrimitive.SubTrigger>
) );
DropdownMenuSubTrigger.displayName = DropdownMenuPrimitive.SubTrigger.displayName;

const contentClass =
	'z-[100050] min-w-[10rem] overflow-hidden rounded-md border border-border bg-popover p-1 text-popover-foreground shadow-pop data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95';

export const DropdownMenuSubContent = React.forwardRef<
	React.ElementRef< typeof DropdownMenuPrimitive.SubContent >,
	React.ComponentPropsWithoutRef< typeof DropdownMenuPrimitive.SubContent >
>( ( { className, ...props }, ref ) => (
	<DropdownMenuPrimitive.Portal container={ usePortalContainer() }>
		<DropdownMenuPrimitive.SubContent ref={ ref } className={ cn( contentClass, className ) } { ...props } />
	</DropdownMenuPrimitive.Portal>
) );
DropdownMenuSubContent.displayName = DropdownMenuPrimitive.SubContent.displayName;

export const DropdownMenuContent = React.forwardRef<
	React.ElementRef< typeof DropdownMenuPrimitive.Content >,
	React.ComponentPropsWithoutRef< typeof DropdownMenuPrimitive.Content >
>( ( { className, sideOffset = 4, ...props }, ref ) => (
	<DropdownMenuPrimitive.Portal container={ usePortalContainer() }>
		<DropdownMenuPrimitive.Content
			ref={ ref }
			sideOffset={ sideOffset }
			className={ cn( contentClass, 'max-h-[var(--radix-dropdown-menu-content-available-height)] overflow-y-auto', className ) }
			{ ...props }
		/>
	</DropdownMenuPrimitive.Portal>
) );
DropdownMenuContent.displayName = DropdownMenuPrimitive.Content.displayName;

export const DropdownMenuItem = React.forwardRef<
	React.ElementRef< typeof DropdownMenuPrimitive.Item >,
	React.ComponentPropsWithoutRef< typeof DropdownMenuPrimitive.Item > & { destructive?: boolean }
>( ( { className, destructive, ...props }, ref ) => (
	<DropdownMenuPrimitive.Item
		ref={ ref }
		className={ cn( itemClass, destructive && 'text-danger focus:bg-danger-soft focus:text-danger', className ) }
		{ ...props }
	/>
) );
DropdownMenuItem.displayName = DropdownMenuPrimitive.Item.displayName;

export const DropdownMenuCheckboxItem = React.forwardRef<
	React.ElementRef< typeof DropdownMenuPrimitive.CheckboxItem >,
	React.ComponentPropsWithoutRef< typeof DropdownMenuPrimitive.CheckboxItem >
>( ( { className, children, checked, ...props }, ref ) => (
	<DropdownMenuPrimitive.CheckboxItem
		ref={ ref }
		className={ cn( itemClass, 'pl-8', className ) }
		checked={ checked }
		{ ...props }
	>
		<span className="absolute left-2 flex h-3.5 w-3.5 items-center justify-center">
			<DropdownMenuPrimitive.ItemIndicator>
				<Check weight="bold" />
			</DropdownMenuPrimitive.ItemIndicator>
		</span>
		{ children }
	</DropdownMenuPrimitive.CheckboxItem>
) );
DropdownMenuCheckboxItem.displayName = DropdownMenuPrimitive.CheckboxItem.displayName;

export const DropdownMenuRadioItem = React.forwardRef<
	React.ElementRef< typeof DropdownMenuPrimitive.RadioItem >,
	React.ComponentPropsWithoutRef< typeof DropdownMenuPrimitive.RadioItem >
>( ( { className, children, ...props }, ref ) => (
	<DropdownMenuPrimitive.RadioItem ref={ ref } className={ cn( itemClass, 'pl-8', className ) } { ...props }>
		<span className="absolute left-2 flex h-3.5 w-3.5 items-center justify-center">
			<DropdownMenuPrimitive.ItemIndicator>
				<span className="block h-2 w-2 rounded-full bg-current" />
			</DropdownMenuPrimitive.ItemIndicator>
		</span>
		{ children }
	</DropdownMenuPrimitive.RadioItem>
) );
DropdownMenuRadioItem.displayName = DropdownMenuPrimitive.RadioItem.displayName;

export const DropdownMenuLabel = React.forwardRef<
	React.ElementRef< typeof DropdownMenuPrimitive.Label >,
	React.ComponentPropsWithoutRef< typeof DropdownMenuPrimitive.Label >
>( ( { className, ...props }, ref ) => (
	<DropdownMenuPrimitive.Label
		ref={ ref }
		className={ cn( 'px-2 py-1.5 text-xs font-medium text-muted-foreground', className ) }
		{ ...props }
	/>
) );
DropdownMenuLabel.displayName = DropdownMenuPrimitive.Label.displayName;

export const DropdownMenuSeparator = React.forwardRef<
	React.ElementRef< typeof DropdownMenuPrimitive.Separator >,
	React.ComponentPropsWithoutRef< typeof DropdownMenuPrimitive.Separator >
>( ( { className, ...props }, ref ) => (
	<DropdownMenuPrimitive.Separator ref={ ref } className={ cn( '-mx-1 my-1 h-px bg-border', className ) } { ...props } />
) );
DropdownMenuSeparator.displayName = DropdownMenuPrimitive.Separator.displayName;
