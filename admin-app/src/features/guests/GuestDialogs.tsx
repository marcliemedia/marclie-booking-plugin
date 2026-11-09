import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { GuestDetail } from '@/api/hooks/guests';
import { useAnonymizeGuest, useMergeGuest } from '@/api/hooks/guests';
import { navigate } from '@/app/router';
import { ConfirmDialog } from '@/components/app/ConfirmDialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toast } from '@/components/ui/sonner';
import type { PickedGuest } from '@/features/common/GuestPicker';
import { GuestPicker, guestName } from '@/features/common/GuestPicker';
import { toastError } from '@/features/common/toastError';

export function MergeGuestDialog( {
	guest,
	open,
	onOpenChange,
}: {
	guest: GuestDetail;
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
} ) {
	const merge = useMergeGuest( guest.id );
	const [ target, setTarget ] = useState< PickedGuest | null >( null );

	useEffect( () => {
		if ( open ) {
			setTarget( null );
		}
	}, [ open ] );

	return (
		<ConfirmDialog
			open={ open }
			onOpenChange={ onOpenChange }
			title={ __( 'Merge guest profiles', 'marclie-booking' ) }
			description={ sprintf(
				/* translators: %s: guest name. */
				__( 'Bookings, payments and coupons of %s move to the profile you choose; this profile is then removed. This cannot be undone.', 'marclie-booking' ),
				guestName( guest )
			) }
			confirmLabel={ __( 'Merge', 'marclie-booking' ) }
			variant="destructive"
			confirmDisabled={ ! target }
			onConfirm={ async () => {
				if ( ! target ) {
					return;
				}
				try {
					const into = await merge.mutateAsync( target.id );
					toast.success(
						sprintf(
							/* translators: %s: guest name. */
							__( 'Merged into %s.', 'marclie-booking' ),
							guestName( into )
						)
					);
					navigate( `/guests/${ into.id }`, { replace: true } );
				} catch ( e ) {
					toastError( e );
					throw e;
				}
			} }
		>
			<div className="flex flex-col gap-1.5">
				<Label htmlFor="merge-target">{ __( 'Keep this profile', 'marclie-booking' ) }</Label>
				<GuestPicker id="merge-target" value={ target } onChange={ setTarget } excludeId={ guest.id } />
			</div>
		</ConfirmDialog>
	);
}

const CONFIRM_WORD = 'ANONYMIZE';

export function AnonymizeGuestDialog( {
	guest,
	open,
	onOpenChange,
}: {
	guest: GuestDetail;
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
} ) {
	const anonymize = useAnonymizeGuest( guest.id );
	const [ typed, setTyped ] = useState( '' );

	useEffect( () => {
		if ( open ) {
			setTyped( '' );
		}
	}, [ open ] );

	return (
		<ConfirmDialog
			open={ open }
			onOpenChange={ onOpenChange }
			title={ __( 'Anonymize guest (GDPR)', 'marclie-booking' ) }
			description={ __(
				'Name, e-mail, phone, notes and tags are erased permanently. Bookings and payments stay for accounting but no longer show personal data. Export the data first if the guest asked for a copy.',
				'marclie-booking'
			) }
			confirmLabel={ __( 'Anonymize', 'marclie-booking' ) }
			variant="destructive"
			confirmDisabled={ typed !== CONFIRM_WORD }
			onConfirm={ async () => {
				try {
					await anonymize.mutateAsync();
					toast.success( __( 'Guest anonymized.', 'marclie-booking' ) );
				} catch ( e ) {
					toastError( e );
					throw e;
				}
			} }
		>
			<div className="flex flex-col gap-1.5">
				<Label htmlFor="anonymize-confirm">
					{ sprintf(
						/* translators: %s: the word to type. */
						__( 'Type %s to confirm', 'marclie-booking' ),
						CONFIRM_WORD
					) }
				</Label>
				<Input
					id="anonymize-confirm"
					value={ typed }
					onChange={ ( e ) => setTyped( e.target.value ) }
					autoComplete="off"
					spellCheck={ false }
				/>
			</div>
		</ConfirmDialog>
	);
}
