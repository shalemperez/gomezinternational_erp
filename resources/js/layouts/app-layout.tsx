import { ImpersonationBanner } from '@/components/erp/impersonation-banner';
import { useFlashMessages } from '@/hooks/use-flash-messages';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    useFlashMessages();

    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            <ImpersonationBanner />
            {children}
        </AppLayoutTemplate>
    );
}
