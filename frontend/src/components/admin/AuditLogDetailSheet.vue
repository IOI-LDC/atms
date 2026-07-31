<script setup lang="ts">
import { computed } from 'vue'
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
} from '@/components/ui/sheet'
import { Button } from '@/components/ui/button'
import { fmtDateTime } from '@/lib/displayHelpers'
import { subjectTypeLabel } from '@/lib/auditColumns'
import type { AuditLog } from '@/types'

const props = defineProps<{
  open: boolean
  log: AuditLog | null
}>()

const emit = defineEmits<{ close: [] }>()

/** Pretty-print a JSON blob, or null when the blob is absent. */
function prettyJson(value: Record<string, unknown> | null): string | null {
  return value ? JSON.stringify(value, null, 2) : null
}

const actorLabel = computed(() => {
  const actor = props.log?.actor
  if (!actor) {
    return 'System'
  }
  return actor.email ? `${actor.name} (${actor.email})` : actor.name
})

const subjectLabel = computed(() => {
  const log = props.log
  if (!log?.subject_type) {
    return '—'
  }
  const label = subjectTypeLabel(log.subject_type)
  return log.subject_id != null ? `${label} #${log.subject_id}` : label
})

const beforeJson = computed(() => prettyJson(props.log?.before_state ?? null))
const afterJson = computed(() => prettyJson(props.log?.after_state ?? null))
const metadataJson = computed(() => prettyJson(props.log?.metadata ?? null))
</script>

<template>
  <Sheet :open="open" :modal="false" @update:open="(v) => !v && emit('close')">
    <SheetContent side="right" class="create-sheet">
      <div class="create-sheet-header">
        <SheetHeader>
          <SheetTitle>Audit Entry</SheetTitle>
          <SheetDescription v-if="log">
            <span class="audit-mono">{{ log.event }}</span>
          </SheetDescription>
        </SheetHeader>
      </div>

      <div v-if="log" class="create-sheet-body">
        <!-- ── Summary ─────────────────────────────────────────────────── -->
        <div class="detail-grid">
          <div class="detail-field">
            <span class="detail-field-label">Timestamp</span>
            <p class="detail-field-value">{{ fmtDateTime(log.created_at) }}</p>
          </div>
          <div class="detail-field">
            <span class="detail-field-label">Event</span>
            <p class="detail-field-value audit-mono">{{ log.event }}</p>
          </div>
          <div class="detail-field">
            <span class="detail-field-label">Actor</span>
            <p class="detail-field-value" :class="{ 'audit-actor-system': !log.actor }">
              {{ actorLabel }}
            </p>
          </div>
          <div class="detail-field">
            <span class="detail-field-label">Subject</span>
            <p class="detail-field-value">{{ subjectLabel }}</p>
          </div>
          <div class="detail-field">
            <span class="detail-field-label">IP Address</span>
            <p class="detail-field-value audit-mono">{{ log.ip_address ?? '—' }}</p>
          </div>
          <div class="detail-field">
            <span class="detail-field-label">Request ID</span>
            <p class="detail-field-value audit-mono">{{ log.request_id ?? '—' }}</p>
          </div>
          <div v-if="log.user_agent" class="detail-field detail-field-block">
            <span class="detail-field-label">User Agent</span>
            <p class="detail-field-value detail-field-prose">{{ log.user_agent }}</p>
          </div>
        </div>

        <!-- ── State change ────────────────────────────────────────────── -->
        <div class="audit-detail-section">
          <span class="audit-json-label">State Change</span>
          <div class="audit-json-grid">
            <div class="audit-json-pane">
              <span class="audit-json-label">Before</span>
              <pre v-if="beforeJson" class="audit-json-code">{{ beforeJson }}</pre>
              <p v-else class="audit-json-empty">No before-state recorded.</p>
            </div>
            <div class="audit-json-pane">
              <span class="audit-json-label">After</span>
              <pre v-if="afterJson" class="audit-json-code">{{ afterJson }}</pre>
              <p v-else class="audit-json-empty">No after-state recorded.</p>
            </div>
          </div>
        </div>

        <!-- ── Metadata ────────────────────────────────────────────────── -->
        <div v-if="metadataJson" class="audit-detail-section">
          <span class="audit-json-label">Metadata</span>
          <pre class="audit-json-code">{{ metadataJson }}</pre>
        </div>
      </div>

      <div class="create-sheet-footer">
        <Button variant="outline" @click="emit('close')">Close</Button>
      </div>
    </SheetContent>
  </Sheet>
</template>
