<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import AppLayout from '@/components/app/AppLayout.vue'
import AuditLogDetailSheet from '@/components/admin/AuditLogDetailSheet.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { DatePicker } from '@/components/ui/date-picker'
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useAuditLogs, type AuditLogFilters } from '@/composables/useAuditLogs'
import { fmtDateTime } from '@/lib/displayHelpers'
import {
  subjectTypeLabel,
  auditEventCategories,
  auditEventGroups,
  auditSubjectTypes,
} from '@/lib/auditColumns'
import type { AuditLog } from '@/types'

const ALL = '__all__'

const { rows, loading, loadingMore, error, hasMore, load, loadMore, actors, loadActors } =
  useAuditLogs()

// ── Filter state (local UI) ───────────────────────────────────────────────────
const eventSelect = ref(ALL)
const eventText = ref('')
const subjectType = ref(ALL)
const actorId = ref(ALL)
const fromDate = ref('')
const toDate = ref('')

// Restrict pickers to the past/today (audit is historical) and keep the range
// coherent: `from` can't exceed `to`, `to` can't precede `from`. ISO date
// strings compare correctly lexicographically, so no date library is needed here.
const todayStr = new Date().toLocaleDateString('en-CA')
const fromMax = computed(() => toDate.value || todayStr)
const dateRangeError = computed(() =>
  fromDate.value && toDate.value && toDate.value < fromDate.value
    ? 'The "To" date cannot be earlier than the "From" date.'
    : '',
)

/** Assemble the server filters and reload from page one. */
function applyFilters() {
  if (dateRangeError.value) {
    return
  }
  const filters: AuditLogFilters = {}

  // Free-text takes precedence over the category/event dropdown.
  const eventValue = eventText.value.trim() || (eventSelect.value !== ALL ? eventSelect.value : '')
  if (eventValue) {
    filters.event = eventValue
  }
  if (subjectType.value !== ALL) {
    filters.subject_type = subjectType.value
  }
  if (actorId.value !== ALL) {
    filters.user_id = actorId.value
  }
  // Date inputs are day-granular; widen `to` to end-of-day so the range is
  // inclusive of entries recorded later that day.
  if (fromDate.value) {
    filters.from = `${fromDate.value}T00:00:00`
  }
  if (toDate.value) {
    filters.to = `${toDate.value}T23:59:59`
  }

  load(filters)
}

function clearFilters() {
  eventSelect.value = ALL
  eventText.value = ''
  subjectType.value = ALL
  actorId.value = ALL
  fromDate.value = ''
  toDate.value = ''
  load()
}

// ── Row detail ────────────────────────────────────────────────────────────────
const selectedLog = ref<AuditLog | null>(null)
const detailOpen = ref(false)

function openDetail(log: AuditLog) {
  selectedLog.value = log
  detailOpen.value = true
}

function closeDetail() {
  detailOpen.value = false
  selectedLog.value = null
}

onMounted(() => {
  loadActors()
  load()
})
</script>

<template>
  <AppLayout>
    <div class="page-section">
      <div class="page-header">
        <div class="page-heading">
          <h1 class="page-title">Audit Logs</h1>
          <p class="page-subtitle">Security and workflow event history</p>
        </div>
      </div>

      <!-- ── Filters ──────────────────────────────────────────────────────── -->
      <div class="audit-filters">
        <div class="audit-filter audit-filter-event">
          <Label for="audit-event">Event</Label>
          <Select v-model="eventSelect">
            <SelectTrigger id="audit-event"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All events</SelectItem>
              <SelectGroup>
                <SelectLabel>Categories</SelectLabel>
                <SelectItem v-for="cat in auditEventCategories" :key="cat.value" :value="cat.value">
                  {{ cat.label }}
                </SelectItem>
              </SelectGroup>
              <SelectGroup v-for="group in auditEventGroups" :key="group.label">
                <SelectLabel>{{ group.label }}</SelectLabel>
                <SelectItem v-for="opt in group.options" :key="opt.value" :value="opt.value">
                  {{ opt.label }}
                </SelectItem>
              </SelectGroup>
            </SelectContent>
          </Select>
        </div>

        <div class="audit-filter audit-filter-text">
          <Label for="audit-event-text">Event contains…</Label>
          <Input
            id="audit-event-text"
            v-model="eventText"
            placeholder="Overrides the dropdown"
            @keydown.enter="applyFilters"
          />
        </div>

        <div class="audit-filter audit-filter-subject">
          <Label for="audit-subject">Subject type</Label>
          <Select v-model="subjectType">
            <SelectTrigger id="audit-subject"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All subjects</SelectItem>
              <SelectItem v-for="st in auditSubjectTypes" :key="st" :value="st">
                {{ subjectTypeLabel(st) }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="audit-filter audit-filter-actor">
          <Label for="audit-actor">Actor</Label>
          <Select v-model="actorId">
            <SelectTrigger id="audit-actor"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All actors</SelectItem>
              <SelectItem v-for="actor in actors" :key="actor.id" :value="String(actor.id)">
                {{ actor.name }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="audit-filter audit-filter-date">
          <Label for="audit-from">From</Label>
          <DatePicker id="audit-from" v-model="fromDate" :max="fromMax" placeholder="Any start" />
        </div>

        <div class="audit-filter audit-filter-date">
          <Label for="audit-to">To</Label>
          <DatePicker
            id="audit-to"
            v-model="toDate"
            :min="fromDate"
            :max="todayStr"
            placeholder="Any end"
          />
        </div>

        <div class="audit-filter-actions">
          <Button variant="outline" :disabled="loading" @click="clearFilters">Clear</Button>
          <Button :disabled="loading || !!dateRangeError" @click="applyFilters">Apply</Button>
        </div>
      </div>

      <p v-if="dateRangeError" class="form-error" role="alert">{{ dateRangeError }}</p>

      <!-- ── Results ──────────────────────────────────────────────────────── -->
      <div class="data-card">
        <div class="data-card-content">
          <div v-if="error" class="error-state" role="alert">{{ error }}</div>

          <div v-if="loading && rows.length === 0" class="loading-state">Loading audit logs…</div>

          <div v-else-if="rows.length === 0" class="empty-state">
            <p class="empty-state-title">No entries</p>
            <p class="empty-state-description">No audit entries match the current filters.</p>
          </div>

          <template v-else>
            <p class="audit-result-meta">
              Showing {{ rows.length }} {{ rows.length === 1 ? 'entry' : 'entries' }}
              <span v-if="hasMore">· more available</span>
            </p>

            <div class="audit-table-wrap">
              <table class="audit-table">
                <thead>
                  <tr>
                    <th scope="col">Timestamp</th>
                    <th scope="col">Actor</th>
                    <th scope="col">Event</th>
                    <th scope="col">Subject</th>
                    <th scope="col">IP Address</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="log in rows"
                    :key="log.id"
                    class="audit-row"
                    role="button"
                    tabindex="0"
                    :aria-label="`View audit entry ${log.event}`"
                    @click="openDetail(log)"
                    @keydown.enter.prevent="openDetail(log)"
                    @keydown.space.prevent="openDetail(log)"
                  >
                    <td class="audit-cell-time">{{ fmtDateTime(log.created_at) }}</td>
                    <td>
                      <span v-if="log.actor">{{ log.actor.name }}</span>
                      <span v-else class="audit-actor-system">System</span>
                    </td>
                    <td class="audit-mono">{{ log.event }}</td>
                    <td>
                      {{ subjectTypeLabel(log.subject_type)
                      }}<span v-if="log.subject_id != null"> #{{ log.subject_id }}</span>
                    </td>
                    <td class="audit-mono">{{ log.ip_address ?? '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div v-if="hasMore" class="audit-load-more">
              <Button variant="outline" :disabled="loadingMore" @click="loadMore">
                {{ loadingMore ? 'Loading…' : 'Load more' }}
              </Button>
            </div>
          </template>
        </div>
      </div>
    </div>

    <AuditLogDetailSheet :open="detailOpen" :log="selectedLog" @close="closeDetail" />
  </AppLayout>
</template>
