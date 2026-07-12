<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import ReportPage from '@/components/app/ReportPage.vue'
import ReportSummaryStats from '@/components/app/ReportSummaryStats.vue'
import ReportLoadMore from '@/components/app/ReportLoadMore.vue'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useOverduePmReport, type OverduePmFilters } from '@/composables/useOverduePmReport'
import { useLocations } from '@/composables/useLocations'
import { usePmRules } from '@/composables/usePmRules'
import { useListOptions } from '@/composables/useListOptions'
import {
  fmtDate,
  priorityClass,
  priorityLabel,
  agingBucketClass,
  agingBucketLabel,
} from '@/lib/displayHelpers'
import { AGING_BUCKET_OPTIONS } from '@/lib/reportOptions'
import type { AgingBucket } from '@/types'

const ALL = '__all__'

const { rows, summary, loading, loadingMore, error, hasMore, load, loadMore } = useOverduePmReport()
const { activeLocations, loadLocations } = useLocations()
const { rules, loadRules } = usePmRules()
const { priorities, loadPriorities } = useListOptions()

const locationId = ref<string>(ALL)
const pmRuleId = ref<string>(ALL)
const priority = ref<string>(ALL)
const bucket = ref<string>(ALL)

function applyFilters() {
  const filters: OverduePmFilters = {}
  if (locationId.value !== ALL) {
    filters.location_id = locationId.value
  }
  if (pmRuleId.value !== ALL) {
    filters.pm_rule_id = pmRuleId.value
  }
  if (priority.value !== ALL) {
    filters.priority = priority.value
  }
  if (bucket.value !== ALL) {
    filters.bucket = bucket.value as AgingBucket
  }
  load(filters)
}

function clearFilters() {
  locationId.value = ALL
  pmRuleId.value = ALL
  priority.value = ALL
  bucket.value = ALL
  load()
}

const summaryStats = computed(() => {
  if (!summary.value) {
    return []
  }
  return [
    { label: 'Total overdue', value: summary.value.total },
    { label: '0–7 days', value: summary.value.by_bucket['0-7'] },
    { label: '8–30 days', value: summary.value.by_bucket['8-30'] },
    { label: '31–90 days', value: summary.value.by_bucket['31-90'] },
    { label: '91+ days', value: summary.value.by_bucket['91+'] },
  ]
})

onMounted(() => {
  loadLocations()
  loadRules()
  loadPriorities()
  load()
})
</script>

<template>
  <ReportPage
    title="Overdue PM"
    subtitle="Date-triggered PMs past due and not yet closed, by aging bucket (R-8)."
  >
    <template #filters>
      <div class="report-filters">
        <div class="report-filter">
          <Label for="opm-location">Location</Label>
          <Select v-model="locationId">
            <SelectTrigger id="opm-location"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All locations</SelectItem>
              <SelectItem v-for="loc in activeLocations" :key="loc.id" :value="String(loc.id)">
                {{ loc.name }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="report-filter">
          <Label for="opm-rule">PM rule</Label>
          <Select v-model="pmRuleId">
            <SelectTrigger id="opm-rule"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All rules</SelectItem>
              <SelectItem v-for="r in rules" :key="r.id" :value="String(r.id)">
                {{ r.name }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="report-filter">
          <Label for="opm-priority">Priority</Label>
          <Select v-model="priority">
            <SelectTrigger id="opm-priority"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All priorities</SelectItem>
              <SelectItem v-for="opt in priorities" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="report-filter">
          <Label for="opm-bucket">Aging bucket</Label>
          <Select v-model="bucket">
            <SelectTrigger id="opm-bucket"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All buckets</SelectItem>
              <SelectItem v-for="opt in AGING_BUCKET_OPTIONS" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="report-filter-actions">
          <Button variant="outline" :disabled="loading" @click="clearFilters">Clear</Button>
          <Button :disabled="loading" @click="applyFilters">Apply</Button>
        </div>
      </div>
    </template>

    <template #summary>
      <ReportSummaryStats v-if="summary" :stats="summaryStats" />
    </template>

    <div class="data-card">
      <div class="data-card-content">
        <div v-if="error" class="error-state" role="alert">{{ error }}</div>

        <div v-else-if="loading && rows.length === 0" class="loading-state">
          Loading overdue PMs…
        </div>

        <div v-else-if="rows.length === 0" class="empty-state">
          <p class="empty-state-title">Nothing overdue</p>
          <p class="empty-state-description">No date-triggered PMs are past due for these filters.</p>
        </div>

        <template v-else>
          <div class="report-table-wrap">
            <table class="report-table">
              <thead>
                <tr>
                  <th scope="col">Request</th>
                  <th scope="col">Asset</th>
                  <th scope="col">Priority</th>
                  <th scope="col">Trigger date</th>
                  <th scope="col" class="report-table-num">Days overdue</th>
                  <th scope="col">Bucket</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in rows" :key="row.id">
                  <td>
                    <RouterLink :to="`/maintenance/requests/${row.id}`" class="report-link">
                      {{ row.number }}
                    </RouterLink>
                  </td>
                  <td>
                    <span class="report-cell-strong">{{ row.asset.name }}</span>
                    <span class="report-cell-muted"> · {{ row.asset.erp_asset_code }}</span>
                  </td>
                  <td><span :class="priorityClass(row.priority)">{{ priorityLabel(row.priority) }}</span></td>
                  <td class="report-cell-muted">{{ fmtDate(row.trigger_date) }}</td>
                  <td class="report-table-num report-cell-strong">{{ row.days_overdue }}</td>
                  <td><span :class="agingBucketClass(row.bucket)">{{ agingBucketLabel(row.bucket) }}</span></td>
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
