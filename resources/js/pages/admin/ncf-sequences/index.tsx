import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { ActiveBadge } from '@/components/erp/active-badge';
import {
    DataTable,
    type Column,
    type Paginated,
} from '@/components/erp/data-table';
import { PageHeader } from '@/components/erp/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { formatDate, formatNumber } from '@/lib/format';
import { useCan } from '@/lib/can';
import { create, edit, index } from '@/routes/admin/ncf-sequences';
import type { NcfSequenceRow, SequenceAlert } from '@/types/fiscal';

type Props = {
    rows: Paginated<NcfSequenceRow>;
    filters: Record<string, string | undefined>;
    alerts: SequenceAlert[];
    environment: string;
    fiscalMode: string;
};

const padNumber = (value: number, digits: number): string =>
    String(value).padStart(digits, '0');

function alertText(alert: SequenceAlert): string {
    const prefix = `${alert.label} (${alert.ncf_type})`;

    switch (alert.reason) {
        case 'low':
            return `${prefix}: quedan ${alert.remaining} números.`;
        case 'expiring':
            return `${prefix}: vence el ${formatDate(alert.expires_on)}.`;
        case 'expired':
            return `${prefix}: venció el ${formatDate(alert.expires_on)}.`;
        case 'missing':
            return `${prefix}: no hay secuencia registrada.`;
    }
}

export default function NcfSequenceIndex({ rows, filters, alerts }: Props) {
    const can = useCan();
    const showEnvironment = rows.data.some((row) => row.environment !== 'prod');
    const columns: Column<NcfSequenceRow>[] = [
        {
            key: 'ncf_type',
            label: 'Tipo',
            sortable: true,
            render: (row) => `${row.ncf_type} — ${row.label}`,
        },
        ...(showEnvironment
            ? [
                  {
                      key: 'environment',
                      label: 'Ambiente',
                      render: (row: NcfSequenceRow) => row.environment_label,
                  },
              ]
            : []),
        {
            key: 'range_from',
            label: 'Rango',
            sortable: true,
            render: (row) =>
                `${padNumber(row.range_from, row.sequence_digits)}–${padNumber(row.range_to, row.sequence_digits)}`,
        },
        {
            key: 'next_number',
            label: 'Próximo',
            render: (row) => padNumber(row.next_number, row.sequence_digits),
        },
        {
            key: 'remaining',
            label: 'Restantes',
            render: (row) => formatNumber(row.remaining, 0),
        },
        {
            key: 'expires_on',
            label: 'Vence',
            sortable: true,
            render: (row) =>
                row.expires_on ? formatDate(row.expires_on) : 'No vence',
        },
        {
            key: 'authorization_number',
            label: 'Autorización',
            render: (row) => row.authorization_number ?? '—',
        },
        {
            key: 'is_active',
            label: 'Estado',
            sortable: true,
            render: (row) => <ActiveBadge active={row.is_active} />,
        },
    ];

    return (
        <>
            <Head title="Secuencias NCF" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Secuencias NCF"
                    actions={
                        can('fiscal.sequences.manage') && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus /> Nueva secuencia
                                </Link>
                            </Button>
                        )
                    }
                />
                {alerts.length > 0 && (
                    <div className="flex flex-col gap-2">
                        {alerts.map((alert) => (
                            <Alert
                                key={alert.ncf_type}
                                variant={
                                    alert.reason === 'expired' ||
                                    alert.reason === 'missing'
                                        ? 'destructive'
                                        : 'default'
                                }
                            >
                                <AlertDescription>
                                    {alertText(alert)}
                                </AlertDescription>
                            </Alert>
                        ))}
                    </div>
                )}
                <DataTable
                    columns={columns}
                    rows={rows}
                    routeUrl={index.url()}
                    filters={filters}
                    searchPlaceholder="Buscar…"
                    rowActions={(row) => (
                        <div className="flex justify-end gap-2">
                            {can('fiscal.sequences.manage') && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={edit(row.id)}>Editar</Link>
                                </Button>
                            )}
                        </div>
                    )}
                />
            </div>
        </>
    );
}

NcfSequenceIndex.layout = {
    breadcrumbs: [{ title: 'Secuencias NCF', href: index() }],
};
