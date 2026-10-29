export function formatMoney(
    value: string | number | null | undefined,
    currency: 'DOP' | 'USD' = 'DOP',
): string {
    if (value === null || value === undefined || value === '') return '';
    return new Intl.NumberFormat('es-DO', {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value));
}

export function formatNumber(
    value: string | number | null | undefined,
    decimals = 2,
): string {
    if (value === null || value === undefined || value === '') return '';
    return new Intl.NumberFormat('es-DO', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(Number(value));
}

export function formatDate(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(iso);
    return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`;
}

export function formatDateTime(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(iso);
    return `${formatDate(iso)} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}
