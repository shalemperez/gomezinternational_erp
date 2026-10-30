import { router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';

type Flash = { success?: string | null; error?: string | null };

function showFlash(flash: Flash | undefined): void {
    if (flash?.success) toast.success(flash.success);
    if (flash?.error) toast.error(flash.error);
}

/**
 * Shows the session flash of the first page load, then one toast per
 * successful Inertia response (so two identical consecutive messages both
 * show). Must be called from the persistent layout only.
 */
export function useFlashMessages(): void {
    const { flash } = usePage().props;

    useEffect(() => {
        showFlash(flash as Flash | undefined);

        return router.on('success', (event) => {
            showFlash(event.detail.page.props.flash as Flash | undefined);
        });
        // Mount-only on purpose: later flashes arrive through the 'success' event.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
}
