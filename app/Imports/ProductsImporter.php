<?php

namespace App\Imports;

use App\Actions\Catalog\SaveProduct;
use App\Models\AdditionalTax;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\UnitOfMeasure;
use App\Support\Money\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductsImporter extends BaseImporter
{
    public static function type(): string
    {
        return 'products';
    }

    public function label(): string
    {
        return 'Productos';
    }

    public function permission(): string
    {
        return 'catalog.products.import';
    }

    public function columns(): array
    {
        return [
            ['key' => 'sku', 'label' => 'SKU', 'required' => true, 'example' => 'RON-750'],
            ['key' => 'barcode', 'label' => 'Código de barras', 'required' => false, 'example' => '7460000000001'],
            ['key' => 'name', 'label' => 'Nombre', 'required' => true, 'example' => 'Ron añejo 750 ml'],
            ['key' => 'brand_name', 'label' => 'Marca', 'required' => false, 'example' => 'Brugal'],
            ['key' => 'category_name', 'label' => 'Categoría', 'required' => false, 'example' => 'Licores'],
            ['key' => 'unit_code', 'label' => 'Unidad', 'required' => true, 'example' => 'UND'],
            ['key' => 'units_per_box', 'label' => 'Unidades por caja', 'required' => false, 'example' => '12'],
            ['key' => 'weight_kg', 'label' => 'Peso (kg)', 'required' => false, 'example' => '1.2'],
            ['key' => 'volume_liters', 'label' => 'Volumen (L)', 'required' => false, 'example' => '0.75'],
            ['key' => 'alcohol_degree', 'label' => 'Grado alcohólico', 'required' => false, 'example' => '37.5'],
            ['key' => 'itbis_rate', 'label' => 'ITBIS', 'required' => true, 'example' => '18'],
            ['key' => 'tracks_lots', 'label' => 'Controla lotes', 'required' => false, 'example' => 'Sí'],
            ['key' => 'tariff_code', 'label' => 'Código arancelario', 'required' => false, 'example' => '2208.40.00'],
            ['key' => 'minimum_stock', 'label' => 'Stock mínimo', 'required' => false, 'example' => '24'],
            ['key' => 'additional_tax_codes', 'label' => 'Impuestos adicionales', 'required' => false, 'example' => 'ISC-ALC;ISC-ADV'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function normalize(array $row, array $meta): array
    {
        $row['sku'] = isset($row['sku']) ? strtoupper($row['sku']) : null;
        $row['unit_code'] = isset($row['unit_code']) ? strtoupper($row['unit_code']) : null;
        $row['itbis_rate'] = $this->normalizeItbisRate($row['itbis_rate'] ?? null);
        $row['tracks_lots'] = in_array(Str::slug((string) ($row['tracks_lots'] ?? '')), ['si', 'yes', 'true', '1'], true);
        $row['additional_tax_codes'] = implode(';', $this->additionalTaxCodes($row));

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function rules(array $row, array $meta): array
    {
        $existing = $this->findExisting($row, $meta);
        $codes = $this->additionalTaxCodes($row);

        return [
            'sku' => ['required', 'string', 'max:50', Rule::unique('products', 'sku')->ignore($existing?->getKey())],
            'barcode' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:200'],
            'brand_name' => ['nullable', 'string', 'exists:brands,name'],
            'category_name' => ['nullable', 'string', 'exists:product_categories,name'],
            'unit_code' => ['required', 'string', 'exists:units_of_measure,code'],
            'units_per_box' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:999999.9999'],
            'volume_liters' => ['nullable', 'numeric', 'min:0', 'max:999999.9999'],
            'alcohol_degree' => ['nullable', 'numeric', 'between:0,100'],
            'itbis_rate' => ['required', Rule::in(['18.00', '16.00', '0.00'])],
            'tracks_lots' => ['boolean'],
            'tariff_code' => ['nullable', 'string', 'max:20'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0', 'max:9999999999.9999'],
            'additional_tax_codes' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($codes, $row): void {
                foreach ($codes as $code) {
                    if (! AdditionalTax::query()->where('code', $code)->exists()) {
                        $fail("Impuesto adicional desconocido: {$code}");

                        return;
                    }
                }
                $types = AdditionalTax::query()->whereIn('code', $codes)->pluck('calculation_type')->map(fn ($type) => $type->value)->all();
                if (array_intersect($types, ['fixed_per_liter', 'fixed_per_degree_liter']) !== [] && blank($row['volume_liters'] ?? null)) {
                    $fail('Los impuestos por litro requieren el volumen en litros del producto.');
                } elseif (in_array('fixed_per_degree_liter', $types, true) && blank($row['alcohol_degree'] ?? null)) {
                    $fail('Los impuestos por grado alcohólico requieren el grado alcohólico del producto.');
                }
            }],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'brand_name.exists' => 'La marca no existe.',
            'category_name.exists' => 'La categoría no existe.',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     */
    public function naturalKey(array $row, array $meta): ?string
    {
        return $row['sku'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     */
    public function findExisting(array $row, array $meta): ?Model
    {
        if (! empty($row['sku'])) {
            return Product::query()->where('sku', $row['sku'])->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     */
    public function persist(array $row, ?Model $existing, array $meta): Model
    {
        $attributes = [
            'sku' => $row['sku'] ?? null,
            'barcode' => $row['barcode'] ?? null,
            'name' => $row['name'] ?? null,
            'brand_id' => filled($row['brand_name'] ?? null) ? Brand::query()->where('name', $row['brand_name'])->value('id') : null,
            'category_id' => filled($row['category_name'] ?? null) ? ProductCategory::query()->where('name', $row['category_name'])->value('id') : null,
            'unit_of_measure_id' => UnitOfMeasure::query()->where('code', $row['unit_code'])->value('id'),
            'units_per_box' => filled($row['units_per_box'] ?? null) ? (int) $row['units_per_box'] : null,
            'weight_kg' => filled($row['weight_kg'] ?? null) ? Decimal::qty((string) $row['weight_kg']) : null,
            'volume_liters' => filled($row['volume_liters'] ?? null) ? Decimal::qty((string) $row['volume_liters']) : null,
            'alcohol_degree' => filled($row['alcohol_degree'] ?? null) ? Decimal::scale((string) $row['alcohol_degree'], 2) : null,
            'itbis_rate' => $row['itbis_rate'] ?? null,
            'tracks_lots' => $row['tracks_lots'] ?? null,
            'tariff_code' => $row['tariff_code'] ?? null,
            // Blank minimum stock keeps the stored value on update; SaveProduct defaults it to 0 on create.
            'minimum_stock' => filled($row['minimum_stock'] ?? null) ? Decimal::qty((string) $row['minimum_stock']) : $existing?->getAttribute('minimum_stock'),
            'additional_tax_ids' => $this->additionalTaxIds($row, $existing),
        ];

        // Blank cells and absent columns never overwrite existing data; the database defaults cover them on create.
        $attributes = array_filter($attributes, fn (mixed $v): bool => $v !== null);

        return app(SaveProduct::class)->handle($attributes, $existing instanceof Product ? $existing : null);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function additionalTaxCodes(array $row): array
    {
        $raw = (string) ($row['additional_tax_codes'] ?? '');

        return array_values(array_filter(array_map(fn (string $code): string => strtoupper(trim($code)), explode(';', $raw)), fn (string $code): bool => $code !== ''));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int>
     */
    private function additionalTaxIds(array $row, ?Model $existing): array
    {
        $codes = $this->additionalTaxCodes($row);
        if ($codes !== []) {
            return AdditionalTax::query()->whereIn('code', $codes)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        }

        // A blank cell keeps the product's current additional taxes instead of clearing them.
        return $existing instanceof Product ? $existing->additionalTaxes()->pluck('additional_taxes.id')->map(fn ($id): int => (int) $id)->all() : [];
    }

    private function normalizeItbisRate(mixed $rate): mixed
    {
        if (! is_string($rate)) {
            return $rate;
        }
        $clean = Str::lower(str_replace([' ', '%'], '', $rate));
        if ($clean === 'exento') {
            return '0.00';
        }

        return is_numeric($clean) ? Decimal::scale($clean, 2) : $rate;
    }
}
