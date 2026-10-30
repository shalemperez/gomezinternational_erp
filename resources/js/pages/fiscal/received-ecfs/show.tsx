import { Head, Link } from '@inertiajs/react';
import { Field } from '@/components/erp/ecf-status-card';
import { PageHeader } from '@/components/erp/page-header';
import { RecordHistory } from '@/components/erp/record-history';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import { index } from '@/routes/fiscal/received-ecfs';
import type { AuditEntry } from '@/types/audit';
import type { ReceivedEcfDetail } from '@/types/ecf';

type Props = {
    receivedEcf: ReceivedEcfDetail;
    history: AuditEntry[];
    can: { approve: boolean };
};

export default function ReceivedEcfShow({ receivedEcf, history }: Props) {
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
        </>
    );
}
