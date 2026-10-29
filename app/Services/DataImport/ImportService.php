<?php

namespace App\Services\DataImport;

use App\Enums\ImportBatchStatus;
use App\Imports\BaseImporter;
use App\Imports\ImporterRegistry;
use App\Models\ImportBatch;
use App\Models\ImportBatchRow;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class ImportService
{
    public const MAX_ROWS = 5000;

    /** @param array<string, mixed> $meta */
    public function preview(BaseImporter $importer, UploadedFile $file, User $user, array $meta = []): ImportBatch
    {
        $originalName = $file->getClientOriginalName();
        $sheet = Excel::toCollection(null, $file)->first();
        if ($sheet === null || $sheet->isEmpty()) {
            throw ValidationException::withMessages(['file' => 'El archivo está vacío.']);
        }

        $labelMap = $importer->labelMap();
        $header = [];
        foreach ($sheet->first() as $index => $cell) {
            $key = $labelMap[Str::slug((string) $cell, '_')] ?? null;
            if ($key !== null) {
                $header[$index] = $key;
            }
        }
        $missing = collect($importer->columns())->filter(fn (array $c) => $c['required'] && ! in_array($c['key'], $header, true))->pluck('label');
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['file' => 'Faltan columnas obligatorias: '.$missing->implode(', ')]);
        }

        $dataRows = $sheet->slice(1)->filter(fn ($row) => collect($row)->contains(fn ($v) => $v !== null && trim((string) $v) !== ''));
        if ($dataRows->isEmpty()) {
            throw ValidationException::withMessages(['file' => 'El archivo está vacío.']);
        }
        if ($dataRows->count() > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'El archivo supera el máximo de '.self::MAX_ROWS.' filas.']);
        }

        $path = $file->store('imports/'.now()->format('Y/m'), 'local'); // stored only after the file passed every structural check

        try {
            return DB::transaction(function () use ($importer, $user, $meta, $path, $originalName, $header, $dataRows): ImportBatch {
                $batch = ImportBatch::create([
                    'type' => $importer::type(), 'file_path' => $path, 'original_name' => $originalName, 'status' => ImportBatchStatus::Previewed,
                    'meta' => $meta ?: null, 'user_id' => $user->id,
                ]);
                $seen = [];
                $valid = 0;
                $invalid = 0;
                $buffer = [];
                foreach ($dataRows as $index => $row) {
                    $rowNumber = $index + 1; // spreadsheet row (header is row 1)
                    $data = array_fill_keys(array_column($importer->columns(), 'key'), null);
                    foreach ($header as $col => $key) {
                        $cell = $row[$col] ?? null;
                        $data[$key] = ($cell === null || trim((string) $cell) === '') ? null : trim((string) $cell);
                    }
                    $data = $importer->normalize($data, $meta);
                    $validator = Validator::make($data, $importer->rules($data, $meta), $importer->messages());
                    $errors = [];
                    foreach ($validator->errors()->toArray() as $field => $messages) {
                        $errors[$field] = $messages[0];
                    }
                    $key = $importer->naturalKey($data, $meta);
                    if ($key !== null && isset($seen[$key])) {
                        $errors['_row'] = 'Valor duplicado en el archivo (fila '.$seen[$key].')';
                    } elseif ($key !== null) {
                        $seen[$key] = $rowNumber;
                    }
                    $isValid = $errors === [];
                    $isValid ? $valid++ : $invalid++;
                    $buffer[] = [
                        'import_batch_id' => $batch->id, 'row_number' => $rowNumber, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                        'errors' => $errors === [] ? null : json_encode($errors, JSON_UNESCAPED_UNICODE), 'is_valid' => $isValid,
                        'action' => $isValid ? ($importer->findExisting($data, $meta) ? 'update' : 'create') : null,
                    ];
                    if (count($buffer) >= 500) {
                        ImportBatchRow::insert($buffer);
                        $buffer = [];
                    }
                }
                if ($buffer !== []) {
                    ImportBatchRow::insert($buffer);
                }
                $batch->update(['total_rows' => $valid + $invalid, 'valid_rows' => $valid, 'invalid_rows' => $invalid]);
                activity('imports')->causedBy($user)->performedOn($batch)->event('previewed')
                    ->withProperties(['file' => $originalName, 'total_rows' => $valid + $invalid, 'valid_rows' => $valid, 'invalid_rows' => $invalid])
                    ->log("Vista previa de importación de {$importer->label()}");

                return $batch;
            });
        } catch (\Throwable $e) {
            if (is_string($path)) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }

    public function confirm(ImportBatch $batch): ImportBatch
    {
        $importer = ImporterRegistry::get($batch->type);
        $meta = $batch->meta ?? [];

        $result = DB::transaction(function () use ($batch, $importer, $meta): ImportBatch {
            /** @var ImportBatch $locked */
            $locked = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ImportBatchStatus::Previewed) {
                throw new UnprocessableEntityHttpException('La importación ya fue procesada.');
            }
            $created = 0;
            $updated = 0;
            $locked->rows()->where('is_valid', true)->orderBy('row_number')->chunkById(200, function ($rows) use ($importer, $meta, &$created, &$updated): void {
                foreach ($rows as $row) {
                    $existing = $importer->findExisting($row->data, $meta);
                    $importer->persist($row->data, $existing, $meta);
                    $existing ? $updated++ : $created++;
                }
            });
            $locked->update(['status' => ImportBatchStatus::Confirmed, 'confirmed_at' => now(), 'created_count' => $created, 'updated_count' => $updated]);
            activity('imports')->performedOn($locked)->event('confirmed')->withProperties(['created' => $created, 'updated' => $updated])->log("Importación confirmada: {$created} creados, {$updated} actualizados");

            return $locked;
        });
        Storage::disk('local')->delete((string) $result->file_path);

        return $result;
    }

    public function cancel(ImportBatch $batch): ImportBatch
    {
        $result = DB::transaction(function () use ($batch): ImportBatch {
            /** @var ImportBatch $locked */
            $locked = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ImportBatchStatus::Previewed) {
                throw new UnprocessableEntityHttpException('La importación ya fue procesada.');
            }
            $locked->update(['status' => ImportBatchStatus::Cancelled]);
            activity('imports')->performedOn($locked)->event('cancelled')->log('Importación cancelada');

            return $locked;
        });
        Storage::disk('local')->delete((string) $result->file_path);

        return $result;
    }
}
