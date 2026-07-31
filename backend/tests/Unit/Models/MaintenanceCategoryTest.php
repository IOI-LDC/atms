<?php

namespace Tests\Unit\Models;

use App\Models\MaintenanceCategory;
use PHPUnit\Framework\TestCase;

class MaintenanceCategoryTest extends TestCase
{
    public function test_code_for_collapses_separator_runs_and_trims_them(): void
    {
        $this->assertSame('MWD_APS', MaintenanceCategory::codeFor('MWD / APS'));
        $this->assertSame('MWD_VERTEX', MaintenanceCategory::codeFor('MWD---VERTEX'));
        $this->assertSame('MWD_SUB_FLOW', MaintenanceCategory::codeFor('  MWD / SUB FLOW  '));
    }
}
