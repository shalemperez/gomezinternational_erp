import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, XCircle } from 'lucide-react';
import { PageHeader } from '@/components/erp/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { resubmit, steps } from '@/routes/fiscal/certification';
import type {
    EcfCertificationDocument,
    EcfCertificationReadiness,
    EcfCertificationStep,
    EcfStatus,
} from '@/types/ecf';

type Props = {
    environment: string;
    environment_label: string;
    provider: string;
    readiness: EcfCertificationReadiness[];
    steps: EcfCertificationStep[];
    documents: EcfCertificationDocument[];
    stats: { accepted: number; rejected: number; pending: number };
    receiverUrls: { reception: string; approval: string };
};

const statusVariants: Record<
    EcfStatus,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    pending: 'outline',
    signed: 'outline',
    sent: 'secondary',
    in_process: 'secondary',
    accepted: 'default',
    conditionally_accepted: 'default',
    rejected: 'destructive',
    cancelled: 'destructive',
};

export default function EcfCertificationIndex({
    environment_label,
    provider,
    readiness,
    steps: checklist,
    documents,
    stats,
    receiverUrls,
}: Props) {
    const toggleStep = (step: EcfCertificationStep) =>
        router.post(
            steps.url(),
            { key: step.key, done: !step.done },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Certificación DGII" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Certificación DGII"
                    description={`Ambiente: ${environment_label}. Proveedor: ${provider}.`}
                    actions={
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    resubmit.url(),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Reenviar pendientes
                        </Button>
                    }
                />

                <p className="text-sm text-muted-foreground">
                    Las pruebas de certificación deben ejecutarse en una
                    instalación separada con su propia base de datos (ambiente
                    CerteCF).
                </p>

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Preparación</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="flex flex-col gap-3">
                                {readiness.map((check) => (
                                    <li
                                        key={check.key}
                                        className="flex items-start gap-2"
                                    >
                                        {check.ok ? (
                                            <CheckCircle2 className="mt-0.5 size-4 text-green-600" />
                                        ) : (
                                            <XCircle className="mt-0.5 size-4 text-destructive" />
                                        )}
                                        <div>
                                            <p className="text-sm font-medium">
                                                {check.label}
                                            </p>
                                            {!check.ok && (
                                                <p className="text-xs text-muted-foreground">
                                                    {check.hint}
                                                </p>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Checklist de certificación</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="flex flex-col gap-3">
                                {checklist.map((step) => (
                                    <li
                                        key={step.key}
                                        className="flex items-start gap-3"
                                    >
                                        <Checkbox
                                            checked={step.done}
                                            onCheckedChange={() =>
                                                toggleStep(step)
                                            }
                                            className="mt-0.5"
                                        />
                                        <div>
                                            <p className="text-sm font-medium">
                                                {step.title}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {step.who === 'sistema'
                                                    ? 'Lo ejecuta el sistema. '
                                                    : 'Lo realiza la empresa. '}
                                                {step.description}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>URLs de recepción y aprobación</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-1 text-sm">
                        <p>
                            Recepción:{' '}
                            <span className="font-mono">
                                {receiverUrls.reception}
                            </span>
                        </p>
                        <p>
                            Aprobación:{' '}
                            <span className="font-mono">
                                {receiverUrls.approval}
                            </span>
                        </p>
                    </CardContent>
                </Card>

                <div className="grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardContent className="flex flex-col gap-1">
                            <span className="text-sm text-muted-foreground">
                                Aceptados
                            </span>
                            <span className="text-2xl font-semibold">
                                {stats.accepted}
                            </span>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex flex-col gap-1">
                            <span className="text-sm text-muted-foreground">
                                Rechazados
                            </span>
                            <span className="text-2xl font-semibold">
                                {stats.rejected}
                            </span>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex flex-col gap-1">
                            <span className="text-sm text-muted-foreground">
                                Pendientes
                            </span>
                            <span className="text-2xl font-semibold">
                                {stats.pending}
                            </span>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Comprobantes del ambiente</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>e-NCF</TableHead>
                                    <TableHead>Tipo</TableHead>
                                    <TableHead>Documento</TableHead>
                                    <TableHead>Estado</TableHead>
                                    <TableHead>TrackId</TableHead>
                                    <TableHead>Creado</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {documents.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={7}
                                            className="text-center text-muted-foreground"
                                        >
                                            No hay comprobantes en este ambiente
                                        </TableCell>
                                    </TableRow>
                                )}
                                {documents.map((document) => (
                                    <TableRow key={document.id}>
                                        <TableCell className="font-mono">
                                            {document.encf}
                                        </TableCell>
                                        <TableCell>
                                            {document.type_label}
                                        </TableCell>
                                        <TableCell>
                                            {document.document_number ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    statusVariants[
                                                        document.status
                                                    ]
                                                }
                                            >
                                                {document.status_label}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {document.track_id ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            {document.created_at
                                                ? new Date(
                                                      document.created_at,
                                                  ).toLocaleString('es-DO')
                                                : '—'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex justify-end gap-3">
                                                <Link
                                                    href={document.show_url}
                                                    className="text-sm underline"
                                                >
                                                    Ver e-CF
                                                </Link>
                                                {document.pdf_url && (
                                                    <a
                                                        href={document.pdf_url}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="text-sm underline"
                                                    >
                                                        PDF
                                                    </a>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
