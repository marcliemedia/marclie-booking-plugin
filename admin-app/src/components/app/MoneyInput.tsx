import * as React from 'react';

import { Input } from '@/components/ui/input';
import { centsToInput, parseMoneyInput } from '@/lib/format';
import { cn } from '@/lib/utils';

export interface MoneyInputProps
	extends Omit< React.InputHTMLAttributes< HTMLInputElement >, 'value' | 'onChange' | 'type' > {
	/** Amount in cents, or null when empty. */
	value: number | null;
	onChange: ( cents: number | null ) => void;
}

/** Euro amount input that reads "1.234,50" / "1234.50" and reports cents. */
export const MoneyInput = React.forwardRef< HTMLInputElement, MoneyInputProps >(
	( { value, onChange, className, onBlur, ...props }, ref ) => {
		const [ text, setText ] = React.useState( () => centsToInput( value ) );
		const lastValue = React.useRef( value );

		React.useEffect( () => {
			if ( value !== lastValue.current ) {
				lastValue.current = value;
				setText( centsToInput( value ) );
			}
		}, [ value ] );

		return (
			<div className={ cn( 'relative', className ) }>
				<Input
					ref={ ref }
					inputMode="decimal"
					autoComplete="off"
					className="pr-8 text-right tabular"
					value={ text }
					onChange={ ( event ) => {
						setText( event.target.value );
						const cents = parseMoneyInput( event.target.value );
						lastValue.current = cents;
						onChange( cents );
					} }
					onBlur={ ( event ) => {
						setText( centsToInput( parseMoneyInput( text ) ) );
						onBlur?.( event );
					} }
					{ ...props }
				/>
				<span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground">
					€
				</span>
			</div>
		);
	}
);
MoneyInput.displayName = 'MoneyInput';
