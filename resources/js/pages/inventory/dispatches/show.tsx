import { Head, Link, router } from '@inertiajs/react';
import { FileText, Pencil } from 'lucide-react';
import { ConfirmDelete } from '@/components/erp/confirm-delete';
import { DocumentHeader } from '@/components/erp/document-header';
import { RecordHistory } from '@/components/erp/record-history';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatDate, formatNumber } from '@/lib/format';
import {
    confirm,
    destroy,
    edit,
    index,
    pdf,
} from '@/routes/inventory/dispatches';
import type { AuditEntry } from '@/types/audit';
import type { DispatchDetail } from '@/types/inventory';

type Props = {
    dispatch: DispatchDetail;
    history: AuditEntry[];
    can: {
        update: boolean;
        delete: boolean;
        confirm: boolean;
    };
};

export default function DispatchShow({
    dispatch,
    history,
    can: abilities,
}: Props) {
    const showCosts = dispatch.lines.some(
        (line) => line.unit_cost !== undefined,
    );
    const meta = [
        { label: 'Factura', value: dispatch.invoice?.number ?? '' },
        { label: 'NCF factura', value: dispatch.invoice?.ncf ?? '' },
        { label: 'Cliente', value: dispatch.client?.business_name ?? '' },
        { label: 'Almacén', value: dispatch.warehouse?.name ?? '' },
        { label: 'Fecha', value: formatDate(dispatch.dispatch_date) },
        { label: 'Chofer', value: dispatch.driver_name ?? '' },
        { label: 'Vehículo', value: dispatch.vehicle ?? '' },
    ];

    const actions = (
        <>
            {abilities.confirm && (
                <AlertDialog>
                    <AlertDialogTrigger asChild>
                        <Button>Confirmar</Button>
                    </AlertDialogTrigger>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                Confirmar conduce
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                Se descontará el inventario. Esta acción no se
                                puede deshacer.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancelar</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={() =>
                                    router.post(
                                        confirm.url(dispatch.id),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Confirmar
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            )}
            {abilities.update && (
                <Button variant="outline" asChild>
                    <Link href={edit.url(dispatch.id)}>
                        <Pencil /> Editar
                    </Link>
                </Button>
            )}
            {abilities.delete && (
                <ConfirmDelete
                    url={destroy.url(dispatch.id)}
                    title="Eliminar borrador de conduce"
                    description="Esta acción no se puede deshacer."
                />
            )}
            <Button variant="outline" asChild>
                <a href={pdf.url(dispatch.id)} target="_blank" rel="noreferrer">
                    <FileText /> PDF
                </a>
            </Button>
        </>
    );

    return (
        <>
            <Head title={`Conduce ${dispatch.number ?? 'borrador'}`} />
            <div className="flex flex-col gap-4 p-4">
                <DocumentHeader
                    title="Conduce"
                    number={dispatch.number ?? 'Borrador'}
                    status={dispatch.status}
                    statusLabel={dispatch.status_label}
                    meta={meta}
                    actions={actions}
                />

                <Tabs defaultValue="lineas">
                    <TabsList>
                        <TabsTrigger value="lineas">Líneas</TabsTrigger>
                        <TabsTrigger value="historial">Historial</TabsTrigger>
                    </TabsList>
                    <TabsContent value="lineas" className="pt-4">
                        <div className="rounded-md border">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left">
                                        <th className="p-2">Producto</th>
                                        <th className="p-2">Lote</th>
                                        <th className="p-2">Vencimiento</th>
                                        <th className="p-2 text-right">
                                            Cantidad
                                        </th>
                                        {showCosts && (
                                            <>
                                                <th className="p-2 text-right">
                                                    Costo unitario
                                                </th>
                                                <th className="p-2 text-right">
                                                    Costo total
                                                </th>
                                            </>
                                        )}
                                    </tr>
                                </thead>
                                <tbody>
                                    {dispatch.lines.map((line) => (
                                        <tr key={line.id} className="border-b">
                                            <td className="p-2">
                                                {line.product
                                                    ? `${line.product.sku} — ${line.product.name}`
                                                    : ''}
                                            </td>
                                            <td className="p-2">
                                                {line.lot?.code ?? ''}
                                            </td>
                                            <td className="p-2">
                                                {formatDate(
                                                    line.lot?.expires_on,
                                                )}
                                            </td>
                                            <td className="p-2 text-right">
                                                {formatNumber(line.quantity, 4)}
                                            </td>
                                            {showCosts && (
                                                <>
                                                    <td className="p-2 text-right">
                                                        {formatNumber(
                                                            line.unit_cost,
                                                            4,
                                                        )}
                                                    </td>
                                                    <td className="p-2 text-right">
                                                        {formatNumber(
                                                            line.total_cost,
                                                            2,
                                                        )}
                                                    </td>
                                                </>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </TabsContent>
                    <TabsContent value="historial" className="pt-4">
                        <RecordHistory history={history} />
                    </TabsContent>
                </Tabs>
            </div>
        </>
    );
}

DispatchShow.layout = {
    breadcrumbs: [{ title: 'Conduces', href: index() }],
};
