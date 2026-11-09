import * as AvatarPrimitive from '@radix-ui/react-avatar';
import * as React from 'react';

import { cn } from '@/lib/utils';

export const Avatar = React.forwardRef<
	React.ElementRef< typeof AvatarPrimitive.Root >,
	React.ComponentPropsWithoutRef< typeof AvatarPrimitive.Root >
>( ( { className, ...props }, ref ) => (
	<AvatarPrimitive.Root
		ref={ ref }
		className={ cn( 'relative flex h-8 w-8 shrink-0 overflow-hidden rounded-full', className ) }
		{ ...props }
	/>
) );
Avatar.displayName = AvatarPrimitive.Root.displayName;

export const AvatarImage = React.forwardRef<
	React.ElementRef< typeof AvatarPrimitive.Image >,
	React.ComponentPropsWithoutRef< typeof AvatarPrimitive.Image >
>( ( { className, ...props }, ref ) => (
	<AvatarPrimitive.Image ref={ ref } className={ cn( 'aspect-square h-full w-full', className ) } { ...props } />
) );
AvatarImage.displayName = AvatarPrimitive.Image.displayName;

export const AvatarFallback = React.forwardRef<
	React.ElementRef< typeof AvatarPrimitive.Fallback >,
	React.ComponentPropsWithoutRef< typeof AvatarPrimitive.Fallback >
>( ( { className, ...props }, ref ) => (
	<AvatarPrimitive.Fallback
		ref={ ref }
		className={ cn(
			'flex h-full w-full items-center justify-center rounded-full bg-surface-muted text-xs font-semibold text-ink-soft',
			className
		) }
		{ ...props }
	/>
) );
AvatarFallback.displayName = AvatarPrimitive.Fallback.displayName;

export function initials( name: string ): string {
	const parts = name.trim().split( /\s+/ ).filter( Boolean );
	if ( parts.length === 0 ) {
		return '?';
	}
	const first = parts[ 0 ][ 0 ] ?? '';
	const last = parts.length > 1 ? parts[ parts.length - 1 ][ 0 ] ?? '' : '';
	return ( first + last ).toUpperCase();
}
