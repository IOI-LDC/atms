<script setup lang="ts">
/**
 * A single horizontal bar split into proportional, semantically-coloured
 * segments — used for the dashboard utilisation split (deployed / idle /
 * maintenance / no location).
 *
 * Lives under components/ui because segment widths are data-driven and must be
 * applied as inline styles, which feature files may not do.
 */
export interface BarSegment {
  key: string
  label: string
  count: number
  /** Share of the whole bar, 0–100. */
  width: number
}

defineProps<{ segments: BarSegment[]; ariaLabel: string }>()

const tone: Record<string, string> = {
  // Utilisation buckets
  deployed: 'bg-success',
  idle: 'bg-info/45',
  maintenance: 'bg-warning',
  unlocated: 'bg-border',
  unclassified: 'bg-destructive/40',
  // Operational status
  active: 'bg-success',
  under_maintenance: 'bg-warning',
  down: 'bg-destructive',
  asset_inactive: 'bg-muted-foreground/35',
  // Booking
  booked: 'bg-info',
  available: 'bg-secondary-foreground/20',
}
</script>

<template>
  <div
    class="flex h-2 w-full overflow-hidden rounded-full bg-secondary"
    role="img"
    :aria-label="ariaLabel"
  >
    <span
      v-for="segment in segments"
      :key="segment.key"
      class="h-full"
      :class="tone[segment.key] ?? 'bg-muted'"
      :style="{ width: `${segment.width}%` }"
      :title="`${segment.label}: ${segment.count}`"
    />
  </div>
</template>
