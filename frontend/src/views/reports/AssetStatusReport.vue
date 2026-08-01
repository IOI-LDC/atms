<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { Download } from '@lucide/vue'
import ReportPage from '@/components/app/ReportPage.vue'
import ReportSummaryStats from '@/components/app/ReportSummaryStats.vue'
import ReportLoadMore from '@/components/app/ReportLoadMore.vue'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useAssetStatusReport, type AssetStatusFilters } from '@/composables/useAssetStatusReport'
import { useLocations } from '@/composables/useLocations'
import { useReportCsvExport } from '@/composables/useReportCsvExport'
import {
  fmtDate,
  operationalStatusClass,
  operationalStatusLabel,
  assetKindLabel,
} from '@/lib/displayHelpers'
import { ASSET_KIND_OPTIONS, OPERATIONAL_STATUS_OPTIONS } from '@/lib/reportOptions'

const ALL = '__all__'

const { rows, summary, loading, loadingMore, error, hasMore, load, loadMore } =
  useAssetStatusReport()
const { activeLocations, loadLocations } = useLocations()
const { exporting, exportError, exportCsv } = useReportCsvExport()

const locationId = ref<string>(ALL)
const operationalStatus = ref<string>(ALL)
const assetKind = ref<string>(ALL)
const booked = ref<string>(ALL)
const dateField = ref<'created_at' | 'updated_at'>('updated_at')
const from = ref<string>('')
const to = ref<string>('')

function currentFilters(): AssetStatusFilters {
  const filters: AssetStatusFilters = {}
  if (locationId.value !== ALL) filters.location_id = locationId.value
  if (operationalStatus.value !== ALL) filters.operational_status = operationalStatus.value
  if (assetKind.value !== ALL) filters.asset_kind = assetKind.value
  if (booked.value !== ALL) filters.booked = booked.value === 'booked'
  if (from.value) filters.from = from.value
  if (to.value) filters.to = to.value
  if (from.value || to.value) filters.date_field = dateField.value
  return filters
}

// Export mirrors what is on screen, so it tracks the filters actually loaded
// rather than whatever the bar currently reads — otherwise editing a filter
// without pressing Apply would download a file that disagrees with the table.
const appliedFilters = ref<AssetStatusFilters>({})

function runLoad(filters: AssetStatusFilters = {}) {
  appliedFilters.value = filters
  load(filters)
}

function applyFilters() {
  runLoad(currentFilters())
}

function exportReport() {
  exportCsv('/reports/asset-status', appliedFilters.value)
}

function clearFilters() {
  locationId.value = ALL
  operationalStatus.value = ALL
  assetKind.value = ALL
  booked.value = ALL
  dateField.value = 'updated_at'
  from.value = ''
  to.value = ''
  runLoad()
}

const summaryStats = computed(() => {
  const s = summary.value
  if (!s) return []

  return [
    { label: 'Assets', value: s.total },
    { label: 'Active', value: s.by_status.active ?? 0 },
    { label: 'Under Maintenance', value: s.by_status.under_maintenance ?? 0 },
    { label: 'Down', value: s.by_status.down ?? 0 },
    { label: 'Inactive', value: s.by_status.inactive ?? 0 },
    { label: 'Booked', value: s.booked },
  ]
})

const dateFieldLabel = computed(() =>
  dateField.value === 'created_at' ? 'Created Date' : 'Last Update',
)

onMounted(() => {
  loadLocations()
  runLoad()
})
</script>

<template>
  <ReportPage
    title="Assets Status Report"
    subtitle="The asset register — tag, type, status, location, and assignee."
  >
    <template #actions>
      <Button variant="outline" :disabled="loading || exporting" @click="exportReport">
        <Download />
        {{ exporting ? 'Exporting…' : 'Export CSV' }}
      </Button>
    </template>

    <template #filters>
      <div class="report-filters">
        <div class="form-field">
          <Label for="asr-location">Location</Label>
          <Select id="asr-location" v-model="locationId">
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All locations</SelectItem>
              <SelectItem v-for="loc in activeLocations" :key="loc.id" :value="String(loc.id)">
                {{ loc.name }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="form-field">
          <Label for="asr-status">Status</Label>
          <Select id="asr-status" v-model="operationalStatus">
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All statuses</SelectItem>
              <SelectItem
                v-for="opt in OPERATIONAL_STATUS_OPTIONS"
                :key="opt.value"
                :value="opt.value"
              >
                {{ opt.label }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="form-field">
          <Label for="asr-kind">Type</Label>
          <Select id="asr-kind" v-model="assetKind">
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All types</SelectItem>
              <SelectItem v-for="opt in ASSET_KIND_OPTIONS" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="form-field">
          <Label for="asr-booked">Booking</Label>
          <Select id="asr-booked" v-model="booked">
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">Any</SelectItem>
              <SelectItem value="booked">Booked</SelectItem>
              <SelectItem value="available">Not booked</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="form-field">
          <Label for="asr-date-field">Date range applies to</Label>
          <Select id="asr-date-field" v-model="dateField">
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="updated_at">Last Update</SelectItem>
              <SelectItem value="created_at">Created Date</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="form-field">
          <Label for="asr-from">From</Label>
          <Input id="asr-from" v-model="from" type="date" />
        </div>

        <div class="form-field">
          <Label for="asr-to">To</Label>
          <Input id="asr-to" v-model="to" type="date" />
        </div>

        <div class="report-filter-actions">
          <Button :disabled="loading" @click="applyFilters">Apply</Button>
          <Button variant="outline" :disabled="loading" @click="clearFilters">Clear</Button>
        </div>
      </div>

      <p class="report-filter-note">
        The date range filters <strong>{{ dateFieldLabel }}</strong> and returns each asset's
        <strong>current</strong> status. ATMS does not keep asset status history, so status as it
        stood on a past date cannot be reported.
      </p>
      <p v-if="exportError" class="report-filter-note" role="alert">{{ exportError }}</p>
    </template>

    <template #summary>
      <ReportSummaryStats v-if="summaryStats.length > 0" :stats="summaryStats" />
    </template>

    <div v-if="error" class="error-state" role="alert">{{ error }}</div>
    <div v-else-if="loading" class="loading-state">Loading report…</div>
    <div v-else-if="rows.length === 0" class="empty-state">No assets match these filters.</div>

    <template v-else>
      <div class="report-table-wrap">
        <table class="report-table">
          <thead>
            <tr>
              <th scope="col">Asset Tag</th>
              <th scope="col">Name</th>
              <th scope="col">Type</th>
              <th scope="col">Status</th>
              <th scope="col">Location</th>
              <th scope="col">Assigned To</th>
              <th scope="col">Last Update</th>
              <th scope="col">Created</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id">
              <td>
                <RouterLink :to="`/assets/${row.id}`">{{ row.asset_tag ?? '—' }}</RouterLink>
              </td>
              <td>{{ row.name }}</td>
              <td>{{ assetKindLabel(row.asset_kind) }}</td>
              <td>
                <span :class="operationalStatusClass(row.operational_status)">
                  {{ operationalStatusLabel(row.operational_status) }}
                </span>
                <span v-if="row.is_booked" class="status-badge status-booked">Booked</span>
              </td>
              <td>{{ row.location ?? 'Unassigned' }}</td>
              <td>
                <RouterLink v-if="row.open_work_order_number" :to="`/assets/${row.id}`">
                  {{ row.assigned_to ?? 'Unassigned' }}
                </RouterLink>
                <span v-else>—</span>
              </td>
              <td>{{ fmtDate(row.updated_at) }}</td>
              <td>{{ fmtDate(row.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <ReportLoadMore :has-more="hasMore" :loading="loadingMore" @more="loadMore" />
    </template>
  </ReportPage>
</template>
