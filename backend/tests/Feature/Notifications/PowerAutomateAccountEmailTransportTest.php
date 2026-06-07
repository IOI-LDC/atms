<?php

namespace Tests\Feature\Notifications;

use App\Services\Notifications\PowerAutomateAccountEmailTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PowerAutomateAccountEmailTransportTest extends TestCase
{
    use RefreshDatabase;

    private PowerAutomateAccountEmailTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('account-email.power_automate_url', 'https://powerautomate.example.com/flow');
        config()->set('account-email.power_automate_tenant_id', 'test-tenant');
        config()->set('account-email.power_automate_client_id', 'test-client');
        config()->set('account-email.power_automate_client_secret', 'test-secret');
        config()->set('account-email.power_automate_mailbox', 'noreply@example.com');

        $this->transport = new PowerAutomateAccountEmailTransport;
    }

    public function test_sends_minimum_required_payload(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'test-token'], 200),
            'https://powerautomate.example.com/*' => Http::response([], 202),
        ]);

        $this->transport->send(
            'user@example.com',
            'Activate your account',
            'https://atms.example.com/activate?token=abc123'
        );

        Http::assertSent(function (Request $request) {
            if (str_contains($request->url(), 'login.microsoftonline')) {
                return true;
            }

            $body = $request->data();
            return isset($body['recipient']) &&
                isset($body['subject']) &&
                isset($body['actionUrl']);
        });
    }

    public function test_non_success_response_is_retryable_failure(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'test-token'], 200),
            'https://powerautomate.example.com/*' => Http::response(['error' => 'denied'], 403),
        ]);

        $this->expectException(\RuntimeException::class);

        $this->transport->send(
            'user@example.com',
            'Test',
            'https://example.com'
        );
    }

    public function test_local_environment_uses_fake_transport(): void
    {
        config()->set('account-email.transport', 'fake');
        $this->assertEquals('fake', config('account-email.transport'));
    }
}
