<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\AccountEmailTransport;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Throwable;

class GraphAccountEmailTransport implements AccountEmailTransport
{
    public function send(string $recipient, string $subject, string $actionUrl): void
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->retry(
                times: 3,
                sleepMilliseconds: function (int $attempt, Throwable $exception): int {
                    if ($exception instanceof RequestException && $exception->response->status() === 429) {
                        return max(1, (int) $exception->response->header('Retry-After', 1)) * 1000;
                    }

                    return $attempt * 1000;
                },
                when: fn (Throwable $exception): bool => $exception instanceof RequestException && $exception->response->status() === 429,
            )
            ->post($this->sendMailUrl(), [
                'message' => [
                    'subject' => $subject,
                    'body' => [
                        'contentType' => 'HTML',
                        'content' => $this->renderMessage($recipient, $subject, $actionUrl),
                    ],
                    'toRecipients' => [[
                        'emailAddress' => ['address' => $recipient],
                    ]],
                ],
                'saveToSentItems' => true,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException("Microsoft Graph email delivery failed: {$response->status()}");
        }
    }

    private function accessToken(): string
    {
        $tenantId = (string) config('account-email.graph_tenant_id');
        $clientId = (string) config('account-email.graph_client_id');
        $cacheKey = 'account-email.graph.access-token.'.hash('sha256', "{$tenantId}|{$clientId}");
        $cachedToken = Cache::get($cacheKey);

        if (is_string($cachedToken) && $cachedToken !== '') {
            return $cachedToken;
        }

        $response = Http::asForm()
            ->connectTimeout(5)
            ->timeout(15)
            ->post("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", [
                'client_id' => $clientId,
                'client_secret' => config('account-email.graph_client_secret'),
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]);

        $accessToken = $response->json('access_token');

        if (! $response->successful() || ! is_string($accessToken) || $accessToken === '') {
            throw new \RuntimeException('Failed to acquire an access token from Microsoft Graph.');
        }

        $expiresIn = max(60, (int) $response->json('expires_in', 300) - 60);
        Cache::put($cacheKey, $accessToken, now()->addSeconds($expiresIn));

        return $accessToken;
    }

    private function sendMailUrl(): string
    {
        return 'https://graph.microsoft.com/v1.0/users/'.rawurlencode((string) config('account-email.graph_mailbox')).'/sendMail';
    }

    private function renderMessage(string $recipient, string $subject, string $actionUrl): string
    {
        return View::make('emails.atms-notification', [
            'heading' => $subject,
            'notificationType' => 'Account notification',
            'recipientName' => Str::before($recipient, '@'),
            'bodyMessage' => 'Use the button below to continue securely in ATMS.',
            'grid' => [
                ['label' => 'System', 'value' => 'ATMS'],
                ['label' => 'Action', 'value' => $subject],
                ['label' => 'Recipient', 'value' => $recipient],
                ['label' => 'Security', 'value' => 'One-time link'],
            ],
            'descriptionLabel' => 'Important',
            'descriptionValue' => 'If you did not expect this email, you can safely ignore it.',
            'actionLabel' => 'Continue securely',
            'actionUrl' => $actionUrl,
        ])->render();
    }
}
