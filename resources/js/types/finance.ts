export type Currency = {
    id: number;
    code: string;
    name: string;
    symbol: string;
    decimal_places: number;
    is_base: boolean;
    is_active: boolean;
};
export type CurrencyWithLatest = Pick<
    Currency,
    'id' | 'code' | 'name' | 'symbol'
> & { latest_rate: { rate_date: string; rate: string; source: string } | null };
export type ExchangeRateRow = {
    id: number;
    rate_date: string;
    rate: string;
    source: 'manual' | 'http_json';
    source_label: string;
    fetched_at: string | null;
    notes: string | null;
    currency: { id: number; code: string };
    creator: { name: string } | null;
};
