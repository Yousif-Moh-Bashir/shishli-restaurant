<?php

namespace Tests\Support;

use App\Services\Notifications\NotificationProviderResult;
use Closure;
use Throwable;

class FakeNotificationProvider
{
    public array $messages = [];

    /** @var list<NotificationProviderResult|Throwable> */
    public array $results = [];

    public ?Closure $beforeSend = null;

    protected function next(object $message): NotificationProviderResult
    {
        $this->messages[] = $message;
        if ($this->beforeSend !== null) {
            ($this->beforeSend)($message);
        }
        $result = array_shift($this->results) ?? new NotificationProviderResult(true, 'fake-message-'.count($this->messages));
        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }
}
