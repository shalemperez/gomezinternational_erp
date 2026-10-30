<?php

use App\Models\AdditionalTax;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

test('index requires permission', function () {
    actingAsRole('sales');
    $this->get(route('admin.activity-log.index'))->assertForbidden();
    actingAsRole('accounting');
    $this->get(route('admin.activity-log.index'))->assertOk()->assertInertia(fn ($p) => $p->component('admin/activity-log/index')->has('rows.data')->has('users')->has('logNames')->has('events'));
});

test('filters by log name and user', function () {
    $owner = actingAsRole('owner');
    Brand::factory()->create();
    $other = User::factory()->create();

    $this->get(route('admin.activity-log.index', ['log_name' => 'brands']))->assertInertia(fn ($p) => $p->where('rows.total', 1));
    $this->get(route('admin.activity-log.index', ['log_name' => 'brands', 'causer_id' => $other->id]))->assertInertia(fn ($p) => $p->where('rows.total', 0));
    $this->get(route('admin.activity-log.index', ['log_name' => 'brands', 'causer_id' => $owner->id]))->assertInertia(fn ($p) => $p->where('rows.total', 1));
});

test('filters by subject type and id and rows expose changes ip and request id', function () {
    actingAsRole('owner');
    $a = Brand::factory()->create();
    $b = Brand::factory()->create();
    $a->update(['name' => 'A2']);
    $b->update(['name' => 'B2']);

    $this->get(route('admin.activity-log.index', ['subject_type' => 'Brand', 'subject_id' => $a->id]))
        ->assertInertia(fn ($p) => $p->where('rows.total', 2)
            ->where('rows.data.0.subject', 'Brand #'.$a->id)
            ->where('rows.data.0.changes.0.field', 'name')
            ->where('rows.data.0.changes.0.new', 'A2')
            ->has('rows.data.0.ip')
            ->has('rows.data.0.request_id'));
});

test('export downloads csv and is itself logged', function () {
    actingAsRole('owner');
    Brand::factory()->create();
    $response = $this->get(route('admin.activity-log.export', ['format' => 'csv']));
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and(file_get_contents($response->getFile()->getPathname()))->toContain('"Fecha","Usuario"')
        ->and(Activity::inLog('exports')->where('event', 'exported')->where('properties->table', 'activity-log')->exists())->toBeTrue();
});

test('date range filter', function () {
    actingAsRole('owner');
    Carbon::setTestNow('2025-01-15 10:00:00');
    activity('test')->log('enero');
    Carbon::setTestNow();

    $this->get(route('admin.activity-log.index', ['date_from' => '2025-01-15', 'date_to' => '2025-01-15']))->assertInertia(fn ($p) => $p->where('rows.total', 1));
    $this->get(route('admin.activity-log.index', ['date_from' => '2025-01-16', 'date_to' => '2025-01-20']))->assertInertia(fn ($p) => $p->where('rows.total', 0));
    $this->get(route('admin.activity-log.index', ['date_from' => 'basura', 'causer_id' => 'abc', 'subject_id' => 'x']))->assertOk();
});

test('subject type filter matches the class exactly and tolerates odd input', function () {
    actingAsRole('owner');
    AdditionalTax::factory()->create();

    $this->get(route('admin.activity-log.index', ['subject_type' => 'Tax']))->assertInertia(fn ($p) => $p->where('rows.total', 0));
    $this->get(route('admin.activity-log.index', ['subject_type' => 'AdditionalTax']))->assertInertia(fn ($p) => $p->where('rows.total', fn ($t) => $t >= 1));
    $this->get(route('admin.activity-log.index', ['subject_type' => 'Brand\\']))->assertOk();
    $this->get(route('admin.activity-log.index', ['search' => '100%_\\']))->assertOk();
});

test('accounting can view the activity log but cannot export it', function () {
    actingAsRole('accounting');
    $this->get(route('admin.activity-log.index'))->assertOk();
    $this->get(route('admin.activity-log.export', ['format' => 'csv']))->assertForbidden();
});
