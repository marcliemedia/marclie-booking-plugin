import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/api/client';
import { settingsKeys } from '@/api/hooks/settings';
import type {
	EmailContent,
	EmailLang,
	EmailPreview,
	EmailPreviewInput,
	EmailTemplateDetail,
	EmailTemplateListItem,
} from '@/api/types';

export const emailKeys = {
	all: [ 'email-templates' ] as const,
	list: () => [ 'email-templates', 'list' ] as const,
	detail: ( code: string ) => [ 'email-templates', 'detail', code ] as const,
	preview: ( code: string, input: EmailPreviewInput ) => [ 'email-templates', 'preview', code, input ] as const,
};

const path = ( code: string ) => `/email-templates/${ encodeURIComponent( code ) }`;

export function useEmailTemplates( enabled = true ) {
	return useQuery( {
		queryKey: emailKeys.list(),
		queryFn: () => api.get< EmailTemplateListItem[] >( '/email-templates' ),
		enabled,
	} );
}

export function useEmailTemplate( code: string ) {
	return useQuery( {
		queryKey: emailKeys.detail( code ),
		queryFn: () => api.get< EmailTemplateDetail >( path( code ) ),
		enabled: code !== '',
	} );
}

/** Rendered e-mail for the editor's live preview (draft = unsaved content of that language). */
export function useEmailPreview( code: string, input: EmailPreviewInput, enabled = true ) {
	return useQuery( {
		queryKey: emailKeys.preview( code, input ),
		queryFn: () => api.post< EmailPreview >( `${ path( code ) }/preview`, input ),
		enabled,
		placeholderData: keepPreviousData,
		staleTime: 60_000,
	} );
}

export function useSaveEmailTemplate( code: string ) {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( { lang, content }: { lang: EmailLang; content: EmailContent } ) =>
			api.put< EmailTemplateDetail >( `${ path( code ) }/${ lang }`, content ),
		onSuccess: ( detail ) => {
			client.setQueryData( emailKeys.detail( code ), detail );
			void client.invalidateQueries( { queryKey: emailKeys.list() } );
		},
	} );
}

/** Drop the override of one language (back to the default shipped with the plugin). */
export function useResetEmailTemplate( code: string ) {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( lang: EmailLang ) => api.del< unknown >( `${ path( code ) }/${ lang }` ),
		onSuccess: () => {
			void client.invalidateQueries( { queryKey: emailKeys.detail( code ) } );
			void client.invalidateQueries( { queryKey: emailKeys.list() } );
		},
	} );
}

/** Turn automatic sending of a template on/off (optimistic in the list). */
export function useToggleEmailTemplate() {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( { code, enabled }: { code: string; enabled: boolean } ) => api.patch< unknown >( path( code ), { enabled } ),
		onMutate: async ( { code, enabled } ) => {
			await client.cancelQueries( { queryKey: emailKeys.list() } );
			const previous = client.getQueryData< EmailTemplateListItem[] >( emailKeys.list() );
			client.setQueryData< EmailTemplateListItem[] >( emailKeys.list(), ( items ) =>
				items?.map( ( item ) => ( item.code === code ? { ...item, enabled } : item ) )
			);
			client.setQueryData< EmailTemplateDetail >( emailKeys.detail( code ), ( detail ) => ( detail ? { ...detail, enabled } : detail ) );
			return { previous };
		},
		onError: ( _error, { code }, context ) => {
			if ( context?.previous ) {
				client.setQueryData( emailKeys.list(), context.previous );
			}
			void client.invalidateQueries( { queryKey: emailKeys.detail( code ) } );
		},
		onSettled: () => {
			void client.invalidateQueries( { queryKey: emailKeys.list() } );
			// The on/off list lives in settings › notifications (`disabled_codes`).
			void client.invalidateQueries( { queryKey: settingsKeys.settings } );
		},
	} );
}

export interface SendTestInput extends EmailPreviewInput {
	to: string;
}

export function useSendTestEmail( code: string ) {
	return useMutation( {
		mutationFn: ( input: SendTestInput ) => api.post< { sent: boolean } >( `${ path( code ) }/test`, input ),
	} );
}

/** Send a guest template for a booking again (logged as an `email_sent` event). */
export function useResendBookingEmail( bookingId: number ) {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( code: string ) =>
			api.post< { sent: boolean } >( `/bookings/${ bookingId }/emails/${ encodeURIComponent( code ) }/resend` ),
		onSettled: () => void client.invalidateQueries( { queryKey: [ 'bookings', 'detail', bookingId ] } ),
	} );
}

export interface GuestEmailInput {
	subject: string;
	body_html: string;
	lang?: EmailLang;
}

/** Free-form e-mail from the guest profile (template `guest_message`). */
export function useSendGuestEmail( guestId: number ) {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( input: GuestEmailInput ) => api.post< { sent: boolean } >( `/guests/${ guestId }/email`, input ),
		onSuccess: () => void client.invalidateQueries( { queryKey: [ 'guests', guestId ] } ),
	} );
}
