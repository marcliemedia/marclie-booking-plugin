import { Check, Minus } from '@phosphor-icons/react';
import * as CheckboxPrimitive from '@radix-ui/react-checkbox';
import * as React from 'react';

import { cn } from '@/lib/utils';

export const Checkbox = React.forwardRef<
	React.ElementRef< typeof CheckboxPrimitive.Root >,
	React.ComponentPropsWithoutRef< typeof CheckboxPrimitive.Root >
>( ( { className, checked, ...props }, ref ) => (
	<CheckboxPrimitive.Root
		ref={ ref }
		checked={ checked }
		className={ cn(
			'peer h-4 w-4 shrink-0 rounded-[4px] border border-input bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:border-ink-soft data-[state=checked]:bg-ink-soft data-[state=checked]:text-white data-[state=indeterminate]:border-ink-soft data-[state=indeterminate]:bg-ink-soft data-[state=indeterminate]:text-white',
			className
		) }
		{ ...props }
	>
		<CheckboxPrimitive.Indicator className="flex items-center justify-center text-current">
			{ checked === 'indeterminate' ? (
				<Minus className="h-3 w-3" weight="bold" />
			) : (
				<Check className="h-3 w-3" weight="bold" />
			) }
		</CheckboxPrimitive.Indicator>
	</CheckboxPrimitive.Root>
) );
Checkbox.displayName = CheckboxPrimitive.Root.displayName;
