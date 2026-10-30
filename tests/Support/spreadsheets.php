<?php

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function makeSpreadsheet(array $rows, string $ext = 'xlsx'): UploadedFile
{
    $path = sys_get_temp_dir().'/'.uniqid('imp_', true).'.'.$ext;
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');
    $writer = $ext === 'csv' ? new Csv($spreadsheet) : new Xlsx($spreadsheet);
    $writer->save($path);

    return new UploadedFile($path, 'archivo.'.$ext, null, null, true);
}
