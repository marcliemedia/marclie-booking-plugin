import { QueryClientProvider } from '@tanstack/react-query';
import { createRoot } from '@wordpress/element';

import { App } from '@/app/App';
import { setupApi } from '@/api/client';
import { queryClient } from '@/api/query';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { PortalRoot } from '@/lib/portal';
import '@/styles/app.css';

setupApi();

const container = document.getElementById( 'mbk-app' );

if ( container ) {
	createRoot( container ).render(
		<QueryClientProvider client={ queryClient }>
			<PortalRoot>
				<TooltipProvider delayDuration={ 300 }>
					<App />
					<Toaster />
				</TooltipProvider>
			</PortalRoot>
		</QueryClientProvider>
	);
}
