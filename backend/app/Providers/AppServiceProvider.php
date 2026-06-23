<?php

namespace App\Providers;

use App\Contracts\Employees\EmployeeDirectorySource;
use App\Contracts\Notifications\AccountEmailTransport;
use App\Models\Attachment;
use App\Notifications\Channels\AccountEmailChannel;
use App\Services\Employees\FakeEmployeeDirectorySource;
use App\Services\Notifications\FakeAccountEmailTransport;
use App\Services\Notifications\PowerAutomateAccountEmailTransport;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AccountEmailTransport::class, function () {
            $transport = config('account-email.transport', 'fake');

            return match ($transport) {
                'power_automate' => new PowerAutomateAccountEmailTransport,
                default => new FakeAccountEmailTransport,
            };
        });

        $this->app->singleton(EmployeeDirectorySource::class, function () {
            $source = config('employees.directory_source', 'fake');

            if ($source === 'sharepoint') {
                throw new \RuntimeException('Real SharePoint transport is not yet implemented. Please use "fake" source.');
            }

            return new FakeEmployeeDirectorySource;
        });
    }

    public function boot(): void
    {
        Relation::morphMap(Attachment::getMorphMap());

        Password::defaults(function () {
            return Password::min(8);
        });

        NotificationFacade::resolved(function (ChannelManager $service) {
            $service->extend('account_email', function ($app) {
                return new AccountEmailChannel($app->make(AccountEmailTransport::class));
            });
        });
    }
}
