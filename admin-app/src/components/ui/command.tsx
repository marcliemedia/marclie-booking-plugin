import { MagnifyingGlass } from '@phosphor-icons/react';
import { Command as CommandPrimitive } from 'cmdk';
import * as React from 'react';

import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

export const Command = React.forwardRef<
	React.ElementRef< typeof CommandPrimitive >,
	React.ComponentPropsWithoutRef< typeof CommandPrimitive >
>( ( { className, ...props }, ref ) => (
	<CommandPrimitive
		ref={ ref }
		className={ cn( 'flex h-full w-full flex-col overflow-hidden rounded-lg bg-popover text-popover-foreground', className ) }
		{ ...props }
	/>
) );
Command.displayName = CommandPrimitive.displayName;

export function CommandDialog( {
	children,
	title,
	description,
	shouldFilter,
	...props
}: React.ComponentProps< typeof Dialog > & { title: string; description?: string; shouldFilter?: boolean } ) {
	return (
		<Dialog { ...props }>
			<DialogContent hideClose className="top-[18%] max-w-xl translate-y-0 gap-0 overflow-hidden p-0">
				<DialogTitle className="sr-only">{ title }</DialogTitle>
				<DialogDescription className="sr-only">{ description ?? title }</DialogDescription>
				<Command shouldFilter={ shouldFilter } className="[&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:py-1.5 [&_[cmdk-group-heading]]:text-xs [&_[cmdk-group-heading]]:font-medium [&_[cmdk-group-heading]]:text-muted-foreground [&_[cmdk-group]]:px-2">
					{ children }
				</Command>
			</DialogContent>
		</Dialog>
	);
}

export const CommandInput = React.forwardRef<
	React.ElementRef< typeof CommandPrimitive.Input >,
	React.ComponentPropsWithoutRef< typeof CommandPrimitive.Input >
>( ( { className, ...props }, ref ) => (
	<div className="flex items-center gap-2 border-b border-border px-4" cmdk-input-wrapper="">
		<MagnifyingGlass className="h-4 w-4 shrink-0 text-muted-foreground" />
		<CommandPrimitive.Input
			ref={ ref }
			className={ cn(
				'flex h-12 w-full rounded-md !border-0 bg-transparent py-3 text-sm outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50',
				className
			) }
			{ ...props }
		/>
	</div>
) );
CommandInput.displayName = CommandPrimitive.Input.displayName;

export const CommandList = React.forwardRef<
	React.ElementRef< typeof CommandPrimitive.List >,
	React.ComponentPropsWithoutRef< typeof CommandPrimitive.List >
>( ( { className, ...props }, ref ) => (
	<CommandPrimitive.List
		ref={ ref }
		className={ cn( 'max-h-[min(420px,60vh)] overflow-y-auto overflow-x-hidden py-2', className ) }
		{ ...props }
	/>
) );
CommandList.displayName = CommandPrimitive.List.displayName;

export const CommandEmpty = React.forwardRef<
	React.ElementRef< typeof CommandPrimitive.Empty >,
	React.ComponentPropsWithoutRef< typeof CommandPrimitive.Empty >
>( ( props, ref ) => (
	<CommandPrimitive.Empty ref={ ref } className="py-8 text-center text-sm text-muted-foreground" { ...props } />
) );
CommandEmpty.displayName = CommandPrimitive.Empty.displayName;

export const CommandGroup = React.forwardRef<
	React.ElementRef< typeof CommandPrimitive.Group >,
	React.ComponentPropsWithoutRef< typeof CommandPrimitive.Group >
>( ( { className, ...props }, ref ) => (
	<CommandPrimitive.Group ref={ ref } className={ cn( 'overflow-hidden text-foreground', className ) } { ...props } />
) );
CommandGroup.displayName = CommandPrimitive.Group.displayName;

export const CommandSeparator = React.forwardRef<
	React.ElementRef< typeof CommandPrimitive.Separator >,
	React.ComponentPropsWithoutRef< typeof CommandPrimitive.Separator >
>( ( { className, ...props }, ref ) => (
	<CommandPrimitive.Separator ref={ ref } className={ cn( 'my-1 h-px bg-border', className ) } { ...props } />
) );
CommandSeparator.displayName = CommandPrimitive.Separator.displayName;

export const CommandItem = React.forwardRef<
	React.ElementRef< typeof CommandPrimitive.Item >,
	React.ComponentPropsWithoutRef< typeof CommandPrimitive.Item >
>( ( { className, ...props }, ref ) => (
	<CommandPrimitive.Item
		ref={ ref }
		className={ cn(
			'relative flex cursor-default select-none items-center gap-3 rounded-md px-2 py-2 text-sm outline-none data-[disabled=true]:pointer-events-none data-[selected=true]:bg-surface-muted data-[disabled=true]:opacity-50 [&_svg]:h-4 [&_svg]:w-4 [&_svg]:shrink-0 [&_svg]:text-muted-foreground',
			className
		) }
		{ ...props }
	/>
) );
CommandItem.displayName = CommandPrimitive.Item.displayName;

export function CommandShortcut( { className, ...props }: React.HTMLAttributes< HTMLSpanElement > ) {
	return <span className={ cn( 'ml-auto text-xs tracking-widest text-muted-foreground', className ) } { ...props } />;
}
