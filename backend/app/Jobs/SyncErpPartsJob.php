<?php

namespace App\Jobs;

use App\Actions\Erp\SyncParts;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncErpPartsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public $timeout = 3600;

    public int $uniqueFor = 3600;

    public function __construct(
        public ?int $triggeredByUserId = null
    ) {}

    public function handle(SyncParts $action): void
    {
        $action->execute($this->triggeredByUserId);
    }
}
