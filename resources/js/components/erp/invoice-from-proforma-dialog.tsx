import { useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
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
import { formatNumber } from '@/lib/format';
import { invoice } from '@/routes/sales/proformas';
import type { Proforma, ProformaLine } from '@/types/sales';

type Props = {
    proforma: Proforma;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

function pendingQuantity(line: ProformaLine): string {
    return String(
        Number(
            (Number(line.quantity) - Number(line.invoiced_quantity)).toFixed(4),
        ),
    );
}

export function InvoiceFromProformaDialog({
    proforma,
    open,
    onOpenChange,
}: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Facturar {proforma.number}</DialogTitle>
                </DialogHeader>
                <InvoiceQuantities proforma={proforma} />
            </DialogContent>
        </Dialog>
    );
}

function InvoiceQuantities({ proforma }: { proforma: Proforma }) {
    const lines = proforma.lines ?? [];
    const { data, setData, post, processing, errors } = useForm<{
        quantities: Record<string, string>;
    }>({
        quantities: Object.fromEntries(
            lines.map((line) => [String(line.id), pendingQuantity(line)]),
        ),
    });
    const fieldErrors = errors as Record<string, string | undefined>;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        post(invoice.url(proforma.id));
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <div className="rounded-md border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Producto</TableHead>
                            <TableHead className="text-right">
                                Cantidad
                            </TableHead>
                            <TableHead className="text-right">
                                Facturado
                            </TableHead>
                            <TableHead className="text-right">
                                Pendiente
                            </TableHead>
                            <TableHead>A facturar</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {lines.map((line) => (
                            <TableRow key={line.id}>
                                <TableCell>
                                    {line.product
                                        ? `${line.product.sku} · ${line.product.name}`
                                        : line.description}
                                </TableCell>
                                <TableCell className="text-right">
                                    {formatNumber(line.quantity, 4)}
                                </TableCell>
                                <TableCell className="text-right">
                                    {formatNumber(line.invoiced_quantity, 4)}
                                </TableCell>
                                <TableCell className="text-right">
                                    {formatNumber(pendingQuantity(line), 4)}
                                </TableCell>
                                <TableCell>
                                    <Input
                                        type="number"
                                        min="0"
                                        step="0.0001"
                                        aria-label={`A facturar ${line.description}`}
                                        value={
                                            data.quantities[String(line.id)] ??
                                            ''
                                        }
                                        onChange={(event) =>
                                            setData('quantities', {
                                                ...data.quantities,
                                                [String(line.id)]:
                                                    event.target.value,
                                            })
                                        }
                                    />
                                    {fieldErrors[`quantities.${line.id}`] && (
                                        <p className="text-sm text-destructive">
                                            {
                                                fieldErrors[
                                                    `quantities.${line.id}`
                                                ]
                                            }
                                        </p>
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
            {fieldErrors.quantities && (
                <p className="text-sm text-destructive">
                    {fieldErrors.quantities}
                </p>
            )}
            <DialogFooter>
                <Button type="submit" disabled={processing}>
                    Crear borrador de factura
                </Button>
            </DialogFooter>
        </form>
    );
}
