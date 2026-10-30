import { Head, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { FormField } from '@/components/erp/form-field';
import { PageHeader } from '@/components/erp/page-header';
import { ProductPicker } from '@/components/erp/product-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { create, store } from '@/routes/inventory/initial-stock';
import type { PickedProduct } from '@/types/sales';
import type { InitialStockLine, Warehouse } from '@/types/inventory';

type Props = {
    warehouses: Pick<Warehouse, 'id' | 'name'>[];
    defaultWarehouseId: number | null;
};

export default function InitialStockForm({
    warehouses,
    defaultWarehouseId,
}: Props) {
    const form = useForm<{ note: string; lines: InitialStockLine[] }>({
        note: '',
        lines: [],
    });
    const errorFor = (key: string) =>
        (form.errors as Record<string, string | undefined>)[key];

    const addLine = (product: PickedProduct) => {
        form.setData('lines', [
            ...form.data.lines,
            {
                product_id: product.id,
                sku: product.sku,
                name: product.name,
                tracks_lots: product.tracks_lots,
                warehouse_id: defaultWarehouseId
                    ? String(defaultWarehouseId)
                    : '',
                quantity: '',
                unit_cost: '',
                lot_code: '',
                lot_expires_on: '',
            },
        ]);
    };

    const updateLine = (index: number, changes: Partial<InitialStockLine>) => {
        form.setData(
            'lines',
            form.data.lines.map((line, position) =>
                position === index ? { ...line, ...changes } : line,
            ),
        );
    };

    const removeLine = (index: number) => {
        form.setData(
            'lines',
            form.data.lines.filter((_, position) => position !== index),
        );
    };

    const submit = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(store.url());
    };

    return (
        <>
            <Head title="Existencias iniciales" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader title="Existencias iniciales" />
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <FormField
                        label="Nota"
                        htmlFor="note"
                        error={form.errors.note}
                        required
                    >
                        <Input
                            id="note"
                            name="note"
                            maxLength={255}
                            value={form.data.note}
                            onChange={(event) =>
                                form.setData('note', event.target.value)
                            }
                            required
                        />
                    </FormField>

                    <div>
                        <ProductPicker
                            clientId={null}
                            onPick={addLine}
                            trigger={
                                <Button type="button" variant="outline">
                                    <Plus /> Añadir producto
                                </Button>
                            }
                        />
                    </div>

                    <div className="overflow-x-auto rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Producto</TableHead>
                                    <TableHead>Almacén</TableHead>
                                    <TableHead>Cantidad</TableHead>
                                    <TableHead>Costo unitario</TableHead>
                                    <TableHead>Lote</TableHead>
                                    <TableHead>Vencimiento</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {form.data.lines.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={7}
                                            className="text-center text-muted-foreground"
                                        >
                                            Añada productos a la carga
                                        </TableCell>
                                    </TableRow>
                                )}
                                {form.data.lines.map((line, index) => (
                                    <TableRow
                                        key={`${line.product_id}-${index}`}
                                    >
                                        <TableCell>
                                            {line.sku} — {line.name}
                                        </TableCell>
                                        <TableCell>
                                            <Select
                                                value={line.warehouse_id}
                                                onValueChange={(value) =>
                                                    updateLine(index, {
                                                        warehouse_id: value,
                                                    })
                                                }
                                            >
                                                <SelectTrigger className="w-44">
                                                    <SelectValue placeholder="Seleccione" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {warehouses.map(
                                                        (warehouse) => (
                                                            <SelectItem
                                                                key={
                                                                    warehouse.id
                                                                }
                                                                value={String(
                                                                    warehouse.id,
                                                                )}
                                                            >
                                                                {warehouse.name}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={errorFor(
                                                    `lines.${index}.warehouse_id`,
                                                )}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                type="number"
                                                min="0.0001"
                                                step="0.0001"
                                                className="w-28"
                                                value={line.quantity}
                                                onChange={(event) =>
                                                    updateLine(index, {
                                                        quantity:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                            <InputError
                                                message={errorFor(
                                                    `lines.${index}.quantity`,
                                                )}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                type="number"
                                                min="0"
                                                step="0.0001"
                                                className="w-32"
                                                value={line.unit_cost}
                                                onChange={(event) =>
                                                    updateLine(index, {
                                                        unit_cost:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                            <InputError
                                                message={errorFor(
                                                    `lines.${index}.unit_cost`,
                                                )}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                className="w-36"
                                                maxLength={50}
                                                disabled={!line.tracks_lots}
                                                value={line.lot_code}
                                                onChange={(event) =>
                                                    updateLine(index, {
                                                        lot_code:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                            <InputError
                                                message={errorFor(
                                                    `lines.${index}.lot_code`,
                                                )}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                type="date"
                                                className="w-40"
                                                disabled={!line.tracks_lots}
                                                value={line.lot_expires_on}
                                                onChange={(event) =>
                                                    updateLine(index, {
                                                        lot_expires_on:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                            <InputError
                                                message={errorFor(
                                                    `lines.${index}.lot_expires_on`,
                                                )}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    removeLine(index)
                                                }
                                            >
                                                <Trash2 /> Quitar
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <InputError message={errorFor('lines')} />

                    <div>
                        <Button type="submit" disabled={form.processing}>
                            Cargar existencias
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

InitialStockForm.layout = {
    breadcrumbs: [{ title: 'Existencias iniciales', href: create() }],
};
