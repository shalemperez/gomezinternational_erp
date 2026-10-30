<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Services\Settings;
use App\Support\FiscalId;
use App\Support\Money\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    private const KEYS = [
        'company.name', 'company.legal_name', 'company.rnc', 'company.address', 'company.phone', 'company.email', 'company.logo_path',
        'exchange_rates.source', 'exchange_rates.http_json.url', 'exchange_rates.http_json.json_path',
        'fiscal.consumo_id_threshold', 'sales.proforma_validity_days', 'inventory.expiring_days',
    ];

    #[Middleware('permission:admin.settings.view')]
    public function index(Request $request, Settings $settings): Response
    {
        $values = $settings->many(self::KEYS);
        $values['fiscal.consumo_id_threshold'] ??= '250000.00';
        $values['sales.proforma_validity_days'] ??= 15;
        $values['inventory.expiring_days'] ??= 30;

        return Inertia::render('admin/settings/index', [
            'values' => $values,
            'canManage' => $request->user()->can('admin.settings.manage'),
            'logoUrl' => $values['company.logo_path'] ? Storage::disk('public')->url($values['company.logo_path']) : null,
            'fiscalMode' => $settings->get('fiscal.mode', 'ncf'),
        ]);
    }

    #[Middleware('permission:admin.settings.manage')]
    public function update(UpdateSettingsRequest $request, Settings $settings): RedirectResponse
    {
        $v = $request->validated();
        $flat = [
            'company.name' => $v['company']['name'],
            'company.legal_name' => $v['company']['legal_name'] ?? null,
            'company.rnc' => isset($v['company']['rnc']) && $v['company']['rnc'] !== '' ? FiscalId::normalize($v['company']['rnc']) : null,
            'company.address' => $v['company']['address'] ?? null,
            'company.phone' => $v['company']['phone'] ?? null,
            'company.email' => $v['company']['email'] ?? null,
            'exchange_rates.source' => $v['exchange_rates']['source'],
            'exchange_rates.http_json.url' => $v['exchange_rates']['http_json']['url'] ?? null,
            'exchange_rates.http_json.json_path' => $v['exchange_rates']['http_json']['json_path'] ?? null,
            'fiscal.consumo_id_threshold' => Decimal::money($v['fiscal']['consumo_id_threshold']),
            'sales.proforma_validity_days' => (int) $v['sales']['proforma_validity_days'],
            'inventory.expiring_days' => (int) $v['inventory']['expiring_days'],
        ];
        if ($request->hasFile('logo')) {
            $flat['company.logo_path'] = $request->file('logo')->store('logos', 'public');
        }
        foreach ($flat as $key => $value) {
            $settings->set($key, $value === '' ? null : $value);
        }
        activity('settings')->causedBy($request->user())->event('updated')->withProperties(['keys' => array_keys($flat)])->log('Configuración actualizada');

        return redirect()->route('admin.settings.index')->with('success', 'Configuración guardada');
    }
}
