<?php

namespace App\Services\Finance;

use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseCategory;
use App\Models\Finance\Outflow;
use App\Models\Finance\Purchase;
use App\Models\Finance\Supplier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class OutflowService
{
    public function __construct(private FinanceAccess $access, private FinanceAudit $audit) {}

    public function model(string $kind): string
    {
        return match ($kind) {
            'expenses' => Expense::class, 'purchases' => Purchase::class
        };
    }

    public function save(User $actor, string $kind, array $data, ?string $uuid, ?UploadedFile $file): Outflow
    {
        $path = null;
        try {
            return DB::transaction(function () use ($actor, $kind, $data, $uuid, $file, &$path): Outflow {
                $model = $this->model($kind);
                $record = $uuid ? $this->access->scope($model::query(), $actor)->where('uuid', $uuid)->lockForUpdate()->firstOrFail() : new $model;
                $branch = $this->access->branch($actor, $data['branch_uuid']);
                $old = $record->exists ? $this->audit->snapshot($record) : [];
                if ($record->exists) {
                    abort_unless($record->version === (int) $data['version'], 409);
                }
                $purchase = $kind === 'purchases';
                if ($purchase) {
                    $data['invoice_number'] = $data['invoice_number'] ?? null;
                    $data['description'] = $data['description'] ?? '';
                }
                $lookup = $purchase ? Supplier::class : ExpenseCategory::class;
                $referenceUuid = $data[$purchase ? 'supplier_uuid' : 'category_uuid'] ?? null;
                $reference = $purchase && ($referenceUuid === null || $referenceUuid === '')
                    ? null : $lookup::where('uuid', $referenceUuid)->lockForUpdate()->firstOrFail();
                $foreignKey = $purchase ? 'supplier_id' : 'category_id';
                if ($reference && ! $reference->is_active && $reference->id !== $record->$foreignKey) {
                    throw ValidationException::withMessages([$purchase ? 'supplier_uuid' : 'category_uuid' => 'العنصر معطّل.']);
                }
                if ($purchase && $reference && $data['invoice_number'] !== null && Purchase::where('supplier_id', $reference->id)->where('invoice_number', $data['invoice_number'])->when($record->exists, fn ($query) => $query->whereKeyNot($record->id))->lockForUpdate()->first() !== null) {
                    throw ValidationException::withMessages(['invoice_number' => 'رقم الفاتورة مسجل لهذا المورد.']);
                }
                $record->fill(Arr::only($data, ['date', 'description', 'payment_method', ...($purchase ? ['invoice_number'] : [])]));
                $record->amount = Money::decimal(Money::cents($data['amount']));
                $record->branch_id = $branch->id;
                $record->$foreignKey = $reference?->id;
                $record->version = $record->exists ? $record->version + 1 : 1;
                if (! $record->exists) {
                    $record->created_by = $actor->id;
                }
                if ($file) {
                    $path = $file->store('finance/attachments', 'local');
                    throw_unless($path, \RuntimeException::class, 'Attachment storage failed.');
                    $record->attachment_path = $path;
                }
                $record->save();
                $new = $this->audit->snapshot($record);
                $new['attachment_changed'] = $file !== null;
                $this->audit->record($actor, $kind.($old ? '.update' : '.create'), $record, $old, $data['reason'] ?? null, $new);

                return $record;
            });
        } catch (\Throwable $error) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
    }
}
