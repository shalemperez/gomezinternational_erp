import { Search } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
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
import { search } from '@/routes/catalog/products';
import type { PickedProduct } from '@/types/sales';

export function ProductPicker({
    clientId,
    onPick,
    trigger,
}: {
    clientId: number | null;
    onPick: (product: PickedProduct) => void;
    trigger?: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<PickedProduct[]>([]);

    useEffect(() => {
        if (!open) {
            return;
        }

        let active = true;
        const timer = setTimeout(async () => {
            try {
                const response = await fetch(
                    search.url({
                        query: { q: query, client_id: clientId ?? undefined },
                    }),
                    {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    },
                );
                const body = (await response.json()) as {
                    data: PickedProduct[];
                };

                if (active) {
                    setResults(body.data);
                }
            } catch {
                // Network failure: keep the previous results.
            }
        }, 300);

        return () => {
            active = false;
            clearTimeout(timer);
        };
    }, [open, query, clientId]);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {trigger ?? (
                    <Button type="button" variant="outline">
                        <Search /> Buscar producto
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Buscar producto</DialogTitle>
                </DialogHeader>
                <Input
                    autoFocus
                    placeholder="SKU, nombre o código de barras"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                />
                <div className="max-h-96 overflow-y-auto rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>SKU</TableHead>
                                <TableHead>Nombre</TableHead>
                                <TableHead className="text-right">
                                    ITBIS
                                </TableHead>
                                <TableHead className="text-right">
                                    Precio
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {results.map((product) => (
                                <TableRow
                                    key={product.id}
                                    className="cursor-pointer"
                                    onClick={() => {
                                        onPick(product);
                                        setOpen(false);
                                    }}
                                >
                                    <TableCell>{product.sku}</TableCell>
                                    <TableCell>{product.name}</TableCell>
                                    <TableCell className="text-right">
                                        {formatNumber(product.itbis_rate, 2)} %
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatNumber(product.unit_price, 4)}
                                    </TableCell>
                                </TableRow>
                            ))}
                            {results.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="text-center text-muted-foreground"
                                    >
                                        Sin resultados
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>
            </DialogContent>
        </Dialog>
    );
}
