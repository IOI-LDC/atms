<?php

use App\Models\Location;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The removed test locations, re-created verbatim on rollback. History
     * rows referencing them stay broken after a rollback — the FK link cannot
     * be restored because the original rows are gone.
     *
     * @var array<int, array{name: string, type: string, code: string, description: string}>
     */
    private const REMOVED = [
        ['name' => 'Workshop', 'type' => 'workshop', 'code' => 'WS', 'description' => 'Main workshop facility'],
        ['name' => 'Main Yard', 'type' => 'yard', 'code' => 'MY', 'description' => 'Primary equipment yard'],
        ['name' => 'Workshop Yard', 'type' => 'yard', 'code' => 'WSY', 'description' => 'Workshop yard area'],
        ['name' => 'Well X', 'type' => 'well_site', 'code' => 'WX', 'description' => 'Well X drilling site'],
        ['name' => 'Well Y', 'type' => 'well_site', 'code' => 'WY', 'description' => 'Well Y drilling site'],
        ['name' => 'Rig A', 'type' => 'rig', 'code' => 'RA', 'description' => 'Rig A location'],
        ['name' => 'Rig B', 'type' => 'rig', 'code' => 'RB', 'description' => 'Rig B location'],
        ['name' => 'Rig C', 'type' => 'rig', 'code' => 'RC', 'description' => 'Rig C location'],
        ['name' => 'Main Building', 'type' => 'building', 'code' => 'MB', 'description' => 'Main building'],
        ['name' => 'Building A', 'type' => 'building', 'code' => 'MBA', 'description' => 'Building A'],
        ['name' => 'Building B', 'type' => 'building', 'code' => 'MBB', 'description' => 'Building B'],
    ];

    public function up(): void
    {
        // Assets already point at Tajoura Base; history rows pointing at the
        // removed locations cascade-delete, and from_location_id nulls via FK.
        Location::where('code', '!=', 'TJB')->delete();
    }

    public function down(): void
    {
        foreach (self::REMOVED as $location) {
            Location::firstOrCreate(['code' => $location['code']], $location);
        }
    }
};
