<?php

namespace App\Console\Commands;

use App\Services\Notifications\PhoneNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizeOrderPhones extends Command
{
    protected $signature = 'customers:normalize-order-phones {--chunk=500}';

    protected $description = 'Backfill missing normalized order phone snapshots without changing customer identity or displayed snapshots.';

    public function handle(PhoneNormalizer $phones): int
    {
        $size = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        if ($size === false) {
            $this->error('Chunk must be between 1 and 5000.');

            return self::FAILURE;
        }
        $updated = 0;
        $skipped = 0;
        DB::table('orders')->whereNull('customer_phone_normalized')->select(['id', 'customer_phone'])->chunkById($size, function ($orders) use ($phones, &$updated, &$skipped): void {
            foreach ($orders as $order) {
                $normalized = $phones->normalize($order->customer_phone, config('outbound_notifications.phone_country'));
                if ($normalized === null) {
                    $skipped++;

                    continue;
                }
                $updated += DB::table('orders')->where('id', $order->id)->where('customer_phone', $order->customer_phone)->whereNull('customer_phone_normalized')->update(['customer_phone_normalized' => $normalized]);
            }
        });
        $this->info("Normalized: {$updated}; skipped: {$skipped}.");

        return self::SUCCESS;
    }
}
