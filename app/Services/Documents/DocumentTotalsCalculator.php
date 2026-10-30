<?php

namespace App\Services\Documents;

use App\Enums\AdditionalTaxCalculationType;
use App\Models\AdditionalTax;
use App\Models\Client;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Services\Catalog\AdditionalTaxCalculator;
use App\Support\Documents\ComputedLine;
use App\Support\Documents\ComputedTotals;
use App\Support\Documents\LineInput;
use App\Support\Money\Decimal;

class DocumentTotalsCalculator
{
    public function __construct(private readonly AdditionalTaxCalculator $taxCalculator) {}

    /** @param list<LineInput> $lines */
    public function compute(array $lines): ComputedTotals
    {
        $products = Product::query()->with('additionalTaxes')->whereIn('id', array_map(fn (LineInput $l) => $l->productId, $lines))->get()->keyBy('id');
        $snapshotTaxes = AdditionalTax::query()->whereIn('code', collect($lines)->flatMap(fn (LineInput $l) => $l->additionalTaxCodes ?? [])->unique()->values()->all())->get()->keyBy('code');
        $computed = [];
        foreach ($lines as $input) {
            /** @var Product $product */
            $product = $products[$input->productId];
            $quantity = Decimal::qty($input->quantity);
            $unitPrice = Decimal::qty($input->unitPrice);
            $discountPercent = Decimal::scale($input->discountPercent === '' ? '0' : $input->discountPercent, 4);
            $lineSubtotal = Decimal::mul($quantity, $unitPrice, Decimal::MONEY);
            $discountAmount = Decimal::percent($lineSubtotal, $discountPercent);
            $taxableBase = Decimal::money(Decimal::of($lineSubtotal)->minus(Decimal::of($discountAmount)));

            $additional = [];
            $itbisBase = Decimal::of($taxableBase);
            $additionalTotal = Decimal::of('0');
            $taxes = $input->additionalTaxCodes === null
                ? $product->additionalTaxes->where('is_active', true)
                : collect($input->additionalTaxCodes)->map(fn (string $code) => $snapshotTaxes->get($code))->filter();
            foreach ($taxes as $tax) {
                if ($input->percentTaxesOnly && $tax->calculation_type !== AdditionalTaxCalculationType::PercentOfBase) {
                    continue;
                }
                $amount = $this->taxCalculator->forLine($tax, $quantity, $taxableBase, (string) $product->volume_liters, (string) $product->alcohol_degree);
                $additional[] = ['code' => $tax->code, 'name' => $tax->name, 'amount' => $amount];
                $additionalTotal = $additionalTotal->plus(Decimal::of($amount));
                if ($tax->included_in_itbis_base) {
                    $itbisBase = $itbisBase->plus(Decimal::of($amount));
                }
            }
            $itbisRate = Decimal::scale($input->itbisRate ?? (string) $product->itbis_rate, 2);
            $itbisAmount = Decimal::percent(Decimal::money($itbisBase), $itbisRate);
            $lineTotal = Decimal::money(Decimal::of($taxableBase)->plus($additionalTotal)->plus(Decimal::of($itbisAmount)));

            $computed[] = new ComputedLine(
                $product->id, $input->description !== null && $input->description !== '' ? $input->description : $product->name,
                $quantity, $unitPrice, $discountPercent, $lineSubtotal, $discountAmount, $taxableBase, $itbisRate, $itbisAmount,
                $additional, Decimal::money($additionalTotal), $lineTotal,
            );
        }

        return new ComputedTotals(
            $computed,
            Decimal::sum(array_map(fn (ComputedLine $l) => $l->lineSubtotal, $computed)),
            Decimal::sum(array_map(fn (ComputedLine $l) => $l->discountAmount, $computed)),
            Decimal::sum(array_map(fn (ComputedLine $l) => $l->taxableBase, $computed)),
            Decimal::sum(array_map(fn (ComputedLine $l) => $l->itbisAmount, $computed)),
            Decimal::sum(array_map(fn (ComputedLine $l) => $l->additionalTaxTotal, $computed)),
            Decimal::sum(array_map(fn (ComputedLine $l) => $l->lineTotal, $computed)),
        );
    }

    public function priceFor(Product $product, ?Client $client): string
    {
        $listIds = array_values(array_filter([$client?->price_list_id, PriceList::default()?->id]));
        foreach ($listIds as $listId) {
            $price = PriceListItem::query()->where('price_list_id', $listId)->where('product_id', $product->id)->value('price');
            if ($price !== null) {
                return Decimal::qty((string) $price);
            }
        }

        return '0.0000';
    }
}
