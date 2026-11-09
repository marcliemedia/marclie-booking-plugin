import { Check, Copy } from '@phosphor-icons/react';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { Button, type ButtonProps } from '@/components/ui/button';
import { toast } from '@/components/ui/sonner';

/** Copies `text` to the clipboard and briefly shows a check mark. */
export function CopyButton( {
	text,
	label = __( 'Copy', 'marclie-booking' ),
	...props
}: { text: string; label?: string } & Omit< ButtonProps, 'onClick' > ) {
	const [ copied, setCopied ] = useState( false );

	useEffect( () => {
		if ( ! copied ) {
			return;
		}
		const t = window.setTimeout( () => setCopied( false ), 1600 );
		return () => window.clearTimeout( t );
	}, [ copied ] );

	const copy = async () => {
		try {
			await navigator.clipboard.writeText( text );
			setCopied( true );
		} catch {
			toast.error( __( 'Copy failed — select the text and copy it manually.', 'marclie-booking' ) );
		}
	};

	return (
		<Button variant="outline" size="sm" onClick={ copy } { ...props }>
			{ copied ? <Check className="h-4 w-4" weight="bold" /> : <Copy className="h-4 w-4" /> }
			<span aria-live="polite">{ copied ? __( 'Copied', 'marclie-booking' ) : label }</span>
		</Button>
	);
}
