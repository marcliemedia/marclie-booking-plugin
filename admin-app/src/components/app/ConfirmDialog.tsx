import { CircleNotch } from '@phosphor-icons/react';
import { __ } from '@wordpress/i18n';
import * as React from 'react';

import { Money } from '@/components/app/Money';
import {
	AlertDialog,
	AlertDialogCancel,
	AlertDialogContent,
	AlertDialogDescription,
	AlertDialogFooter,
	AlertDialogHeader,
	AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export interface ConfirmAmountLine {
	label: React.ReactNode;
	cents: number;
	signed?: boolean;
	strong?: boolean;
}

export interface ConfirmDialogProps {
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
	title: React.ReactNode;
	description?: React.ReactNode;
	/** Exact amounts shown in a summary box (money actions MUST pass these). */
	amounts?: ConfirmAmountLine[];
	/** Extra content (form fields, warnings) between the summary and the buttons. */
	children?: React.ReactNode;
	confirmLabel?: React.ReactNode;
	cancelLabel?: React.ReactNode;
	variant?: 'default' | 'destructive' | 'ink';
	/** Resolve to close; throw/reject to keep the dialog open. */
	onConfirm: () => unknown | Promise< unknown >;
	loading?: boolean;
	confirmDisabled?: boolean;
}

export function ConfirmDialog( {
	open,
	onOpenChange,
	title,
	description,
	amounts,
	children,
	confirmLabel,
	cancelLabel,
	variant = 'default',
	onConfirm,
	loading: loadingProp,
	confirmDisabled,
}: ConfirmDialogProps ) {
	const [ pending, setPending ] = React.useState( false );
	const loading = loadingProp ?? pending;

	const handleConfirm = async () => {
		setPending( true );
		try {
			await onConfirm();
			onOpenChange( false );
		} catch {
			// Caller reports the error (toast); keep the dialog open.
		} finally {
			setPending( false );
		}
	};

	return (
		<AlertDialog open={ open } onOpenChange={ ( next ) => ! loading && onOpenChange( next ) }>
			<AlertDialogContent>
				<AlertDialogHeader>
					<AlertDialogTitle>{ title }</AlertDialogTitle>
					{ description ? (
						<AlertDialogDescription>{ description }</AlertDialogDescription>
					) : (
						<AlertDialogDescription className="sr-only">{ title }</AlertDialogDescription>
					) }
				</AlertDialogHeader>
				{ amounts && amounts.length > 0 && (
					<dl className="divide-y divide-border rounded-lg border border-border bg-surface-muted/50 px-4">
						{ amounts.map( ( line, index ) => (
							<div
								key={ index }
								className={ cn( 'flex items-center justify-between gap-4 py-2 text-sm', line.strong && 'font-semibold text-ink' ) }
							>
								<dt className={ cn( ! line.strong && 'text-muted-foreground' ) }>{ line.label }</dt>
								<dd>
									<Money cents={ line.cents } signed={ line.signed } />
								</dd>
							</div>
						) ) }
					</dl>
				) }
				{ children }
				<AlertDialogFooter>
					<AlertDialogCancel disabled={ loading }>{ cancelLabel ?? __( 'Cancel', 'marclie-booking' ) }</AlertDialogCancel>
					<Button variant={ variant } onClick={ handleConfirm } disabled={ loading || confirmDisabled }>
						{ loading && <CircleNotch className="h-4 w-4 animate-spin" /> }
						{ confirmLabel ?? __( 'Confirm', 'marclie-booking' ) }
					</Button>
				</AlertDialogFooter>
			</AlertDialogContent>
		</AlertDialog>
	);
}
