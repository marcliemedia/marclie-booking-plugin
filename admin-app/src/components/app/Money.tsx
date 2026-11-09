import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';

/** Formatted amount from integer cents, tabular figures. */
export function Money( {
	cents,
	className,
	signed = false,
	muted = false,
}: {
	cents: number | null | undefined;
	className?: string;
	/** Prefix positive amounts with "+". */
	signed?: boolean;
	/** Render zero in muted colour. */
	muted?: boolean;
} ) {
	if ( cents === null || cents === undefined ) {
		return <span className={ cn( 'tabular text-muted-foreground', className ) }>—</span>;
	}
	return (
		<span className={ cn( 'tabular whitespace-nowrap', muted && cents === 0 && 'text-muted-foreground', className ) }>
			{ signed && cents > 0 ? '+' : '' }
			{ formatMoney( cents ) }
		</span>
	);
}
