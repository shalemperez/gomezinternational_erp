export type Brand = { id: number; name: string; is_active: boolean };
export type ProductCategory = {
    id: number;
    name: string;
    parent_id: number | null;
    is_active: boolean;
    parent?: { id: number; name: string } | null;
};
export type UnitOfMeasure = {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
};
export type AdditionalTax = {
    id: number;
    code: string;
    name: string;
    calculation_type:
        | 'percent_of_base'
        | 'fixed_per_unit'
        | 'fixed_per_liter'
        | 'fixed_per_degree_liter';
    calculation_type_label?: string;
    rate: string | null;
    amount: string | null;
    included_in_itbis_base: boolean;
    dgii_code: string | null;
    is_active: boolean;
};
export type Salesperson = {
    id: number;
    code: string;
    name: string;
    email: string | null;
    phone: string | null;
    user_id: number | null;
    is_active: boolean;
    user?: { id: number; name: string; email: string } | null;
};
export type Product = {
    id: number;
    sku: string;
    barcode: string | null;
    name: string;
    brand_id: number | null;
    category_id: number | null;
    unit_of_measure_id: number;
    units_per_box: number;
    weight_kg: string | null;
    volume_liters: string | null;
    alcohol_degree: string | null;
    itbis_rate: string;
    tracks_lots: boolean;
    tariff_code: string | null;
    minimum_stock: string;
    description: string | null;
    is_active: boolean;
    brand?: { id: number; name: string } | null;
    category?: { id: number; name: string } | null;
    unit?: { id: number; code: string } | null;
    additional_tax_ids?: number[];
};
