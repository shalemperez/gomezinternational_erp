import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime } from '@/lib/format';
import type { AuditEntry } from '@/types/audit';

const show = (v: unknown): string =>
    v === null || v === undefined || v === ''
        ? '—'
        : typeof v === 'object'
          ? JSON.stringify(v)
          : typeof v === 'string'
            ? v
            : JSON.stringify(v);

export function RecordHistory({ history }: { history: AuditEntry[] }) {
    if (history.length === 0) {
        return (
            <p className="py-8 text-center text-sm text-muted-foreground">
                Sin historial
            </p>
        );
    }
    return (
        <div className="rounded-md border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Fecha</TableHead>
                        <TableHead>Usuario</TableHead>
                        <TableHead>Evento</TableHead>
                        <TableHead>Descripción</TableHead>
                        <TableHead>Cambios</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {history.map((h) => (
                        <TableRow key={h.id}>
                            <TableCell className="whitespace-nowrap">
                                {formatDateTime(h.created_at)}
                            </TableCell>
                            <TableCell>
                                {h.causer ?? 'Sistema'}
                                {h.impersonator && (
                                    <span className="block text-xs text-muted-foreground">
                                        suplantado por {h.impersonator}
                                    </span>
                                )}
                            </TableCell>
                            <TableCell>{h.event ?? '—'}</TableCell>
                            <TableCell>{h.description}</TableCell>
                            <TableCell>
                                {h.changes.length === 0 ? (
                                    '—'
                                ) : (
                                    <ul className="space-y-1 text-xs">
                                        {h.changes.map((c) => (
                                            <li key={c.field}>
                                                <span className="font-medium">
                                                    {c.field}
                                                </span>
                                                : {show(c.old)} → {show(c.new)}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
