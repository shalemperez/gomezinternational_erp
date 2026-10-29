<?php

namespace App\Http\Controllers\Admin;

use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ActivityLogController extends Controller
{
    #[Middleware('permission:admin.activity-log.view')]
    public function index(Request $request): Response
    {
        $rows = TableQuery::apply($this->filtered($request), $request, [], ['created_at'], 'created_at', 'desc')
            ->through(fn (ActivityLog $a) => $this->row($a));

        return Inertia::render('admin/activity-log/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page', 'causer_id', 'log_name', 'subject_type', 'subject_id', 'event', 'date_from', 'date_to']),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'logNames' => ActivityLog::query()->whereNotNull('log_name')->distinct()->orderBy('log_name')->pluck('log_name'),
            'events' => ActivityLog::query()->whereNotNull('event')->distinct()->orderBy('event')->pluck('event'),
        ]);
    }

    #[Middleware('permission:admin.activity-log.view')]
    public function export(Request $request): BinaryFileResponse
    {
        $query = TableQuery::applyFilters($this->filtered($request), $request, [], ['created_at'], 'created_at', 'desc');

        return TableExport::fromQuery($query, 'activity-log', [
            'Fecha' => fn (ActivityLog $a) => $a->created_at->format('Y-m-d H:i:s'),
            'Usuario' => fn (ActivityLog $a) => $a->causer instanceof User ? $a->causer->name : 'Sistema',
            'Suplantado por' => fn (ActivityLog $a) => $a->impersonator?->name,
            'Registro' => 'log_name',
            'Evento' => 'event',
            'Descripción' => 'description',
            'Objeto' => fn (ActivityLog $a) => $a->subject_type ? class_basename($a->subject_type).' #'.$a->subject_id : null,
            'Cambios' => fn (ActivityLog $a) => $a->attribute_changes ? json_encode($a->attribute_changes, JSON_UNESCAPED_UNICODE) : null,
            'Propiedades' => fn (ActivityLog $a) => $a->properties?->isNotEmpty() ? json_encode($a->properties, JSON_UNESCAPED_UNICODE) : null,
            'IP' => 'ip',
            'Request' => 'request_id',
        ], $request->string('format', 'xlsx')->toString());
    }

    /** @return Builder<ActivityLog> */
    private function filtered(Request $request): Builder
    {
        $query = ActivityLog::query()->with(['causer:id,name', 'impersonator:id,name']);
        if ($id = $request->query('causer_id')) {
            $query->where('causer_id', $id);
        }
        if ($log = $request->query('log_name')) {
            $query->where('log_name', $log);
        }
        if ($event = $request->query('event')) {
            $query->where('event', $event);
        }
        $type = $request->string('subject_type')->toString();
        if ($type !== '') {
            $query->where('subject_type', 'ILIKE', '%\\'.ltrim($type, '\\'));
        }
        if ($subjectId = $request->query('subject_id')) {
            $query->where('subject_id', $subjectId);
        }
        if ($from = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $to);
        }
        if (($search = $request->string('search')->trim()->toString()) !== '') {
            $query->where('description', 'ILIKE', '%'.$search.'%');
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function row(ActivityLog $a): array
    {
        $old = $a->attribute_changes['old'] ?? [];
        $new = $a->attribute_changes['attributes'] ?? [];
        $fields = array_values(array_unique([...array_keys($old), ...array_keys($new)]));

        return [
            'id' => $a->id,
            'created_at' => $a->created_at->toIso8601String(),
            'causer' => $a->causer instanceof User ? ['id' => $a->causer->id, 'name' => $a->causer->name] : null,
            'impersonator' => $a->impersonator ? ['id' => $a->impersonator->id, 'name' => $a->impersonator->name] : null,
            'ip' => $a->ip,
            'request_id' => $a->request_id,
            'log_name' => $a->log_name,
            'event' => $a->event,
            'description' => $a->description,
            'subject' => $a->subject_type ? class_basename($a->subject_type).' #'.$a->subject_id : null,
            'changes' => array_map(fn (int|string $f) => ['field' => (string) $f, 'old' => $old[$f] ?? null, 'new' => $new[$f] ?? null], $fields),
            'properties' => $a->properties?->toArray() ?? [],
        ];
    }
}
