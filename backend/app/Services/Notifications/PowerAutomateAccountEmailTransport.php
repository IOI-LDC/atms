<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\AccountEmailTransport;
use Illuminate\Support\Facades\Http;

class PowerAutomateAccountEmailTransport implements AccountEmailTransport
{
    public function send(string $recipient, string $subject, string $actionUrl): void
    {
        $accessToken = $this->getAccessToken();

        $response = Http::withToken($accessToken)
            ->post(config('account-email.power_automate_url'), [
                'recipient' => $recipient,
                'subject' => $subject,
                'actionUrl' => $actionUrl,
                'mailbox' => config('account-email.power_automate_mailbox'),
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Power Automate delivery failed: {$response->status()}"
            );
        }
    }

    private function getAccessToken(): string
    {
        $tenantId = config('account-email.power_automate_tenant_id');
        $clientId = config('account-email.power_automate_client_id');
        $clientSecret = config('account-email.power_automate_client_secret');

        $response = Http::asForm()->post(
            "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
            [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]
        );

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to acquire access token from Microsoft.');
        }

        return $response->json('access_token');
    }
}
