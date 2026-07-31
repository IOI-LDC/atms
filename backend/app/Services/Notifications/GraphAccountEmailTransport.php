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
    public function send(array $message): void
    {
        $payload = [
            'message' => [
                'subject' => $message['subject'],
                'body' => [
                    'contentType' => 'HTML',
                    'content' => $this->renderMessage($message['templateData']),
                ],
                'toRecipients' => $this->formatRecipients($message['to']),
            ],
            'saveToSentItems' => true,
        ];

        if (! empty($message['cc'])) {
            $payload['message']['ccRecipients'] = $this->formatRecipients($message['cc']);
        }

        $bcc = config('account-email.bcc');

        if (is_string($bcc) && $bcc !== '') {
            $payload['message']['bccRecipients'] = $this->formatRecipients([$bcc]);
        }

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
            ->post($this->sendMailUrl(), $payload);

        if (! $response->successful()) {
            throw new \RuntimeException("Microsoft Graph email delivery failed: {$response->status()}");
        }
    }

    /**
     * @param  array<int, string>  $emails
     * @return array<int, array{emailAddress: array{address: string}}>
     */
    private function formatRecipients(array $emails): array
    {
        return array_map(
            fn (string $email): array => ['emailAddress' => ['address' => $email]],
            array_values($emails)
        );
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
            // Azure's error_description carries the AADSTS code that names the actual
            // cause (expired secret, wrong tenant, and so on). It never contains the
            // secret itself, so it is safe to surface, and without it this failure is
            // indistinguishable from a network problem.
            throw new \RuntimeException(sprintf(
                'Failed to acquire an access token from Microsoft Graph (HTTP %d): %s',
                $response->status(),
                Str::limit((string) ($response->json('error_description')
                    ?? $response->json('error')
                    ?? 'no error detail returned'), 300)
            ));
        }

        $expiresIn = max(60, (int) $response->json('expires_in', 300) - 60);
        Cache::put($cacheKey, $accessToken, now()->addSeconds($expiresIn));

        return $accessToken;
    }

    private function sendMailUrl(): string
    {
        return 'https://graph.microsoft.com/v1.0/users/'.rawurlencode((string) config('account-email.graph_mailbox')).'/sendMail';
    }

    /**
     * @param  array<string, mixed>  $templateData
     */
    private function renderMessage(array $templateData): string
    {
        return View::make('emails.atms-notification', $templateData)->render();
    }
}
