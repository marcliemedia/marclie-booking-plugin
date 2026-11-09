import { ApiError, errorMessage } from '@/api/client';
import { toast } from '@/components/ui/sonner';

/**
 * Error toast that also lists server field errors (`validation` responses carry
 * `data.fields`, whose messages are more useful than the generic summary).
 */
export function toastError( error: unknown ): void {
	const fields = error instanceof ApiError ? Object.values( error.fields ) : [];
	toast.error( errorMessage( error ), fields.length > 0 ? { description: fields.join( ' ' ) } : undefined );
}
