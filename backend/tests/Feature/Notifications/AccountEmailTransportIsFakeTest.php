<?php

namespace Tests\Feature\Notifications;

use App\Contracts\Notifications\AccountEmailTransport;
use App\Services\Notifications\FakeAccountEmailTransport;
use Tests\TestCase;

/**
 * Guards the one property that must hold for every other test in the suite: a test
 * run must not send real email.
 *
 * This is not hypothetical. Compose injects the live ACCOUNT_EMAIL_TRANSPORT and
 * GRAPH_* values into the container, and on 2026-07-26 — the day the Graph transport
 * was switched on — the suite began calling Microsoft Graph for real, because
 * phpunit.xml did not pin these values. Runtime went from 17s to 170s and the
 * transaction-safety test failed, which is the only reason it was noticed.
 */
class AccountEmailTransportIsFakeTest extends TestCase
{
    public function test_the_bound_transport_is_the_fake_one(): void
    {
        $this->assertInstanceOf(
            FakeAccountEmailTransport::class,
            app(AccountEmailTransport::class),
            'Tests must never resolve a transport that sends real email.'
        );
    }

    public function test_no_real_graph_credentials_are_present(): void
    {
        $this->assertSame('fake', config('account-email.transport'));
        $this->assertEmpty(config('account-email.graph_client_secret'));
        $this->assertEmpty(config('account-email.graph_tenant_id'));
    }
}
