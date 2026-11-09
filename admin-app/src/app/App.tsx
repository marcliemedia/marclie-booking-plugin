import { LockKey, MapTrifold } from '@phosphor-icons/react';
import { __ } from '@wordpress/i18n';
import * as React from 'react';
import { Suspense } from 'react';

import { routes } from '@/app/routes';
import { matchPattern, navigate, RouteProvider, useLocation } from '@/app/router';
import { BootstrapProvider, useBootstrap, useBootstrapData } from '@/api/hooks/bootstrap';
import { AppShell } from '@/components/app/AppShell';
import { EmptyState } from '@/components/app/EmptyState';
import { ErrorState } from '@/components/app/ErrorState';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { setFormatLocale } from '@/lib/format';

function PageFallback() {
	return (
		<div className="flex flex-col gap-4" aria-busy="true" aria-label={ __( 'Loading…', 'marclie-booking' ) }>
			<Skeleton className="h-7 w-48" />
			<div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
				{ Array.from( { length: 4 } ).map( ( _, index ) => (
					<Skeleton key={ index } className="h-24" />
				) ) }
			</div>
			<Skeleton className="h-80" />
		</div>
	);
}

class PageErrorBoundary extends React.Component< { children: React.ReactNode; resetKey: string }, { error: Error | null } > {
	state = { error: null as Error | null };

	static getDerivedStateFromError( error: Error ) {
		return { error };
	}

	componentDidUpdate( prev: { resetKey: string } ) {
		if ( prev.resetKey !== this.props.resetKey && this.state.error ) {
			this.setState( { error: null } );
		}
	}

	render() {
		if ( this.state.error ) {
			return <ErrorState error={ this.state.error } onRetry={ () => this.setState( { error: null } ) } />;
		}
		return this.props.children;
	}
}

function Router() {
	const location = useLocation();
	const { caps } = useBootstrapData();

	for ( const route of routes ) {
		const params = matchPattern( route.pattern, location.path );
		if ( ! params ) {
			continue;
		}
		if ( ! caps[ route.cap ] ) {
			return (
				<EmptyState
					icon={ LockKey }
					title={ __( 'No access', 'marclie-booking' ) }
					description={ __( 'Your role does not allow access to this section.', 'marclie-booking' ) }
				/>
			);
		}
		const Page = route.component;
		// Booking detail ids keep the list page mounted; other sections remount per path.
		const pageKey = route.pattern.split( '/' )[ 1 ] ?? '';
		return (
			<RouteProvider value={ { path: location.path, params, query: location.query } }>
				<PageErrorBoundary resetKey={ location.path }>
					<Suspense fallback={ <PageFallback /> }>
						<Page key={ pageKey } params={ params } />
					</Suspense>
				</PageErrorBoundary>
			</RouteProvider>
		);
	}

	return (
		<EmptyState
			icon={ MapTrifold }
			title={ __( 'Page not found', 'marclie-booking' ) }
			action={
				<Button variant="secondary" onClick={ () => navigate( '/' ) }>
					{ __( 'Back to overview', 'marclie-booking' ) }
				</Button>
			}
		/>
	);
}

export function App() {
	const bootstrap = useBootstrap();

	if ( bootstrap.isPending ) {
		return (
			<div className="flex min-h-[calc(100vh-32px)]">
				<div className="hidden w-[232px] border-r border-border min-[783px]:block" />
				<div className="flex-1 p-6">
					<PageFallback />
				</div>
			</div>
		);
	}

	if ( bootstrap.isError ) {
		return (
			<div className="flex min-h-[60vh] items-center justify-center p-6">
				<ErrorState
					title={ __( 'The booking admin could not load', 'marclie-booking' ) }
					error={ bootstrap.error }
					onRetry={ () => bootstrap.refetch() }
				/>
			</div>
		);
	}

	setFormatLocale( bootstrap.data.user.locale );

	return (
		<BootstrapProvider value={ bootstrap.data }>
			<AppShell>
				<Router />
			</AppShell>
		</BootstrapProvider>
	);
}
