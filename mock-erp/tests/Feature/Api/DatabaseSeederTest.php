<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_is_idempotent(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->seed(\Database\Seeders\DatabaseSeeder::class); // Run it twice

        $this->assertEquals(2, Asset::count());
        $this->assertEquals(2, Part::count());
    }
}
