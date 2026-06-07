<?php

namespace App\Jobs;

use App\Actions\Erp\SyncAssets;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncErpAssetsJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 3600; // 1 hour max

    public function __construct(
        public ?int $triggeredByUserId = null
    ) {}

    public function handle(SyncAssets $action): void
    {
        $action->execute($this->triggeredByUserId);
    }
}
