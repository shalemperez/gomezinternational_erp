<?php

namespace App\Http\Controllers\DataImport;

use App\Enums\ImportBatchStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DataImport\UploadImportRequest;
use App\Imports\BaseImporter;
use App\Imports\ImporterRegistry;
use App\Imports\TemplateExport;
use App\Models\ImportBatch;
use App\Models\PriceList;
use App\Models\Warehouse;
use App\Services\DataImport\ImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ImportController extends Controller
{
    public function index(Request $request): Response
    {
        $usable = $this->usableImporters($request);
        $batches = ImportBatch::query()->with('user:id,name')->whereIn('type', array_keys($usable))->latest('id')->limit(20)->get();

        return Inertia::render('admin/imports/index', [
            'importers' => array_values(array_map(fn (BaseImporter $importer) => [
                'type' => $importer::type(),
                'label' => $importer->label(),
                'columns' => array_map(fn (array $column) => ['label' => $column['label'], 'required' => $column['required']], $importer->columns()),
                'templateUrl' => route('admin.imports.template', $importer::type()),
                'meta' => $this->metaOptions($importer),
            ], $usable)),
            'batches' => $batches->map(fn (ImportBatch $batch) => $this->batchInfo($batch))->values()->all(),
        ]);
    }

    public function template(Request $request, string $type): BinaryFileResponse
    {
        $importer = ImporterRegistry::get($type);
        abort_unless($request->user()->can($importer->permission()), 403);

        return TemplateExport::for($importer);
    }

    public function preview(UploadImportRequest $request, string $type, ImportService $service): RedirectResponse
    {
        $importer = ImporterRegistry::get($type);
        abort_unless($request->user()->can($importer->permission()), 403);

        $batch = $service->preview($importer, $request->file('file'), $request->user(), $request->safe()->except('file'));

        return redirect()->route('admin.imports.show', $batch)->with('success', 'Vista previa generada');
    }

    public function show(Request $request, ImportBatch $batch): Response
    {
        $importer = ImporterRegistry::get($batch->type);
        abort_unless($request->user()->can($importer->permission()), 403);

        $rows = $batch->rows()->orderBy('is_valid')->orderBy('row_number')->paginate(50)->withQueryString();

        return Inertia::render('admin/imports/show', [
            'batch' => $this->batchInfo($batch),
            'rows' => $rows,
            'columns' => array_map(fn (array $column) => ['key' => $column['key'], 'label' => $column['label']], $importer->columns()),
            'canConfirm' => $batch->status === ImportBatchStatus::Previewed && $batch->valid_rows > 0,
        ]);
    }

    public function confirm(Request $request, ImportBatch $batch, ImportService $service): RedirectResponse
    {
        $importer = ImporterRegistry::get($batch->type);
        abort_unless($request->user()->can($importer->permission()), 403);

        $batch = $service->confirm($batch);

        return redirect()->route('admin.imports.show', $batch)->with('success', "Importación completada: {$batch->created_count} creados, {$batch->updated_count} actualizados");
    }

    public function cancel(Request $request, ImportBatch $batch, ImportService $service): RedirectResponse
    {
        $importer = ImporterRegistry::get($batch->type);
        abort_unless($request->user()->can($importer->permission()), 403);

        $service->cancel($batch);

        return redirect()->route('admin.imports.index')->with('success', 'Importación cancelada');
    }

    /** @return array<string, BaseImporter> */
    private function usableImporters(Request $request): array
    {
        return array_filter(ImporterRegistry::all(), fn (BaseImporter $importer) => $request->user()->can($importer->permission()));
    }

    /** @return array<string, mixed> */
    private function metaOptions(BaseImporter $importer): array
    {
        if ($importer::type() === 'price_list_items') {
            return ['price_lists' => PriceList::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])];
        }

        if ($importer::type() === 'initial_stock') {
            return ['warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])];
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function batchInfo(ImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'type' => $batch->type,
            'label' => ImporterRegistry::get($batch->type)->label(),
            'original_name' => $batch->original_name,
            'status' => $batch->status->value,
            'status_label' => $batch->status->label(),
            'total_rows' => $batch->total_rows,
            'valid_rows' => $batch->valid_rows,
            'invalid_rows' => $batch->invalid_rows,
            'created_count' => $batch->created_count,
            'updated_count' => $batch->updated_count,
            'created_at' => $batch->created_at?->toIso8601String(),
            'confirmed_at' => $batch->confirmed_at?->toIso8601String(),
            'user' => ['name' => $batch->user?->name],
        ];
    }
}
