<script setup lang="ts">
import { Button } from '@/components/ui/button'
import { PencilIcon, Trash2Icon } from '@lucide/vue'
import { fmtDate } from '@/lib/displayHelpers'
import type { AssetMeterReading } from '@/types'

/**
 * The meter-reading table used twice on the work order page: once for readings
 * taken on this work order, once for the asset's earlier history. Extracted so
 * the two stay identical rather than drifting apart.
 */
defineProps<{
  readings: AssetMeterReading[]
  /** Catalogue for resolving a reading's type name. */
  types: { id: number; name: string; unit: string }[]
  canManage: boolean
}>()

const emit = defineEmits<{ edit: [reading: AssetMeterReading]; remove: [id: number] }>()
</script>

<template>
  <table class="detail-table">
    <thead class="detail-table-head">
      <tr>
        <th>Reading</th>
        <th>Entered</th>
        <th>Total</th>
        <th>Read at</th>
        <th>Status</th>
        <th v-if="canManage"></th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="r in readings" :key="r.id" class="detail-table-row">
        <td class="detail-table-cell">
          {{ types.find((t) => t.id === r.usage_reading_type_id)?.name ?? 'Meter reading' }}
        </td>
        <!-- What the operator typed. Null for readings entered as an absolute
             (imports, API clients) and for any reading edited afterwards, since
             the edit dialog takes a total and the delta stops matching it. -->
        <td class="detail-table-cell">
          <template v-if="r.entered_delta != null">+{{ r.entered_delta }}</template>
          <span v-else class="detail-table-remove">—</span>
        </td>
        <td class="detail-table-cell table-cell-primary">{{ r.reading_value }}</td>
        <td class="detail-table-cell">{{ fmtDate(r.reading_at) }}</td>
        <td class="detail-table-cell">
          <span v-if="r.confirmed_at">Confirmed</span>
          <span v-else class="detail-table-remove">—</span>
        </td>
        <td v-if="canManage" class="detail-table-cell">
          <div v-if="!r.confirmed_at" class="detail-table-actions">
            <Button
              variant="ghost"
              size="icon-sm"
              :title="`Edit reading ${r.reading_value}`"
              :aria-label="`Edit reading ${r.reading_value}`"
              @click="emit('edit', r)"
            >
              <PencilIcon />
            </Button>
            <Button
              variant="ghost"
              size="icon-sm"
              class="attachment-delete"
              :title="`Delete reading ${r.reading_value}`"
              :aria-label="`Delete reading ${r.reading_value}`"
              @click="emit('remove', r.id)"
            >
              <Trash2Icon />
            </Button>
          </div>
        </td>
      </tr>
    </tbody>
  </table>
</template>
