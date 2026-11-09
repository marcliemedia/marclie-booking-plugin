import * as AlertDialogPrimitive from '@radix-ui/react-alert-dialog';
import * as React from 'react';

import { buttonVariants } from '@/components/ui/button';
import { usePortalContainer } from '@/lib/portal';
import { cn } from '@/lib/utils';

export const AlertDialog = AlertDialogPrimitive.Root;
export const AlertDialogTrigger = AlertDialogPrimitive.Trigger;

const AlertDialogOverlay = React.forwardRef<
	React.ElementRef< typeof AlertDialogPrimitive.Overlay >,
	React.ComponentPropsWithoutRef< typeof AlertDialogPrimitive.Overlay >
>( ( { className, ...props }, ref ) => (
	<AlertDialogPrimitive.Overlay
		className={ cn(
			'fixed inset-0 z-[100055] bg-ink/30 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
			className
		) }
		{ ...props }
		ref={ ref }
	/>
) );
AlertDialogOverlay.displayName = AlertDialogPrimitive.Overlay.displayName;

export const AlertDialogContent = React.forwardRef<
	React.ElementRef< typeof AlertDialogPrimitive.Content >,
	React.ComponentPropsWithoutRef< typeof AlertDialogPrimitive.Content >
>( ( { className, ...props }, ref ) => (
	<AlertDialogPrimitive.Portal container={ usePortalContainer() }>
		<AlertDialogOverlay />
		<AlertDialogPrimitive.Content
			ref={ ref }
			className={ cn(
				'fixed left-1/2 top-1/2 z-[100056] grid max-h-[calc(100vh-2rem)] w-[calc(100vw-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 gap-4 overflow-y-auto rounded-xl border border-border bg-surface p-6 shadow-pop duration-150 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95',
				className
			) }
			{ ...props }
		/>
	</AlertDialogPrimitive.Portal>
) );
AlertDialogContent.displayName = AlertDialogPrimitive.Content.displayName;

export function AlertDialogHeader( { className, ...props }: React.HTMLAttributes< HTMLDivElement > ) {
	return <div className={ cn( 'flex flex-col gap-1.5 text-left', className ) } { ...props } />;
}

export function AlertDialogFooter( { className, ...props }: React.HTMLAttributes< HTMLDivElement > ) {
	return <div className={ cn( 'flex flex-col-reverse gap-2 sm:flex-row sm:justify-end', className ) } { ...props } />;
}

export const AlertDialogTitle = React.forwardRef<
	React.ElementRef< typeof AlertDialogPrimitive.Title >,
	React.ComponentPropsWithoutRef< typeof AlertDialogPrimitive.Title >
>( ( { className, ...props }, ref ) => (
	<AlertDialogPrimitive.Title ref={ ref } className={ cn( 'text-base font-semibold text-ink', className ) } { ...props } />
) );
AlertDialogTitle.displayName = AlertDialogPrimitive.Title.displayName;

export const AlertDialogDescription = React.forwardRef<
	React.ElementRef< typeof AlertDialogPrimitive.Description >,
	React.ComponentPropsWithoutRef< typeof AlertDialogPrimitive.Description >
>( ( { className, ...props }, ref ) => (
	<AlertDialogPrimitive.Description
		ref={ ref }
		className={ cn( 'text-sm text-muted-foreground', className ) }
		{ ...props }
	/>
) );
AlertDialogDescription.displayName = AlertDialogPrimitive.Description.displayName;

export const AlertDialogAction = React.forwardRef<
	React.ElementRef< typeof AlertDialogPrimitive.Action >,
	React.ComponentPropsWithoutRef< typeof AlertDialogPrimitive.Action >
>( ( { className, ...props }, ref ) => (
	<AlertDialogPrimitive.Action ref={ ref } className={ cn( buttonVariants(), className ) } { ...props } />
) );
AlertDialogAction.displayName = AlertDialogPrimitive.Action.displayName;

export const AlertDialogCancel = React.forwardRef<
	React.ElementRef< typeof AlertDialogPrimitive.Cancel >,
	React.ComponentPropsWithoutRef< typeof AlertDialogPrimitive.Cancel >
>( ( { className, ...props }, ref ) => (
	<AlertDialogPrimitive.Cancel
		ref={ ref }
		className={ cn( buttonVariants( { variant: 'secondary' } ), className ) }
		{ ...props }
	/>
) );
AlertDialogCancel.displayName = AlertDialogPrimitive.Cancel.displayName;
