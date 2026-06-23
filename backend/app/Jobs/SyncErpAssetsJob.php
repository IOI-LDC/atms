<?php

namespace App\Jobs;

use App\Actions\Erp\SyncAssets;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncErpAssetsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public int $timeout = 3600;

    public int $uniqueFor = 3600;

    public function __construct(
        public ?int $triggeredByUserId = null
    ) {}

    public function handle(SyncAssets $action): void
    {
        $action->execute($this->triggeredByUserId);
    }
}
