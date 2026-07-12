<script setup lang="ts">
import { ref, onMounted } from 'vue'
import ReportPage from '@/components/app/ReportPage.vue'
import ReportSummaryStats from '@/components/app/ReportSummaryStats.vue'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { Progress } from '@/components/ui/progress'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  useOperationalStatusReport,
  type OperationalStatusReportFilters,
} from '@/composables/useOperationalStatusReport'
import { operationalStatusLabel, operationalStatusClass } from '@/lib/displayHelpers'
import { ASSET_KIND_OPTIONS } from '@/lib/reportOptions'
import type { AssetKind } from '@/types'

const ALL = '__all__'

const { loading, error, load, total, rows } = useOperationalStatusReport()

const assetKind = ref<string>(ALL)
const includeInactive = ref(false)

function applyFilters() {
  const filters: OperationalStatusReportFilters = {}
  if (assetKind.value !== ALL) {
    filters.asset_kind = assetKind.value as AssetKind
  }
  if (includeInactive.value) {
    filters.include_inactive = true
  }
  load(filters)
}

function clearFilters() {
  assetKind.value = ALL
  includeInactive.value = false
  load()
}

onMounted(() => load())
</script>

<template>
  <ReportPage
    title="Operational Status Distribution"
    subtitle="How the fleet is split across operational states (R-10A)."
  >
    <template #filters>
      <div class="report-filters">
        <div class="report-filter">
          <Label for="osd-kind">Asset kind</Label>
          <Select v-model="assetKind">
            <SelectTrigger id="osd-kind"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All kinds</SelectItem>
              <SelectItem v-for="opt in ASSET_KIND_OPTIONS" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="report-filter report-filter-inline">
          <Label for="osd-inactive" class="report-filter-check">
            <Checkbox id="osd-inactive" v-model="includeInactive" />
            Include deactivated assets
          </Label>
        </div>

        <div class="report-filter-actions">
          <Button variant="outline" :disabled="loading" @click="clearFilters">Clear</Button>
          <Button :disabled="loading" @click="applyFilters">Apply</Button>
        </div>
      </div>
    </template>

    <template #summary>
      <ReportSummaryStats :stats="[{ label: 'Total assets', value: total }]" />
    </template>

    <div class="data-card">
      <div class="data-card-content">
        <div v-if="error" class="error-state" role="alert">{{ error }}</div>

        <div v-else-if="loading" class="loading-state">Loading distribution…</div>

        <div v-else-if="total === 0" class="empty-state">
          <p class="empty-state-title">No assets</p>
          <p class="empty-state-description">No assets match the current filters.</p>
        </div>

        <div v-else class="report-table-wrap">
          <table class="report-table">
            <thead>
              <tr>
                <th scope="col">Operational status</th>
                <th scope="col" class="report-table-num">Count</th>
                <th scope="col">Share</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows" :key="row.status">
                <td>
                  <span :class="operationalStatusClass(row.status)">
                    {{ operationalStatusLabel(row.status) }}
                  </span>
                </td>
                <td class="report-table-num report-cell-strong">{{ row.count }}</td>
                <td>
                  <div class="report-bar">
                    <Progress :value="row.percentage" />
                    <span class="report-bar-value">{{ row.percentage }}%</span>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </ReportPage>
</template>
