<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Finance\AuditLog;
use App\Models\Finance\ExpenseCategory;
use App\Models\Finance\RevenueSource;
use App\Models\Finance\Supplier;
use App\Services\Finance\FinanceAccess;
use App\Services\Finance\FinanceAudit;
use App\Services\Finance\ValidationMessages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function __construct(private FinanceAccess $access, private FinanceAudit $audit) {}

    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success([
            'timezone' => config('finance.timezone'),
            'currency' => config('finance.currency'),
            'user' => ['uuid' => $user->uuid, 'name' => $user->name, 'email' => $user->email],
            'permissions' => $user->getAllPermissions()->pluck('name')->filter(fn (string $name): bool => str_starts_with($name, 'finance.'))->values(),
            'branches' => $this->access->branches($user)->orderBy('name')->get(['uuid', 'name']),
            'sources' => RevenueSource::orderBy('id')->get(),
            'categories' => ExpenseCategory::orderBy('id')->get(),
        ]);
    }

    private function model(string $kind): string
    {
        return match ($kind) {
            'sources' => RevenueSource::class, 'categories' => ExpenseCategory::class, 'suppliers' => Supplier::class
        };
    }

    public function index(Request $request): JsonResponse
    {
        $kind = $request->route('kind');
        abort_unless($request->user()->can($kind === 'suppliers' ? 'finance.suppliers.view' : 'finance.settings.manage'), 403);
        $class = $this->model($kind);

        return ApiResponse::success($class::orderBy('name')->get());
    }

    public function save(Request $request): JsonResponse
    {
        $kind = $request->route('kind');
        $uuid = $request->route('uuid');
        abort_unless($request->user()->can($kind === 'suppliers' ? 'finance.suppliers.'.($uuid ? 'edit' : 'create') : 'finance.settings.manage'), 403);
        $class = $this->model($kind);
        $record = $uuid ? $class::where('uuid', $uuid)->firstOrFail() : new $class;
        $rules = ['name' => ['required', 'string', 'max:120'], 'is_active' => ['required', 'boolean']];
        if ($kind !== 'suppliers') {
            $rules['name'][] = Rule::unique($record->getTable(), 'name')->ignore($record->id);
        } else {
            $rules += ['phone' => ['nullable', 'string', 'max:40'], 'tax_number' => ['nullable', 'string', 'max:40'], 'notes' => ['nullable', 'string', 'max:5000']];
        }
        $data = $request->validate($rules, ValidationMessages::messages(), ValidationMessages::attributes());
        $record = DB::transaction(function () use ($request, $record, $data, $kind): Model {
            if ($record->exists) {
                $record = $record->newQuery()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            }
            $old = $record->exists ? $this->audit->snapshot($record) : [];
            $record->fill($data)->save();
            $this->audit->record($request->user(), $kind.($old ? '.update' : '.create'), $record, $old);

            return $record;
        });

        return ApiResponse::success($record, statusCode: $uuid ? 200 : 201);
    }

    public function audit(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'branch_uuid' => ['nullable', 'uuid']], ValidationMessages::messages(), ValidationMessages::attributes());
        $query = AuditLog::query();
        if (! $request->user()->can('finance.branches.all')) {
            $this->access->scope($query, $request->user());
        }
        if ($request->filled('branch_uuid')) {
            $query->where('branch_id', $this->access->branch($request->user(), $request->string('branch_uuid')->toString())->id);
        }
        $result = $query->with('actor:id,uuid,name')->orderByDesc('id')->paginate(25);

        return ApiResponse::success($result);
    }
}
