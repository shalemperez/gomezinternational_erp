import { Plus, Trash2 } from 'lucide-react';
import { ProductPicker } from '@/components/erp/product-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatMoney } from '@/lib/format';
import type {
    ComputedTotals,
    DocumentLineInput,
    PickedProduct,
} from '@/types/sales';

export function DocumentLinesEditor({
    lines,
    onChange,
    computed,
    clientId,
    readOnly = false,
    currency,
}: {
    lines: DocumentLineInput[];
    onChange: (lines: DocumentLineInput[]) => void;
    computed: ComputedTotals | null;
    clientId: number | null;
    readOnly?: boolean;
    currency: 'DOP' | 'USD';
}) {
    const updateLine = (index: number, patch: Partial<DocumentLineInput>) =>
        onChange(
            lines.map((line, i) =>
                i === index ? { ...line, ...patch } : line,
            ),
        );

    const removeLine = (index: number) =>
        onChange(lines.filter((_, i) => i !== index));

    const addProduct = (product: PickedProduct) =>
        onChange([
            ...lines,
            {
                key: crypto.randomUUID(),
                product_id: product.id,
                sku: product.sku,
                description: product.name,
                quantity: '1',
                unit_price: product.unit_price,
                discount_percent: '0',
            },
        ]);

    const totals = computed?.totals;

    return (
        <div className="flex flex-col gap-4">
            <div className="rounded-md border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Producto</TableHead>
                            <TableHead className="text-right">
                                Cantidad
                            </TableHead>
                            <TableHead className="text-right">Precio</TableHead>
                            <TableHead className="text-right">
                                Desc. %
                            </TableHead>
                            <TableHead className="text-right">ITBIS</TableHead>
                            <TableHead className="text-right">
                                Imp. adic.
                            </TableHead>
                            <TableHead className="text-right">Total</TableHead>
                            <TableHead />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {lines.map((line, index) => {
                            const computedLine = computed?.lines[index];

                            return (
                                <TableRow key={line.key}>
                                    <TableCell className="min-w-64">
                                        {line.sku && (
                                            <div className="text-xs text-muted-foreground">
                                                {line.sku}
                                            </div>
                                        )}
                                        {readOnly ? (
                                            line.description
                                        ) : (
                                            <Input
                                                value={line.description}
                                                onChange={(event) =>
                                                    updateLine(index, {
                                                        description:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {readOnly ? (
                                            line.quantity
                                        ) : (
                                            <Input
                                                inputMode="decimal"
                                                className="text-right"
                                                value={line.quantity}
                                                onChange={(event) =>
                                                    updateLine(index, {
                                                        quantity:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {readOnly ? (
                                            line.unit_price
                                        ) : (
                                            <Input
                                                inputMode="decimal"
                                                className="text-right"
                                                value={line.unit_price}
                                                onChange={(event) =>
                                                    updateLine(index, {
                                                        unit_price:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {readOnly ? (
                                            line.discount_percent
                                        ) : (
                                            <Input
                                                inputMode="decimal"
                                                className="text-right"
                                                value={line.discount_percent}
                                                onChange={(event) =>
                                                    updateLine(index, {
                                                        discount_percent:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatMoney(
                                            computedLine?.itbis_amount,
                                            currency,
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatMoney(
                                            computedLine?.additional_tax_total,
                                            currency,
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatMoney(
                                            computedLine?.line_total,
                                            currency,
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {!readOnly && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label="Quitar"
                                                onClick={() =>
                                                    removeLine(index)
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        )}
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </div>

            {!readOnly && (
                <div>
                    <ProductPicker
                        clientId={clientId}
                        onPick={addProduct}
                        trigger={
                            <Button type="button" variant="outline" size="sm">
                                <Plus /> Añadir producto
                            </Button>
                        }
                    />
                </div>
            )}

            <dl className="ml-auto grid w-full max-w-sm grid-cols-2 gap-x-4 gap-y-1 text-sm">
                <dt className="text-muted-foreground">Subtotal</dt>
                <dd className="text-right">
                    {formatMoney(totals?.subtotal, currency)}
                </dd>
                <dt className="text-muted-foreground">Descuento</dt>
                <dd className="text-right">
                    {formatMoney(totals?.discount_total, currency)}
                </dd>
                <dt className="text-muted-foreground">Base imponible</dt>
                <dd className="text-right">
                    {formatMoney(totals?.taxable_total, currency)}
                </dd>
                <dt className="text-muted-foreground">ITBIS</dt>
                <dd className="text-right">
                    {formatMoney(totals?.itbis_total, currency)}
                </dd>
                <dt className="text-muted-foreground">Impuestos adicionales</dt>
                <dd className="text-right">
                    {formatMoney(totals?.additional_tax_total, currency)}
                </dd>
                <dt className="font-semibold">Total</dt>
                <dd className="text-right font-semibold">
                    {formatMoney(totals?.total, currency)}
                </dd>
            </dl>
        </div>
    );
}
