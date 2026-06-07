<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\SyncErpAssetsJob;
use App\Jobs\SyncErpPartsJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new SyncErpAssetsJob)->weekly()->timezone('Africa/Tripoli')->withoutOverlapping();
Schedule::job(new SyncErpPartsJob)->weekly()->timezone('Africa/Tripoli')->withoutOverlapping();
