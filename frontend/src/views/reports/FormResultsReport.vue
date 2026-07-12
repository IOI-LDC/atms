<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import ReportPage from '@/components/app/ReportPage.vue'
import ReportSummaryStats from '@/components/app/ReportSummaryStats.vue'
import ReportLoadMore from '@/components/app/ReportLoadMore.vue'
import AssetCombobox from '@/components/app/AssetCombobox.vue'
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
import { useFormResultsReport, type FormResultsFilters } from '@/composables/useFormResultsReport'
import { useListOptions } from '@/composables/useListOptions'
import { toFaSubclassFilterOptions } from '@/lib/assetColumns'
import { woFormFieldTypeLabel, woFieldValueDisplay } from '@/lib/displayHelpers'
import { reportDateWindow } from '@/lib/reportOptions'

const ALL = '__all__'
const DEFAULT = reportDateWindow(90)

const { rows, summary, loading, loadingMore, error, hasMore, load, loadMore } = useFormResultsReport()
const { faSubclasses, loadFaSubclasses } = useListOptions()

const faSubclassOptions = computed(() => toFaSubclassFilterOptions(faSubclasses.value))
const numericComparisons = computed(() => summary.value?.numeric_comparisons ?? [])

const fromDate = ref(DEFAULT.from)
const toDate = ref(DEFAULT.to)
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
  return [
    { label: 'Field responses', value: summary.value.total_fields },
    { label: 'Boolean “Yes”', value: summary.value.boolean_true_count },
    { label: 'Boolean “No”', value: summary.value.boolean_false_count },
    { label: 'Numeric pairs', value: summary.value.numeric_pre_post_count },
  ]
})

function applyFilters() {
  if (dateRangeError.value) {
    return
  }
  const filters: FormResultsFilters = { from: fromDate.value, to: toDate.value }
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
    title="Work Order Form Results"
    subtitle="Recorded pre/post inspection values, with same-field numeric comparisons (R-19)."
  >
    <template #filters>
      <div class="report-filters">
        <div class="report-filter">
          <Label for="fr-from">From</Label>
          <DatePicker id="fr-from" v-model="fromDate" :max="toDate || todayStr" />
        </div>
        <div class="report-filter">
          <Label for="fr-to">To</Label>
          <DatePicker id="fr-to" v-model="toDate" :min="fromDate" :max="todayStr" />
        </div>
        <div class="report-filter">
          <Label for="fr-asset">Asset</Label>
          <AssetCombobox v-model="selectedAsset" input-id="fr-asset" />
        </div>
        <div class="report-filter">
          <Label for="fr-class">Asset class</Label>
          <Select v-model="faSubclassCode">
            <SelectTrigger id="fr-class"><SelectValue /></SelectTrigger>
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

    <!-- Numeric comparisons (same field + unit only) -->
    <div v-if="numericComparisons.length" class="data-card">
      <div class="data-card-header">
        <h2 class="data-card-title">Numeric comparisons</h2>
      </div>
      <div class="data-card-content">
        <div class="report-table-wrap">
          <table class="report-table">
            <thead>
              <tr>
                <th scope="col">Field</th>
                <th scope="col">Unit</th>
                <th scope="col" class="report-table-num">Pairs</th>
                <th scope="col" class="report-table-num">Avg pre</th>
                <th scope="col" class="report-table-num">Avg post</th>
                <th scope="col" class="report-table-num">Avg change</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in numericComparisons" :key="c.field_uuid">
                <td class="report-cell-strong">{{ c.label }}</td>
                <td class="report-cell-muted">{{ c.unit ?? '—' }}</td>
                <td class="report-table-num">{{ c.comparison_count }}</td>
                <td class="report-table-num">{{ c.avg_pre_value ?? '—' }}</td>
                <td class="report-table-num">{{ c.avg_post_value ?? '—' }}</td>
                <td class="report-table-num report-cell-strong">{{ c.avg_change ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Individual field responses -->
    <div class="data-card">
      <div class="data-card-content">
        <div v-if="error" class="error-state" role="alert">{{ error }}</div>
        <div v-else-if="loading && rows.length === 0" class="loading-state">Loading results…</div>
        <div v-else-if="rows.length === 0" class="empty-state">
          <p class="empty-state-title">No form results</p>
          <p class="empty-state-description">No work-order form fields recorded in the window.</p>
        </div>
        <template v-else>
          <p class="report-result-meta">
            Showing {{ rows.length }} {{ rows.length === 1 ? 'response' : 'responses' }}
            <span v-if="hasMore">· more available</span>
          </p>
          <div class="report-table-wrap">
            <table class="report-table">
              <thead>
                <tr>
                  <th scope="col">Work order</th>
                  <th scope="col">Asset</th>
                  <th scope="col">Field</th>
                  <th scope="col">Type</th>
                  <th scope="col">Pre</th>
                  <th scope="col">Post</th>
                  <th scope="col">Notes</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in rows" :key="row.id">
                  <td>
                    <RouterLink :to="`/work-orders/${row.work_order.id}`" class="report-link">
                      {{ row.work_order.number }}
                    </RouterLink>
                  </td>
                  <td>
                    <span class="report-cell-strong">{{ row.asset.name }}</span>
                    <span class="report-cell-muted"> · {{ row.asset.erp_asset_code }}</span>
                  </td>
                  <td>
                    {{ row.label }}<span v-if="row.unit" class="report-cell-muted"> ({{ row.unit }})</span>
                  </td>
                  <td class="report-cell-muted">{{ woFormFieldTypeLabel(row.field_type) }}</td>
                  <td>{{ woFieldValueDisplay(row.field_type, row.pre_value) }}</td>
                  <td class="report-cell-strong">{{ woFieldValueDisplay(row.field_type, row.post_value) }}</td>
                  <td class="report-cell-muted">{{ row.notes ?? '—' }}</td>
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
