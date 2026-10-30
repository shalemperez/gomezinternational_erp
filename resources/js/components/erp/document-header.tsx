import type { ReactNode } from 'react';
import { StatusBadge } from '@/components/erp/status-badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

export function DocumentHeader({
    title,
    number,
    status,
    statusLabel,
    meta,
    actions,
}: {
    title: string;
    number: string;
    status: string;
    statusLabel: string;
    meta: { label: string; value: ReactNode }[];
    actions?: ReactNode;
}) {
    return (
        <Card>
            <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2">
                <CardTitle className="flex items-center gap-3">
                    <span>{title}</span>
                    <span className="font-mono text-base">{number}</span>
                    <StatusBadge status={status} label={statusLabel} />
                </CardTitle>
                {actions && (
                    <div className="flex flex-wrap gap-2">{actions}</div>
                )}
            </CardHeader>
            <CardContent>
                <dl className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm md:grid-cols-4">
                    {meta.map((item) => (
                        <div key={item.label}>
                            <dt className="text-muted-foreground">
                                {item.label}
                            </dt>
                            <dd>{item.value}</dd>
                        </div>
                    ))}
                </dl>
            </CardContent>
        </Card>
    );
}
