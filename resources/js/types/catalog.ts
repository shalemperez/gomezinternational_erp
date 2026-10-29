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
