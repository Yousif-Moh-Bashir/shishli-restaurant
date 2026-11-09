<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Http;
use Throwable;

class StaffTelegramTransport
{
    /** @return array{status:string,error_code?:string,retry_after?:int} */
    public function send(string $chatId, string $text): array
    {
        $token = config('staff_notifications.telegram.token');
        if (! is_string($token) || trim($token) === '' || $chatId === '') {
            return ['status' => 'failed', 'error_code' => 'configuration_required'];
        }
        try {
            $request = Http::connectTimeout(3)->timeout(10)->withoutRedirecting();
            $data = ['chat_id' => $chatId, 'allow_paid_broadcast' => false];
            // Keep one Telegram message even when all order details exceed the text limit.
            if (strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2 > 4096) {
                $method = 'sendDocument';
                $data['allow_paid_broadcast'] = 'false';
                $data['caption'] = mb_substr(implode("\n", array_slice(explode("\n", $text), 0, 5)), 0, 450);
                $request->attach('document', $text, 'shishli-order-details.txt', ['Content-Type' => 'text/plain; charset=UTF-8']);
            } else {
                $method = 'sendMessage';
                $data['text'] = $text;
                $data['link_preview_options'] = ['is_disabled' => true];
            }
            $response = $request->post('https://api.telegram.org/bot'.$token.'/'.$method, $data);
            if ($response->status() === 429 || $response->json('error_code') === 429) {
                return ['status' => 'retry', 'retry_after' => (int) $response->json('parameters.retry_after', 60)];
            }
            if ($response->serverError() && $response->json('ok') === false && (int) $response->json('error_code') >= 500) {
                // Retry only an explicit rejection. A missing/ambiguous response may have been accepted.
                return ['status' => 'retry', 'error_code' => 'telegram_unavailable'];
            }

            return $response->successful() && $response->json('ok') === true
                ? ['status' => 'sent'] : ['status' => 'failed', 'error_code' => 'telegram_rejected'];
        } catch (Throwable) {
            // Never report exceptions: Telegram URLs contain the bot token.
            return ['status' => 'uncertain', 'error_code' => 'transport_uncertain'];
        }
    }
}
