import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
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
import { search } from '@/routes/catalog/clients';
import type { PickedClient } from '@/types/sales';

export function ClientPicker({
    value,
    onPick,
    disabled = false,
}: {
    value: PickedClient | null;
    onPick: (client: PickedClient) => void;
    disabled?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<PickedClient[]>([]);

    useEffect(() => {
        if (!open) {
            return;
        }

        let active = true;
        const timer = setTimeout(async () => {
            try {
                const response = await fetch(
                    search.url({ query: { q: query } }),
                    {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    },
                );
                if (!response.ok) {
                    return;
                }
                const body = (await response.json()) as {
                    data: PickedClient[];
                };

                if (active) {
                    setResults(Array.isArray(body.data) ? body.data : []);
                }
            } catch {
                // Network failure: keep the previous results.
            }
        }, 300);

        return () => {
            active = false;
            clearTimeout(timer);
        };
    }, [open, query]);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    disabled={disabled}
                    className="justify-start"
                >
                    <Search />
                    {value ? value.business_name : 'Seleccionar cliente'}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Buscar cliente</DialogTitle>
                </DialogHeader>
                <Input
                    autoFocus
                    placeholder="Código, razón social o RNC"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                />
                <div className="max-h-96 overflow-y-auto rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Código</TableHead>
                                <TableHead>Razón social</TableHead>
                                <TableHead>RNC</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {results.map((client) => (
                                <TableRow
                                    key={client.id}
                                    className="cursor-pointer"
                                    onClick={() => {
                                        onPick(client);
                                        setOpen(false);
                                    }}
                                >
                                    <TableCell>{client.code}</TableCell>
                                    <TableCell>
                                        {client.business_name}
                                    </TableCell>
                                    <TableCell>
                                        {client.formatted_tax_id ?? ''}
                                    </TableCell>
                                </TableRow>
                            ))}
                            {results.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={3}
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
