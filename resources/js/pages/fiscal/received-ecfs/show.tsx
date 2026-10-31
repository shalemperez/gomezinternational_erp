import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Field } from '@/components/erp/ecf-status-card';
import { FormField } from '@/components/erp/form-field';
import { PageHeader } from '@/components/erp/page-header';
import { RecordHistory } from '@/components/erp/record-history';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import { approve } from '@/routes/fiscal/received-ecf';
import { index } from '@/routes/fiscal/received-ecfs';
import type { AuditEntry } from '@/types/audit';
import type { ReceivedEcfDetail } from '@/types/ecf';

type Props = {
    receivedEcf: ReceivedEcfDetail;
    history: AuditEntry[];
    can: { approve: boolean };
};

export default function ReceivedEcfShow({ receivedEcf, history, can }: Props) {
    const [decisionOpen, setDecisionOpen] = useState(false);

    return (
        <>
            <Head title={`e-CF recibido ${receivedEcf.encf}`} />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title={`e-CF recibido ${receivedEcf.encf}`}
                    description={receivedEcf.razon_social_emisor ?? undefined}
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <a href={receivedEcf.xml_url}>Descargar XML</a>
                            </Button>
                            {receivedEcf.arecf_url && (
                                <Button variant="outline" asChild>
                                    <a href={receivedEcf.arecf_url}>
                                        Descargar ARECF
                                    </a>
                                </Button>
                            )}
                            {receivedEcf.acecf_url && (
                                <Button variant="outline" asChild>
                                    <a href={receivedEcf.acecf_url}>
                                        Descargar ACECF
                                    </a>
                                </Button>
                            )}
                            {can.approve &&
                                receivedEcf.commercial_status === 'pending' && (
                                    <Button
                                        onClick={() => setDecisionOpen(true)}
                                    >
                                        Aprobar o rechazar
                                    </Button>
                                )}
                            <Button variant="outline" asChild>
                                <Link href={index.url()}>Volver</Link>
                            </Button>
                        </>
                    }
                />

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Documento</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid gap-3 text-sm sm:grid-cols-2">
                                <Field label="e-NCF" value={receivedEcf.encf} />
                                <Field
                                    label="RNC emisor"
                                    value={receivedEcf.rnc_emisor}
                                />
                                <Field
                                    label="Razón social"
                                    value={receivedEcf.razon_social_emisor}
                                />
                                <Field
                                    label="RNC comprador"
                                    value={receivedEcf.rnc_comprador}
                                />
                                <Field
                                    label="Fecha emisión"
                                    value={formatDate(
                                        receivedEcf.fecha_emision,
                                    )}
                                />
                                <Field
                                    label="Monto total"
                                    value={formatMoney(receivedEcf.monto_total)}
                                />
                                <Field
                                    label="ITBIS"
                                    value={formatMoney(receivedEcf.total_itbis)}
                                />
                                <Field
                                    label="Proveedor"
                                    value={receivedEcf.supplier?.name ?? null}
                                />
                            </dl>
                            {receivedEcf.supplier && (
                                <Button variant="link" className="px-0" asChild>
                                    <Link href={receivedEcf.supplier.url}>
                                        Ver proveedor
                                    </Link>
                                </Button>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Recepción y aprobación</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid gap-3 text-sm sm:grid-cols-2">
                                <div>
                                    <dt className="text-muted-foreground">
                                        Acuse de recibo (ARECF)
                                    </dt>
                                    <dd>
                                        <Badge
                                            variant={
                                                receivedEcf.arecf_status === 0
                                                    ? 'default'
                                                    : 'destructive'
                                            }
                                        >
                                            {receivedEcf.status_label}
                                        </Badge>
                                    </dd>
                                </div>
                                <Field
                                    label="Motivo de no recepción"
                                    value={
                                        receivedEcf.arecf_reason_code === null
                                            ? null
                                            : String(
                                                  receivedEcf.arecf_reason_code,
                                              )
                                    }
                                />
                                <div>
                                    <dt className="text-muted-foreground">
                                        Aprobación comercial
                                    </dt>
                                    <dd>
                                        <Badge
                                            variant={
                                                receivedEcf.commercial_status ===
                                                'rejected'
                                                    ? 'destructive'
                                                    : receivedEcf.commercial_status ===
                                                        'approved'
                                                      ? 'default'
                                                      : 'outline'
                                            }
                                        >
                                            {
                                                receivedEcf.commercial_status_label
                                            }
                                        </Badge>
                                    </dd>
                                </div>
                                <Field
                                    label="Motivo comercial"
                                    value={receivedEcf.commercial_reason}
                                />
                                <Field
                                    label="Fecha de recepción"
                                    value={formatDateTime(
                                        receivedEcf.received_at,
                                    )}
                                />
                                <Field
                                    label="IP del emisor"
                                    value={receivedEcf.sender_ip}
                                />
                            </dl>
                        </CardContent>
                    </Card>
                </div>

                <Tabs defaultValue="historial">
                    <TabsList>
                        <TabsTrigger value="historial">Historial</TabsTrigger>
                    </TabsList>
                    <TabsContent value="historial" className="pt-4">
                        <RecordHistory history={history} />
                    </TabsContent>
                </Tabs>
            </div>
            <CommercialDecisionDialog
                receivedEcf={receivedEcf}
                open={decisionOpen}
                onOpenChange={setDecisionOpen}
            />
        </>
    );
}

function CommercialDecisionDialog({
    receivedEcf,
    open,
    onOpenChange,
}: {
    receivedEcf: ReceivedEcfDetail;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({
        decision: 'approve' as 'approve' | 'reject',
        reason: '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(approve.url(receivedEcf.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Decisión comercial</DialogTitle>
                        <DialogDescription>
                            La decisión se envía a la DGII y al emisor del
                            comprobante.
                        </DialogDescription>
                    </DialogHeader>
                    <fieldset className="grid gap-2 text-sm">
                        <legend className="mb-1 font-medium">Decisión</legend>
                        <label className="flex items-center gap-2">
                            <input
                                type="radio"
                                name="decision"
                                value="approve"
                                checked={form.data.decision === 'approve'}
                                onChange={() =>
                                    form.setData('decision', 'approve')
                                }
                            />
                            Aprobar
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="radio"
                                name="decision"
                                value="reject"
                                checked={form.data.decision === 'reject'}
                                onChange={() =>
                                    form.setData('decision', 'reject')
                                }
                            />
                            Rechazar
                        </label>
                    </fieldset>
                    <FormField
                        label="Motivo del rechazo"
                        htmlFor="commercial-reason"
                        error={form.errors.reason}
                        required={form.data.decision === 'reject'}
                    >
                        <Textarea
                            id="commercial-reason"
                            rows={3}
                            maxLength={250}
                            disabled={form.data.decision === 'approve'}
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                        />
                    </FormField>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Enviar decisión
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
