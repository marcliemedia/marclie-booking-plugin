import type { ReactNode } from 'react';

import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

/** Label + control + error/hint stack used by the module forms (guests, rates, coupons, settings). */
export function FormField( {
	id,
	label,
	error,
	hint,
	children,
	className,
}: {
	id: string;
	label: ReactNode;
	error?: string;
	hint?: ReactNode;
	children: ReactNode;
	className?: string;
} ) {
	return (
		<div className={ cn( 'flex flex-col gap-1.5', className ) }>
			<Label htmlFor={ id }>{ label }</Label>
			{ children }
			{ error ? (
				<p className="text-xs text-danger" role="alert" id={ `${ id }-error` }>
					{ error }
				</p>
			) : (
				hint && (
					<p className="text-xs text-muted-foreground" id={ `${ id }-hint` }>
						{ hint }
					</p>
				)
			) }
		</div>
	);
}
