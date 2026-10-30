<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Sales\ApplyAdvance;
use App\Actions\Sales\CancelReceipt;
use App\Actions\Sales\PostReceipt;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReceiptStatus;
use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\ApplyAdvanceRequest;
use App\Http\Requests\Sales\CancelReceiptRequest;
use App\Http\Requests\Sales\ReceiptPreviewRequest;
use App\Http\Requests\Sales\StoreReceiptRequest;
use App\Models\Client;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Receipt;
use App\Models\User;
use App\Services\Documents\DocumentPdf;
use App\Services\Sales\ClientAccount;
use App\Support\AuditHistory;
use App\Support\Money\Decimal;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ReceiptController extends Controller
{
    #[Middleware('permission:sales.receipts.view')]
    public function index(Request $request): Response
    {
        $rows = TableQuery::apply($this->filteredQuery($request), $request, ['receipts.number', 'receipts.reference'], ['number', 'receipt_date', 'amount_received', 'applied_total', 'unapplied_amount'], 'receipt_date', 'desc')
            ->through(fn (Receipt $receipt) => [...$receipt->toArray(), 'status_label' => $receipt->status->label(), 'payment_method_label' => $receipt->payment_method->label()]);

        return Inertia::render('sales/receipts/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page', 'client_id', 'status', 'payment_method', 'from', 'to']),
            'statuses' => array_map(fn (ReceiptStatus $status) => ['value' => $status->value, 'label' => $status->label()], ReceiptStatus::cases()),
            'paymentMethods' => $this->paymentMethodOptions(),
        ]);
    }

    #[Middleware('permission:sales.receipts.create')]
    public function create(Request $request): Response
    {
        $client = $request->integer('client') > 0
            ? Client::query()->whereKey($request->integer('client'))->with('currency:id,code')->first()
            : null;

        return Inertia::render('sales/receipts/form', [
            'client' => $client === null ? null : [
                'id' => $client->id,
                'code' => $client->code,
                'business_name' => $client->business_name,
                'trade_name' => $client->trade_name,
                'tax_id' => $client->tax_id,
                'formatted_tax_id' => $client->getAttribute('formatted_tax_id'),
                'currency_code' => $client->currency?->code,
                'price_list_id' => $client->price_list_id,
                'credit_days' => $client->credit_days,
                'email' => $client->email,
                'salesperson_id' => $client->salesperson_id,
            ],
            'openInvoices' => $client === null ? [] : $this->openInvoices($this->actor($request), $client->id),
            'advances' => $client === null ? [] : Receipt::query()->where('client_id', $client->id)->where('status', ReceiptStatus::Posted)
                ->where('unapplied_amount', '>', 0)->get(['id', 'number', 'unapplied_amount', 'currency_id']),
            'accounts' => FinancialAccount::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type', 'currency_id']),
            'paymentMethods' => $this->paymentMethodOptions(),
            'currencies' => Currency::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'is_base']),
            'advanceTotals' => Currency::query()->where('is_active', true)->get()->mapWithKeys(fn (Currency $currency) => [(string) $currency->id => $client === null ? '0.00' : ClientAccount::advanceBalance($client, $currency)])->all(),
        ]);
    }

    #[Middleware('permission:sales.receipts.create')]
    public function store(StoreReceiptRequest $request, PostReceipt $postReceipt): RedirectResponse
    {
        $receipt = $postReceipt->handle($request->validated(), $this->actor($request));

        return redirect()->route('sales.receipts.show', $receipt)->with('success', "Recibo {$receipt->number} registrado");
    }

    #[Middleware('permission:sales.receipts.create')]
    public function preview(ReceiptPreviewRequest $request): JsonResponse
    {
        $money = fn (mixed $value): string => is_numeric($value) ? Decimal::money((string) $value) : '0.00';
        $credit = Decimal::sum([$money($request->input('amount_received')), $money($request->input('retention_itbis')), $money($request->input('retention_isr'))]);
        $applied = Decimal::sum(array_map(fn (array $application) => $money($application['amount'] ?? null), $request->validated('applications')));

        return response()->json([
            'credit_total' => $credit,
            'applied_total' => $applied,
            'unapplied_amount' => Decimal::money(Decimal::of($credit)->minus(Decimal::of($applied))),
        ]);
    }

    #[Middleware('permission:sales.receipts.view')]
    public function show(Request $request, Receipt $receipt): Response
    {
        Gate::authorize('view', $receipt);
        $receipt->loadMissing(['client:id,business_name', 'salesperson:id,name', 'currency:id,code', 'financialAccount:id,name', 'canceller:id,name', 'applications.invoice:id,number,ncf,balance']);
        $actor = $this->actor($request);
        $isPosted = $receipt->status === ReceiptStatus::Posted;

        return Inertia::render('sales/receipts/show', [
            'receipt' => [...$receipt->toArray(), 'status_label' => $receipt->status->label(), 'payment_method_label' => $receipt->payment_method->label(), 'retention_total' => Decimal::sum([$receipt->retention_itbis, $receipt->retention_isr])],
            'openInvoices' => $this->openInvoices($actor, $receipt->client_id, $receipt->currency_id),
            'history' => AuditHistory::for($receipt),
            'can' => [
                'cancel' => $isPosted && $actor->can('sales.receipts.cancel'),
                'apply' => $isPosted && Decimal::cmp($receipt->unapplied_amount, '0') > 0 && $actor->can('sales.receipts.create'),
            ],
        ]);
    }

    #[Middleware('permission:sales.receipts.create')]
    public function apply(ApplyAdvanceRequest $request, Receipt $receipt, ApplyAdvance $applyAdvance): RedirectResponse
    {
        Gate::authorize('view', $receipt);
        /** @var list<array{invoice_id: int|string, amount: string}> $applications */
        $applications = $request->validated('applications');
        $applied = Decimal::sum(array_column($applications, 'amount'));
        $receipt = $applyAdvance->handle($receipt, $applications, $this->actor($request));

        return redirect()->route('sales.receipts.show', $receipt)->with('success', 'Anticipo aplicado: '.DocumentPdf::money($applied, $receipt->currency->code));
    }

    #[Middleware('permission:sales.receipts.view')]
    public function pdf(Receipt $receipt, DocumentPdf $pdf): SymfonyResponse
    {
        Gate::authorize('view', $receipt);
        activity('receipts')->performedOn($receipt)->event('pdf_generated')->log("PDF de {$receipt->number} generado");

        return $pdf->stream('pdf.sales.receipt', $this->pdfData($receipt), $receipt->number.'.pdf');
    }

    /** @return array<string, mixed> */
    public function pdfData(Receipt $receipt): array
    {
        $receipt->load('applications.invoice', 'client', 'currency', 'financialAccount');

        return [
            'receipt' => $receipt,
            'currency' => $receipt->currency->code,
        ];
    }

    #[Middleware('permission:sales.receipts.cancel')]
    public function cancel(CancelReceiptRequest $request, Receipt $receipt, CancelReceipt $cancelReceipt): RedirectResponse
    {
        Gate::authorize('view', $receipt);
        $cancelled = $cancelReceipt->handle($receipt, $this->actor($request), $request->string('reason')->trim()->toString());

        return redirect()->route('sales.receipts.show', $cancelled)->with('success', "Recibo {$cancelled->number} anulado");
    }

    #[Middleware('permission:sales.receipts.export')]
    public function export(Request $request): BinaryFileResponse
    {
        $query = TableQuery::applyFilters($this->filteredQuery($request), $request, ['receipts.number', 'receipts.reference'], ['number', 'receipt_date', 'amount_received', 'applied_total', 'unapplied_amount'], 'receipt_date', 'desc');

        return TableExport::fromQuery($query, 'receipts', [
            'Número' => 'number',
            'Fecha' => fn (Receipt $receipt) => $receipt->receipt_date->format('d/m/Y'),
            'Cliente' => 'client.business_name',
            'Método' => fn (Receipt $receipt) => $receipt->payment_method->label(),
            'Cuenta' => 'financialAccount.name',
            'Referencia' => 'reference',
            'Moneda' => 'currency.code',
            'Monto' => 'amount_received',
            'Ret. ITBIS' => 'retention_itbis',
            'Ret. ISR' => 'retention_isr',
            'Aplicado' => 'applied_total',
            'Anticipo' => 'unapplied_amount',
            'Estado' => fn (Receipt $receipt) => $receipt->status->label(),
        ], $request->string('format', 'xlsx')->toString());
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /** @return Builder<Receipt> */
    private function filteredQuery(Request $request): Builder
    {
        $clientId = $request->integer('client_id');
        $status = $request->string('status')->toString();
        $paymentMethod = $request->string('payment_method')->toString();
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        return Receipt::query()->visibleTo($this->actor($request))
            ->with(['client:id,business_name', 'currency:id,code', 'financialAccount:id,name'])
            ->when($clientId > 0, fn (Builder $query) => $query->where('client_id', $clientId))
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->when($paymentMethod !== '', fn (Builder $query) => $query->where('payment_method', $paymentMethod))
            ->when($from !== '', fn (Builder $query) => $query->whereDate('receipt_date', '>=', $from))
            ->when($to !== '', fn (Builder $query) => $query->whereDate('receipt_date', '<=', $to));
    }

    /**
     * Open invoices of a client the user can see, optionally limited to one currency.
     *
     * @return Collection<int, Invoice>
     */
    private function openInvoices(User $user, int $clientId, ?int $currencyId = null): Collection
    {
        return Invoice::query()->visibleTo($user)->where('client_id', $clientId)
            ->when($currencyId !== null, fn (Builder $query) => $query->where('currency_id', $currencyId))
            ->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid])
            ->orderBy('issue_date')
            ->get(['id', 'number', 'ncf', 'issue_date', 'due_date', 'total', 'itbis_total', 'taxable_total', 'balance', 'currency_id']);
    }

    /** @return array<int, array{value: string, label: string}> */
    private function paymentMethodOptions(): array
    {
        return collect(PaymentMethod::cases())
            ->reject(fn (PaymentMethod $method) => $method === PaymentMethod::Advance)
            ->map(fn (PaymentMethod $method) => ['value' => $method->value, 'label' => $method->label()])
            ->values()
            ->all();
    }
}
