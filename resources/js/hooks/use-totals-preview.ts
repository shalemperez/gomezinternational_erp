import { useEffect, useState } from 'react';
import { preview } from '@/routes/sales/totals';
import type { ComputedTotals, DocumentLineInput } from '@/types/sales';

export function useTotalsPreview(lines: DocumentLineInput[]): {
    computed: ComputedTotals | null;
    loading: boolean;
} {
    const [computed, setComputed] = useState<ComputedTotals | null>(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        const payload = lines
            .filter((line) => line.product_id !== null)
            .map((line) => ({
                product_id: line.product_id,
                quantity: line.quantity,
                unit_price: line.unit_price,
                discount_percent: line.discount_percent,
                description: line.description,
                itbis_rate: line.itbis_rate,
            }));

        if (payload.length === 0) {
            setComputed(null);

            return;
        }

        let active = true;
        const timer = setTimeout(async () => {
            setLoading(true);
            try {
                const response = await fetch(preview.url(), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': decodeURIComponent(
                            document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ??
                                '',
                        ),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ lines: payload }),
                });

                if (response.ok && active) {
                    setComputed((await response.json()) as ComputedTotals);
                }
            } catch {
                // Network failure: keep the previous preview.
            } finally {
                if (active) {
                    setLoading(false);
                }
            }
        }, 400);

        return () => {
            active = false;
            clearTimeout(timer);
        };
    }, [lines]);

    return { computed, loading };
}
