<?php

use App\Models\FinancialAccount;

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
