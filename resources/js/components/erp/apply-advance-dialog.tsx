import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate, formatMoney } from '@/lib/format';
import type { OpenInvoiceRow } from '@/types/sales';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    applyUrl: string;
    title?: string;
    currency: 'DOP' | 'USD';
    unappliedAmount: string;
    openInvoices: OpenInvoiceRow[];
};

export function ApplyAdvanceDialog({
    open,
    onOpenChange,
    applyUrl,
    title,
    currency,
    unappliedAmount,
    openInvoices,
}: Props) {
    const [amounts, setAmounts] = useState<Record<number, string>>({});
    const [processing, setProcessing] = useState(false);

    const applications = openInvoices
        .filter((invoice) => Number(amounts[invoice.id] ?? 0) > 0)
        .map((invoice) => ({
            invoice_id: invoice.id,
            amount: amounts[invoice.id],
        }));
    const applied = applications.reduce(
        (sum, application) => sum + Number(application.amount),
        0,
    );

    const submit = () => {
        setProcessing(true);
        router.post(
            applyUrl,
            { applications },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setAmounts({});
                    onOpenChange(false);
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{title ?? 'Aplicar anticipo'}</DialogTitle>
                </DialogHeader>
                <p className="text-sm text-muted-foreground">
                    Disponible: {formatMoney(unappliedAmount, currency)}
                </p>
                <div className="max-h-96 overflow-y-auto rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Factura</TableHead>
                                <TableHead>NCF</TableHead>
                                <TableHead>Fecha</TableHead>
                                <TableHead className="text-right">
                                    Saldo
                                </TableHead>
                                <TableHead>Monto a aplicar</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {openInvoices.map((invoice) => (
                                <TableRow key={invoice.id}>
                                    <TableCell>{invoice.number}</TableCell>
                                    <TableCell className="font-mono">
                                        {invoice.ncf ?? ''}
                                    </TableCell>
                                    <TableCell>
                                        {formatDate(invoice.issue_date)}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatMoney(invoice.balance, currency)}
                                    </TableCell>
                                    <TableCell>
                                        <Input
                                            type="number"
                                            min={0}
                                            step="0.01"
                                            max={999999999999.99}
                                            aria-label={`Monto a aplicar a ${invoice.number}`}
                                            value={amounts[invoice.id] ?? ''}
                                            onChange={(event) =>
                                                setAmounts((current) => ({
                                                    ...current,
                                                    [invoice.id]:
                                                        event.target.value,
                                                }))
                                            }
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                            {openInvoices.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="text-center text-muted-foreground"
                                    >
                                        Sin facturas pendientes
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>
                <p className="text-sm">
                    Total a aplicar: {formatMoney(applied, currency)}
                </p>
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancelar
                    </Button>
                    <Button
                        onClick={submit}
                        disabled={processing || applications.length === 0}
                    >
                        Aplicar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
