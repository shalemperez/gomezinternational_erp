<?php

namespace App\Http\Controllers\Admin;

use App\Enums\NcfType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreNcfSequenceRequest;
use App\Http\Requests\Admin\UpdateNcfSequenceRequest;
use App\Models\NcfSequence;
use App\Services\Fiscal\FiscalNumberingService;
use App\Services\Fiscal\SequenceAlerts;
use App\Support\AuditHistory;
use App\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

class NcfSequenceController extends Controller
{
    #[Middleware('permission:fiscal.sequences.view')]
    public function index(Request $request): Response
    {
        $rows = TableQuery::apply(NcfSequence::query(), $request, [], ['ncf_type', 'range_from', 'expires_on', 'is_active'], 'ncf_type', 'asc')
            ->through(fn (NcfSequence $sequence) => [...$sequence->toArray(), 'ncf_type' => $sequence->ncf_type->value, 'label' => $sequence->ncf_type->label(), 'remaining' => $sequence->remaining, 'used' => $sequence->used]);

        return Inertia::render('admin/ncf-sequences/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page']),
            'alerts' => app(SequenceAlerts::class)->current(),
            'canManage' => $request->user()->can('fiscal.sequences.manage'),
            'fiscalMode' => app(FiscalNumberingService::class)->mode(),
        ]);
    }

    #[Middleware('permission:fiscal.sequences.manage')]
    public function create(): Response
    {
        return Inertia::render('admin/ncf-sequences/form', $this->formOptions());
    }

    #[Middleware('permission:fiscal.sequences.manage')]
    public function store(StoreNcfSequenceRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        NcfSequence::create([...$validated, 'next_number' => $validated['range_from']]);

        return redirect()->route('admin.ncf-sequences.index')->with('success', 'Secuencia registrada');
    }

    #[Middleware('permission:fiscal.sequences.manage')]
    public function edit(NcfSequence $ncfSequence): Response
    {
        return Inertia::render('admin/ncf-sequences/form', [
            'ncfSequence' => $ncfSequence,
            'history' => AuditHistory::for($ncfSequence),
            ...$this->formOptions(),
        ]);
    }

    #[Middleware('permission:fiscal.sequences.manage')]
    public function update(UpdateNcfSequenceRequest $request, NcfSequence $ncfSequence): RedirectResponse
    {
        $data = $request->validated();
        if ($ncfSequence->next_number <= $ncfSequence->range_from) {
            $data['next_number'] = $data['range_from']; // unused sequence: numbering starts at the new range_from
        }
        if ($ncfSequence->exhausted_at !== null && (int) $data['range_to'] >= $ncfSequence->next_number) {
            $data['exhausted_at'] = null;
        }
        $ncfSequence->update($data);

        return redirect()->route('admin.ncf-sequences.index')->with('success', 'Secuencia actualizada');
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        $types = collect(NcfType::cases())
            ->filter(fn (NcfType $type) => $type->isSalesType() || in_array($type, [NcfType::B11, NcfType::B13, NcfType::B17], true))
            ->map(fn (NcfType $type) => ['value' => $type->value, 'label' => $type->label(), 'expires' => $type->expires()])
            ->values();

        return ['types' => $types];
    }
}
