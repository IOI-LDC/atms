<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\AccountEmailTransport;

class FakeAccountEmailTransport implements AccountEmailTransport
{
    public static array $sent = [];

    public function send(string $recipient, string $subject, string $actionUrl): void
    {
        static::$sent[] = [
            'recipient' => $recipient,
            'subject' => $subject,
            'actionUrl' => $actionUrl,
        ];
    }

    public static function flush(): void
    {
        static::$sent = [];
    }
}
