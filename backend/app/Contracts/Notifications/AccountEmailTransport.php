<?php

namespace App\Contracts\Notifications;

interface AccountEmailTransport
{
    public function send(string $recipient, string $subject, string $actionUrl): void;
}
