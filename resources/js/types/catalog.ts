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
