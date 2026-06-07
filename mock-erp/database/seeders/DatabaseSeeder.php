<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\Part;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Asset::firstOrCreate(['code' => 'AST-001'], [
            'name' => 'Main Generator',
            'description' => 'Backup power generator',
            'serial_number' => 'GEN-9912-XY',
            'category' => 'Electrical',
            'manufacturer' => 'PowerCorp',
            'model' => 'PC-5000',
            'status' => 'active',
        ]);

        Asset::firstOrCreate(['code' => 'AST-002'], [
            'name' => 'HVAC Unit A',
            'description' => 'Cooling unit for server room',
            'serial_number' => 'HVAC-A-1',
            'category' => 'Mechanical',
            'manufacturer' => 'CoolBreeze',
            'model' => 'CB-200',
            'status' => 'inactive',
        ]);

        Part::firstOrCreate(['code' => 'PRT-001'], [
            'name' => 'Air Filter',
            'description' => 'Standard HVAC air filter',
            'unit_of_measure' => 'EA',
            'category' => 'Consumables',
            'status' => 'active',
        ]);

        Part::firstOrCreate(['code' => 'PRT-002'], [
            'name' => 'Fan Belt',
            'description' => 'Replacement fan belt for generator',
            'unit_of_measure' => 'EA',
            'category' => 'Spares',
            'status' => 'active',
        ]);
    }
}
