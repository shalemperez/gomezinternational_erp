<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Excel;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class RowsExport extends DefaultValueBinder implements FromArray, WithCustomCsvSettings, WithCustomValueBinder, WithHeadings
{
    /**
     * @param  list<list<mixed>>  $rows
     * @param  list<string>  $headings
     */
    public function __construct(private readonly array $rows, private readonly array $headings, private readonly string $format = 'xlsx') {}

    /**
     * @param  list<list<mixed>>  $rows
     * @param  list<string>  $headings
     */
    public static function download(array $rows, string $baseName, array $headings, string $format): BinaryFileResponse
    {
        $format = $format === 'csv' ? 'csv' : 'xlsx';
        $fileName = $baseName.'-'.now()->format('Ymd-His').'.'.$format;

        activity('exports')->event('exported')->withProperties([
            'table' => $baseName,
            'format' => $format,
            'filters' => request()->query(),
            'row_count' => count($rows),
        ])->log("Exportación de {$baseName} ({$format})");

        return ExcelFacade::download(new self($rows, $headings, $format), $fileName, $format === 'csv' ? Excel::CSV : Excel::XLSX);
    }

    /** @return list<list<mixed>> */
    public function array(): array
    {
        return array_map(fn (array $row): array => array_map(fn (mixed $value): mixed => $this->format === 'csv' ? self::csvSafe($value) : $value, $row), $this->rows);
    }

    /** @return list<string> */
    public function headings(): array
    {
        return $this->headings;
    }

    /** CSV cells are typed by the consumer, so active content must be neutralised with a leading apostrophe. */
    public static function csvSafe(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || is_numeric($value)) {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /** xlsx: every non-numeric string is stored as a literal string, never evaluated as a formula. */
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value) && ! is_numeric($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function getCsvSettings(): array
    {
        return ['delimiter' => ',', 'use_bom' => true];
    }
}
