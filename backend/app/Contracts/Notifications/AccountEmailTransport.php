<?php

namespace App\Contracts\Notifications;

interface AccountEmailTransport
{
    /**
     * Send an email notification.
     *
     * @param  array{
     *     to: array<int, string>,
     *     cc?: array<int, string>,
     *     subject: string,
     *     templateData: array<string, mixed>,
     * }  $message
     */
    public function send(array $message): void;
}
