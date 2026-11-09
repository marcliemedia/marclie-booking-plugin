import { Toaster as Sonner, toast } from 'sonner';

/** Rendered inside `#mbk-app` (via PortalRoot) so the app tokens apply. */
export function Toaster() {
	return (
		<Sonner
			position="bottom-right"
			offset={ 20 }
			style={ { zIndex: 100070 } }
			toastOptions={ {
				classNames: {
					toast: 'group !rounded-lg !border !border-border !bg-surface !text-ink-soft !shadow-pop !font-sans',
					description: '!text-muted-foreground',
					actionButton: '!bg-ink-soft !text-white',
					cancelButton: '!bg-surface-muted !text-ink-soft',
					success: '[&_[data-icon]]:!text-success',
					error: '[&_[data-icon]]:!text-danger',
				},
			} }
		/>
	);
}

export { toast };
