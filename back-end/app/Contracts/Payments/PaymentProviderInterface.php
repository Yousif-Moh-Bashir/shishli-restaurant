<?php
namespace App\Contracts\Payments;
use App\Data\Payments\CreatePaymentData;
use App\Data\Payments\PaymentProviderResult;
use App\Data\Payments\WebhookPaymentData;
use App\Enums\PaymentMethod;
interface PaymentProviderInterface
{
    public function supports(PaymentMethod $method): bool;
    public function createPayment(CreatePaymentData $data): PaymentProviderResult;
    public function retrievePayment(string $providerPaymentId): PaymentProviderResult;
    /** @param array<string, list<string>> $headers
     * Verify authenticity and replay timestamp according to the provider contract.
     */
    public function verifyWebhook(string $rawBody, array $headers): bool;
    public function parseWebhook(string $rawBody): WebhookPaymentData;
}
