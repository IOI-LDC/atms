<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $locations = [
            ['name' => 'Workshop',       'type' => 'workshop',      'code' => 'WS',   'description' => 'Main workshop facility'],
            ['name' => 'Main Yard',       'type' => 'yard',          'code' => 'MY',   'description' => 'Primary equipment yard'],
            ['name' => 'Workshop Yard',   'type' => 'workshop_yard', 'code' => 'WSY',  'description' => 'Workshop yard area'],
            ['name' => 'Well X',          'type' => 'well_site',     'code' => 'WX',   'description' => 'Well X drilling site'],
            ['name' => 'Well Y',          'type' => 'well_site',     'code' => 'WY',   'description' => 'Well Y drilling site'],
            ['name' => 'Rig A',           'type' => 'rig',           'code' => 'RA',   'description' => 'Rig A location'],
            ['name' => 'Rig B',           'type' => 'rig',           'code' => 'RB',   'description' => 'Rig B location'],
            ['name' => 'Rig C',           'type' => 'rig',           'code' => 'RC',   'description' => 'Rig C location'],
        ];

        foreach ($locations as $loc) {
            Location::firstOrCreate(
                ['code' => $loc['code']],
                $loc,
            );
        }
    }
}
