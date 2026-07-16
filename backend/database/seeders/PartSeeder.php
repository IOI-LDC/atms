<?php

namespace Database\Seeders;

use App\Models\Part;
use Illuminate\Database\Seeder;

/**
 * Seeds a realistic placeholder parts catalogue (55 items) so the Parts
 * Management UI and Work Order "Parts used" picker are functional before the
 * ERP Parts sync (SyncErpPartsJob) ships real data.
 *
 * Parts are aligned to the ATMS FA subclass asset catalogue (mud motors,
 * MWD/LWD, jars, shock subs, drill collars, wireline, completion, etc.) plus
 * cross-asset maintenance consumables (hydraulics, filters/fluids, bearings,
 * electrical, consumables).
 *
 * Replacement: when SyncErpPartsJob runs against the real ERP, it upserts into
 * this same table — overwriting these rows. erp_part_id and erp_raw_data are
 * left NULL here so the real sync can populate them without colliding with
 * fake identifiers.
 */
class PartSeeder extends Seeder
{
    public function run(): void
    {
        if (Part::whereNotNull('erp_part_id')->exists()) {
            return;
        }

        foreach ($this->parts() as $code => $part) {
            Part::firstOrCreate(
                ['erp_part_code' => $code],
                array_merge(['erp_part_code' => $code], $part),
            );
        }
    }

    /**
     * @return array<string, array{name: string, description: string|null, unit_of_measure: string, category: string}>
     */
    private function parts(): array
    {
        return [

            // ── Mud Motor (assets: MUD MOTOR, ROTOR, STATOR) ────────────────────
            'MM-ROT-675' => ['name' => 'Rotor 6-3/4"', 'description' => 'Power section rotor for 6-3/4" mud motor', 'unit_of_measure' => 'each', 'category' => 'Mud Motor'],
            'MM-STA-675' => ['name' => 'Stator 6-3/4"', 'description' => 'Power section stator with elastomer lining, 6-3/4"', 'unit_of_measure' => 'each', 'category' => 'Mud Motor'],
            'MM-BRG-001' => ['name' => 'Bearing Assembly', 'description' => 'Radial and thrust bearing stack for mud motor', 'unit_of_measure' => 'each', 'category' => 'Mud Motor'],
            'MM-DSH-001' => ['name' => 'Drive Shaft', 'description' => 'Transmission drive shaft, mud motor to bit', 'unit_of_measure' => 'each', 'category' => 'Mud Motor'],
            'MM-UJT-001' => ['name' => 'Universal Joint', 'description' => 'Flexible universal joint assembly', 'unit_of_measure' => 'each', 'category' => 'Mud Motor'],
            'MM-BHS-172' => ['name' => 'Adjustable Bend Housing 1.72°', 'description' => 'Steerable bent housing, 1.72° setting', 'unit_of_measure' => 'each', 'category' => 'Mud Motor'],
            'MM-SLK-001' => ['name' => 'Mud Motor Seal Kit', 'description' => 'Complete seal kit for mud motor service', 'unit_of_measure' => 'set', 'category' => 'Mud Motor'],
            'MM-ELK-001' => ['name' => 'Stator Elastomer Repair Kit', 'description' => 'Elastomer replacement kit for stator refurbishment', 'unit_of_measure' => 'set', 'category' => 'Mud Motor'],

            // ── MWD/LWD (assets: MWD/LWD, GYRO) ────────────────────────────────
            'MW-BAT-001' => ['name' => 'MWD Battery Pack', 'description' => 'Lithium battery pack for downhole MWD tools', 'unit_of_measure' => 'each', 'category' => 'MWD/LWD'],
            'MW-GMP-001' => ['name' => 'Gamma Sensor Probe', 'description' => 'Gamma ray sensor module', 'unit_of_measure' => 'each', 'category' => 'MWD/LWD'],
            'MW-DIR-001' => ['name' => 'Directional Module', 'description' => 'Directional and inclination sensor package', 'unit_of_measure' => 'each', 'category' => 'MWD/LWD'],
            'MW-PUL-001' => ['name' => 'Mud Pulser Assembly', 'description' => 'Mud pulse telemetry valve assembly', 'unit_of_measure' => 'each', 'category' => 'MWD/LWD'],
            'MW-ANT-001' => ['name' => 'EM Transmitter Antenna', 'description' => 'Electromagnetic transmitter antenna', 'unit_of_measure' => 'each', 'category' => 'MWD/LWD'],
            'MW-CEN-001' => ['name' => 'MWD Centralizer', 'description' => 'Bowspring centralizer for MWD string', 'unit_of_measure' => 'each', 'category' => 'MWD/LWD'],
            'MW-FLW-001' => ['name' => 'Flow Switch', 'description' => 'Pump-down flow detection switch', 'unit_of_measure' => 'each', 'category' => 'MWD/LWD'],

            // ── Downhole Tools: Jars & Shock Subs (assets: JARS, SHOCK SUBS, DHT)
            'DT-JSK-001' => ['name' => 'Hydraulic Jar Seal Kit', 'description' => 'Seal kit for hydraulic drilling jar', 'unit_of_measure' => 'set', 'category' => 'Downhole Tools'],
            'DT-JMD-001' => ['name' => 'Jar Mandrel', 'description' => 'Drilling jar mandrel shaft', 'unit_of_measure' => 'each', 'category' => 'Downhole Tools'],
            'DT-OIL-005' => ['name' => 'Jar Hydraulic Oil 5L', 'description' => 'Specialized hydraulic oil for jar service', 'unit_of_measure' => 'liter', 'category' => 'Downhole Tools'],
            'DT-SSP-001' => ['name' => 'Shock Sub Spring Assembly', 'description' => 'Belleville spring stack for shock sub', 'unit_of_measure' => 'each', 'category' => 'Downhole Tools'],
            'DT-DMR-001' => ['name' => 'Shock Sub Dampener', 'description' => 'Hydraulic dampener cartridge', 'unit_of_measure' => 'each', 'category' => 'Downhole Tools'],

            // ── Drill Collars / NMDC (assets: NMDC, HOLEOPENER) ────────────────
            'DC-HBW-001' => ['name' => 'Hardbanding Wire', 'description' => 'Tungsten carbide hardbanding wire for drill collar refurbishment', 'unit_of_measure' => 'spool', 'category' => 'Drill Collars'],
            'DC-TPR-001' => ['name' => 'Thread Protector', 'description' => 'Steel thread protector, 4-1/2 IF', 'unit_of_measure' => 'each', 'category' => 'Drill Collars'],
            'DC-STB-001' => ['name' => 'Stabilizer Sleeve', 'description' => 'Replaceable stabilizer blade sleeve', 'unit_of_measure' => 'each', 'category' => 'Drill Collars'],
            'DC-RMB-001' => ['name' => 'Reamer Block Set', 'description' => 'Roller reamer cutter block set', 'unit_of_measure' => 'set', 'category' => 'Drill Collars'],
            'DC-XOV-450' => ['name' => 'Crossover Sub 4-1/2 IF', 'description' => '4-1/2 IF to 4-1/2 IF crossover sub', 'unit_of_measure' => 'each', 'category' => 'Drill Collars'],
            'DC-NOZ-001' => ['name' => 'Bit Nozzle Set', 'description' => 'Assorted drill bit nozzle set, 8/16 to 16/32', 'unit_of_measure' => 'set', 'category' => 'Drill Collars'],

            // ── Wireline (asset: WIRELINE) ────────────────────────────────────
            'WL-CAB-716' => ['name' => 'Wireline Cable 7/16"', 'description' => '7/16" single-conductor wireline cable', 'unit_of_measure' => 'meter', 'category' => 'Wireline'],
            'WL-CHD-001' => ['name' => 'Cable Head Assembly', 'description' => 'Wireline cable head with weak point', 'unit_of_measure' => 'each', 'category' => 'Wireline'],
            'WL-RSP-001' => ['name' => 'Rope Socket', 'description' => 'Mechanical rope socket termination', 'unit_of_measure' => 'each', 'category' => 'Wireline'],
            'WL-WPK-001' => ['name' => 'Weak Point Kit', 'description' => 'Shear-pin weak point assembly kit', 'unit_of_measure' => 'set', 'category' => 'Wireline'],

            // ── Completion / Hole Opener (assets: COMPLETION, COMPPER, WHIPSTOCK)
            'HO-CUT-001' => ['name' => 'Hole Opener Cutter Set', 'description' => 'PDC cutter block set for underreamer', 'unit_of_measure' => 'set', 'category' => 'Completion'],
            'HO-BRG-001' => ['name' => 'Hole Opener Bearing', 'description' => 'Sealed bearing for hole opener arm', 'unit_of_measure' => 'each', 'category' => 'Completion'],
            'CM-PKR-001' => ['name' => 'Packer Element', 'description' => 'Compression packer sealing element', 'unit_of_measure' => 'each', 'category' => 'Completion'],
            'CM-STL-001' => ['name' => 'Setting Tool Assembly', 'description' => 'Hydraulic packer setting tool', 'unit_of_measure' => 'each', 'category' => 'Completion'],
            'CM-BRG-001' => ['name' => 'Bridge Plug', 'description' => 'Drillable bridge plug, 7" casing', 'unit_of_measure' => 'each', 'category' => 'Completion'],

            // ── Hydraulics (cross-asset) ──────────────────────────────────────
            'HY-HSE-012' => ['name' => 'Hydraulic Hose 1/2"', 'description' => '1/2" SAE 100R2 hydraulic hose', 'unit_of_measure' => 'meter', 'category' => 'Hydraulics'],
            'HY-HSE-034' => ['name' => 'Hydraulic Hose 3/4"', 'description' => '3/4" SAE 100R2 hydraulic hose', 'unit_of_measure' => 'meter', 'category' => 'Hydraulics'],
            'HY-QCK-001' => ['name' => 'Quick Connect Coupling', 'description' => 'Hydraulic quick-connect coupling, 1/2"', 'unit_of_measure' => 'each', 'category' => 'Hydraulics'],
            'HY-FIT-001' => ['name' => 'High Pressure Fitting Set', 'description' => 'JIC and ORFS fitting assortment', 'unit_of_measure' => 'set', 'category' => 'Hydraulics'],
            'HY-PSK-001' => ['name' => 'Hydraulic Pump Seal Kit', 'description' => 'Mechanical seal kit for hydraulic pump', 'unit_of_measure' => 'set', 'category' => 'Hydraulics'],

            // ── Filters & Fluids (cross-asset) ────────────────────────────────
            'FF-FIL-001' => ['name' => 'Hydraulic Oil Filter Element', 'description' => 'Spin-on hydraulic oil filter element, 10 micron', 'unit_of_measure' => 'each', 'category' => 'Filters & Fluids'],
            'FF-FHL-001' => ['name' => 'High-Pressure Filter Housing', 'description' => 'Stainless steel high-pressure filter housing', 'unit_of_measure' => 'each', 'category' => 'Filters & Fluids'],
            'FF-GRS-005' => ['name' => 'Lithium Grease Complex 5kg', 'description' => 'Lithium complex grease, NLGI #2', 'unit_of_measure' => 'tube', 'category' => 'Filters & Fluids'],
            'FF-TCO-005' => ['name' => 'Thread Compound 5kg', 'description' => 'Copper-free thread doping compound', 'unit_of_measure' => 'can', 'category' => 'Filters & Fluids'],
            'FF-OIL-046' => ['name' => 'Hydraulic Oil ISO 46', 'description' => 'ISO VG 46 anti-wear hydraulic oil', 'unit_of_measure' => 'liter', 'category' => 'Filters & Fluids'],

            // ── Bearings & Seals (cross-asset) ────────────────────────────────
            'BS-ORK-001' => ['name' => 'O-Ring Seal Kit Assorted', 'description' => 'Assorted hydraulic O-ring assortment', 'unit_of_measure' => 'set', 'category' => 'Bearings & Seals'],
            'BS-RDB-080' => ['name' => 'Radial Bearing 80mm', 'description' => 'Deep-groove radial ball bearing, 80mm bore', 'unit_of_measure' => 'each', 'category' => 'Bearings & Seals'],
            'BS-THB-001' => ['name' => 'Thrust Bearing Assembly', 'description' => 'Angular contact thrust bearing pair', 'unit_of_measure' => 'each', 'category' => 'Bearings & Seals'],
            'BS-LIP-120' => ['name' => 'Lip Seal 120mm', 'description' => 'Single-lip rotary seal, 120mm shaft', 'unit_of_measure' => 'each', 'category' => 'Bearings & Seals'],
            'BS-GSK-001' => ['name' => 'Gasket Sheet 1mm', 'description' => 'Compressed fiber gasket sheet, 1mm', 'unit_of_measure' => 'sheet', 'category' => 'Bearings & Seals'],

            // ── Electrical & Sensors (cross-asset) ────────────────────────────
            'EL-PTD-001' => ['name' => 'Pressure Transducer 0-5000psi', 'description' => '0-5000 psi pressure transducer, 4-20mA', 'unit_of_measure' => 'each', 'category' => 'Electrical'],
            'EL-TMP-001' => ['name' => 'Temperature Sensor RTD', 'description' => 'PT100 RTD temperature probe', 'unit_of_measure' => 'each', 'category' => 'Electrical'],
            'EL-JBX-001' => ['name' => 'Junction Box Weatherproof', 'description' => 'IP66 weatherproof junction box', 'unit_of_measure' => 'each', 'category' => 'Electrical'],
            'EL-GLD-001' => ['name' => 'Cable Gland Kit', 'description' => 'M20 brass cable gland assortment', 'unit_of_measure' => 'set', 'category' => 'Electrical'],

            // ── Consumables (cross-asset) ─────────────────────────────────────
            'CN-WIR-001' => ['name' => 'Safety Wire 0.032"', 'description' => 'Stainless safety wire, 0.032" gauge', 'unit_of_measure' => 'spool', 'category' => 'Consumables'],
        ];
    }
}
