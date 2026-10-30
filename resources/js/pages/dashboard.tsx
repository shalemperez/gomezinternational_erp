import { Head, usePage } from '@inertiajs/react';
import { PageHeader } from '@/components/erp/page-header';

export default function Dashboard() {
    const { auth } = usePage().props;
    return (
        <>
            <Head title="Panel" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title={`Hola, ${auth.user?.name ?? ''}`}
                    description="Panel principal. Los indicadores se agregan en la fase de reportes."
                />
            </div>
        </>
    );
}

Dashboard.layout = { breadcrumbs: [{ title: 'Panel', href: '/dashboard' }] };
