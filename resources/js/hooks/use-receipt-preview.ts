import { useEffect, useState } from 'react';
import { preview } from '@/routes/sales/receipts';

export type ReceiptPreviewInput = {
    amount_received: string;
    retention_itbis: string;
    retention_isr: string;
    applications: { invoice_id: number; amount: string }[];
};

export type ReceiptPreview = {
    credit_total: string;
    applied_total: string;
    unapplied_amount: string;
};

export function useReceiptPreview(
    input: ReceiptPreviewInput,
): ReceiptPreview | null {
    const [summary, setSummary] = useState<ReceiptPreview | null>(null);
    const body = JSON.stringify(input);

    useEffect(() => {
        let active = true;
        const timer = setTimeout(async () => {
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
                    body,
                });

                if (response.ok && active) {
                    setSummary((await response.json()) as ReceiptPreview);
                }
            } catch {
                // Network failure: keep the previous preview.
            }
        }, 400);

        return () => {
            active = false;
            clearTimeout(timer);
        };
    }, [body]);

    return summary;
}
