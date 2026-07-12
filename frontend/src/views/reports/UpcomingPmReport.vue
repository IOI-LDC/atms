<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import ReportPage from '@/components/app/ReportPage.vue'
import ReportSummaryStats from '@/components/app/ReportSummaryStats.vue'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useUpcomingPmReport, type UpcomingPmFilters } from '@/composables/useUpcomingPmReport'
import { useLocations } from '@/composables/useLocations'
import { usePmRules } from '@/composables/usePmRules'
import {
  fmtDate,
  pmTriggerLabel,
  pmChainStatusLabel,
  pmChainStatusClass,
} from '@/lib/displayHelpers'
import { PM_HORIZON_OPTIONS } from '@/lib/reportOptions'

const ALL = '__all__'

const { loading, error, summary, rows, triggerBreakdown, dueWeekBreakdown, load } =
  useUpcomingPmReport()
const { activeLocations, loadLocations } = useLocations()
const { rules, loadRules } = usePmRules()

const days = ref('30')
const locationId = ref<string>(ALL)
const pmRuleId = ref<string>(ALL)

function applyFilters() {
  const filters: UpcomingPmFilters = { days: days.value }
  if (locationId.value !== ALL) {
    filters.location_id = locationId.value
  }
  if (pmRuleId.value !== ALL) {
    filters.pm_rule_id = pmRuleId.value
  }
  load(filters)
}

function clearFilters() {
  days.value = '30'
  locationId.value = ALL
  pmRuleId.value = ALL
  load({ days: '30' })
}

onMounted(() => {
  loadLocations()
  loadRules()
  load({ days: '30' })
})
</script>

<template>
  <ReportPage
    title="Upcoming PM Schedule"
    subtitle="Date-triggered PMs coming due within the horizon, on enrolled assets (R-1)."
  >
    <template #filters>
      <div class="report-filters">
        <div class="report-filter">
          <Label for="upm-days">Horizon</Label>
          <Select v-model="days">
            <SelectTrigger id="upm-days"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="opt in PM_HORIZON_OPTIONS" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="report-filter">
          <Label for="upm-location">Location</Label>
          <Select v-model="locationId">
            <SelectTrigger id="upm-location"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All locations</SelectItem>
              <SelectItem v-for="loc in activeLocations" :key="loc.id" :value="String(loc.id)">
                {{ loc.name }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="report-filter">
          <Label for="upm-rule">PM rule</Label>
          <Select v-model="pmRuleId">
            <SelectTrigger id="upm-rule"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All rules</SelectItem>
              <SelectItem v-for="r in rules" :key="r.id" :value="String(r.id)">
                {{ r.name }}
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
      <ReportSummaryStats
        v-if="summary"
        :stats="[{ label: 'PMs due in horizon', value: summary.total }]"
      />
      <div v-if="triggerBreakdown.length || dueWeekBreakdown.length" class="report-chips report-subsummary">
        <span v-for="t in triggerBreakdown" :key="t.key" class="report-chip">
          {{ pmTriggerLabel(t.key) }} <b>{{ t.count }}</b>
        </span>
        <span v-for="w in dueWeekBreakdown" :key="w.week" class="report-chip">
          {{ w.week }} <b>{{ w.count }}</b>
        </span>
      </div>
    </template>

    <div class="data-card">
      <div class="data-card-content">
        <div v-if="error" class="error-state" role="alert">{{ error }}</div>

        <div v-else-if="loading" class="loading-state">Loading upcoming PMs…</div>

        <div v-else-if="rows.length === 0" class="empty-state">
          <p class="empty-state-title">Nothing upcoming</p>
          <p class="empty-state-description">
            No date-triggered PMs come due within the selected horizon.
          </p>
        </div>

        <div v-else class="report-table-wrap">
          <table class="report-table">
            <thead>
              <tr>
                <th scope="col">Asset</th>
                <th scope="col">Location</th>
                <th scope="col">PM rule</th>
                <th scope="col">Trigger</th>
                <th scope="col">Next due</th>
                <th scope="col" class="report-table-num">Days until</th>
                <th scope="col">Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows" :key="row.assignment_id">
                <td>
                  <RouterLink :to="`/assets/${row.asset.id}`" class="report-link">
                    {{ row.asset.name }}
                  </RouterLink>
                  <span class="report-cell-muted"> · {{ row.asset.erp_asset_code }}</span>
                </td>
                <td :class="row.location?.name ? '' : 'report-cell-muted'">
                  {{ row.location?.name ?? 'Unassigned' }}
                </td>
                <td>{{ row.pm_rule.name }}</td>
                <td>{{ pmTriggerLabel(row.trigger_type) }}</td>
                <td class="report-cell-muted">{{ fmtDate(row.next_due_date) }}</td>
                <td class="report-table-num report-cell-strong">{{ row.days_until_due }}</td>
                <td>
                  <span :class="pmChainStatusClass(row.chain_status)">
                    {{ pmChainStatusLabel(row.chain_status) }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </ReportPage>
</template>
