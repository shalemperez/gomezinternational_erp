import { router, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { stop } from '@/routes/admin/impersonate';

export function ImpersonationBanner() {
    const { auth } = usePage().props;
    if (!auth.impersonator || !auth.user) return null;
    return (
        <div className="flex items-center justify-between gap-2 bg-amber-500 px-4 py-2 text-sm text-black">
            <span>
                Estás viendo el sistema como <strong>{auth.user.name}</strong>{' '}
                (tu cuenta: {auth.impersonator.name}).
            </span>
            <Button
                size="sm"
                variant="secondary"
                onClick={() => router.delete(stop.url())}
            >
                Volver a mi cuenta
            </Button>
        </div>
    );
}
