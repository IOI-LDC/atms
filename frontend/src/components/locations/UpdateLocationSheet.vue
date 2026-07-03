<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
} from '@/components/ui/sheet'
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog'
import api, { ApiError } from '@/lib/api'
import { toast } from 'vue-sonner'
import { fmtDate } from '@/lib/displayHelpers'
import type { Asset, Location, AssetLocationHistoryItem } from '@/types'

const props = defineProps<{
  asset: Asset
  locations: Location[] // active locations only, from useLocations().activeLocations
  open: boolean
}>()

const emit = defineEmits<{ close: []; saved: [] }>()

// ── Form state ────────────────────────────────────────────────────────────────
const locationId = ref<string>('')
// Display-only: backend hardcodes now() as effective_at until API supports it
const effectiveDate = ref<string>(new Date().toISOString().slice(0, 16))
const reason = ref('')
const notes = ref('')
const saving = ref(false)
const validationErrors = ref<Record<string, string[]> | null>(null)
const confirmOpen = ref(false)
const error = ref<string | null>(null)

const availableLocations = computed(() =>
  props.locations.filter((l) => l.id !== props.asset.current_location?.id),
)

const locationGroups = computed(() => {
  const groups: Record<string, Location[]> = {}
  for (const loc of availableLocations.value) {
    const type = loc.type || 'Other'
    if (!groups[type]) groups[type] = []
    groups[type].push(loc)
  }
  return Object.entries(groups)
})

// ── Location history (compact mini-timeline) ─────────────────────────────────
const history = ref<AssetLocationHistoryItem[]>([])
const historyLoading = ref(false)

async function loadHistory() {
  historyLoading.value = true
  try {
    const res = await api.get<{ data: AssetLocationHistoryItem[] }>(
      `/assets/${props.asset.id}/location-history`,
    )
    history.value = (res.data ?? []).slice(0, 3)
  } catch {
    history.value = []
  } finally {
    historyLoading.value = false
  }
}

watch(
  () => props.asset?.id,
  () => {
    if (props.open && props.asset?.id) loadHistory()
  },
)

watch(
  () => props.open,
  (nowOpen) => {
    if (nowOpen && props.asset?.id) {
      loadHistory()
      resetForm()
    }
  },
  { immediate: true },
)

function resetForm() {
  locationId.value = ''
  effectiveDate.value = new Date().toISOString().slice(0, 16)
  reason.value = ''
  notes.value = ''
  validationErrors.value = null
  error.value = null
}

// ── Confirm + submit ─────────────────────────────────────────────────────────
function requestSave() {
  if (!locationId.value) {
    validationErrors.value = { location_id: ['Please select a location.'] }
    return
  }
  validationErrors.value = null
  confirmOpen.value = true
}

async function doSave() {
  saving.value = true
  error.value = null
  try {
    await api.post(`/assets/${props.asset.id}/location`, {
      location_id: Number(locationId.value),
      reason: reason.value.trim() || null,
      notes: notes.value.trim() || null,
    })
    toast.success(`Location updated for ${props.asset.asset_tag ?? props.asset.name}`)
    emit('saved')
    emit('close')
  } catch (e) {
    if (e instanceof ApiError) {
      if (e.validationErrors) validationErrors.value = e.validationErrors
      else error.value = e.message
    } else {
      error.value = 'Failed to update location.'
    }
  } finally {
    saving.value = false
    confirmOpen.value = false
  }
}

const selectedLocation = computed(() =>
  availableLocations.value.find((l) => String(l.id) === locationId.value),
)
</script>

<template>
  <Sheet :open="open" :modal="false" @update:open="(v) => !v && emit('close')">
    <SheetContent side="right" class="create-sheet">
      <div class="create-sheet-header">
        <SheetHeader>
          <SheetTitle>Update Location</SheetTitle>
          <SheetDescription>
            {{ asset.asset_tag ? `${asset.asset_tag} — ` : '' }}{{ asset.name }}
          </SheetDescription>
        </SheetHeader>
      </div>

      <div class="create-sheet-body">
        <div v-if="error" class="error-state" role="alert">{{ error }}</div>

        <div class="sheet-form">
          <!-- Current location (read-only) -->
          <div class="form-field">
            <Label>Current Location</Label>
            <p v-if="asset.current_location" class="detail-field-value">
              {{ asset.current_location.name }}
            </p>
            <p v-else class="detail-field-muted">No location assigned</p>
          </div>

          <!-- New location -->
          <div class="form-field">
            <Label for="update-location">
              New Location <span class="field-required">*</span>
            </Label>
            <Select v-model="locationId">
              <SelectTrigger id="update-location">
                <SelectValue placeholder="Select a location…" />
              </SelectTrigger>
              <SelectContent>
                <template v-for="[type, locs] in locationGroups" :key="type">
                  <SelectGroup>
                    <SelectLabel>{{ type }}</SelectLabel>
                    <SelectItem v-for="loc in locs" :key="loc.id" :value="String(loc.id)">
                      {{ loc.name }}
                      <span v-if="loc.code" class="select-code-hint">{{ loc.code }}</span>
                    </SelectItem>
                  </SelectGroup>
                </template>
              </SelectContent>
            </Select>
            <p v-if="validationErrors?.location_id" class="form-error">
              {{ validationErrors.location_id[0] }}
            </p>
          </div>

          <!-- Effective date (display-only until backend supports custom effective_at) -->
          <div class="form-field">
            <Label for="update-effective-date">Effective Date</Label>
            <Input
              id="update-effective-date"
              type="datetime-local"
              v-model="effectiveDate"
              disabled
            />
          </div>

          <!-- Reason -->
          <div class="form-field">
            <Label for="update-reason">
              Reason <span class="field-optional">— optional</span>
            </Label>
            <Input
              id="update-reason"
              v-model="reason"
              placeholder="E.g. reassigned to field, returned from maintenance…"
              maxlength="255"
            />
          </div>

          <!-- Notes -->
          <div class="form-field form-field-full">
            <Label for="update-notes"> Notes <span class="field-optional">— optional</span> </Label>
            <Textarea
              id="update-notes"
              v-model="notes"
              :rows="3"
              placeholder="Additional context about this location change…"
            />
          </div>
        </div>

        <!-- Compact location history timeline -->
        <div class="location-history-section">
          <div v-if="historyLoading" class="compact-timeline">
            <p class="compact-timeline-empty">Loading history…</p>
          </div>
          <div v-else-if="history.length > 0" class="compact-timeline">
            <p class="compact-timeline-title">Recent Location History</p>
            <div v-for="h in history" :key="h.id" class="compact-timeline-item">
              <span class="compact-timeline-date">{{ fmtDate(h.effective_at) }}</span>
              <span class="compact-timeline-summary">
                Moved{{ h.to_location_id ? ' to new location' : ''
                }}<template v-if="h.reason"> — {{ h.reason }}</template>
              </span>
            </div>
          </div>
          <div v-else class="compact-timeline">
            <p class="compact-timeline-empty">No previous location changes recorded.</p>
          </div>
        </div>
      </div>

      <div class="create-sheet-footer">
        <Button variant="outline" :disabled="saving" @click="emit('close')"> Cancel </Button>
        <Button :disabled="saving" @click="requestSave"> Update Location </Button>
      </div>
    </SheetContent>
  </Sheet>

  <!-- Confirm dialog -->
  <Dialog v-model:open="confirmOpen">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Confirm Location Change</DialogTitle>
        <DialogDescription>
          Move <strong>{{ asset.asset_tag ?? asset.name }}</strong> from
          <strong>{{ asset.current_location?.name ?? 'No location' }}</strong> to
          <strong>{{ selectedLocation?.name ?? '—' }}</strong
          >?
        </DialogDescription>
      </DialogHeader>
      <DialogFooter>
        <Button variant="outline" @click="confirmOpen = false">Cancel</Button>
        <Button :disabled="saving" @click="doSave">
          {{ saving ? 'Saving…' : 'Confirm' }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
