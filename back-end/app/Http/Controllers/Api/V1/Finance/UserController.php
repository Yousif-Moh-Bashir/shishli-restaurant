<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Branch;
use App\Models\User;
use App\Services\Finance\FinanceAudit;
use App\Services\Finance\FinancePermissions;
use App\Services\Finance\ValidationMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct(private FinanceAudit $audit) {}

    private function values(User $user): array
    {
        return ['uuid' => $user->uuid, 'name' => $user->name, 'email' => $user->email, 'is_active' => $user->is_active,
            'role' => $user->getRoleNames()->first(fn (string $role): bool => str_starts_with($role, 'finance_')),
            'branch_uuids' => Branch::whereIn('id', DB::table('finance_branch_user')->where('user_id', $user->id)->select('branch_id'))->pluck('uuid')->all()];
    }

    public function index(): JsonResponse
    {
        return ApiResponse::success(['users' => User::role(array_keys(FinancePermissions::roles()))->with('roles')->orderBy('name')->get()->map(fn (User $user): array => $this->values($user)), 'roles' => array_keys(FinancePermissions::roles())]);
    }

    public function save(Request $request, ?string $uuid = null): JsonResponse
    {
        $user = $uuid ? User::role(array_keys(FinancePermissions::roles()))->where('uuid', $uuid)->firstOrFail() : new User;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => [$uuid ? 'nullable' : 'required', 'string', Password::min(12)->mixedCase()->numbers()],
            'role' => ['required', Rule::in(array_keys(FinancePermissions::roles()))],
            'is_active' => ['required', 'boolean'],
            'branch_uuids' => ['required', 'array', 'min:1'],
            'branch_uuids.*' => ['required', 'uuid', 'distinct', Rule::exists('branches', 'uuid')->whereNull('deleted_at')],
        ], ValidationMessages::messages(), ValidationMessages::attributes());
        $user = DB::transaction(function () use ($request, $user, $data): User {
            Role::where('name', 'finance_manager')->where('guard_name', 'web')->lockForUpdate()->firstOrFail();
            if ($user->exists) {
                $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                abort_if($user->roles()->whereNotIn('name', array_keys(FinancePermissions::roles()))->exists(), 403, 'هذا الحساب مشترك مع نظام آخر.');
                abort_if($user->getAllPermissions()->contains(fn ($permission): bool => ! str_starts_with($permission->name, 'finance.')), 403, 'هذا الحساب يملك صلاحيات لنظام آخر.');
                abort_if($user->id === $request->user()->id && (! $data['is_active'] || $data['role'] !== 'finance_manager'), 422, 'لا يمكن إزالة صلاحياتك أو تعطيل حسابك.');
                if ($user->hasRole('finance_manager') && (! $data['is_active'] || $data['role'] !== 'finance_manager')) {
                    abort_unless(User::role('finance_manager')->where('is_active', true)->whereKeyNot($user->id)->exists(), 422, 'يلزم وجود مدير مالي نشط.');
                }
            }
            $old = $user->exists ? $this->values($user) : [];
            $user->fill(Arr::only($data, ['name', 'email']));
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->is_active = $data['is_active'];
            $user->save();
            $user->syncRoles([$data['role']]);
            DB::table('finance_branch_user')->where('user_id', $user->id)->delete();
            foreach (Branch::whereIn('uuid', $data['branch_uuids'])->pluck('id') as $branchId) {
                DB::table('finance_branch_user')->insert(['user_id' => $user->id, 'branch_id' => $branchId]);
            }
            if ($old) {
                $user->tokens()->delete();
            }
            $user->unsetRelation('roles');
            $this->audit->record($request->user(), $old ? 'users.update' : 'users.create', $user, $old, new: $this->values($user));

            return $user;
        });

        return ApiResponse::success($this->values($user), statusCode: $uuid ? 200 : 201);
    }
}
