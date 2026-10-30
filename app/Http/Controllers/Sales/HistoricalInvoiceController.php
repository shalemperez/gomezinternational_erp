<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Sales\RegisterHistoricalInvoice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreHistoricalInvoiceRequest;
use App\Models\Currency;
use App\Models\Salesperson;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

class HistoricalInvoiceController extends Controller
{
    #[Middleware('permission:sales.historical-invoices.import')]
    public function create(): Response
    {
        return Inertia::render('sales/invoices/historical-form', [
            'currencies' => Currency::query()->active()->orderBy('code')->get(['id', 'code']),
            'salespeople' => Salesperson::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'defaultCurrencyId' => Currency::base()->id,
        ]);
    }

    #[Middleware('permission:sales.historical-invoices.import')]
    public function store(StoreHistoricalInvoiceRequest $request, RegisterHistoricalInvoice $registerHistoricalInvoice): RedirectResponse
    {
        $invoice = $registerHistoricalInvoice->handle($request->validated(), $this->actor($request));

        return redirect()->route('sales.invoices.show', $invoice)->with('success', "Factura histórica {$invoice->ncf} registrada");
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
