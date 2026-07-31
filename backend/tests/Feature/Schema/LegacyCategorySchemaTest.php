<?php

namespace Tests\Feature\Schema;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyCategorySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_assets_and_parts_do_not_have_legacy_category_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('assets', 'category'));
        $this->assertFalse(Schema::hasColumn('parts', 'category'));
    }
}
