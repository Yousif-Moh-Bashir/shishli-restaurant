<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakePaymentProvider;

trait ConfiguresPaymentProvider
{
    private function configureProvider(): FakePaymentProvider
    {
        $provider = new FakePaymentProvider;
        $this->app->instance(FakePaymentProvider::class, $provider);
        config(['payments.default_provider' => 'test', 'payments.providers.test.driver' => FakePaymentProvider::class]);

        return $provider;
    }

    /** @param array<string, mixed> $payload */
    private function sendWebhook(array $payload, bool $valid = true, ?int $timestamp = null): TestResponse
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $timestamp ??= now()->timestamp;
        $signature = $valid ? hash_hmac('sha256', $timestamp.'.'.$raw, FakePaymentProvider::SECRET) : 'invalid';

        return $this->call('POST', '/api/v1/webhooks/payments/test', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYMENT_SIGNATURE' => $signature, 'HTTP_X_PAYMENT_TIMESTAMP' => (string) $timestamp,
        ], $raw);
    }
}
