<?php

namespace Tests\Feature\Notifications;

use App\Contracts\Notifications\AccountEmailTransport;
use App\Notifications\PasswordResetNotification;
use App\Notifications\UserActivationNotification;
use App\Services\Notifications\GraphAccountEmailTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GraphAccountEmailTransportTest extends TestCase
{
    private GraphAccountEmailTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('account-email.graph_tenant_id', 'test-tenant');
        config()->set('account-email.graph_client_id', 'test-client');
        config()->set('account-email.graph_client_secret', 'test-secret');
        config()->set('account-email.graph_mailbox', 'notification@example.com');

        $this->transport = new GraphAccountEmailTransport;
    }

    public function test_sends_a_rendered_message_through_graph(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => 3600,
            ]),
            'https://graph.microsoft.com/*' => Http::response([], 202),
        ]);

        $this->transport->send(
            'user@example.com',
            'Activate your account',
            'https://atms.example.com/activate?token=abc123'
        );

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'login.microsoftonline.com') &&
                $request->data() === [
                    'client_id' => 'test-client',
                    'client_secret' => 'test-secret',
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ];
        });

        Http::assertSent(function (Request $request) {
            if ($request->url() !== 'https://graph.microsoft.com/v1.0/users/notification%40example.com/sendMail') {
                return false;
            }

            $body = $request->data();
            $message = $body['message'];

            return $request->hasHeader('Authorization', 'Bearer test-token') &&
                $message['subject'] === 'Activate your account' &&
                $message['toRecipients'][0]['emailAddress']['address'] === 'user@example.com' &&
                $message['body']['contentType'] === 'HTML' &&
                str_contains($message['body']['content'], 'https://atms.example.com/activate?token=abc123');
        });
    }

    public function test_retries_a_graph_throttle_using_retry_after(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => 3600,
            ]),
            'https://graph.microsoft.com/*' => Http::sequence()
                ->push([], 429, ['Retry-After' => '0'])
                ->push([], 202),
        ]);

        $this->transport->send(
            'user@example.com',
            'Reset your password',
            'https://atms.example.com/reset?token=abc123'
        );

        Http::assertSentCount(3);
    }

    public function test_graph_is_the_production_account_email_transport(): void
    {
        config()->set('account-email.transport', 'graph');
        $this->app->forgetInstance(AccountEmailTransport::class);

        $this->assertInstanceOf(GraphAccountEmailTransport::class, $this->app->make(AccountEmailTransport::class));
    }

    public function test_account_email_notifications_share_a_delivery_lock(): void
    {
        $activationMiddleware = (new UserActivationNotification('https://atms.example.com/activate'))
            ->middleware(new \stdClass, 'account_email');
        $resetMiddleware = (new PasswordResetNotification('https://atms.example.com/reset'))
            ->middleware(new \stdClass, 'account_email');

        $this->assertCount(1, $activationMiddleware);
        $this->assertCount(1, $resetMiddleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $activationMiddleware[0]);
        $this->assertInstanceOf(WithoutOverlapping::class, $resetMiddleware[0]);
    }

    public function test_account_email_notifications_allow_released_lock_attempts_to_retry(): void
    {
        $this->assertSame(10, (new UserActivationNotification('https://atms.example.com/activate'))->tries);
        $this->assertSame(10, (new PasswordResetNotification('https://atms.example.com/reset'))->tries);
    }
}
