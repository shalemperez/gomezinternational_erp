<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class TemplateExport implements FromArray, WithHeadings
{
    use Exportable;

    public function __construct(private readonly BaseImporter $importer) {}

    public static function for(BaseImporter $importer): BinaryFileResponse
    {
        activity('imports')->event('template_downloaded')->withProperties(['type' => $importer::type()])->log('Plantilla descargada: '.$importer->label());

        return (new self($importer))->download('plantilla-'.$importer::type().'.xlsx', Excel::XLSX);
    }

    public function headings(): array
    {
        return array_map(fn (array $c) => $c['label'], $this->importer->columns());
    }

    public function array(): array
    {
        return [array_map(fn (array $c) => $c['example'], $this->importer->columns())];
    }
}
