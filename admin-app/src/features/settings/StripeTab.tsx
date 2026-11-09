import { ArrowSquareOut, CheckCircle, PlugsConnected, Warning, XCircle } from '@phosphor-icons/react';
import { __, sprintf } from '@wordpress/i18n';

import type { StripeKeyState, StripeStatus } from '@/api/hooks/settings';
import { useStripeStatus, useVerifyStripe } from '@/api/hooks/settings';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { toast } from '@/components/ui/sonner';
import { ErrorState } from '@/components/app/ErrorState';
import { CopyButton } from '@/features/common/CopyButton';
import { toastError } from '@/features/common/toastError';
import { formatDateTime, formatRelative } from '@/lib/format';

const CONSTANTS = {
	publishable: 'MBK_STRIPE_PUBLISHABLE_KEY',
	secret: 'MBK_STRIPE_SECRET_KEY',
	webhook: 'MBK_STRIPE_WEBHOOK_SECRET',
} as const;

const PREFIX = { publishable: 'pk', secret: 'sk', webhook: 'whsec' } as const;

function keyLabel( key: keyof typeof CONSTANTS ): string {
	switch ( key ) {
		case 'publishable':
			return __( 'Publishable key', 'marclie-booking' );
		case 'secret':
			return __( 'Secret key', 'marclie-booking' );
		default:
			return __( 'Webhook signing secret', 'marclie-booking' );
	}
}

function maskedKey( key: keyof typeof CONSTANTS, state: StripeKeyState ): string {
	const mode = key === 'webhook' ? '' : `${ state.mode ?? 'test' }_`;
	return `${ PREFIX[ key ] }_${ mode }…${ state.last4 ?? '' }`;
}

function snippet( mode: StripeStatus[ 'mode' ] ): string {
	const m = mode ?? 'test';
	return [
		"// wp-config.php — above \"That's all, stop editing!\"",
		`define( 'MBK_STRIPE_PUBLISHABLE_KEY', 'pk_${ m }_…' );`,
		`define( 'MBK_STRIPE_SECRET_KEY', 'sk_${ m }_…' );`,
		"define( 'MBK_STRIPE_WEBHOOK_SECRET', 'whsec_…' );",
	].join( '\n' );
}

export function StripeTab() {
	const status = useStripeStatus();
	const verify = useVerifyStripe();

	if ( status.isPending ) {
		return (
			<div className="flex flex-col gap-4">
				<Skeleton className="h-40 w-full" />
				<Skeleton className="h-56 w-full" />
			</div>
		);
	}
	if ( status.isError ) {
		return <ErrorState error={ status.error } onRetry={ () => status.refetch() } />;
	}

	const s = status.data;
	const lastVerify = verify.data ?? s.last_verify;

	const runVerify = async () => {
		try {
			const res = await verify.mutateAsync();
			if ( res.ok ) {
				toast.success( __( 'Connected to Stripe.', 'marclie-booking' ) );
			} else {
				toast.error( res.error ?? __( 'Stripe connection failed.', 'marclie-booking' ) );
			}
		} catch ( e ) {
			toastError( e );
		}
	};

	return (
		<div className="flex flex-col gap-4">
			<Card>
				<CardHeader className="flex-wrap">
					<div className="flex flex-col gap-1">
						<CardTitle className="flex items-center gap-2">
							{ __( 'Stripe connection', 'marclie-booking' ) }
							{ s.mode === 'live' && <Badge variant="success-strong">{ __( 'LIVE', 'marclie-booking' ) }</Badge> }
							{ s.mode === 'test' && <Badge variant="warning">{ __( 'TEST', 'marclie-booking' ) }</Badge> }
							{ s.mode === null && <Badge variant="muted">{ __( 'Not configured', 'marclie-booking' ) }</Badge> }
						</CardTitle>
						<CardDescription>
							{ __(
								'Keys are set by your developer in wp-config.php and are never shown or stored in the database. This screen is read-only.',
								'marclie-booking'
							) }
						</CardDescription>
					</div>
					{ s.dashboard_url && (
						<Button variant="outline" size="sm" asChild>
							<a href={ s.dashboard_url } target="_blank" rel="noreferrer noopener">
								{ __( 'Stripe Dashboard', 'marclie-booking' ) }
								<ArrowSquareOut className="h-4 w-4" aria-hidden="true" />
								<span className="sr-only">{ __( '(opens in a new tab)', 'marclie-booking' ) }</span>
							</a>
						</Button>
					) }
				</CardHeader>
				<CardContent className="flex flex-col gap-4">
					{ s.mismatch && (
						<div role="alert" className="flex items-start gap-2 rounded-md bg-danger-soft px-3 py-2 text-sm text-danger">
							<Warning className="mt-0.5 h-4 w-4 shrink-0" weight="bold" aria-hidden="true" />
							{ __(
								'The publishable and secret keys belong to different modes (test vs live). Checkout is blocked until both use the same mode.',
								'marclie-booking'
							) }
						</div>
					) }
					<ul className="divide-y divide-border rounded-md border border-border">
						{ ( Object.keys( CONSTANTS ) as ( keyof typeof CONSTANTS )[] ).map( ( key ) => {
							const state = s.keys[ key ];
							return (
								<li key={ key } className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2.5 text-sm">
									{ state.set ? (
										<CheckCircle className="h-5 w-5 text-success" weight="fill" aria-hidden="true" />
									) : (
										<XCircle className="h-5 w-5 text-danger" weight="fill" aria-hidden="true" />
									) }
									<span className="font-medium">{ keyLabel( key ) }</span>
									<span className="sr-only">
										{ state.set ? __( 'configured', 'marclie-booking' ) : __( 'missing', 'marclie-booking' ) }
									</span>
									{ state.set ? (
										<code className="rounded bg-surface-muted px-1.5 py-0.5 text-xs">{ maskedKey( key, state ) }</code>
									) : (
										<span className="text-muted-foreground">
											{ sprintf(
												/* translators: %s: PHP constant name. */
												__( 'Developer needs to add %s to wp-config.php.', 'marclie-booking' ),
												CONSTANTS[ key ]
											) }
										</span>
									) }
								</li>
							);
						} ) }
					</ul>

					<div className="flex flex-col gap-3 rounded-md border border-border p-3 sm:flex-row sm:items-start sm:justify-between">
						<div className="flex flex-col gap-1 text-sm">
							<p className="font-medium">{ __( 'Connection check', 'marclie-booking' ) }</p>
							{ ! lastVerify && <p className="text-muted-foreground">{ __( 'Not checked yet.', 'marclie-booking' ) }</p> }
							{ lastVerify && lastVerify.ok && lastVerify.account && (
								<dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-0.5">
									<dt className="text-muted-foreground">{ __( 'Account', 'marclie-booking' ) }</dt>
									<dd>
										{ lastVerify.account.name || lastVerify.account.id }{ ' ' }
										<span className="text-muted-foreground">({ lastVerify.account.id })</span>
									</dd>
									<dt className="text-muted-foreground">{ __( 'Country', 'marclie-booking' ) }</dt>
									<dd>{ lastVerify.account.country }</dd>
									<dt className="text-muted-foreground">{ __( 'Charges', 'marclie-booking' ) }</dt>
									<dd>
										<YesNo value={ lastVerify.account.charges_enabled } />
									</dd>
									<dt className="text-muted-foreground">{ __( 'Payouts', 'marclie-booking' ) }</dt>
									<dd>
										<YesNo value={ lastVerify.account.payouts_enabled } />
									</dd>
									<dt className="text-muted-foreground">{ __( 'Checked', 'marclie-booking' ) }</dt>
									<dd>{ formatDateTime( lastVerify.at ) }</dd>
								</dl>
							) }
							{ lastVerify && ! lastVerify.ok && (
								<p className="text-danger" role="alert">
									{ lastVerify.error ?? __( 'Stripe connection failed.', 'marclie-booking' ) }{ ' ' }
									<span className="text-muted-foreground">({ formatDateTime( lastVerify.at ) })</span>
								</p>
							) }
						</div>
						<Button
							variant="secondary"
							onClick={ runVerify }
							disabled={ verify.isPending || ! s.keys.secret.set }
							className="shrink-0"
						>
							<PlugsConnected className="h-4 w-4" aria-hidden="true" />
							{ verify.isPending ? __( 'Checking…', 'marclie-booking' ) : __( 'Check connection', 'marclie-booking' ) }
						</Button>
					</div>
				</CardContent>
			</Card>

			<Card>
				<CardHeader>
					<div>
						<CardTitle>{ __( 'Webhook', 'marclie-booking' ) }</CardTitle>
						<CardDescription>
							{ __( 'Send this endpoint URL to your developer to register in Stripe › Developers › Webhooks.', 'marclie-booking' ) }
						</CardDescription>
					</div>
				</CardHeader>
				<CardContent className="flex flex-col gap-4 text-sm">
					<div className="flex flex-col gap-2 sm:flex-row sm:items-center">
						<code className="min-w-0 flex-1 break-all rounded-md border border-border bg-surface-muted px-3 py-2 text-xs">
							{ s.webhook.url }
						</code>
						<CopyButton text={ s.webhook.url } label={ __( 'Copy URL', 'marclie-booking' ) } />
					</div>
					<div>
						<p className="mb-1.5 font-medium">{ __( 'Required events', 'marclie-booking' ) }</p>
						<ul className="flex flex-col gap-1">
							{ s.webhook.events.map( ( event ) => (
								<li key={ event } className="flex items-center gap-2">
									<CheckCircle className="h-4 w-4 text-success" aria-hidden="true" />
									<code className="text-xs">{ event }</code>
								</li>
							) ) }
						</ul>
					</div>
					<dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
						<dt className="text-muted-foreground">{ __( 'Last event received', 'marclie-booking' ) }</dt>
						<dd>
							{ s.webhook.last_received_at
								? `${ formatRelative( s.webhook.last_received_at ) } (${ formatDateTime( s.webhook.last_received_at ) })`
								: __( 'Never', 'marclie-booking' ) }
						</dd>
						<dt className="text-muted-foreground">{ __( 'Last signature error', 'marclie-booking' ) }</dt>
						<dd className={ s.webhook.last_error ? 'text-danger' : undefined }>
							{ s.webhook.last_error ?? __( 'None', 'marclie-booking' ) }
						</dd>
					</dl>
				</CardContent>
			</Card>

			<Card>
				<CardHeader className="flex-wrap">
					<div>
						<CardTitle>{ __( 'Developer instructions', 'marclie-booking' ) }</CardTitle>
						<CardDescription>
							{ __(
								'Add the keys from Stripe › Developers › API keys as constants in wp-config.php. Use test keys (pk_test_/sk_test_) until the site goes live.',
								'marclie-booking'
							) }
						</CardDescription>
					</div>
					<CopyButton text={ snippet( s.mode ) } label={ __( 'Copy snippet', 'marclie-booking' ) } />
				</CardHeader>
				<CardContent>
					<pre className="overflow-x-auto rounded-md bg-ink-soft p-4 text-xs leading-relaxed text-white">
						<code>{ snippet( s.mode ) }</code>
					</pre>
				</CardContent>
			</Card>
		</div>
	);
}

function YesNo( { value }: { value: boolean } ) {
	return value ? (
		<Badge variant="success">{ __( 'Enabled', 'marclie-booking' ) }</Badge>
	) : (
		<Badge variant="danger">{ __( 'Disabled', 'marclie-booking' ) }</Badge>
	);
}
