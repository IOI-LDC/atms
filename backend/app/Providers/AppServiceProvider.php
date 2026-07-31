<?php

namespace App\Providers;

use App\Contracts\Employees\EmployeeDirectorySource;
use App\Contracts\Erp\ErpSource;
use App\Contracts\Notifications\AccountEmailTransport;
use App\Models\Attachment;
use App\Models\User;
use App\Notifications\Channels\AccountEmailChannel;
use App\Services\Employees\CsvEmployeeDirectorySource;
use App\Services\Employees\FakeEmployeeDirectorySource;
use App\Services\Erp\LdcErpHttpSource;
use App\Services\Notifications\FakeAccountEmailTransport;
use App\Services\Notifications\GraphAccountEmailTransport;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Gate;
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
                'graph' => new GraphAccountEmailTransport,
                default => new FakeAccountEmailTransport,
            };
        });

        $this->app->singleton(EmployeeDirectorySource::class, function () {
            $source = config('employees.directory_source', 'csv');

            if ($source === 'sharepoint') {
                throw new \RuntimeException('Real SharePoint transport is not yet implemented. Please use "fake" or "csv" source.');
            }

            if ($source === 'csv') {
                $path = config('employees.csv_path', base_path('employee.csv'));

                return new CsvEmployeeDirectorySource($path);
            }

            return new FakeEmployeeDirectorySource;
        });

        $this->app->singleton(ErpSource::class, LdcErpHttpSource::class);
    }

    public function boot(): void
    {
        Relation::morphMap(Attachment::getMorphMap());

        // The dashboard endpoint is already auth-gated; any authenticated user
        // may view it. Role-based widget visibility is enforced in the controller.
        Gate::define('viewDashboard', fn (User $user): bool => true);

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
