<?php

namespace App\Support\Documents;

use Illuminate\Database\Schema\Blueprint;

final class DocumentSchema
{
    public static function totals(Blueprint $table): void
    {
        $table->decimal('subtotal', 14, 2)->default(0);
        $table->decimal('discount_total', 14, 2)->default(0);
        $table->decimal('taxable_total', 14, 2)->default(0);
        $table->decimal('itbis_total', 14, 2)->default(0);
        $table->decimal('additional_tax_total', 14, 2)->default(0);
        $table->decimal('total', 14, 2)->default(0);
        $table->decimal('total_base', 14, 2)->default(0);
    }

    public static function lineAmounts(Blueprint $table): void
    {
        $table->unsignedSmallInteger('line_number');
        $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
        $table->string('description', 200);
        $table->decimal('quantity', 14, 4);
        $table->decimal('unit_price', 14, 4);
        $table->decimal('discount_percent', 7, 4)->default(0);
        $table->decimal('line_subtotal', 14, 2);
        $table->decimal('discount_amount', 14, 2);
        $table->decimal('taxable_base', 14, 2);
        $table->decimal('itbis_rate', 7, 2);
        $table->decimal('itbis_amount', 14, 2);
        $table->jsonb('additional_taxes');
        $table->decimal('additional_tax_total', 14, 2);
        $table->decimal('line_total', 14, 2);
    }
}
