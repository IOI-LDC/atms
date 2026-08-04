<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $locations = [
            ['name' => 'Tajoura Base',     'type' => 'yard',          'code' => 'TJB',  'description' => 'Tajoura Base — primary asset storage and operational base'],
        ];

        foreach ($locations as $loc) {
            Location::firstOrCreate(
                ['code' => $loc['code']],
                $loc,
            );
        }
    }
}
