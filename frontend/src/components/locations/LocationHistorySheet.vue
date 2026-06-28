<script setup lang="ts">
import { ref, watch } from 'vue'
import {
  Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription,
} from '@/components/ui/sheet'
import { Button } from '@/components/ui/button'
import api from '@/lib/api'
import { fmtDate } from '@/lib/displayHelpers'
import type { Asset, AssetLocationHistoryItem } from '@/types'

const props = defineProps<{
  asset: Asset
  open: boolean
}>()

const emit = defineEmits<{ close: [] }>()

const history = ref<AssetLocationHistoryItem[]>([])
const loading = ref(false)

watch(() => props.open, (nowOpen) => {
  if (nowOpen && props.asset?.id) loadHistory()
  else history.value = []
}, { immediate: true })

async function loadHistory() {
  loading.value = true
  try {
    // The API returns resolved `from_location` / `to_location` objects
    // ({ id, name }) directly — consume them as-is.
    const res = await api.get<{ data: AssetLocationHistoryItem[] }>(
      `/assets/${props.asset.id}/location-history`,
    )
    history.value = res.data ?? []
  } catch {
    history.value = []
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <Sheet :open="open" :modal="false" @update:open="(v) => !v && emit('close')">
    <SheetContent side="right" class="create-sheet">
      <div class="create-sheet-header">
        <SheetHeader>
          <SheetTitle>Location History</SheetTitle>
          <SheetDescription>
            {{ asset.asset_tag ? `${asset.asset_tag} — ` : '' }}{{ asset.name }}
          </SheetDescription>
        </SheetHeader>
      </div>

      <div class="create-sheet-body">
        <div v-if="loading" class="loading-state">Loading history…</div>

        <div v-else-if="history.length === 0" class="empty-state">
          No location changes recorded for this asset.
        </div>

        <table v-else class="detail-table">
          <thead class="detail-table-head">
            <tr>
              <th>Date</th>
              <th>From</th>
              <th>To</th>
              <th>Reason</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="h in history" :key="h.id" class="detail-table-row">
              <td class="detail-table-cell detail-field-muted">{{ fmtDate(h.effective_at) }}</td>
              <td class="detail-table-cell detail-field-muted">
                {{ h.from_location?.name ?? '—' }}
              </td>
              <td class="detail-table-cell">
                {{ h.to_location?.name ?? '—' }}
              </td>
              <td class="detail-table-cell">{{ h.reason ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="create-sheet-footer">
        <Button variant="outline" @click="emit('close')">Close</Button>
      </div>
    </SheetContent>
  </Sheet>
</template>
