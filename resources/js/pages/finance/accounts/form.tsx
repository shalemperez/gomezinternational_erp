import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { FormField } from '@/components/erp/form-field';
import { PageHeader } from '@/components/erp/page-header';
import { RecordHistory } from '@/components/erp/record-history';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { create, edit, index, store, update } from '@/routes/finance/accounts';
import type { AuditEntry } from '@/types/audit';
import type { FinancialAccount } from '@/types/finance';

type Props = {
    financialAccount?: FinancialAccount;
    history?: AuditEntry[];
    types: { value: 'bank' | 'cash'; label: string }[];
    currencies: { id: number; code: string; name: string }[];
};

export default function FinancialAccountForm({
    financialAccount,
    history,
    types,
    currencies,
}: Props) {
    const title = financialAccount ? 'Editar cuenta' : 'Nueva cuenta';
    const [type, setType] = useState<'bank' | 'cash'>(
        financialAccount?.type ?? 'bank',
    );
    const form = (
        <Form
            {...(financialAccount
                ? update.form(financialAccount.id)
                : store.form())}
            className="grid max-w-2xl gap-4"
        >
            {({ errors, processing }) => (
                <>
                    <FormField
                        label="Tipo"
                        htmlFor="type"
                        error={errors.type}
                        required
                    >
                        <Select
                            value={type}
                            onValueChange={(value) =>
                                setType(value as 'bank' | 'cash')
                            }
                        >
                            <SelectTrigger id="type">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {types.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <input type="hidden" name="type" value={type} />
                    </FormField>
                    <FormField
                        label="Nombre"
                        htmlFor="name"
                        error={errors.name}
                        required
                    >
                        <Input
                            id="name"
                            name="name"
                            maxLength={100}
                            defaultValue={financialAccount?.name}
                            required
                        />
                    </FormField>
                    {type === 'bank' && (
                        <>
                            <FormField
                                label="Banco"
                                htmlFor="bank_name"
                                error={errors.bank_name}
                                required
                            >
                                <Input
                                    id="bank_name"
                                    name="bank_name"
                                    maxLength={100}
                                    defaultValue={
                                        financialAccount?.bank_name ?? ''
                                    }
                                    required
                                />
                            </FormField>
                            <FormField
                                label="Número de cuenta"
                                htmlFor="account_number"
                                error={errors.account_number}
                                required
                            >
                                <Input
                                    id="account_number"
                                    name="account_number"
                                    maxLength={50}
                                    defaultValue={
                                        financialAccount?.account_number ?? ''
                                    }
                                    required
                                />
                            </FormField>
                        </>
                    )}
                    <FormField
                        label="Moneda"
                        htmlFor="currency_id"
                        error={errors.currency_id}
                        required
                    >
                        <Select
                            name="currency_id"
                            defaultValue={
                                financialAccount
                                    ? String(financialAccount.currency_id)
                                    : undefined
                            }
                            required
                        >
                            <SelectTrigger id="currency_id">
                                <SelectValue placeholder="Seleccione una moneda" />
                            </SelectTrigger>
                            <SelectContent>
                                {currencies.map((currency) => (
                                    <SelectItem
                                        key={currency.id}
                                        value={String(currency.id)}
                                    >
                                        {`${currency.code} — ${currency.name}`}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField
                        label="Activa"
                        htmlFor="is_active"
                        error={errors.is_active}
                        className="flex items-center gap-2"
                    >
                        <Switch
                            id="is_active"
                            name="is_active"
                            defaultChecked={financialAccount?.is_active ?? true}
                        />
                    </FormField>
                    <div className="flex gap-2">
                        <Button type="submit" disabled={processing}>
                            Guardar
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={index()}>Cancelar</Link>
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );

    return (
        <>
            <Head title={title} />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader title={title} />
                {history ? (
                    <Tabs defaultValue="datos">
                        <TabsList>
                            <TabsTrigger value="datos">Datos</TabsTrigger>
                            <TabsTrigger value="historial">
                                Historial
                            </TabsTrigger>
                        </TabsList>
                        <TabsContent value="datos">{form}</TabsContent>
                        <TabsContent value="historial">
                            <RecordHistory history={history} />
                        </TabsContent>
                    </Tabs>
                ) : (
                    form
                )}
            </div>
        </>
    );
}

FinancialAccountForm.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Cuentas bancarias y cajas', href: index() },
        {
            title: props.financialAccount ? 'Editar cuenta' : 'Nueva cuenta',
            href: props.financialAccount
                ? edit.url(props.financialAccount.id)
                : create.url(),
        },
    ],
});
