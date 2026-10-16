<?php

declare(strict_types=1);

namespace ShishliRuntimeCi;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ProbeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(public string $token, public bool $shouldFail = false)
    {
        $this->onConnection('redis')->onQueue('runtime-ci');
    }

    public function handle(): void
    {
        if ($this->shouldFail) {
            throw new RuntimeException('CI_INTENTIONAL_JOB_FAILURE');
        }

        DB::table('ci_runtime_probes')->insert(['token' => $this->token, 'kind' => 'queue']);
    }
}
