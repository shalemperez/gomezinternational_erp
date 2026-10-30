export type PickedProduct = {
    id: number;
    sku: string;
    name: string;
    itbis_rate: string;
    tracks_lots: boolean;
    unit_price: string;
};
export type DocumentLineInput = {
    key: string;
    product_id: number | null;
    sku?: string;
    description: string;
    quantity: string;
    unit_price: string;
    discount_percent: string;
};
export type ComputedLine = {
    product_id: number;
    description: string;
    quantity: string;
    unit_price: string;
    discount_percent: string;
    line_subtotal: string;
    discount_amount: string;
    taxable_base: string;
    itbis_rate: string;
    itbis_amount: string;
    additional_taxes: { code: string; name: string; amount: string }[];
    additional_tax_total: string;
    line_total: string;
};
export type ComputedTotals = {
    lines: ComputedLine[];
    totals: {
        subtotal: string;
        discount_total: string;
        taxable_total: string;
        itbis_total: string;
        additional_tax_total: string;
        total: string;
    };
};
