import { Form, Head, Link } from '@inertiajs/react';
import { FormField } from '@/components/erp/form-field';
import { PageHeader } from '@/components/erp/page-header';
import { RecordHistory } from '@/components/erp/record-history';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { create, edit, index, store, update } from '@/routes/catalog/products';
import type { AuditEntry } from '@/types/audit';
import type { Product } from '@/types/catalog';

type Props = {
    product?: Product;
    history?: AuditEntry[];
    brands: { id: number; name: string }[];
    categories: { id: number; name: string }[];
    units: { id: number; code: string; name: string }[];
    additionalTaxes: {
        id: number;
        code: string;
        name: string;
        calculation_type: string;
    }[];
    itbisOptions: { value: string; label: string }[];
};

export default function ProductForm({
    product,
    history,
    brands,
    categories,
    units,
    additionalTaxes,
    itbisOptions,
}: Props) {
    const title = product ? 'Editar producto' : 'Nuevo producto';
    const selectedTaxIds = product?.additional_tax_ids ?? [];
    const form = (
        <Form
            {...(product ? update.form(product.id) : store.form())}
            className="grid max-w-3xl gap-4"
        >
            {({ errors, processing }) => (
                <>
                    <Card>
                        <CardHeader>
                            <CardTitle>Identificación</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4">
                            <FormField
                                label="SKU"
                                htmlFor="sku"
                                error={errors.sku}
                                required
                            >
                                <Input
                                    id="sku"
                                    name="sku"
                                    maxLength={50}
                                    className="uppercase"
                                    defaultValue={product?.sku}
                                    required
                                />
                            </FormField>
                            <FormField
                                label="Código de barras"
                                htmlFor="barcode"
                                error={errors.barcode}
                            >
                                <Input
                                    id="barcode"
                                    name="barcode"
                                    maxLength={50}
                                    defaultValue={product?.barcode ?? ''}
                                />
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
                                    maxLength={200}
                                    defaultValue={product?.name}
                                    required
                                />
                            </FormField>
                            <FormField
                                label="Marca"
                                htmlFor="brand_id"
                                error={errors.brand_id}
                            >
                                <Select
                                    name="brand_id"
                                    defaultValue={
                                        product?.brand_id
                                            ? String(product.brand_id)
                                            : 'none'
                                    }
                                >
                                    <SelectTrigger id="brand_id">
                                        <SelectValue placeholder="Sin marca" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            Sin marca
                                        </SelectItem>
                                        {brands.map((brand) => (
                                            <SelectItem
                                                key={brand.id}
                                                value={String(brand.id)}
                                            >
                                                {brand.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>
                            <FormField
                                label="Categoría"
                                htmlFor="category_id"
                                error={errors.category_id}
                            >
                                <Select
                                    name="category_id"
                                    defaultValue={
                                        product?.category_id
                                            ? String(product.category_id)
                                            : 'none'
                                    }
                                >
                                    <SelectTrigger id="category_id">
                                        <SelectValue placeholder="Sin categoría" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            Sin categoría
                                        </SelectItem>
                                        {categories.map((category) => (
                                            <SelectItem
                                                key={category.id}
                                                value={String(category.id)}
                                            >
                                                {category.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Unidades y medidas</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4">
                            <FormField
                                label="Unidad de medida"
                                htmlFor="unit_of_measure_id"
                                error={errors.unit_of_measure_id}
                                required
                            >
                                <Select
                                    name="unit_of_measure_id"
                                    defaultValue={
                                        product
                                            ? String(product.unit_of_measure_id)
                                            : undefined
                                    }
                                >
                                    <SelectTrigger id="unit_of_measure_id">
                                        <SelectValue placeholder="Seleccione una unidad" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {units.map((unit) => (
                                            <SelectItem
                                                key={unit.id}
                                                value={String(unit.id)}
                                            >
                                                {unit.code} — {unit.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>
                            <FormField
                                label="Unidades por caja"
                                htmlFor="units_per_box"
                                error={errors.units_per_box}
                                required
                            >
                                <Input
                                    id="units_per_box"
                                    name="units_per_box"
                                    type="number"
                                    min={1}
                                    defaultValue={product?.units_per_box ?? 1}
                                    required
                                />
                            </FormField>
                            <FormField
                                label="Peso (kg)"
                                htmlFor="weight_kg"
                                error={errors.weight_kg}
                            >
                                <Input
                                    id="weight_kg"
                                    name="weight_kg"
                                    inputMode="decimal"
                                    defaultValue={product?.weight_kg ?? ''}
                                />
                            </FormField>
                            <FormField
                                label="Volumen (L)"
                                htmlFor="volume_liters"
                                error={errors.volume_liters}
                            >
                                <Input
                                    id="volume_liters"
                                    name="volume_liters"
                                    inputMode="decimal"
                                    defaultValue={product?.volume_liters ?? ''}
                                />
                            </FormField>
                            <FormField
                                label="Grado alcohólico"
                                htmlFor="alcohol_degree"
                                error={errors.alcohol_degree}
                            >
                                <Input
                                    id="alcohol_degree"
                                    name="alcohol_degree"
                                    inputMode="decimal"
                                    defaultValue={product?.alcohol_degree ?? ''}
                                />
                            </FormField>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Impuestos</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4">
                            <FormField
                                label="ITBIS"
                                htmlFor="itbis_rate"
                                error={errors.itbis_rate}
                                required
                            >
                                <Select
                                    name="itbis_rate"
                                    defaultValue={
                                        product?.itbis_rate ?? '18.00'
                                    }
                                >
                                    <SelectTrigger id="itbis_rate">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {itbisOptions.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>
                            <FormField
                                label="Impuestos adicionales"
                                htmlFor="additional_tax_ids"
                                error={errors.additional_tax_ids}
                            >
                                <div className="grid gap-2">
                                    {additionalTaxes.map((tax) => (
                                        <div
                                            key={tax.id}
                                            className="flex items-center gap-2"
                                        >
                                            <Checkbox
                                                id={`additional_tax_${tax.id}`}
                                                name="additional_tax_ids[]"
                                                value={String(tax.id)}
                                                defaultChecked={selectedTaxIds.includes(
                                                    tax.id,
                                                )}
                                            />
                                            <Label
                                                htmlFor={`additional_tax_${tax.id}`}
                                            >
                                                {tax.code} — {tax.name}
                                            </Label>
                                        </div>
                                    ))}
                                </div>
                            </FormField>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Inventario</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4">
                            <FormField
                                label="Controla lotes y vencimiento"
                                htmlFor="tracks_lots"
                                error={errors.tracks_lots}
                                hint="Cada entrada exigirá lote y fecha de vencimiento"
                                className="flex items-center gap-2"
                            >
                                <Switch
                                    id="tracks_lots"
                                    name="tracks_lots"
                                    defaultChecked={
                                        product?.tracks_lots ?? false
                                    }
                                />
                            </FormField>
                            <FormField
                                label="Stock mínimo"
                                htmlFor="minimum_stock"
                                error={errors.minimum_stock}
                            >
                                <Input
                                    id="minimum_stock"
                                    name="minimum_stock"
                                    inputMode="decimal"
                                    defaultValue={product?.minimum_stock ?? '0'}
                                />
                            </FormField>
                            <FormField
                                label="Código arancelario"
                                htmlFor="tariff_code"
                                error={errors.tariff_code}
                            >
                                <Input
                                    id="tariff_code"
                                    name="tariff_code"
                                    maxLength={20}
                                    defaultValue={product?.tariff_code ?? ''}
                                />
                            </FormField>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Otros</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4">
                            <FormField
                                label="Descripción"
                                htmlFor="description"
                                error={errors.description}
                            >
                                <Textarea
                                    id="description"
                                    name="description"
                                    maxLength={2000}
                                    defaultValue={product?.description ?? ''}
                                />
                            </FormField>
                            <FormField
                                label="Activo"
                                htmlFor="is_active"
                                error={errors.is_active}
                                className="flex items-center gap-2"
                            >
                                <Switch
                                    id="is_active"
                                    name="is_active"
                                    defaultChecked={product?.is_active ?? true}
                                />
                            </FormField>
                        </CardContent>
                    </Card>

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

ProductForm.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Productos', href: index() },
        {
            title: props.product ? 'Editar producto' : 'Nuevo producto',
            href: props.product ? edit.url(props.product.id) : create.url(),
        },
    ],
});
