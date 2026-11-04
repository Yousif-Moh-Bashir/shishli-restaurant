<?php

namespace App\Services\Finance;

final class FinancePermissions
{
    public static function roles(): array
    {
        $entry = ['finance.access', 'finance.revenues.view', 'finance.revenues.create', 'finance.revenues.edit', 'finance.expenses.view', 'finance.expenses.create', 'finance.purchases.view', 'finance.purchases.create', 'finance.suppliers.view'];
        $accountant = [...$entry, 'finance.expenses.edit', 'finance.purchases.edit', 'finance.suppliers.create', 'finance.suppliers.edit', 'finance.reports.view', 'finance.reports.export'];

        return [
            'finance_entry' => $entry,
            'finance_accountant' => $accountant,
            'finance_manager' => [...$accountant, 'finance.revenues.approve', 'finance.revenues.correct', 'finance.settings.manage', 'finance.users.manage', 'finance.audit.view', 'finance.branches.all'],
        ];
    }
}
