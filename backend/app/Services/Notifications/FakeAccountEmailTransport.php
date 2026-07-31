<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\AccountEmailTransport;

class FakeAccountEmailTransport implements AccountEmailTransport
{
    /** @var array<int, array{to: array<int, string>, cc: array<int, string>, subject: string, templateData: array<string, mixed>}> */
    public static array $sent = [];

    public function send(array $message): void
    {
        static::$sent[] = [
            'to' => $message['to'],
            'cc' => $message['cc'] ?? [],
            'subject' => $message['subject'],
            'templateData' => $message['templateData'],
        ];
    }

    public static function flush(): void
    {
        static::$sent = [];
    }
}
