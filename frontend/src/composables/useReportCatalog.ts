import type { Component } from 'vue'
import { Activity, CalendarCheck, HardDrive, ListTodo, Package, Gauge } from '@lucide/vue'

/**
 * Static catalogue of the ATMS Phase 1 reports (spec: `docs/atms/01-product/REPORTS.md`).
 *
 * This backs the Reports landing page only. Pass 1 (Must tier) is live:
 * R-1, R-2, R-7, R-8, R-10A, and R-14 are `available` and route to their pages
 * at `/reports/{slug}`. The rest are `planned` (endpoint not built yet) or
 * `deferred` (R-5 no downtime source D-1; R-10B/R-11/R-12 Phase 2). Deferred
 * reports are hidden by the landing page (see `plannedThemes` below).
 *
 * When a report's backend endpoint lands, flip its `status` to `available` and
 * add its route (`/reports/{slug}`). Keep this list in sync with the spec.
 */
export type ReportStatus = 'available' | 'planned' | 'deferred' | 'conditional'

export interface ReportCatalogItem {
  /** Spec identifier, e.g. `R-2`. */
  id: string
  /** Route slug for the future per-report page (`/reports/{slug}`). */
  slug: string
  title: string
  /** The guiding question the report answers (from the spec). */
  question: string
  status: ReportStatus
  /** Short reason shown for deferred/conditional reports. */
  note?: string
}

export interface ReportThemeGroup {
  key: string
  title: string
  icon: Component
  items: ReportCatalogItem[]
}

export interface ReportStatusMeta {
  label: string
  badgeClass: string
}

/** Display label + semantic badge class per status. */
const STATUS_META: Record<ReportStatus, ReportStatusMeta> = {
  available: { label: 'Available', badgeClass: 'report-status-available' },
  planned: { label: 'Coming soon', badgeClass: 'report-status-planned' },
  deferred: { label: 'Deferred', badgeClass: 'report-status-deferred' },
  conditional: { label: 'Conditional', badgeClass: 'report-status-conditional' },
}

export function useReportCatalog(): {
  /** Live reports, flattened — shown first as a prominent "Available now" grid. */
  availableReports: ReportCatalogItem[]
  /** Not-yet-built reports, grouped by theme. Deferred reports are excluded. */
  plannedThemes: ReportThemeGroup[]
  statusMeta: Record<ReportStatus, ReportStatusMeta>
} {
  const allThemes: ReportThemeGroup[] = [
    {
      key: 'reliability',
      title: 'Reliability & Availability',
      icon: Activity,
      items: [
        {
          id: 'R-3',
          slug: 'mtbf-failure-rate',
          title: 'MTBF / Failure Rate by dimension',
          question: 'Where do failures concentrate — by asset, category, or location?',
          status: 'available',
        },
        {
          id: 'R-4',
          slug: 'mttr',
          title: 'MTTR by dimension',
          question: 'What is our repair turnaround by asset, category, or technician?',
          status: 'available',
        },
        {
          id: 'R-5',
          slug: 'asset-availability',
          title: 'Asset Availability / Downtime',
          question: 'Uptime % and total downtime hours per asset in the period.',
          status: 'deferred',
          note: 'Deferred — no dependable downtime source (D-1). Covered in part by MTTR (R-4) and WO Backlog (R-14).',
        },
        {
          id: 'R-6',
          slug: 'bad-actor-analysis',
          title: 'Bad-Actor / Breakdown Analysis',
          question: 'Which assets, categories, or locations have the most confirmed failures?',
          status: 'available',
        },
      ],
    },
    {
      key: 'pm',
      title: 'PM Management',
      icon: CalendarCheck,
      items: [
        {
          id: 'R-1',
          slug: 'upcoming-pm-schedule',
          title: 'Upcoming PM Schedule',
          question: 'Which assets have a PM due in the next 30 days?',
          status: 'available',
        },
        {
          id: 'R-7',
          slug: 'pm-compliance',
          title: 'PM Compliance',
          question: 'On-time PM completion % by rule, asset, location, and period.',
          status: 'available',
        },
        {
          id: 'R-8',
          slug: 'overdue-pm',
          title: 'Overdue PM',
          question: 'Which PMs are past due and not closed, by aging bucket?',
          status: 'available',
        },
        {
          id: 'R-9',
          slug: 'pm-coverage',
          title: 'PM Coverage / Gaps',
          question: 'Which active assets have no active PM assignment?',
          status: 'planned',
        },
      ],
    },
    {
      key: 'fleet',
      title: 'Asset Status & Fleet',
      icon: HardDrive,
      items: [
        {
          id: 'R-2',
          slug: 'assets-by-location',
          title: 'Asset Distribution by Location',
          question: 'Where are our assets, and how many are at each location?',
          status: 'available',
        },
        {
          id: 'R-10A',
          slug: 'asset-status-distribution',
          title: 'Operational Status Distribution',
          question:
            'How is the fleet split across operational states — Active, Under Maintenance, Down, Inactive?',
          status: 'available',
        },
        {
          id: 'R-10B',
          slug: 'maintenance-lifecycle-status',
          title: 'Maintenance Lifecycle Status Distribution',
          question: 'How is the fleet split across ERP-derived maintenance lifecycle states?',
          status: 'deferred',
          note: 'Deferred to Phase 2 — depends on ERP-derived maintenance lifecycle state.',
        },
        {
          id: 'R-11',
          slug: 'lost-decommissioned-assets',
          title: 'Lost / Decommissioned Assets',
          question: 'Counts of LIH, DBR, Disposed, and Scrapped in the period.',
          status: 'deferred',
          note: 'Deferred to Phase 2 — depends on ERP-derived maintenance lifecycle state.',
        },
        {
          id: 'R-12',
          slug: 'spare-rotor-pool',
          title: 'Spare / Rotor Pool',
          question: 'Components Ready (spare) vs Installed — spare availability by category.',
          status: 'deferred',
          note: 'Deferred to Phase 2 — depends on the asset-assembly / component model (D-3).',
        },
        {
          id: 'R-13',
          slug: 'asset-booking',
          title: 'Asset Booking / Availability',
          question: 'Booked vs freely-available assets, by location.',
          status: 'available',
        },
      ],
    },
    {
      key: 'workload',
      title: 'Workload & Backlog',
      icon: ListTodo,
      items: [
        {
          id: 'R-14',
          slug: 'wo-backlog',
          title: 'WO Backlog / Aging',
          question: 'Open and in-progress work orders by age bucket and priority.',
          status: 'available',
        },
        {
          id: 'R-15',
          slug: 'technician-workload',
          title: 'Workload by Technician',
          question:
            'Assigned vs completed work orders and avg duration per technician (operational workload only).',
          status: 'planned',
        },
        {
          id: 'R-16',
          slug: 'throughput',
          title: 'MR / WO Throughput',
          question: 'Counts by status over the period, plus average conversion time.',
          status: 'planned',
        },
      ],
    },
    {
      key: 'parts-movement',
      title: 'Parts & Movement',
      icon: Package,
      items: [
        {
          id: 'R-17',
          slug: 'parts-consumption',
          title: 'Parts Consumption',
          question: 'Quantities used by asset, category, location, and period (top consumers).',
          status: 'available',
        },
        {
          id: 'R-18',
          slug: 'asset-movement-log',
          title: 'Asset Movement Log',
          question: 'Relocations in the period, by from → to route and category.',
          status: 'planned',
        },
      ],
    },
    {
      key: 'inspection',
      title: 'Inspection, Readings & PM Audit',
      icon: Gauge,
      items: [
        {
          id: 'R-19',
          slug: 'wo-form-results',
          title: 'Work Order Form Results',
          question:
            'What pre/post inspection results were recorded, by asset, FA subclass, field, and period?',
          status: 'planned',
        },
        {
          id: 'R-20',
          slug: 'meter-reading-progression',
          title: 'Meter Reading Progression',
          question: 'How have confirmed readings changed over time, by asset and reading type?',
          status: 'available',
        },
        {
          id: 'R-21',
          slug: 'pm-suppression-register',
          title: 'PM Suppression Register',
          question: 'Which PM occurrences were suppressed or overridden — by whom, when, and why?',
          status: 'available',
        },
      ],
    },
  ]

  // Live reports surface first as a flat "Available now" grid.
  const availableReports: ReportCatalogItem[] = allThemes
    .flatMap((theme) => theme.items)
    .filter((item) => item.status === 'available')

  // Remaining themed sections show only planned reports; deferred are hidden for
  // now (kept in the catalogue data above so they're easy to reinstate later).
  const plannedThemes: ReportThemeGroup[] = allThemes
    .map((theme) => ({ ...theme, items: theme.items.filter((item) => item.status === 'planned') }))
    .filter((theme) => theme.items.length > 0)

  return { availableReports, plannedThemes, statusMeta: STATUS_META }
}
