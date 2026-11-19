<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\PrintAgent;
use App\Models\PrintJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderPrintingController extends Controller
{
    public function index(Order $order): JsonResponse
    {
        return ApiResponse::success(data: PrintJob::where('order_id', $order->id)->with('attempts')->orderBy('id')->get());
    }

    public function reprint(Request $request, Order $order, string $job): JsonResponse
    {
        $data = $request->validate(['operation_key' => ['required', 'uuid'], 'reason' => ['required', 'string', 'min:3', 'max:250'],
            'acknowledge_duplicate_risk' => ['required', 'accepted']]);
        $result = DB::transaction(function () use ($request, $order, $job, $data): PrintJob {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->status === OrderStatus::Cancelled, 409);
            $original = PrintJob::where('uuid', $job)->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($original->status, ['spooler_accepted', 'simulated', 'failed', 'uncertain', 'review_required'], true), 409);
            $copy = PrintJob::firstOrCreate(['acceptance_id' => $original->acceptance_id,
                'destination' => $original->destination, 'copy_key' => $data['operation_key']],
                ['order_id' => $order->id, 'print_agent_id' => $original->print_agent_id, 'status' => 'pending',
                    'receipt' => $original->receipt, 'available_at' => now(), 'requested_by' => $request->user()->id,
                    'reprint_reason' => $data['reason']]);
            abort_unless($copy->requested_by === $request->user()->id && $copy->reprint_reason === $data['reason'], 409);

            return $copy;
        }, 3);

        return ApiResponse::success(data: $result);
    }

    public function agents(): JsonResponse
    {
        return ApiResponse::success(data: PrintAgent::orderBy('id')->get());
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate(['branch_id' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:100'],
            'mode' => ['sometimes', 'in:preview,live']]);
        $mode = $data['mode'] ?? 'preview';
        abort_if($mode === 'live' && (! config('printing.enabled') || ! config('printing.allow_live')), 409);
        $branch = Branch::where('uuid', $data['branch_id'])->firstOrFail();
        $token = 'spa_'.bin2hex(random_bytes(32));
        $agent = DB::transaction(function () use ($branch, $data, $token, $mode): PrintAgent {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            abort_if(PrintAgent::where('branch_id', $branch->id)->exists(), 409);

            return PrintAgent::create(['branch_id' => $branch->id, 'name' => $data['name'], 'mode' => $mode,
                'token_hash' => hash('sha256', $token), 'enabled' => true, 'activated_at' => now(),
                'activation_history_id' => OrderStatusHistory::max('id') ?? 0, 'expires_at' => now()->addDays(90)]);
        }, 3);

        return ApiResponse::success(data: ['agent' => $agent, 'token' => $token])->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, PrintAgent $printAgent): JsonResponse
    {
        $data = $request->validate(['enabled' => ['sometimes', 'boolean'], 'rotate_token' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', 'in:live'], 'confirm_new_orders_only' => ['exclude_unless:mode,live', 'required', 'accepted']]);
        $token = ! empty($data['rotate_token']) ? 'spa_'.bin2hex(random_bytes(32)) : null;
        $printAgent = DB::transaction(function () use ($printAgent, $data, $token): PrintAgent {
            $agent = PrintAgent::whereKey($printAgent->id)->lockForUpdate()->firstOrFail();
            if (isset($data['mode'])) {
                abort_unless(config('printing.enabled') && config('printing.allow_live'), 409);
                abort_unless($agent->enabled && $agent->expires_at->isFuture(), 409);
                abort_if($token !== null || array_key_exists('enabled', $data), 409);
                if ($agent->mode !== 'live') {
                    abort_unless($agent->mode === 'preview', 409);
                    abort_if(PrintJob::where('print_agent_id', $agent->id)->whereIn('status', ['leased', 'executing'])->exists(), 409);
                    PrintJob::where('print_agent_id', $agent->id)->where('status', 'pending')->update(['status' => 'review_required']);
                    $agent->mode = 'live';
                    $agent->activated_at = now();
                    $agent->activation_history_id = OrderStatusHistory::max('id') ?? 0;
                }
            }
            if ($token !== null) {
                $agent->token_hash = hash('sha256', $token);
                $agent->expires_at = now()->addDays(90);
            }
            if (array_key_exists('enabled', $data)) {
                $agent->enabled = $data['enabled'];
            }
            $agent->save();

            return $agent;
        }, 3);

        return ApiResponse::success(data: ['agent' => $printAgent, 'token' => $token])->header('Cache-Control', 'no-store, private');
    }
}
