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
export type PriceList = {
    id: number;
    name: string;
    currency_id: number;
    is_default: boolean;
    is_active: boolean;
    currency?: { id: number; code: string } | null;
};
export type PriceListProductRow = {
    id: number;
    sku: string;
    name: string;
    price: string | null;
};
export type ClientContact = {
    id?: number;
    name: string;
    position: string | null;
    email: string | null;
    phone: string | null;
    is_primary: boolean;
};
export type Client = {
    id: number;
    code: string;
    business_name: string;
    trade_name: string | null;
    tax_id_type: 'rnc' | 'cedula' | 'none';
    tax_id: string | null;
    formatted_tax_id: string | null;
    taxpayer_type: string;
    address: string | null;
    city: string | null;
    phone: string | null;
    email: string | null;
    salesperson_id: number | null;
    credit_days: number;
    credit_limit: string;
    price_list_id: number | null;
    currency_id: number;
    notes: string | null;
    tax_id_validation_overridden: boolean;
    tax_id_override_reason: string | null;
    tax_id_override_at: string | null;
    tax_id_override_by_name?: string | null;
    is_active: boolean;
    salesperson?: { id: number; name: string } | null;
    currency?: { id: number; code: string } | null;
    contacts?: ClientContact[];
};
