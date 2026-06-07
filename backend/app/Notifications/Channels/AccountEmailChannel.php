<?php

namespace App\Notifications\Channels;

use App\Contracts\Notifications\AccountEmailTransport;
use Illuminate\Notifications\Notification;

class AccountEmailChannel
{
    public function __construct(
        private AccountEmailTransport $transport
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $message = $notification->toAccountEmail($notifiable);

        $this->transport->send(
            $message['recipient'],
            $message['subject'],
            $message['actionUrl']
        );
    }
}
