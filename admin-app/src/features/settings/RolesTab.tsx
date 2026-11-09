import { Trash, UserPlus, Users } from '@phosphor-icons/react';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { RoleUser } from '@/api/hooks/settings';
import { useAddRole, useRemoveRole, useRoles } from '@/api/hooks/settings';
import { ConfirmDialog } from '@/components/app/ConfirmDialog';
import { EmptyState } from '@/components/app/EmptyState';
import { ErrorState } from '@/components/app/ErrorState';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { toast } from '@/components/ui/sonner';
import { applyFieldErrors } from '@/lib/formErrors';
import { toastError } from '@/features/common/toastError';

export function RolesTab() {
	const roles = useRoles();
	const add = useAddRole();
	const remove = useRemoveRole();
	const [ email, setEmail ] = useState( '' );
	const [ emailError, setEmailError ] = useState< string | null >( null );
	const [ removing, setRemoving ] = useState< RoleUser | null >( null );

	const submit = async ( e: React.FormEvent ) => {
		e.preventDefault();
		if ( ! email.trim() ) {
			return;
		}
		try {
			await add.mutateAsync( email.trim() );
			toast.success( __( 'Booking manager added.', 'marclie-booking' ) );
			setEmail( '' );
		} catch ( err ) {
			if ( ! applyFieldErrors( err, ( _name, { message } ) => setEmailError( message ) ) ) {
				toastError( err );
			}
		}
	};

	return (
		<Card>
			<CardHeader>
				<div>
					<CardTitle>{ __( 'Users & roles', 'marclie-booking' ) }</CardTitle>
					<CardDescription>
						{ __(
							'Booking managers can manage bookings, calendar, guests, payments, rates and coupons, but not settings, Stripe or user roles.',
							'marclie-booking'
						) }
					</CardDescription>
				</div>
			</CardHeader>
			<CardContent className="flex flex-col gap-4">
				<form onSubmit={ submit } className="flex flex-col gap-1.5">
					<Label htmlFor="role-email">{ __( 'Add an existing WordPress user by e-mail', 'marclie-booking' ) }</Label>
					<div className="flex flex-col gap-2 sm:flex-row">
						<Input
							id="role-email"
							type="email"
							value={ email }
							placeholder="name@example.com"
							onChange={ ( e ) => {
								setEmail( e.target.value );
								setEmailError( null );
							} }
							aria-invalid={ !! emailError }
							aria-describedby={ emailError ? 'role-email-error' : undefined }
							className="sm:max-w-sm"
						/>
						<Button type="submit" variant="secondary" disabled={ ! email.trim() || add.isPending }>
							<UserPlus className="h-4 w-4" aria-hidden="true" />
							{ __( 'Add booking manager', 'marclie-booking' ) }
						</Button>
					</div>
					{ emailError && (
						<p id="role-email-error" className="text-xs text-danger" role="alert">
							{ emailError }
						</p>
					) }
					<p className="text-xs text-muted-foreground">
						{ __( 'The user must already have a WordPress account (Users › Add New).', 'marclie-booking' ) }
					</p>
				</form>

				{ roles.isPending && <Skeleton className="h-24 w-full" /> }
				{ roles.isError && <ErrorState error={ roles.error } onRetry={ () => roles.refetch() } /> }
				{ roles.data && roles.data.users.length === 0 && (
					<EmptyState
						compact
						icon={ Users }
						title={ __( 'No booking managers yet', 'marclie-booking' ) }
						description={ __( 'Administrators always have full access.', 'marclie-booking' ) }
					/>
				) }
				{ roles.data && roles.data.users.length > 0 && (
					<ul className="divide-y divide-border rounded-md border border-border">
						{ roles.data.users.map( ( user ) => (
							<li key={ user.id } className="flex items-center justify-between gap-3 px-3 py-2.5">
								<div className="min-w-0">
									<p className="truncate text-sm font-medium">{ user.name }</p>
									<p className="truncate text-xs text-muted-foreground">{ user.email }</p>
								</div>
								<Button
									variant="ghost"
									size="icon-sm"
									onClick={ () => setRemoving( user ) }
									aria-label={ sprintf(
										/* translators: %s: user name. */
										__( 'Remove booking manager role from %s', 'marclie-booking' ),
										user.name
									) }
								>
									<Trash className="h-4 w-4" />
								</Button>
							</li>
						) ) }
					</ul>
				) }
			</CardContent>
			<ConfirmDialog
				open={ !! removing }
				onOpenChange={ ( open ) => ! open && setRemoving( null ) }
				title={ __( 'Remove booking manager?', 'marclie-booking' ) }
				description={
					removing &&
					sprintf(
						/* translators: %s: user name. */
						__( '%s will lose access to the booking app. Their WordPress account is not deleted.', 'marclie-booking' ),
						removing.name
					)
				}
				confirmLabel={ __( 'Remove', 'marclie-booking' ) }
				variant="destructive"
				onConfirm={ async () => {
					if ( ! removing ) {
						return;
					}
					try {
						await remove.mutateAsync( removing.id );
						toast.success( __( 'Role removed.', 'marclie-booking' ) );
					} catch ( err ) {
						toastError( err );
						throw err;
					}
				} }
			/>
		</Card>
	);
}
