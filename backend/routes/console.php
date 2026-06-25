<?php

use App\Jobs\EvaluatePmRulesJob;
use App\Jobs\SyncErpPartsJob;
use App\Support\Jobs\OverlapKeys;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new SyncErpPartsJob)
    ->name(OverlapKeys::ERP_PART_SYNC)
    ->weekly()->mondays()->at('03:00')
    ->timezone('Africa/Tripoli')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new EvaluatePmRulesJob)
    ->name(OverlapKeys::PM_EVALUATION)
    ->daily()->at('06:00')
    ->timezone('Africa/Tripoli')
    ->withoutOverlapping()
    ->onOneServer();
