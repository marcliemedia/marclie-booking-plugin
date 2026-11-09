/**
 * Portal container scoped inside `#mbk-app`.
 *
 * Tailwind utilities are prefixed with `#mbk-app` (`important` selector) and the
 * design tokens (CSS variables) are defined on `#mbk-app`, so anything portalled to
 * `document.body` would render unstyled. Every Radix/shadcn Portal (Dialog, Popover,
 * Select, DropdownMenu, Tooltip, Sheet, …) MUST therefore receive
 * `container={ usePortalContainer() }`, e.g.:
 *
 *     <DialogPrimitive.Portal container={ usePortalContainer() }>
 *
 * `<PortalRoot>` renders `#mbk-portal-root` as the last child of the app and only
 * renders its children once that node exists, so the container is never `null`
 * (Radix would silently fall back to `document.body`).
 */
import { createContext, useContext, useState } from '@wordpress/element';
import type { ReactNode } from 'react';

const PortalContainerContext = createContext< HTMLElement | null >( null );

export function usePortalContainer(): HTMLElement {
	const container = useContext( PortalContainerContext );
	if ( ! container ) {
		throw new Error( 'usePortalContainer() must be used inside <PortalRoot>.' );
	}
	return container;
}

export function PortalRoot( { children }: { children: ReactNode } ) {
	const [ container, setContainer ] = useState< HTMLElement | null >( null );

	return (
		<PortalContainerContext.Provider value={ container }>
			{ container && children }
			<div id="mbk-portal-root" ref={ setContainer } />
		</PortalContainerContext.Provider>
	);
}
