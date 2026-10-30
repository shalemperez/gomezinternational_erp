<?php

namespace App\Imports;

use App\Actions\Inventory\LoadInitialStock;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class InitialStockImporter extends BaseImporter
{
    public static function type(): string
    {
        return 'initial_stock';
    }

    public function label(): string
    {
        return 'Existencias iniciales';
    }

    public function permission(): string
    {
        return 'inventory.initial-stock.import';
    }

    public function columns(): array
    {
        return [
            ['key' => 'sku', 'label' => 'SKU', 'required' => true, 'example' => 'RON-750'],
            ['key' => 'quantity', 'label' => 'Cantidad', 'required' => true, 'example' => '24'],
            ['key' => 'unit_cost', 'label' => 'Costo unitario', 'required' => true, 'example' => '350.50'],
            ['key' => 'lot_code', 'label' => 'Lote', 'required' => false, 'example' => 'L-2026-01'],
            ['key' => 'lot_expires_on', 'label' => 'Vencimiento', 'required' => false, 'example' => '2027-01-31'],
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
        $row['lot_code'] = isset($row['lot_code']) ? strtoupper($row['lot_code']) : null;

        return $row;
    }

    /** @return array<string, mixed> */
    public function metaRules(): array
    {
        return ['warehouse_id' => ['required', 'integer', 'exists:warehouses,id']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return array_merge(parent::messages(), ['lot_code.required' => 'El producto controla lotes: indica el lote.']);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function rules(array $row, array $meta): array
    {
        return [
            'sku' => ['required', 'string', 'max:50', 'exists:products,sku'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'lot_code' => ['nullable', Rule::requiredIf(fn (): bool => (bool) Product::query()->where('sku', $row['sku'] ?? '')->value('tracks_lots')), 'string', 'max:50'],
            'lot_expires_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     */
    public function naturalKey(array $row, array $meta): ?string
    {
        return $row['sku'].'|'.($row['lot_code'] ?? '');
    }

    /**
     * Every row is a new movement, so there is never an existing record to update.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     */
    public function findExisting(array $row, array $meta): ?Model
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     */
    public function persist(array $row, ?Model $existing, array $meta): Model
    {
        $productId = (int) Product::query()->where('sku', $row['sku'])->value('id');

        return app(LoadInitialStock::class)->handle([[
            'product_id' => $productId,
            'warehouse_id' => $meta['warehouse_id'],
            'quantity' => $row['quantity'],
            'unit_cost' => $row['unit_cost'],
            'lot_code' => $row['lot_code'],
            'lot_expires_on' => $row['lot_expires_on'],
        ]], auth()->user(), 'Importación '.now()->format('Y-m-d'))[0];
    }
}
