<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import ReportPage from '@/components/app/ReportPage.vue'
import ReportSummaryStats from '@/components/app/ReportSummaryStats.vue'
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
import { useMttrReport, type MttrFilters } from '@/composables/useMttrReport'
import { useLocations } from '@/composables/useLocations'
import { useListOptions } from '@/composables/useListOptions'
import { useUsers } from '@/composables/useUsers'
import { useAuthStore } from '@/stores/auth.store'
import { fmtKpiHours } from '@/lib/displayHelpers'
import { toFaSubclassFilterOptions } from '@/lib/assetColumns'
import { MTTR_GROUP_BY_OPTIONS, reportDateWindow } from '@/lib/reportOptions'
import type { MttrGroupBy } from '@/types'

const ALL = '__all__'
const DEFAULT = reportDateWindow(90)

const auth = useAuthStore()
const { loading, error, summary, rows, load } = useMttrReport()
const { activeLocations, loadLocations } = useLocations()
const { faSubclasses, loadFaSubclasses } = useListOptions()
const { users, loadUsers } = useUsers()

const canFilterByTechnician = computed(() => auth.isAdminOrManager)
const faSubclassOptions = computed(() => toFaSubclassFilterOptions(faSubclasses.value))
const technicianOptions = computed(() => users.value.filter((u) => u.role?.code === 'technician'))

const fromDate = ref(DEFAULT.from)
const toDate = ref(DEFAULT.to)
const groupBy = ref<MttrGroupBy>('asset')
const locationId = ref<string>(ALL)
const faSubclassCode = ref<string>(ALL)
const technicianId = ref<string>(ALL)
const appliedGroupBy = ref<MttrGroupBy>('asset')

const todayStr = new Date().toLocaleDateString('en-CA')
const dateRangeError = computed(() =>
  fromDate.value && toDate.value && toDate.value < fromDate.value
    ? 'The "To" date cannot be earlier than the "From" date.'
    : '',
)
const dimensionLabel = computed(
  () => MTTR_GROUP_BY_OPTIONS.find((o) => o.value === appliedGroupBy.value)?.label ?? 'Group',
)

function applyFilters() {
  if (dateRangeError.value) {
    return
  }
  const filters: MttrFilters = { group_by: groupBy.value, from: fromDate.value, to: toDate.value }
  if (locationId.value !== ALL) {
    filters.location_id = locationId.value
  }
  if (faSubclassCode.value !== ALL) {
    filters.fa_subclass_code = faSubclassCode.value
  }
  if (technicianId.value !== ALL) {
    filters.technician_id = technicianId.value
  }
  appliedGroupBy.value = groupBy.value
  load(filters)
}

function clearFilters() {
  fromDate.value = DEFAULT.from
  toDate.value = DEFAULT.to
  groupBy.value = 'asset'
  locationId.value = ALL
  faSubclassCode.value = ALL
  technicianId.value = ALL
  appliedGroupBy.value = 'asset'
  load({ group_by: 'asset', from: DEFAULT.from, to: DEFAULT.to })
}

onMounted(() => {
  loadLocations()
  loadFaSubclasses()
  if (canFilterByTechnician.value) {
    loadUsers()
  }
  load({ group_by: 'asset', from: DEFAULT.from, to: DEFAULT.to })
})
</script>

<template>
  <ReportPage
    title="MTTR by Dimension"
    subtitle="Mean time to repair (assigned → closed) by asset, class, or technician (R-4)."
  >
    <template #filters>
      <div class="report-filters">
        <div class="report-filter">
          <Label for="mttr-from">From</Label>
          <DatePicker id="mttr-from" v-model="fromDate" :max="toDate || todayStr" />
        </div>
        <div class="report-filter">
          <Label for="mttr-to">To</Label>
          <DatePicker id="mttr-to" v-model="toDate" :min="fromDate" :max="todayStr" />
        </div>
        <div class="report-filter">
          <Label for="mttr-group">Group by</Label>
          <Select v-model="groupBy">
            <SelectTrigger id="mttr-group"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="o in MTTR_GROUP_BY_OPTIONS" :key="o.value" :value="o.value">
                {{ o.label }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="report-filter">
          <Label for="mttr-location">Location</Label>
          <Select v-model="locationId">
            <SelectTrigger id="mttr-location"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All locations</SelectItem>
              <SelectItem v-for="loc in activeLocations" :key="loc.id" :value="String(loc.id)">
                {{ loc.name }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="report-filter">
          <Label for="mttr-class">Asset class</Label>
          <Select v-model="faSubclassCode">
            <SelectTrigger id="mttr-class"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All classes</SelectItem>
              <SelectItem v-for="o in faSubclassOptions" :key="o.value" :value="o.value">
                {{ o.label }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div v-if="canFilterByTechnician" class="report-filter">
          <Label for="mttr-tech">Technician</Label>
          <Select v-model="technicianId">
            <SelectTrigger id="mttr-tech"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All technicians</SelectItem>
              <SelectItem v-for="u in technicianOptions" :key="u.id" :value="String(u.id)">
                {{ u.name }}
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
      <ReportSummaryStats
        v-if="summary"
        :stats="[
          { label: 'Overall MTTR', value: fmtKpiHours(summary.mttr_hours) },
          { label: 'Repairs', value: summary.repair_count },
        ]"
      />
    </template>

    <div class="data-card">
      <div class="data-card-content">
        <div v-if="error" class="error-state" role="alert">{{ error }}</div>
        <div v-else-if="loading" class="loading-state">Loading MTTR…</div>
        <div v-else-if="rows.length === 0" class="empty-state">
          <p class="empty-state-title">No repairs</p>
          <p class="empty-state-description">No closed corrective work orders in the window.</p>
        </div>
        <div v-else class="report-table-wrap">
          <table class="report-table">
            <thead>
              <tr>
                <th scope="col">{{ dimensionLabel }}</th>
                <th scope="col" class="report-table-num">Repairs</th>
                <th scope="col" class="report-table-num">MTTR</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows" :key="String(row.group_key)">
                <td class="report-cell-strong">{{ row.group_label ?? '—' }}</td>
                <td class="report-table-num">{{ row.repair_count }}</td>
                <td class="report-table-num">{{ fmtKpiHours(row.mttr_hours) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </ReportPage>
</template>
