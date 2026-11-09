import { X } from '@phosphor-icons/react';
import * as SheetPrimitive from '@radix-ui/react-dialog';
import { __ } from '@wordpress/i18n';
import { cva, type VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { usePortalContainer } from '@/lib/portal';
import { cn } from '@/lib/utils';

export const Sheet = SheetPrimitive.Root;
export const SheetTrigger = SheetPrimitive.Trigger;
export const SheetClose = SheetPrimitive.Close;

const SheetOverlay = React.forwardRef<
	React.ElementRef< typeof SheetPrimitive.Overlay >,
	React.ComponentPropsWithoutRef< typeof SheetPrimitive.Overlay >
>( ( { className, ...props }, ref ) => (
	<SheetPrimitive.Overlay
		className={ cn(
			'fixed inset-0 z-[100030] bg-ink/25 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
			className
		) }
		{ ...props }
		ref={ ref }
	/>
) );
SheetOverlay.displayName = SheetPrimitive.Overlay.displayName;

const sheetVariants = cva(
	'fixed z-[100035] flex flex-col bg-surface shadow-pop transition ease-in-out data-[state=closed]:duration-200 data-[state=open]:duration-300 data-[state=open]:animate-in data-[state=closed]:animate-out',
	{
		variants: {
			side: {
				right: 'inset-y-0 right-0 h-full w-full border-l border-border data-[state=closed]:slide-out-to-right data-[state=open]:slide-in-from-right sm:max-w-xl',
				left: 'inset-y-0 left-0 h-full w-3/4 border-r border-border data-[state=closed]:slide-out-to-left data-[state=open]:slide-in-from-left sm:max-w-sm',
				bottom: 'inset-x-0 bottom-0 max-h-[90vh] rounded-t-xl border-t border-border data-[state=closed]:slide-out-to-bottom data-[state=open]:slide-in-from-bottom',
			},
		},
		defaultVariants: {
			side: 'right',
		},
	}
);

interface SheetContentProps
	extends React.ComponentPropsWithoutRef< typeof SheetPrimitive.Content >,
		VariantProps< typeof sheetVariants > {
	hideClose?: boolean;
}

export const SheetContent = React.forwardRef< React.ElementRef< typeof SheetPrimitive.Content >, SheetContentProps >(
	( { side = 'right', className, children, hideClose, ...props }, ref ) => (
		<SheetPrimitive.Portal container={ usePortalContainer() }>
			<SheetOverlay />
			<SheetPrimitive.Content ref={ ref } className={ cn( sheetVariants( { side } ), className ) } { ...props }>
				{ children }
				{ ! hideClose && (
					<SheetPrimitive.Close className="absolute right-4 top-4 rounded-sm p-1 text-muted-foreground transition-colors hover:bg-surface-muted hover:text-ink-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
						<X className="h-4 w-4" />
						<span className="sr-only">{ __( 'Close', 'marclie-booking' ) }</span>
					</SheetPrimitive.Close>
				) }
			</SheetPrimitive.Content>
		</SheetPrimitive.Portal>
	)
);
SheetContent.displayName = SheetPrimitive.Content.displayName;

export function SheetHeader( { className, ...props }: React.HTMLAttributes< HTMLDivElement > ) {
	return <div className={ cn( 'flex flex-col gap-1 border-b border-border px-6 py-5 pr-12', className ) } { ...props } />;
}

export function SheetBody( { className, ...props }: React.HTMLAttributes< HTMLDivElement > ) {
	return <div className={ cn( 'flex-1 overflow-y-auto px-6 py-5', className ) } { ...props } />;
}

export function SheetFooter( { className, ...props }: React.HTMLAttributes< HTMLDivElement > ) {
	return (
		<div
			className={ cn( 'flex flex-wrap items-center justify-end gap-2 border-t border-border px-6 py-4', className ) }
			{ ...props }
		/>
	);
}

export const SheetTitle = React.forwardRef<
	React.ElementRef< typeof SheetPrimitive.Title >,
	React.ComponentPropsWithoutRef< typeof SheetPrimitive.Title >
>( ( { className, ...props }, ref ) => (
	<SheetPrimitive.Title ref={ ref } className={ cn( 'text-lg font-semibold text-ink', className ) } { ...props } />
) );
SheetTitle.displayName = SheetPrimitive.Title.displayName;

export const SheetDescription = React.forwardRef<
	React.ElementRef< typeof SheetPrimitive.Description >,
	React.ComponentPropsWithoutRef< typeof SheetPrimitive.Description >
>( ( { className, ...props }, ref ) => (
	<SheetPrimitive.Description
		ref={ ref }
		className={ cn( 'text-sm text-muted-foreground', className ) }
		{ ...props }
	/>
) );
SheetDescription.displayName = SheetPrimitive.Description.displayName;
