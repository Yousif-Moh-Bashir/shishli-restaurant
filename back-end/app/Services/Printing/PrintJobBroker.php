<?php

namespace App\Services\Printing;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\PrintAgent;
use App\Models\PrintJob;
use App\Models\PrintJobAttempt;
use Illuminate\Support\Facades\DB;

class PrintJobBroker
{
    /** @return array<string, mixed>|null */
    public function claim(PrintAgent $agent, string $mode): ?array
    {
        return DB::transaction(function () use ($agent, $mode): ?array {
            $agent = PrintAgent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            abort_unless($agent->enabled && $agent->expires_at->isFuture(), 401);
            $this->mode($agent, $mode);
            $ids = PrintJob::where('print_agent_id', $agent->id)->whereIn('status', ['pending', 'leased', 'executing'])
                ->orderBy('id')->limit(100)->pluck('id');
            foreach ($ids as $id) {
                $candidate = PrintJob::findOrFail($id);
                $order = Order::whereKey($candidate->order_id)->lockForUpdate()->firstOrFail();
                $job = PrintJob::whereKey($id)->lockForUpdate()->firstOrFail();
                $this->expire($job);
                if ($job->status !== 'pending' || $job->available_at->isFuture()) {
                    continue;
                }
                if ($order->status === OrderStatus::Cancelled) {
                    $job->update(['status' => 'cancelled']);

                    continue;
                }
                $requestedAt = $job->copy_key === PrintJob::ORIGINAL_COPY
                    ? OrderStatusHistory::findOrFail($job->acceptance_id)->created_at : $job->created_at;
                if ($requestedAt->lt(now()->subMinutes(config('printing.max_age_minutes')))) {
                    $job->update(['status' => 'review_required']);

                    continue;
                }
                if ($job->attempt_count >= config('printing.max_attempts')) {
                    $job->update(['status' => 'failed']);

                    continue;
                }
                $job->update(['status' => 'leased', 'attempt_count' => $job->attempt_count + 1,
                    'lease_until' => now()->addSeconds(config('printing.lease_seconds'))]);
                $attempt = $job->attempts()->create(['number' => $job->attempt_count, 'status' => 'leased']);

                return ['job_id' => $job->uuid, 'attempt_id' => $attempt->uuid, 'destination' => $job->destination,
                    'lease_until' => $job->lease_until->toIso8601String(), 'mode' => $agent->mode,
                    'receipt' => [...$job->receipt, 'is_reprint' => $job->copy_key !== PrintJob::ORIGINAL_COPY]];
            }

            return null;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function start(PrintAgent $agent, string $uuid, string $attemptUuid, string $mode): array
    {
        $this->mode($agent, $mode);

        return $this->locked($agent, $uuid, $attemptUuid, function (Order $order, PrintJob $job, PrintJobAttempt $attempt): array {
            $this->expire($job);
            $attempt->refresh();
            if ($job->status === 'leased' && $attempt->status === 'leased' && $order->status === OrderStatus::Cancelled) {
                $job->update(['status' => 'cancelled', 'lease_until' => null]);
                $attempt->update(['status' => 'cancelled', 'finished_at' => now()]);
            }
            if ($job->status === 'leased' && $attempt->status === 'leased') {
                $job->update(['status' => 'executing', 'lease_until' => now()->addSeconds(config('printing.execution_seconds'))]);
                $attempt->update(['status' => 'executing', 'started_at' => now()]);
            }

            return ['allowed' => $job->status === 'executing' && $attempt->status === 'executing' && $order->status !== OrderStatus::Cancelled,
                'status' => $job->status, 'execution_seconds' => $job->lease_until === null ? 0 : max(0, (int) now()->diffInSeconds($job->lease_until, false))];
        });
    }

    /** @return array<string, mixed> */
    public function result(PrintAgent $agent, string $uuid, string $attemptUuid, string $outcome, ?string $error, ?string $spoolerId): array
    {
        abort_if($outcome === 'spooler_accepted' && ($agent->mode !== 'live' || ! config('printing.allow_live')), 409);
        abort_if($outcome === 'simulated' && $agent->mode !== 'preview', 409);

        return $this->locked($agent, $uuid, $attemptUuid, function (Order $order, PrintJob $job, PrintJobAttempt $attempt) use ($outcome, $error, $spoolerId): array {
            if (in_array($attempt->status, ['spooler_accepted', 'simulated', 'not_submitted', 'uncertain'], true)) {
                abort_unless($attempt->status === $outcome, 409);

                return ['status' => $job->status];
            }
            abort_unless(in_array($attempt->status, ['leased', 'executing'], true), 409);
            abort_if($attempt->status !== 'executing' && $outcome !== 'not_submitted', 409);
            $status = $outcome;
            if ($outcome === 'not_submitted') {
                $status = $order->status === OrderStatus::Cancelled ? 'cancelled'
                    : ($job->attempt_count < config('printing.max_attempts') ? 'pending' : 'failed');
            }
            $attempt->update(['status' => $outcome, 'error_code' => $error, 'spooler_job_id' => $spoolerId, 'finished_at' => now()]);
            $job->update(['status' => $status, 'lease_until' => null, 'available_at' => now()->addSeconds(15 * $job->attempt_count)]);

            return ['status' => $job->status];
        });
    }

    private function mode(PrintAgent $agent, string $mode): void
    {
        abort_unless($agent->mode === $mode && ($mode === 'preview' || config('printing.allow_live')), 409);
    }

    public function expireLeases(): void
    {
        PrintJob::whereIn('status', ['leased', 'executing'])->where('lease_until', '<=', now())
            ->orderBy('id')->limit(200)->get()->each(function (PrintJob $candidate): void {
                DB::transaction(function () use ($candidate): void {
                    Order::whereKey($candidate->order_id)->lockForUpdate()->firstOrFail();
                    $job = PrintJob::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                    $this->expire($job);
                }, 3);
            });
    }

    private function expire(PrintJob $job): void
    {
        if (! in_array($job->status, ['leased', 'executing'], true) || $job->lease_until === null || $job->lease_until->isFuture()) {
            return;
        }
        $attempt = $job->attempts()->where('number', $job->attempt_count)->firstOrFail();
        $status = $job->status === 'executing' ? 'uncertain' : 'lease_expired';
        $attempt->update(['status' => $status, 'error_code' => 'LEASE_EXPIRED', 'finished_at' => now()]);
        $job->update(['status' => $status === 'uncertain' ? 'uncertain' : 'pending', 'lease_until' => null]);
    }

    /** @param callable(Order, PrintJob, PrintJobAttempt): array<string, mixed> $callback
     * @return array<string, mixed>
     */
    private function locked(PrintAgent $agent, string $uuid, string $attemptUuid, callable $callback): array
    {
        $candidate = PrintJob::where('uuid', $uuid)->where('print_agent_id', $agent->id)->firstOrFail();

        return DB::transaction(function () use ($candidate, $attemptUuid, $callback): array {
            $order = Order::whereKey($candidate->order_id)->lockForUpdate()->firstOrFail();
            $job = PrintJob::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $attempt = $job->attempts()->where('uuid', $attemptUuid)->lockForUpdate()->firstOrFail();
            abort_unless($attempt->number === $job->attempt_count, 409);

            return $callback($order, $job, $attempt);
        }, 3);
    }
}
