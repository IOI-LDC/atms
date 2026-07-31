<?php

namespace Tests\Feature\Notifications;

use App\Contracts\Notifications\AccountEmailTransport;
use App\Notifications\Concerns\AccountEmailNotification;
use App\Notifications\PasswordResetNotification;
use App\Notifications\UserActivationNotification;
use App\Services\Notifications\GraphAccountEmailTransport;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\File;
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
        config()->set('account-email.bcc', 'archive@example.com');

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

        $this->transport->send([
            'to' => ['user@example.com'],
            'subject' => 'Activate your account',
            'templateData' => [
                'heading' => 'Activate your account',
                'notificationType' => 'Account notification',
                'recipientName' => 'user',
                'bodyMessage' => 'Use the button below to continue.',
                'grid' => [
                    ['label' => 'System', 'value' => 'ATMS'],
                    ['label' => 'Action', 'value' => 'Activate'],
                    ['label' => 'Recipient', 'value' => 'user@example.com'],
                    ['label' => 'Security', 'value' => 'One-time link'],
                ],
                'descriptionLabel' => 'Important',
                'descriptionValue' => 'Ignore if unexpected.',
                'actionLabel' => 'Continue',
                'actionUrl' => 'https://atms.example.com/activate?token=abc123',
            ],
        ]);

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

    public function test_sends_cc_and_bcc_recipients(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => 3600,
            ]),
            'https://graph.microsoft.com/*' => Http::response([], 202),
        ]);

        $this->transport->send([
            'to' => ['tech@example.com'],
            'cc' => ['manager@example.com'],
            'subject' => 'WO Closed',
            'templateData' => [
                'heading' => 'WO Closed',
                'notificationType' => 'Work order',
                'recipientName' => 'Team',
                'bodyMessage' => 'Done.',
                'grid' => [
                    ['label' => 'A', 'value' => '1'],
                    ['label' => 'B', 'value' => '2'],
                    ['label' => 'C', 'value' => '3'],
                    ['label' => 'D', 'value' => '4'],
                ],
                'descriptionLabel' => 'Info',
                'descriptionValue' => 'Closed.',
                'actionLabel' => 'View',
                'actionUrl' => 'https://atms.example.com/work-orders/1',
            ],
        ]);

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'graph.microsoft.com')) {
                return false;
            }

            $message = $request->data()['message'];

            return $message['toRecipients'][0]['emailAddress']['address'] === 'tech@example.com' &&
                $message['ccRecipients'][0]['emailAddress']['address'] === 'manager@example.com' &&
                $message['bccRecipients'][0]['emailAddress']['address'] === 'archive@example.com';
        });
    }

    public function test_sends_no_bcc_when_none_is_configured(): void
    {
        config()->set('account-email.bcc', null);

        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => 3600,
            ]),
            'https://graph.microsoft.com/*' => Http::response([], 202),
        ]);

        $this->transport->send([
            'to' => ['user@example.com'],
            'subject' => 'No BCC',
            'templateData' => $this->templateData(),
        ]);

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'graph.microsoft.com')) {
                return false;
            }

            return ! array_key_exists('bccRecipients', $request->data()['message']);
        });
    }

    public function test_a_token_failure_reports_the_reason_microsoft_gave(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response([
                'error' => 'invalid_client',
                'error_description' => 'AADSTS7000222: The provided client secret keys are expired.',
            ], 401),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 401.*AADSTS7000222/s');

        $this->transport->send([
            'to' => ['user@example.com'],
            'subject' => 'Any',
            'templateData' => $this->templateData(),
        ]);
    }

    public function test_a_token_failure_never_leaks_the_client_secret(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['error' => 'invalid_client'], 401),
        ]);

        try {
            $this->transport->send([
                'to' => ['user@example.com'],
                'subject' => 'Any',
                'templateData' => $this->templateData(),
            ]);
            $this->fail('Expected the token acquisition to fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('test-secret', $e->getMessage());
        }
    }

    public function test_the_bcc_configuration_declares_no_default_recipient(): void
    {
        $config = (string) file_get_contents(config_path('account-email.php'));

        $this->assertDoesNotMatchRegularExpression(
            "/env\('ACCOUNT_EMAIL_BCC'\s*,/",
            $config,
            'ACCOUNT_EMAIL_BCC must have no default; a default address would silently BCC that person on every message.'
        );
    }

    public function test_sends_multiple_to_recipients(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => 3600,
            ]),
            'https://graph.microsoft.com/*' => Http::response([], 202),
        ]);

        $this->transport->send([
            'to' => ['mgr1@example.com', 'mgr2@example.com'],
            'subject' => 'New MR',
            'templateData' => [
                'heading' => 'New MR',
                'notificationType' => 'Maintenance request',
                'recipientName' => 'Team',
                'bodyMessage' => 'New request.',
                'grid' => [
                    ['label' => 'A', 'value' => '1'],
                    ['label' => 'B', 'value' => '2'],
                    ['label' => 'C', 'value' => '3'],
                    ['label' => 'D', 'value' => '4'],
                ],
                'descriptionLabel' => 'Info',
                'descriptionValue' => 'Review.',
                'actionLabel' => 'View',
                'actionUrl' => 'https://atms.example.com/maintenance/requests/1',
            ],
        ]);

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'graph.microsoft.com')) {
                return false;
            }

            $message = $request->data()['message'];

            return count($message['toRecipients']) === 2 &&
                $message['toRecipients'][0]['emailAddress']['address'] === 'mgr1@example.com' &&
                $message['toRecipients'][1]['emailAddress']['address'] === 'mgr2@example.com';
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

        $this->transport->send([
            'to' => ['user@example.com'],
            'subject' => 'Reset your password',
            'templateData' => [
                'heading' => 'Reset your password',
                'notificationType' => 'Account notification',
                'recipientName' => 'user',
                'bodyMessage' => 'Reset.',
                'grid' => [
                    ['label' => 'A', 'value' => '1'],
                    ['label' => 'B', 'value' => '2'],
                    ['label' => 'C', 'value' => '3'],
                    ['label' => 'D', 'value' => '4'],
                ],
                'descriptionLabel' => 'Info',
                'descriptionValue' => 'Reset link.',
                'actionLabel' => 'Reset',
                'actionUrl' => 'https://atms.example.com/reset?token=abc123',
            ],
        ]);

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

    public function test_every_account_email_notification_is_dispatched_after_commit(): void
    {
        $notifications = collect(File::allFiles(app_path('Notifications')))
            ->filter(fn ($file): bool => $file->getExtension() === 'php')
            ->map(fn ($file): string => 'App\\Notifications\\'.str_replace(
                ['/', '.php'], ['\\', ''], $file->getRelativePathname()
            ))
            ->filter(fn (string $class): bool => class_exists($class)
                && in_array(AccountEmailNotification::class, class_uses_recursive($class), true));

        $this->assertGreaterThan(0, $notifications->count(), 'No account-email notifications were discovered.');

        foreach ($notifications as $class) {
            $this->assertTrue(
                is_subclass_of($class, ShouldQueueAfterCommit::class),
                "{$class} must implement ShouldQueueAfterCommit so a rolled-back transition cannot emit mail."
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function templateData(): array
    {
        return [
            'heading' => 'Heading',
            'notificationType' => 'Account notification',
            'recipientName' => 'user',
            'bodyMessage' => 'Body.',
            'grid' => [
                ['label' => 'A', 'value' => '1'],
                ['label' => 'B', 'value' => '2'],
                ['label' => 'C', 'value' => '3'],
                ['label' => 'D', 'value' => '4'],
            ],
            'descriptionLabel' => 'Info',
            'descriptionValue' => 'Detail.',
            'actionLabel' => 'Open',
            'actionUrl' => 'https://atms.example.com/x',
        ];
    }
}
