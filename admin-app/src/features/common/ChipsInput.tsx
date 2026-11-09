import { X } from '@phosphor-icons/react';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/**
 * Free-form token input: Enter, comma or blur adds the typed value, Backspace on an
 * empty field removes the last chip. `validate` returns an error message or null.
 */
export function ChipsInput( {
	id,
	value,
	onChange,
	placeholder,
	validate,
	normalize = ( v ) => v.trim(),
	suggestions,
	className,
	disabled = false,
}: {
	id: string;
	value: string[];
	onChange: ( next: string[] ) => void;
	placeholder?: string;
	validate?: ( v: string ) => string | null;
	normalize?: ( v: string ) => string;
	suggestions?: string[];
	className?: string;
	disabled?: boolean;
} ) {
	const [ draft, setDraft ] = useState( '' );
	const [ error, setError ] = useState< string | null >( null );

	const add = ( raw: string ) => {
		const v = normalize( raw );
		if ( ! v ) {
			return true;
		}
		if ( value.some( ( x ) => x.toLowerCase() === v.toLowerCase() ) ) {
			setDraft( '' );
			return true;
		}
		const problem = validate?.( v ) ?? null;
		if ( problem ) {
			setError( problem );
			return false;
		}
		onChange( [ ...value, v ] );
		setDraft( '' );
		setError( null );
		return true;
	};

	const remaining = suggestions?.filter( ( s ) => ! value.some( ( x ) => x.toLowerCase() === s.toLowerCase() ) ) ?? [];

	return (
		<div className={ cn( 'flex flex-col gap-1.5', className ) }>
			<div
				className={ cn(
					'flex min-h-9 flex-wrap items-center gap-1.5 rounded-md border border-input bg-surface px-2 py-1 focus-within:ring-2 focus-within:ring-ring/25',
					error && 'border-danger'
				) }
			>
				{ value.map( ( chip ) => (
					<span
						key={ chip }
						className="inline-flex items-center gap-1 rounded-full bg-surface-muted py-0.5 pl-2.5 pr-1 text-xs font-medium"
					>
						{ chip }
						<button
							type="button"
							className="rounded-full p-0.5 hover:bg-border focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
							disabled={ disabled }
							onClick={ () => onChange( value.filter( ( x ) => x !== chip ) ) }
							aria-label={ sprintf(
								/* translators: %s: chip value. */
								__( 'Remove %s', 'marclie-booking' ),
								chip
							) }
						>
							<X className="h-3 w-3" weight="bold" />
						</button>
					</span>
				) ) }
				<Input
					id={ id }
					value={ draft }
					disabled={ disabled }
					placeholder={ value.length === 0 ? placeholder : undefined }
					aria-invalid={ !! error }
					className="h-7 min-w-[8rem] flex-1 border-0 px-1 shadow-none focus-visible:ring-0"
					onChange={ ( e ) => {
						setDraft( e.target.value );
						setError( null );
					} }
					onKeyDown={ ( e ) => {
						if ( e.key === 'Enter' || e.key === ',' ) {
							e.preventDefault();
							add( draft );
						} else if ( e.key === 'Backspace' && draft === '' && value.length > 0 ) {
							onChange( value.slice( 0, -1 ) );
						}
					} }
					onBlur={ () => add( draft ) }
					onPaste={ ( e ) => {
						const text = e.clipboardData.getData( 'text/plain' );
						if ( /[,;\s]/.test( text.trim() ) ) {
							e.preventDefault();
							const parts = text.split( /[,;\s]+/ ).filter( Boolean );
							let next = [ ...value ];
							for ( const part of parts ) {
								const v = normalize( part );
								if ( v && ! validate?.( v ) && ! next.some( ( x ) => x.toLowerCase() === v.toLowerCase() ) ) {
									next = [ ...next, v ];
								}
							}
							onChange( next );
						}
					} }
				/>
			</div>
			{ error && (
				<p className="text-xs text-danger" role="alert">
					{ error }
				</p>
			) }
			{ remaining.length > 0 && (
				<div className="flex flex-wrap gap-1.5">
					{ remaining.map( ( s ) => (
						<button
							key={ s }
							type="button"
							disabled={ disabled }
							onClick={ () => onChange( [ ...value, s ] ) }
							className="rounded-full border border-dashed border-border px-2 py-0.5 text-xs text-muted-foreground hover:border-solid hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
						>
							+ { s }
						</button>
					) ) }
				</div>
			) }
		</div>
	);
}
