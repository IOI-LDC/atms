import type { PmRule, AssetPmAssignment } from '@/types'

/**
 * Compose a human-readable schedule string from a PM rule's trigger config.
 * Accepts either a template (`PmRule`, intervals top-level) or an assignment
 * (`AssetPmAssignment`, intervals nested under `rule`).
 *
 * Examples:
 *   date (90 days)                        → "Every 3 Months"
 *   reading (500, "Operating Hours")      → "Every 500 Operating Hours"
 *   date_or_reading (180d, 500 hrs)       → "Every 500 Operating Hours or 6 Months, whichever comes first"
 *
 * Day-to-period conversion is an approximation (180d ≈ 6 months); acceptable for
 * O&G where intervals are guidelines, not exact deadlines.
 */

/** Convert an interval in days to the largest clean period unit. */
export function formatDayInterval(days: number): string {
  if (days % 365 === 0) {
    const years = days / 365
    return `${years} Year${years === 1 ? '' : 's'}`
  }
  if (days % 30 === 0) {
    const months = days / 30
    return `${months} Month${months === 1 ? '' : 's'}`
  }
  if (days % 7 === 0) {
    const weeks = days / 7
    return `${weeks} Week${weeks === 1 ? '' : 's'}`
  }
  return `${days} Day${days === 1 ? '' : 's'}`
}

/** Full schedule sentence for detail / list display. */
export function pmScheduleText(source: PmRule | AssetPmAssignment): string {
  // Normalise: an assignment carries its template under `rule`; a template has top-level fields.
  const rule = 'rule' in source ? source.rule : source
  const datePart = rule.interval_days != null ? `Every ${formatDayInterval(rule.interval_days)}` : null
  const readingPart =
    rule.interval_reading != null ? `Every ${rule.interval_reading} ${rule.usage_reading_type?.name ?? 'Reading'}` : null

  switch (rule.trigger_type) {
    case 'date':
      return datePart ?? '—'
    case 'reading':
      return readingPart ?? '—'
    case 'date_or_reading':
      if (readingPart && datePart) {
        return `${readingPart} or ${datePart.replace(/^Every /, '')}, whichever comes first`
      }
      return readingPart ?? datePart ?? '—'
    default:
      return '—'
  }
}
