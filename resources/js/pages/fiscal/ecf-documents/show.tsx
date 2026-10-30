import { Head, Link } from '@inertiajs/react';
import { EcfStatusCard } from '@/components/erp/ecf-status-card';
import { PageHeader } from '@/components/erp/page-header';
import { RecordHistory } from '@/components/erp/record-history';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatDateTime } from '@/lib/format';
import { index } from '@/routes/fiscal/ecf-documents';
import type { AuditEntry } from '@/types/audit';
import type { EcfDocumentDetail, EcfTransmissionRow } from '@/types/ecf';

type Props = {
    ecfDocument: EcfDocumentDetail;
    transmissions: EcfTransmissionRow[];
    history: AuditEntry[];
    can: { manage: boolean };
};

export default function EcfDocumentShow({
    ecfDocument,
    transmissions,
    history,
    can: abilities,
}: Props) {
    return (
        <>
            <Head title={`e-CF ${ecfDocument.encf}`} />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title={`e-CF ${ecfDocument.encf}`}
                    description={`${ecfDocument.environment_label} · Documento ${ecfDocument.document.number ?? ''}`}
                    actions={
                        <>
                            {ecfDocument.xml_url && (
                                <Button variant="outline" asChild>
                                    <a href={ecfDocument.xml_url}>
                                        Descargar XML
                                    </a>
                                </Button>
                            )}
                            {ecfDocument.rfce_url && (
                                <Button variant="outline" asChild>
                                    <a href={ecfDocument.rfce_url}>
                                        Descargar RFCE
                                    </a>
                                </Button>
                            )}
                            <Button variant="outline" asChild>
                                <Link href={index.url()}>Volver</Link>
                            </Button>
                        </>
                    }
                />

                <EcfStatusCard ecf={ecfDocument} canManage={abilities.manage} />

                <Tabs defaultValue="transmisiones">
                    <TabsList>
                        <TabsTrigger value="transmisiones">
                            Transmisiones
                        </TabsTrigger>
                        <TabsTrigger value="historial">Historial</TabsTrigger>
                    </TabsList>
                    <TabsContent value="transmisiones" className="pt-4">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Operación</TableHead>
                                    <TableHead>HTTP</TableHead>
                                    <TableHead>Resultado</TableHead>
                                    <TableHead>Fecha</TableHead>
                                    <TableHead>Respuesta</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {transmissions.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={5}>
                                            No hay transmisiones
                                        </TableCell>
                                    </TableRow>
                                )}
                                {transmissions.map((transmission) => (
                                    <TableRow key={transmission.id}>
                                        <TableCell>
                                            {transmission.kind}
                                        </TableCell>
                                        <TableCell>
                                            {transmission.http_status ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            {transmission.succeeded
                                                ? 'Correcto'
                                                : transmission.error}
                                        </TableCell>
                                        <TableCell>
                                            {formatDateTime(
                                                transmission.created_at,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <a
                                                className="underline"
                                                href={transmission.url}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                Ver JSON
                                            </a>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </TabsContent>
                    <TabsContent value="historial" className="pt-4">
                        <RecordHistory history={history} />
                    </TabsContent>
                </Tabs>
            </div>
        </>
    );
}
