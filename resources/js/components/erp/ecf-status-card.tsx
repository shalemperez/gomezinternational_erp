import { router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime } from '@/lib/format';
import { poll, retry } from '@/routes/fiscal/ecf-documents';
import type { EcfStatus, EcfSummary } from '@/types/ecf';

const variants: Record<
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

export function EcfStatusBadge({
    status,
    label,
}: {
    status: EcfStatus;
    label: string;
}) {
    return <Badge variant={variants[status]}>{label}</Badge>;
}

function Field({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="font-mono break-all">{value || '—'}</dd>
        </div>
    );
}

export function EcfStatusCard({
    ecf,
    canManage,
}: {
    ecf: EcfSummary;
    canManage: boolean;
}) {
    const canRetry = ecf.status === 'pending' || ecf.status === 'signed';
    const canPoll = ecf.status === 'sent' || ecf.status === 'in_process';

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex flex-wrap items-center gap-2">
                    e-CF <span className="font-mono">{ecf.encf}</span>
                    <EcfStatusBadge
                        status={ecf.status}
                        label={ecf.status_label}
                    />
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <dl className="grid gap-3 text-sm md:grid-cols-3">
                    <Field label="TrackId" value={ecf.track_id} />
                    <Field
                        label="Código de seguridad"
                        value={ecf.security_code}
                    />
                    <Field
                        label="Fecha de firma"
                        value={formatDateTime(ecf.signed_at)}
                    />
                    <Field label="Intentos" value={String(ecf.attempts)} />
                    <Field
                        label="Próximo intento"
                        value={formatDateTime(ecf.next_attempt_at)}
                    />
                    <Field label="Último error" value={ecf.last_error} />
                </dl>
                <div className="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <a href={ecf.show_url}>Ver e-CF</a>
                    </Button>
                    {ecf.qr_url && (
                        <Button variant="outline" size="sm" asChild>
                            <a
                                href={ecf.qr_url}
                                target="_blank"
                                rel="noreferrer"
                            >
                                Consultar en la DGII
                            </a>
                        </Button>
                    )}
                    {canManage && canRetry && (
                        <Button
                            size="sm"
                            onClick={() => router.post(retry.url(ecf.id))}
                        >
                            Reintentar
                        </Button>
                    )}
                    {canManage && canPoll && (
                        <Button
                            size="sm"
                            onClick={() => router.post(poll.url(ecf.id))}
                        >
                            Consultar
                        </Button>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
