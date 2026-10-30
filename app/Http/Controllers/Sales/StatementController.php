<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Mail\SendDocumentEmail;
use App\Exports\RowsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SendDocumentEmailRequest;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Salesperson;
use App\Models\User;
use App\Services\Documents\DocumentPdf;
use App\Services\Sales\ClientAccount;
use App\Services\Sales\ClientStatementService;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class StatementController extends Controller
{
    #[Middleware('permission:sales.statements.view')]
    public function index(Request $request, ClientStatementService $statements): Response
    {
        $user = $this->actor($request);

        return Inertia::render('sales/statements/index', [
            'aging' => $this->aging($request, $statements),
            'filters' => [
                'as_of' => $this->asOf($request)->toDateString(),
                'salesperson_id' => $request->integer('salesperson_id') ?: null,
                'search' => $request->string('search')->trim()->toString(),
            ],
            'salespeople' => $user->can('catalog.clients.view-all') ? Salesperson::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
            'asOf' => $this->asOf($request)->toDateString(),
        ]);
    }

    #[Middleware('permission:sales.statements.view')]
    public function show(Request $request, Client $client, ClientStatementService $statements): Response
    {
        Gate::authorize('view', $client);
        [$from, $to] = $this->period($request);
        $client->loadMissing('currency:id,code');

        return Inertia::render('sales/statements/show', [
            'client' => [
                'id' => $client->id,
                'code' => $client->code,
                'business_name' => $client->business_name,
                'formatted_tax_id' => $client->getAttribute('formatted_tax_id'),
                'email' => $client->email,
                'credit_limit' => $client->credit_limit,
                'credit_days' => $client->credit_days,
            ],
            'statement' => $statements->statement($client, $from, $to),
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'advances' => $this->advances($client),
            'canSend' => $this->actor($request)->can('sales.statements.send'),
        ]);
    }

    #[Middleware('permission:sales.statements.view')]
    public function pdf(Request $request, Client $client, ClientStatementService $statements, DocumentPdf $pdf): SymfonyResponse
    {
        Gate::authorize('view', $client);
        [$from, $to] = $this->period($request);
        $user = $this->actor($request);
        activity('clients')->causedBy($user)->performedOn($client)->event('statement_generated')
            ->withProperties(['from' => $from->toDateString(), 'to' => $to->toDateString()])
            ->log("Estado de cuenta de {$client->business_name} generado");

        $fileName = "estado-de-cuenta-{$client->code}-{$to->format('Ymd')}.pdf";

        return $pdf->stream('pdf.sales.statement', $this->pdfData($client, $from, $to, $statements, $user), $fileName);
    }

    #[Middleware('permission:sales.statements.send')]
    public function send(SendDocumentEmailRequest $request, Client $client, SendDocumentEmail $sendEmail, DocumentPdf $pdf, Settings $settings, ClientStatementService $statements): RedirectResponse
    {
        Gate::authorize('view', $client);
        $user = $this->actor($request);
        $to = array_values($request->validated('to'));
        $cc = array_values($request->validated('cc') ?? []);
        $request->validate(['period_from' => ['nullable', 'date'], 'period_to' => ['nullable', 'date', 'after_or_equal:period_from']]);
        [$defaultFrom, $defaultTo] = $this->defaultPeriod();
        $from = $request->string('period_from')->toString() !== '' ? CarbonImmutable::parse($request->string('period_from')->toString())->startOfDay() : $defaultFrom;
        $asOf = $request->string('period_to')->toString() !== '' ? CarbonImmutable::parse($request->string('period_to')->toString())->startOfDay() : $defaultTo;
        $client->loadMissing('currency:id,code');
        $currencyCode = $client->currency->code;
        $data = $this->pdfData($client, $from, $asOf, $statements, $user);
        $closing = $data['statement'][$currencyCode]['closing_balance'] ?? '0.00';

        $vars = [
            'cliente' => $client->business_name,
            'empresa' => (string) $settings->get('company.name', ''),
            'total' => DocumentPdf::money($closing, $currencyCode),
            'fecha' => $asOf->format('d/m/Y'),
            'usuario' => $user->name,
        ];
        $fileName = "estado-de-cuenta-{$client->code}-{$asOf->format('Ymd')}.pdf";
        $attachments = [[
            'name' => $fileName,
            'mime' => 'application/pdf',
            'content' => $pdf->binary('pdf.sales.statement', $data, $fileName),
        ]];

        $sendEmail->handle($user, $client, 'statement', $to, $cc, $vars, $attachments, $request->validated('body') ?: null, $request->validated('subject') ?: null);

        return back()->with('success', 'Correo enviado a '.implode(', ', $to));
    }

    #[Middleware('permission:sales.statements.view')]
    public function export(Request $request, ClientStatementService $statements): BinaryFileResponse
    {
        $rows = array_values(array_map(fn (array $row): array => [
            $row['client_code'], $row['client_name'], $row['salesperson'], $row['currency_code'], $row['current'], $row['d1_30'],
            $row['d31_60'], $row['d61_90'], $row['d90_plus'], $row['total'], $row['total_base'],
        ], $this->aging($request, $statements)['rows']));

        return RowsExport::download($rows, 'aging', ['Código', 'Cliente', 'Vendedor', 'Moneda', 'Vigente', '1-30', '31-60', '61-90', '+90', 'Total', 'Total RD$'], $request->string('format', 'xlsx')->toString());
    }

    /** @return array<string, mixed> */
    private function aging(Request $request, ClientStatementService $statements): array
    {
        $search = $request->string('search')->trim()->toString();

        return $statements->aging(
            $this->actor($request),
            $this->asOf($request),
            $request->integer('salesperson_id') ?: null,
            $search !== '' ? $search : null,
        );
    }

    private function asOf(Request $request): CarbonImmutable
    {
        $request->validate(['as_of' => ['nullable', 'date', 'before_or_equal:today']], ['as_of.before_or_equal' => 'La fecha de corte no puede ser futura.']);
        $asOf = $request->string('as_of')->toString();

        return CarbonImmutable::parse($asOf !== '' ? $asOf : 'today')->startOfDay();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(Request $request): array
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        [$defaultFrom, $defaultTo] = $this->defaultPeriod();
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        return [
            $from !== '' ? CarbonImmutable::parse($from)->startOfDay() : $defaultFrom,
            $to !== '' ? CarbonImmutable::parse($to)->startOfDay() : $defaultTo,
        ];
    }

    /**
     * From the first day of the month eleven months ago until today.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function defaultPeriod(): array
    {
        return [CarbonImmutable::now()->subMonths(11)->startOfMonth(), CarbonImmutable::now()->startOfDay()];
    }

    /** @return array<string, mixed> */
    private function pdfData(Client $client, CarbonImmutable $from, CarbonImmutable $to, ClientStatementService $statements, User $viewer): array
    {
        return [
            'client' => $client,
            'from' => $from,
            'to' => $to,
            'statement' => $statements->statement($client, $from, $to),
            'aging' => $statements->aging($viewer, $to, null, null, $client->id)['rows'],
        ];
    }

    /** @return list<array{code: string, amount: string}> */
    private function advances(Client $client): array
    {
        return array_values(Currency::query()->active()->orderBy('code')->get()
            ->map(fn (Currency $currency) => ['code' => $currency->code, 'amount' => ClientAccount::advanceBalance($client, $currency)])
            ->all());
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
