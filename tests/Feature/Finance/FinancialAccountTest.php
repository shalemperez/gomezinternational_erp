<?php

use App\Models\FinancialAccount;
use Spatie\Permission\Models\Role;

crudResourceTests([
    'label' => 'financial accounts', 'routePrefix' => 'finance.accounts', 'component' => 'finance/accounts/index', 'model' => FinancialAccount::class, 'table' => 'financial_accounts', 'routeParam' => 'financialAccount',
    'allowedRole' => 'accounting', 'deniedIndexRole' => 'sales', 'deniedStoreRole' => 'warehouse',
    'payload' => fn () => validFinancialAccountPayload(), 'required' => ['type', 'name', 'currency_id'], 'assertColumn' => 'name',
    'update' => ['field' => 'name', 'value' => 'Cuenta editada'], 'exportTable' => 'accounts',
    'created' => 'Cuenta creada', 'updated' => 'Cuenta actualizada', 'deleted' => 'Cuenta eliminada',
]);

test('bank account requires bank name and number and the number is unique per bank; cash box does not', function () {
    actingAsRole('accounting');
    $this->post(route('finance.accounts.store'), validFinancialAccountPayload(['bank_name' => '', 'account_number' => '']))->assertSessionHasErrors(['bank_name', 'account_number']);
    $this->post(route('finance.accounts.store'), validFinancialAccountPayload())->assertRedirect(route('finance.accounts.index'));
    $this->post(route('finance.accounts.store'), validFinancialAccountPayload(['name' => 'Duplicada']))->assertSessionHasErrors(['account_number']);
    $this->post(route('finance.accounts.store'), validFinancialAccountPayload(['name' => 'Otro banco', 'bank_name' => 'Banreservas']))->assertRedirect(route('finance.accounts.index'));
    $this->post(route('finance.accounts.store'), validFinancialAccountPayload(['type' => 'cash', 'name' => 'Caja chica', 'bank_name' => '', 'account_number' => '']))->assertRedirect(route('finance.accounts.index'));
    expect(FinancialAccount::where('type', 'cash')->first()->bank_name)->toBeNull();
});

test('switching an account to cash clears the bank fields', function () {
    actingAsRole('accounting');
    $account = FinancialAccount::factory()->create(['type' => 'bank', 'bank_name' => 'Banco Popular', 'account_number' => '1']);
    $this->put(route('finance.accounts.update', $account), validFinancialAccountPayload(['type' => 'cash', 'name' => 'Caja', 'bank_name' => 'Banco Popular', 'account_number' => '1']))->assertRedirect(route('finance.accounts.index'));
    expect($account->fresh()->bank_name)->toBeNull()->and($account->fresh()->account_number)->toBeNull();
});

test('export requires the accounts export permission', function () {
    actingAsRole('accounting');
    Role::findByName('accounting')->revokePermissionTo('finance.accounts.export');
    $this->get(route('finance.accounts.export', ['format' => 'csv']))->assertForbidden();
});
