// ═══════════════════════════════════════════════════════════════════════════
//  ⚠️  TEMPORARY MOCK — DELETE THIS FILE  ⚠️
//  ─────────────────────────────────────────────────────────────────────────
//  Purpose: lets the Work Order "Parts used" section be exercised in the UI
//  BEFORE the Parts module (`GET /parts`) ships real data.
//
//  This file is imported ONLY by src/composables/useWorkOrderDetail.ts inside
//  blocks tagged `// MOCK(PARTS)`.
//
//  Removal checklist (do this once `GET /parts` returns real rows):
//    1. Delete this file (src/lib/__mockParts.ts).
//    2. In useWorkOrderDetail.ts remove every `// MOCK(PARTS)` block.
//    3. In WorkOrderDetailView.vue revert `parts` -> `record.parts` if needed.
// ═══════════════════════════════════════════════════════════════════════════

export interface MockPart {
  id: number
  name: string
  erp_part_code: string
  unit_of_measure: string | null
}

/** Sentinel: mock catalogue ids live in the 900_000 range; real ids are far lower. */
export const MOCK_PART_ID_FLOOR = 900_000

/** Sentinel: in-memory WO part *line* ids live in the 9_000_000 range. */
export const MOCK_LINE_ID_FLOOR = 9_000_000

export const isMockPartId = (id: number | null): boolean => id !== null && id >= MOCK_PART_ID_FLOOR
export const isMockLineId = (id: number | null): boolean => id !== null && id >= MOCK_LINE_ID_FLOOR

/** A handful of realistic sample parts for the picker + initial table rows. */
export const MOCK_PARTS: MockPart[] = [
  { id: 900_001, name: 'Hydraulic Hose 1/2"', erp_part_code: 'HH-1042', unit_of_measure: 'meter' },
  { id: 900_002, name: 'O-Ring Seal Kit', erp_part_code: 'OR-5521', unit_of_measure: 'each' },
  { id: 900_003, name: 'Bearing Assembly', erp_part_code: 'BG-3309', unit_of_measure: 'each' },
  { id: 900_004, name: 'Filter Element', erp_part_code: 'FE-7781', unit_of_measure: 'each' },
  { id: 900_005, name: 'Lithium Grease 5kg', erp_part_code: 'LG-2255', unit_of_measure: 'tube' },
  { id: 900_006, name: 'Drive Belt V-Section', erp_part_code: 'DB-6610', unit_of_measure: 'each' },
  { id: 900_007, name: 'Pressure Gauge 0–200 bar', erp_part_code: 'PG-9920', unit_of_measure: 'each' },
  { id: 900_008, name: 'Bolting Kit M16', erp_part_code: 'BK-4407', unit_of_measure: 'set' },
]
