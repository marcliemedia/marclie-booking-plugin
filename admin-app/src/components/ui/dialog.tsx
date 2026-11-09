import { X } from '@phosphor-icons/react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { __ } from '@wordpress/i18n';
import * as React from 'react';

import { usePortalContainer } from '@/lib/portal';
import { cn } from '@/lib/utils';

export const Dialog = DialogPrimitive.Root;
export const DialogTrigger = DialogPrimitive.Trigger;
export const DialogClose = DialogPrimitive.Close;

export const DialogOverlay = React.forwardRef<
	React.ElementRef< typeof DialogPrimitive.Overlay >,
	React.ComponentPropsWithoutRef< typeof DialogPrimitive.Overlay >
>( ( { className, ...props }, ref ) => (
	<DialogPrimitive.Overlay
		ref={ ref }
		className={ cn(
			'fixed inset-0 z-[100040] bg-ink/30 backdrop-blur-[1px] data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
			className
		) }
		{ ...props }
	/>
) );
DialogOverlay.displayName = DialogPrimitive.Overlay.displayName;

export const DialogContent = React.forwardRef<
	React.ElementRef< typeof DialogPrimitive.Content >,
	React.ComponentPropsWithoutRef< typeof DialogPrimitive.Content > & { hideClose?: boolean }
>( ( { className, children, hideClose, ...props }, ref ) => (
	<DialogPrimitive.Portal container={ usePortalContainer() }>
		<DialogOverlay />
		<DialogPrimitive.Content
			ref={ ref }
			className={ cn(
				'fixed left-1/2 top-1/2 z-[100045] grid max-h-[calc(100vh-2rem)] w-[calc(100vw-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 gap-4 overflow-y-auto rounded-xl border border-border bg-surface p-6 shadow-pop duration-150 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95',
				className
			) }
			{ ...props }
		>
			{ children }
			{ ! hideClose && (
				<DialogPrimitive.Close className="absolute right-4 top-4 rounded-sm p-1 text-muted-foreground transition-colors hover:bg-surface-muted hover:text-ink-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
					<X className="h-4 w-4" />
					<span className="sr-only">{ __( 'Close', 'marclie-booking' ) }</span>
				</DialogPrimitive.Close>
			) }
		</DialogPrimitive.Content>
	</DialogPrimitive.Portal>
) );
DialogContent.displayName = DialogPrimitive.Content.displayName;

export function DialogHeader( { className, ...props }: React.HTMLAttributes< HTMLDivElement > ) {
	return <div className={ cn( 'flex flex-col gap-1 pr-6 text-left', className ) } { ...props } />;
}

export function DialogFooter( { className, ...props }: React.HTMLAttributes< HTMLDivElement > ) {
	return (
		<div
			className={ cn( 'flex flex-col-reverse gap-2 sm:flex-row sm:justify-end', className ) }
			{ ...props }
		/>
	);
}

export const DialogTitle = React.forwardRef<
	React.ElementRef< typeof DialogPrimitive.Title >,
	React.ComponentPropsWithoutRef< typeof DialogPrimitive.Title >
>( ( { className, ...props }, ref ) => (
	<DialogPrimitive.Title
		ref={ ref }
		className={ cn( 'text-base font-semibold leading-tight text-ink', className ) }
		{ ...props }
	/>
) );
DialogTitle.displayName = DialogPrimitive.Title.displayName;

export const DialogDescription = React.forwardRef<
	React.ElementRef< typeof DialogPrimitive.Description >,
	React.ComponentPropsWithoutRef< typeof DialogPrimitive.Description >
>( ( { className, ...props }, ref ) => (
	<DialogPrimitive.Description
		ref={ ref }
		className={ cn( 'text-sm text-muted-foreground', className ) }
		{ ...props }
	/>
) );
DialogDescription.displayName = DialogPrimitive.Description.displayName;
