<?php

namespace App\Services\Documents;

use App\Services\Settings;
use App\Support\FiscalId;
use App\Support\Money\Decimal;
use Barryvdh\DomPDF\Facade\Pdf as PdfFacade;
use Barryvdh\DomPDF\PDF;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class DocumentPdf
{
    public function __construct(private readonly Settings $settings) {}

    public static function money(string $value, string $currency): string
    {
        return ($currency === 'USD' ? 'US$ ' : 'RD$ ').self::number($value, 2);
    }

    /**
     * Formats a decimal string with thousands separators without going through float.
     *
     * @param  int<0, max>  $decimals
     */
    public static function number(string|int $value, int $decimals = 2): string
    {
        $fixed = Decimal::scale((string) $value, $decimals);
        $negative = str_starts_with($fixed, '-');
        [$int, $frac] = array_pad(explode('.', ltrim($fixed, '-')), 2, '');
        $int = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $int);

        return ($negative ? '-' : '').$int.($decimals > 0 ? '.'.$frac : '');
    }

    /** @return array<string, mixed> */
    public function company(): array
    {
        $v = $this->settings->many(['company.name', 'company.legal_name', 'company.rnc', 'company.address', 'company.phone', 'company.email', 'company.logo_path']);
        $logo = $v['company.logo_path'] ? Storage::disk('public')->path($v['company.logo_path']) : null;

        return [
            'name' => $v['company.name'], 'legal_name' => $v['company.legal_name'], 'rnc' => $v['company.rnc'],
            'rnc_formatted' => $v['company.rnc'] ? FiscalId::format($v['company.rnc']) : null,
            'address' => $v['company.address'], 'phone' => $v['company.phone'], 'email' => $v['company.email'],
            'logo_path_absolute' => $logo && is_file($logo) ? $logo : null,
        ];
    }

    /** @param array<string, mixed> $data */
    public function render(string $view, array $data, string $fileName): PDF
    {
        return PdfFacade::loadView($view, [...$data, 'company' => $this->company(), 'fileName' => $fileName])->setPaper('a4', 'portrait');
    }

    /** @param array<string, mixed> $data */
    public function download(string $view, array $data, string $fileName): Response
    {
        return $this->render($view, $data, $fileName)->download($fileName);
    }

    /** @param array<string, mixed> $data */
    public function stream(string $view, array $data, string $fileName): Response
    {
        return $this->render($view, $data, $fileName)->stream($fileName);
    }

    /** @param array<string, mixed> $data */
    public function binary(string $view, array $data, string $fileName): string
    {
        return $this->render($view, $data, $fileName)->output();
    }
}
