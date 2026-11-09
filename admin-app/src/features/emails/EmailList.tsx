import { CaretRight, EnvelopeSimple } from '@phosphor-icons/react';
import { __, sprintf } from '@wordpress/i18n';

import { useEmailTemplates, useToggleEmailTemplate } from '@/api/hooks/emails';
import type { EmailLang, EmailRecipient, EmailTemplateListItem } from '@/api/types';
import { Link } from '@/app/router';
import { EmptyState } from '@/components/app/EmptyState';
import { ErrorState } from '@/components/app/ErrorState';
import { PageHeader } from '@/components/app/PageHeader';
import { Badge } from '@/components/ui/badge';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { toastError } from '@/features/common/toastError';
import { formatDateTime, formatRelative } from '@/lib/format';

const LANGS: EmailLang[] = [ 'hr', 'en' ];

function LangBadges( { item }: { item: EmailTemplateListItem } ) {
	return (
		<div className="flex flex-wrap items-center gap-1.5">
			{ LANGS.map( ( lang ) => {
				const meta = item.langs[ lang ];
				return meta.customized ? (
					<Badge
						key={ lang }
						variant="accent"
						title={ meta.updated_at ? formatDateTime( meta.updated_at ) : undefined }
					>
						{ sprintf(
							/* translators: 1: language code (HR/EN), 2: relative time, e.g. "2 days ago". */
							__( '%1$s · edited %2$s', 'marclie-booking' ),
							lang.toUpperCase(),
							meta.updated_at ? formatRelative( meta.updated_at ) : ''
						).trim() }
					</Badge>
				) : (
					<Badge key={ lang } variant="muted">
						{ sprintf(
							/* translators: %s: language code (HR/EN). */
							__( '%s · default', 'marclie-booking' ),
							lang.toUpperCase()
						) }
					</Badge>
				);
			} ) }
		</div>
	);
}

function TemplateRow( { item }: { item: EmailTemplateListItem } ) {
	const toggle = useToggleEmailTemplate();
	const switchId = `mbk-email-enabled-${ item.code }`;
	return (
		<li className="flex items-center gap-4 px-5 py-4">
			<div className="flex min-w-0 flex-1 flex-col gap-1.5">
				<Link
					to={ `/emails/${ item.code }` }
					className="group inline-flex items-center gap-1 self-start rounded text-sm font-semibold text-ink hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
				>
					{ item.label }
					<CaretRight className="h-3.5 w-3.5 text-muted-foreground transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
				</Link>
				<p className="text-xs text-muted-foreground">{ item.description }</p>
				<LangBadges item={ item } />
			</div>
			<div className="flex shrink-0 items-center gap-2">
				<label htmlFor={ switchId } className="hidden text-xs text-muted-foreground sm:inline">
					{ item.enabled ? __( 'On', 'marclie-booking' ) : __( 'Off', 'marclie-booking' ) }
				</label>
				<Switch
					id={ switchId }
					checked={ item.enabled }
					aria-label={ sprintf(
						/* translators: %s: e-mail template name. */
						__( 'Send “%s”', 'marclie-booking' ),
						item.label
					) }
					onCheckedChange={ ( enabled ) => toggle.mutate( { code: item.code, enabled }, { onError: toastError } ) }
				/>
			</div>
		</li>
	);
}

function Group( { recipient, items }: { recipient: EmailRecipient; items: EmailTemplateListItem[] } ) {
	if ( items.length === 0 ) {
		return null;
	}
	return (
		<Card>
			<CardHeader>
				<div>
					<CardTitle>{ recipient === 'guest' ? __( 'To guests', 'marclie-booking' ) : __( 'To you (admin)', 'marclie-booking' ) }</CardTitle>
					<CardDescription>
						{ recipient === 'guest'
							? __( 'Sent in the language of the booking (Croatian or English).', 'marclie-booking' )
							: __( 'Sent to the admin recipients from Settings › Notifications.', 'marclie-booking' ) }
					</CardDescription>
				</div>
			</CardHeader>
			<ul className="divide-y divide-border border-t border-border">
				{ items.map( ( item ) => (
					<TemplateRow key={ item.code } item={ item } />
				) ) }
			</ul>
		</Card>
	);
}

export function EmailList() {
	const templates = useEmailTemplates();

	return (
		<div className="mx-auto w-full max-w-4xl">
			<PageHeader
				title={ __( 'Emails', 'marclie-booking' ) }
				description={ __( 'Automatic e-mails for guests and for you. Edit the Croatian and English text, preview it and switch off what you do not need.', 'marclie-booking' ) }
			/>
			{ templates.isPending && (
				<div className="flex flex-col gap-4">
					<Skeleton className="h-72 w-full" />
					<Skeleton className="h-40 w-full" />
				</div>
			) }
			{ templates.isError && <ErrorState error={ templates.error } onRetry={ () => templates.refetch() } /> }
			{ templates.data && templates.data.length === 0 && (
				<EmptyState icon={ EnvelopeSimple } title={ __( 'No e-mail templates', 'marclie-booking' ) } />
			) }
			{ templates.data && templates.data.length > 0 && (
				<div className="flex flex-col gap-5">
					<Group recipient="guest" items={ templates.data.filter( ( item ) => item.recipient === 'guest' ) } />
					<Group recipient="admin" items={ templates.data.filter( ( item ) => item.recipient === 'admin' ) } />
				</div>
			) }
		</div>
	);
}
