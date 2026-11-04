<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Finance\FinanceAudit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

#[Signature('finance:grant-manager {email : Existing user email} {--force : Confirm an explicitly authorized production operation}')]
#[Description('Explicitly grant financial manager access to an existing active user; does not seed or migrate')]
class FinanceGrantManager extends Command
{
    use ConfirmableTrait;

    public function handle(FinanceAudit $audit): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }
        $user = User::where('email', $this->argument('email'))->where('is_active', true)->first();
        if (! $user || ! Role::where('name', 'finance_manager')->where('guard_name', 'web')->exists()) {
            $this->error('An active existing user and the finance_manager role are required. No changes made.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($user, $audit): void {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $old = ['roles' => $user->getRoleNames()->all()];
            $user->assignRole('finance_manager');
            $user->unsetRelation('roles');
            $audit->record($user, 'users.bootstrap_manager', $user, $old, 'Explicit console grant', ['roles' => $user->getRoleNames()->all()]);
            $user->tokens()->delete();
        });
        $this->info('Financial manager access granted. Existing sessions revoked.');

        return self::SUCCESS;
    }
}
