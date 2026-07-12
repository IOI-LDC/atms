<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import ReportPage from '@/components/app/ReportPage.vue'
import ReportSummaryStats from '@/components/app/ReportSummaryStats.vue'
import ReportLoadMore from '@/components/app/ReportLoadMore.vue'
import AssetCombobox from '@/components/app/AssetCombobox.vue'
import PartCombobox from '@/components/app/PartCombobox.vue'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { DatePicker } from '@/components/ui/date-picker'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  usePartsConsumptionReport,
  type PartsConsumptionFilters,
} from '@/composables/usePartsConsumptionReport'
import { useListOptions } from '@/composables/useListOptions'
import { toFaSubclassFilterOptions } from '@/lib/assetColumns'
import { reportDateWindow } from '@/lib/reportOptions'

const ALL = '__all__'
const DEFAULT = reportDateWindow(90)

const { rows, summary, loading, loadingMore, error, hasMore, load, loadMore } =
  usePartsConsumptionReport()
const { faSubclasses, loadFaSubclasses } = useListOptions()

const faSubclassOptions = computed(() => toFaSubclassFilterOptions(faSubclasses.value))

const fromDate = ref(DEFAULT.from)
const toDate = ref(DEFAULT.to)
const selectedPart = ref<{ id: number; label: string } | null>(null)
const selectedAsset = ref<{ id: number; label: string } | null>(null)
const faSubclassCode = ref<string>(ALL)

const todayStr = new Date().toLocaleDateString('en-CA')
const dateRangeError = computed(() =>
  fromDate.value && toDate.value && toDate.value < fromDate.value
    ? 'The "To" date cannot be earlier than the "From" date.'
    : '',
)

const summaryStats = computed(() => {
  if (!summary.value) {
    return []
  }
  const qty =
    summary.value.total_quantity != null
      ? `${summary.value.total_quantity}${summary.value.unit_of_measure ? ' ' + summary.value.unit_of_measure : ''}`
      : '—'
  return [
    { label: 'Line items', value: summary.value.total_line_items },
    { label: 'Distinct parts', value: summary.value.distinct_parts },
    { label: 'Work orders', value: summary.value.distinct_work_orders },
    { label: 'Total qty', value: qty, hint: 'Only when a single unit is in scope' },
  ]
})

function applyFilters() {
  if (dateRangeError.value) {
    return
  }
  const filters: PartsConsumptionFilters = { from: fromDate.value, to: toDate.value }
  if (selectedPart.value) {
    filters.part_id = selectedPart.value.id
  }
  if (selectedAsset.value) {
    filters.asset_id = selectedAsset.value.id
  }
  if (faSubclassCode.value !== ALL) {
    filters.fa_subclass_code = faSubclassCode.value
  }
  load(filters)
}

function clearFilters() {
  fromDate.value = DEFAULT.from
  toDate.value = DEFAULT.to
  selectedPart.value = null
  selectedAsset.value = null
  faSubclassCode.value = ALL
  load({ from: DEFAULT.from, to: DEFAULT.to })
}

onMounted(() => {
  loadFaSubclasses()
  load({ from: DEFAULT.from, to: DEFAULT.to })
})
</script>

<template>
  <ReportPage
    title="Parts Consumption"
    subtitle="Quantities consumed by completed and closed work orders (R-17). Quantity only — no cost."
  >
    <template #filters>
      <div class="report-filters">
        <div class="report-filter">
          <Label for="pc-from">From</Label>
          <DatePicker id="pc-from" v-model="fromDate" :max="toDate || todayStr" />
        </div>
        <div class="report-filter">
          <Label for="pc-to">To</Label>
          <DatePicker id="pc-to" v-model="toDate" :min="fromDate" :max="todayStr" />
        </div>
        <div class="report-filter">
          <Label for="pc-part">Part</Label>
          <PartCombobox v-model="selectedPart" input-id="pc-part" />
        </div>
        <div class="report-filter">
          <Label for="pc-asset">Asset</Label>
          <AssetCombobox v-model="selectedAsset" input-id="pc-asset" />
        </div>
        <div class="report-filter">
          <Label for="pc-class">Asset class</Label>
          <Select v-model="faSubclassCode">
            <SelectTrigger id="pc-class"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All classes</SelectItem>
              <SelectItem v-for="o in faSubclassOptions" :key="o.value" :value="o.value">
                {{ o.label }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="report-filter-actions">
          <Button variant="outline" :disabled="loading" @click="clearFilters">Clear</Button>
          <Button :disabled="loading || !!dateRangeError" @click="applyFilters">Apply</Button>
        </div>
      </div>
      <p v-if="dateRangeError" class="form-error" role="alert">{{ dateRangeError }}</p>
    </template>

    <template #summary>
      <ReportSummaryStats v-if="summary" :stats="summaryStats" />
    </template>

    <div class="data-card">
      <div class="data-card-content">
        <div v-if="error" class="error-state" role="alert">{{ error }}</div>
        <div v-else-if="loading && rows.length === 0" class="loading-state">Loading parts…</div>
        <div v-else-if="rows.length === 0" class="empty-state">
          <p class="empty-state-title">No parts consumed</p>
          <p class="empty-state-description">No parts were used by closed work orders in the window.</p>
        </div>
        <template v-else>
          <p class="report-result-meta">
            Showing {{ rows.length }} {{ rows.length === 1 ? 'part' : 'parts' }}
            <span v-if="hasMore">· more available</span>
          </p>
          <div class="report-table-wrap">
            <table class="report-table">
              <thead>
                <tr>
                  <th scope="col">Part</th>
                  <th scope="col">Asset class</th>
                  <th scope="col" class="report-table-num">Quantity</th>
                  <th scope="col" class="report-table-num">Line items</th>
                  <th scope="col" class="report-table-num">Work orders</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in rows" :key="row.part_id">
                  <td>
                    <span class="report-cell-strong">{{ row.part_name }}</span>
                    <span class="report-cell-muted"> · {{ row.part_code }}</span>
                  </td>
                  <td class="report-cell-muted">{{ row.fa_subclass_code ?? '—' }}</td>
                  <td class="report-table-num report-cell-strong">
                    {{ row.total_quantity }}<span class="report-cell-muted"> {{ row.unit_of_measure }}</span>
                  </td>
                  <td class="report-table-num">{{ row.line_item_count }}</td>
                  <td class="report-table-num">{{ row.work_order_count }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <ReportLoadMore :has-more="hasMore" :loading="loadingMore" @more="loadMore" />
        </template>
      </div>
    </div>
  </ReportPage>
</template>
