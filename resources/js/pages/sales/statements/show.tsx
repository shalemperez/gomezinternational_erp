import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@/components/erp/page-header';
import { SendEmailDialog } from '@/components/erp/send-email-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate, formatMoney } from '@/lib/format';
import { index, pdf, send, show } from '@/routes/sales/statements';
import type { ClientStatement } from '@/types/sales';

type Props = {
    client: {
        id: number;
        code: string;
        business_name: string;
        formatted_tax_id: string | null;
        email: string | null;
        credit_limit: string;
        credit_days: number;
    };
    statement: ClientStatement;
    filters: { from: string; to: string };
    advances: { code: string; amount: string }[];
    canSend: boolean;
};

export default function StatementShow({
    client,
    statement,
    filters,
    advances,
    canSend,
}: Props) {
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const [emailOpen, setEmailOpen] = useState(false);

    const applyPeriod = () =>
        router.get(
            show.url(client.id),
            { from, to },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const advancesWithBalance = advances.filter(
        (advance) => Number(advance.amount) > 0,
    );
    const currencies = Object.keys(statement);

    return (
        <>
            <Head title={`Estado de cuenta — ${client.business_name}`} />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title={`Estado de cuenta — ${client.business_name}`}
                    description={[client.code, client.formatted_tax_id]
                        .filter(Boolean)
                        .join(' · ')}
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <a
                                    href={pdf.url(client.id, {
                                        query: { from, to },
                                    })}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    PDF
                                </a>
                            </Button>
                            {canSend && (
                                <Button onClick={() => setEmailOpen(true)}>
                                    Enviar por correo
                                </Button>
                            )}
                        </>
                    }
                />

                <div className="flex flex-wrap items-end gap-3">
                    <div className="flex flex-col gap-1">
                        <label className="text-sm font-medium" htmlFor="from">
                            Desde
                        </label>
                        <Input
                            id="from"
                            type="date"
                            className="w-44"
                            value={from}
                            onChange={(event) => setFrom(event.target.value)}
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label className="text-sm font-medium" htmlFor="to">
                            Hasta
                        </label>
                        <Input
                            id="to"
                            type="date"
                            className="w-44"
                            value={to}
                            onChange={(event) => setTo(event.target.value)}
                        />
                    </div>
                    <Button variant="secondary" onClick={applyPeriod}>
                        Aplicar
                    </Button>
                </div>

                {advancesWithBalance.length > 0 && (
                    <Alert>
                        <AlertTitle>Anticipos a favor del cliente</AlertTitle>
                        <AlertDescription>
                            {advancesWithBalance
                                .map((advance) =>
                                    formatMoney(
                                        advance.amount,
                                        advance.code as 'DOP' | 'USD',
                                    ),
                                )
                                .join(' · ')}
                        </AlertDescription>
                    </Alert>
                )}

                {currencies.length === 0 && (
                    <p className="text-muted-foreground">
                        Sin movimientos en el período.
                    </p>
                )}

                {currencies.map((currency) => {
                    const section = statement[currency];
                    const money = (value: string) =>
                        formatMoney(value, currency as 'DOP' | 'USD');

                    return (
                        <Card key={currency}>
                            <CardHeader>
                                <CardTitle>Moneda: {currency}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Fecha</TableHead>
                                            <TableHead>Tipo</TableHead>
                                            <TableHead>Número</TableHead>
                                            <TableHead>NCF</TableHead>
                                            <TableHead>Descripción</TableHead>
                                            <TableHead className="text-right">
                                                Débito
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Crédito
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Balance
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow>
                                            <TableCell
                                                colSpan={7}
                                                className="text-muted-foreground"
                                            >
                                                Saldo inicial
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {money(section.opening_balance)}
                                            </TableCell>
                                        </TableRow>
                                        {section.rows.map((row, rowIndex) => (
                                            <TableRow
                                                key={`${row.type}-${row.number ?? ''}-${rowIndex}`}
                                            >
                                                <TableCell>
                                                    {formatDate(row.date)}
                                                </TableCell>
                                                <TableCell>
                                                    {row.type_label}
                                                </TableCell>
                                                <TableCell className="font-mono">
                                                    {row.number ?? ''}
                                                </TableCell>
                                                <TableCell className="font-mono">
                                                    {row.ncf ?? ''}
                                                </TableCell>
                                                <TableCell>
                                                    {row.description}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {money(row.debit)}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {money(row.credit)}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {money(row.balance)}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                    <TableFooter>
                                        <TableRow>
                                            <TableCell
                                                colSpan={7}
                                                className="font-medium"
                                            >
                                                Saldo final
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                {money(section.closing_balance)}
                                            </TableCell>
                                        </TableRow>
                                    </TableFooter>
                                </Table>
                            </CardContent>
                        </Card>
                    );
                })}
            </div>

            {canSend && (
                <SendEmailDialog
                    open={emailOpen}
                    onOpenChange={setEmailOpen}
                    defaultTo={client.email ? [client.email] : []}
                    templateKey="statement"
                    submitUrl={send.url(client.id, {
                        query: {
                            period_from: filters.from,
                            period_to: filters.to,
                        },
                    })}
                    attachmentsLabel={`estado-de-cuenta-${client.code}.pdf`}
                />
            )}
        </>
    );
}

StatementShow.layout = {
    breadcrumbs: [{ title: 'Estados de cuenta', href: index() }],
};
