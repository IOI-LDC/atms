<?php

namespace App\Jobs;

use App\Actions\Erp\SyncAssets;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncErpAssetsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public $timeout = 3600;

    public int $uniqueFor = 3600;

    public function __construct(
        public ?int $triggeredByUserId = null
    ) {}

    public function handle(SyncAssets $action): void
    {
        $action->execute($this->triggeredByUserId);
    }
}
