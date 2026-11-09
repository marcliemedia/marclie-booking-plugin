import { Warning } from '@phosphor-icons/react';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { AdvancedSettings } from '@/api/hooks/settings';
import { useUpdateSettings } from '@/api/hooks/settings';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { toast } from '@/components/ui/sonner';
import { toastError } from '@/features/common/toastError';

export function AdvancedTab( { advanced }: { advanced: AdvancedSettings } ) {
	const update = useUpdateSettings( 'advanced' );
	const [ values, setValues ] = useState( advanced );

	useEffect( () => setValues( advanced ), [ advanced ] );

	const dirty =
		values.delete_data_on_uninstall !== advanced.delete_data_on_uninstall || values.debug_log !== advanced.debug_log;

	const save = async () => {
		try {
			await update.mutateAsync( { ...values } );
			toast.success( __( 'Advanced settings saved.', 'marclie-booking' ) );
		} catch ( e ) {
			toastError( e );
		}
	};

	return (
		<Card>
			<CardHeader>
				<div>
					<CardTitle>{ __( 'Advanced', 'marclie-booking' ) }</CardTitle>
					<CardDescription>{ __( 'Maintenance options. Change only if you know why.', 'marclie-booking' ) }</CardDescription>
				</div>
			</CardHeader>
			<CardContent className="flex flex-col gap-5">
				<div className="flex items-start justify-between gap-4">
					<div className="flex flex-col gap-1">
						<Label htmlFor="adv-delete">{ __( 'Delete all data when the plugin is uninstalled', 'marclie-booking' ) }</Label>
						<p className="text-xs text-muted-foreground" id="adv-delete-hint">
							{ __( 'Removes bookings, guests, payments, rates, coupons and settings when the plugin is deleted from Plugins.', 'marclie-booking' ) }
						</p>
					</div>
					<Switch
						id="adv-delete"
						aria-describedby="adv-delete-hint"
						checked={ values.delete_data_on_uninstall }
						onCheckedChange={ ( v ) => setValues( ( s ) => ( { ...s, delete_data_on_uninstall: v } ) ) }
					/>
				</div>
				{ values.delete_data_on_uninstall && (
					<div role="alert" className="flex items-start gap-2 rounded-md bg-danger-soft px-3 py-2 text-sm text-danger">
						<Warning className="mt-0.5 h-4 w-4 shrink-0" weight="bold" aria-hidden="true" />
						{ __(
							'Deleting the plugin will permanently erase all booking and financial records. Export what you need for accounting first. Deactivating keeps the data.',
							'marclie-booking'
						) }
					</div>
				) }
				<div className="flex items-start justify-between gap-4">
					<div className="flex flex-col gap-1">
						<Label htmlFor="adv-debug">{ __( 'Debug log', 'marclie-booking' ) }</Label>
						<p className="text-xs text-muted-foreground" id="adv-debug-hint">
							{ __( 'Writes detailed booking, payment and e-mail diagnostics to the PHP error log. Never contains Stripe keys or card data.', 'marclie-booking' ) }
						</p>
					</div>
					<Switch
						id="adv-debug"
						aria-describedby="adv-debug-hint"
						checked={ values.debug_log }
						onCheckedChange={ ( v ) => setValues( ( s ) => ( { ...s, debug_log: v } ) ) }
					/>
				</div>
				<div className="flex justify-end gap-2">
					<Button variant="outline" disabled={ ! dirty || update.isPending } onClick={ () => setValues( advanced ) }>
						{ __( 'Reset', 'marclie-booking' ) }
					</Button>
					<Button onClick={ save } disabled={ ! dirty || update.isPending }>
						{ update.isPending ? __( 'Saving…', 'marclie-booking' ) : __( 'Save', 'marclie-booking' ) }
					</Button>
				</div>
			</CardContent>
		</Card>
	);
}
