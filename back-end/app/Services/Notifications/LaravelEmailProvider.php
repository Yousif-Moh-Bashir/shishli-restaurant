<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\EmailProviderInterface;
use App\Enums\NotificationErrorCode;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class LaravelEmailProvider implements EmailProviderInterface
{
    public function send(EmailMessageData $message): NotificationProviderResult
    {
        $mailer = config('mail.default');
        $driver = config('mail.mailers.'.$mailer.'.transport');
        if (! in_array($driver, ['smtp', 'ses', 'mailgun', 'postmark', 'sendmail'], true)) {
            return NotificationProviderResult::failure(NotificationErrorCode::ProviderNotConfigured);
        }
        try {
            $sent = Mail::mailer($mailer)->raw($message->body, function (Message $mail) use ($message): void {
                $mail->to($message->recipient, $message->recipientName)->subject($message->subject);
                $mail->getSymfonyMessage()->getHeaders()->addTextHeader('X-Notification-Reference', $message->idempotencyKey);
            });
        } catch (TransportExceptionInterface $exception) {
            $code = match (true) {
                in_array($exception->getCode(), [530, 535], true) => NotificationErrorCode::Unauthorized,
                $exception->getCode() >= 500 => NotificationErrorCode::Rejected,
                $exception->getCode() >= 400 => NotificationErrorCode::Unavailable,
                default => NotificationErrorCode::ConnectionFailure,
            };

            return NotificationProviderResult::failure($code);
        }

        return $sent !== null ? new NotificationProviderResult(true, $sent->getMessageId()) : NotificationProviderResult::failure(NotificationErrorCode::Rejected);
    }
}
