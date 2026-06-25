# Locations Sidebar Feature — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a dedicated "Locations" sidebar item (tabbed group) with an "Asset Location Update" tab for Admin/Manager/Logistics and a "Manage Locations" tab for Admin only. Follows the "Insightful" design approach — location type badges, compact location history timeline in update sheet.

**Architecture:** Tab-based view at `/locations` following the existing `WorkOrdersView.vue` pattern. Two tabs: `asset-location-update` (asset list + UpdateLocationSheet) and `manage-locations` (location CRUD table + LocationForm). Uses existing `POST /api/assets/{asset}/location` and `GET/POST/PATCH /api/admin/locations` endpoints. The new `GET /api/locations` endpoint (for non-Admin location access) is flagged as a backend prerequisite.

**Tech Stack:** Vue 3 + TypeScript + semantic CSS (no Tailwind in feature files) + shadcn-vue components + Pinia auth store + vue-sonner toasts + lucide-vue icons.

---

## ⚠️ Backend Prerequisite (Gate)

Before frontend implementation, the backend needs:

**Required:** A way for Manager and Logistics roles to list active locations. Current state:
- `GET /api/admin/locations` — Admin only (`LocationPolicy::manage`)
- No non-admin endpoint exists

**Impact if not resolved:** The "Asset Location Update" tab's location picker dropdown will be empty for Manager/Logistics roles. The tab will still render but show "No active locations available."

**Recommended fix:** Add `GET /api/locations` endpoint returning active locations only, authorized for Admin/Manager/Logistics. OR add `view` to `LocationPolicy` and broaden the existing admin endpoint.

---

## Files to Create

| # | File | Purpose |
|---|---|---|
| 1 | `frontend/src/composables/useLocations.ts` | Location data fetching + mutations |
| 2 | `frontend/src/components/locations/UpdateLocationSheet.vue` | Side sheet for asset location update + mini history |
| 3 | `frontend/src/components/locations/LocationForm.vue` | Side sheet for location CRUD |
| 4 | `frontend/src/views/locations/AssetLocationUpdateView.vue` | Tab 1: asset list + update action |
| 5 | `frontend/src/views/locations/ManageLocationsView.vue` | Tab 2: location CRUD table |
| 6 | `frontend/src/views/locations/LocationsView.vue` | Tab wrapper container |

## Files to Modify

| # | File | Change |
|---|---|---|
| 7 | `frontend/src/style.css` | Add location type badge classes + compact timeline classes |
| 8 | `frontend/src/lib/displayHelpers.ts` | Add `locationTypeClass()` and `locationTypeLabel()` helpers |
| 9 | `frontend/src/router/index.ts` | Add `/locations` route |
| 10 | `frontend/src/components/app/AppSidebar.vue` | Add Locations nav item with MapPin icon |
| 11 | `frontend/src/types/index.ts` | No changes needed (Location, Asset, AssetLocationHistoryItem already exist) |

---

### Task 1: Add location type badge CSS + compact timeline CSS

**File:** `frontend/src/style.css`

**Step 1: Append location type badge classes**

Add after the existing badge styles (after line ~1347):

```css
/* Location type badges — color-coded by facility type.
   Each type gets a subtle hue using the existing status-badge pattern. */
.location-type-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.125rem 0.5rem;
  border-radius: 9999px;
  font-size: 0.75rem;
  font-weight: 500;
  border: 1px solid transparent;
}
.location-type-workshop {
  background: oklch(0.7 0.12 75 / 0.12);
  color: oklch(0.45 0.1 75);
  border-color: oklch(0.7 0.12 75 / 0.2);
}
.location-type-workshop_yard {
  background: oklch(0.65 0.08 75 / 0.12);
  color: oklch(0.42 0.07 75);
  border-color: oklch(0.65 0.08 75 / 0.2);
}
.location-type-yard {
  background: oklch(0.62 0.1 232 / 0.12);
  color: oklch(0.4 0.08 232);
  border-color: oklch(0.62 0.1 232 / 0.2);
}
.location-type-well_site {
  background: oklch(0.55 0.12 155 / 0.12);
  color: oklch(0.36 0.1 155);
  border-color: oklch(0.55 0.12 155 / 0.2);
}
.location-type-rig {
  background: oklch(0.52 0.14 275 / 0.12);
  color: oklch(0.34 0.12 275);
  border-color: oklch(0.52 0.14 275 / 0.2);
}
.location-type-building {
  background: oklch(0.55 0.02 265 / 0.08);
  color: oklch(0.36 0.02 265);
  border-color: oklch(0.55 0.02 265 / 0.15);
}
```

**Step 2: Append compact timeline classes for UpdateLocationSheet**

```css
/* Compact location history timeline in UpdateLocationSheet */
.compact-timeline {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.75rem;
  background: var(--muted);
  border-radius: var(--radius);
  border: 1px solid var(--border);
}
.compact-timeline-title {
  font-size: 0.6875rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--muted-foreground);
  margin-bottom: 0.125rem;
}
.compact-timeline-item {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  font-size: 0.8125rem;
  padding-left: 0.75rem;
  border-left: 2px solid var(--border);
  position: relative;
}
.compact-timeline-item::before {
  content: '';
  position: absolute;
  left: -0.3125rem;
  top: 0.375rem;
  width: 0.4375rem;
  height: 0.4375rem;
  border-radius: 50%;
  background: var(--primary);
}
.compact-timeline-date {
  font-size: 0.6875rem;
  color: var(--muted-foreground);
  white-space: nowrap;
  min-width: 4.5rem;
}
.compact-timeline-summary {
  flex: 1;
  line-height: 1.35;
}
.compact-timeline-empty {
  font-size: 0.8125rem;
  color: var(--muted-foreground);
  text-align: center;
  padding: 0.5rem 0;
}
```

**Validation:** Run `npm run build -- --emptyOutDir` from `frontend/` — should compile without CSS errors.

---

### Task 2: Add location type helpers

**File:** `frontend/src/lib/displayHelpers.ts`

**Step: Append helper functions**

Add at the end of the file:

```ts
/**
 * Maps a location type string to its CSS badge class.
 * Unknown types fall back to .location-type-building (slate).
 */
export function locationTypeClass(type: string | null | undefined): string {
  const m: Record<string, string> = {
    workshop:      'location-type-badge location-type-workshop',
    workshop_yard: 'location-type-badge location-type-workshop_yard',
    yard:          'location-type-badge location-type-yard',
    well_site:     'location-type-badge location-type-well_site',
    rig:           'location-type-badge location-type-rig',
    building:      'location-type-badge location-type-building',
  }
  return m[type ?? ''] ?? 'location-type-badge location-type-building'
}

/**
 * Returns a human-readable label for a location type.
 */
export function locationTypeLabel(type: string | null | undefined): string {
  if (!type) return '—'
  const m: Record<string, string> = {
    workshop:      'Workshop',
    workshop_yard: 'Workshop Yard',
    yard:          'Yard',
    well_site:     'Well Site',
    rig:           'Rig',
    building:      'Building',
  }
  return m[type] ?? type.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase())
}
```

**Validation:** `npm run build` should compile without TypeScript errors.

---

### Task 3: Create `useLocations` composable

**File:** `frontend/src/composables/useLocations.ts`

**Purpose:** Fetch active locations for the picker dropdown (handles Admin-only gap gracefully), provide CRUD mutations for Manage Locations tab, fetch asset list filtered by active status for the update tab.

**Full code:**

```ts
import { ref } from 'vue'
import api, { ApiError, fetchList } from '@/lib/api'
import type { Asset, Location } from '@/types'
import { useAuthStore } from '@/stores/auth.store'

/**
 * Composable for the Locations feature.
 *
 * ── Location list ──────────────────────────────────────────────────
 * Fetches active locations for the UpdateLocationSheet picker.
 * Uses GET /api/admin/locations (Admin only) — if the user is not
 * Admin, the list comes back empty until a non-admin endpoint exists.
 *
 * ── Asset list ─────────────────────────────────────────────────────
 * Fetches active assets for the Asset Location Update table.
 * Uses GET /api/assets?is_active=true with cursor-walking.
 *
 * ── CRUD mutations ─────────────────────────────────────────────────
 * createLocation / updateLocation / deactivateLocation / activateLocation
 * for the Manage Locations tab (Admin only — backend enforces).
 */

export function useLocations() {
  const auth = useAuthStore()

  // ── Location list for picker ────────────────────────────────────
  const locations = ref<Location[]>([])
  const locationsLoading = ref(false)
  const locationsError = ref<string | null>(null)

  async function loadLocations(force = false) {
    if (locations.value.length > 0 && !force) return
    locationsLoading.value = true
    locationsError.value = null
    try {
      // TODO: Switch to GET /api/locations once backend provides a
      // non-admin endpoint. For now, Admin gets locations; Manager/
      // Logistics gets empty list + error message.
      if (auth.isAdmin) {
        const res = await api.get<{ data: Location[] }>('/admin/locations')
        locations.value = (res.data ?? []).filter((l) => l.is_active)
      }
      // non-admin: locations.value stays empty — handled in UI
    } catch (e) {
      locations.value = []
      if (e instanceof ApiError && e.status === 403) {
        locationsError.value = 'Location list not available for your role. Contact an administrator.'
      } else {
        locationsError.value = 'Failed to load locations.'
      }
    } finally {
      locationsLoading.value = false
    }
  }

  // ── Asset list for update tab ───────────────────────────────────
  const assets = ref<Asset[]>([])
  const assetsLoading = ref(false)

  async function loadAssets(force = false) {
    if (assets.value.length > 0 && !force) return
    assetsLoading.value = true
    try {
      assets.value = await fetchList<Asset>('/assets', { is_active: true, sort: 'name:asc' })
    } catch {
      assets.value = []
    } finally {
      assetsLoading.value = false
    }
  }

  // ── Location CRUD mutations ─────────────────────────────────────
  const saving = ref(false)
  const validationErrors = ref<Record<string, string[]> | null>(null)

  interface LocationPayload {
    name: string
    type: string
    code?: string | null
    parent_id?: number | null
    description?: string | null
    is_active?: boolean
  }

  async function createLocation(payload: LocationPayload): Promise<Location | null> {
    saving.value = true
    validationErrors.value = null
    try {
      const res = await api.post<{ data: Location }>('/admin/locations', payload)
      // Refresh location list after create
      await loadLocations(true)
      return res.data
    } catch (e) {
      if (e instanceof ApiError && e.validationErrors) {
        validationErrors.value = e.validationErrors
      }
      return null
    } finally {
      saving.value = false
    }
  }

  async function updateLocation(id: number, payload: Partial<LocationPayload>): Promise<Location | null> {
    saving.value = true
    validationErrors.value = null
    try {
      const res = await api.patch<{ data: Location }>(`/admin/locations/${id}`, payload)
      await loadLocations(true)
      return res.data
    } catch (e) {
      if (e instanceof ApiError && e.validationErrors) {
        validationErrors.value = e.validationErrors
      }
      return null
    } finally {
      saving.value = false
    }
  }

  async function toggleLocationActive(location: Location): Promise<boolean> {
    saving.value = true
    validationErrors.value = null
    try {
      await api.patch<{ data: Location }>(`/admin/locations/${location.id}`, {
        is_active: !location.is_active,
      })
      await loadLocations(true)
      return true
    } catch (e) {
      if (e instanceof ApiError && e.validationErrors) {
        validationErrors.value = e.validationErrors
      }
      return false
    } finally {
      saving.value = false
    }
  }

  return {
    locations,
    locationsLoading,
    locationsError,
    loadLocations,
    assets,
    assetsLoading,
    loadAssets,
    saving,
    validationErrors,
    createLocation,
    updateLocation,
    toggleLocationActive,
  }
}
```

**Validation:** `npm run build` — no TS errors.

---

### Task 4: Create `UpdateLocationSheet`

**File:** `frontend/src/components/locations/UpdateLocationSheet.vue`

**Design (Insightful):** Side sheet with form fields at top, compact 3-entry location history timeline at bottom. Follows the existing `AssetDetailView.vue` edit sheet pattern.

**Props:**
- `asset: Asset` — the asset to update
- `locations: Location[]` — active locations for the picker
- `open: boolean` — controlled by parent

**Emits:** `close`, `saved`

**Form fields:**
- Asset display (read-only, tag + name)
- Current location (read-only badge)
- New location (Select, grouped by type, excludes current)
- Effective date (datetime-local, defaults to now)
- Reason (text input, optional)
- Notes (textarea, optional)

**Full code:**

```vue
<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import {
  Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription,
} from '@/components/ui/sheet'
import {
  Select, SelectContent, SelectGroup, SelectItem, SelectItemText,
  SelectLabel, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog'
import { Badge } from '@/components/ui/badge'
import api, { ApiError } from '@/lib/api'
import { toast } from 'vue-sonner'
import { locationTypeClass, fmtDate } from '@/lib/displayHelpers'
import type { Asset, Location, AssetLocationHistoryItem } from '@/types'

const props = defineProps<{
  asset: Asset
  locations: Location[]
  open: boolean
}>()

const emit = defineEmits<{ close: []; saved: [] }>()

// ── Form state ───────────────────────────────────────────────────────
const locationId = ref<string>('')
const effectiveDate = ref<string>(new Date().toISOString().slice(0, 16))
const reason = ref('')
const notes = ref('')
const saving = ref(false)
const validationErrors = ref<Record<string, string[]> | null>(null)
const confirmOpen = ref(false)
const error = ref<string | null>(null)

// Exclude current location from options
const availableLocations = computed(() =>
  props.locations.filter((l) => l.id !== props.asset.current_location?.id),
)

// Group locations by type for the Select
const locationGroups = computed(() => {
  const groups: Record<string, Location[]> = {}
  for (const loc of availableLocations.value) {
    const type = loc.type || 'Other'
    if (!groups[type]) groups[type] = []
    groups[type].push(loc)
  }
  return Object.entries(groups)
})

// ── Location history (mini timeline) ─────────────────────────────────
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

// Reload history when sheet opens with a new asset
watch(() => props.asset?.id, () => {
  if (props.open && props.asset?.id) loadHistory()
})

watch(() => props.open, (nowOpen) => {
  if (nowOpen && props.asset?.id) {
    loadHistory()
    resetForm()
  }
})

function resetForm() {
  locationId.value = ''
  effectiveDate.value = new Date().toISOString().slice(0, 16)
  reason.value = ''
  notes.value = ''
  validationErrors.value = null
  error.value = null
}

// ── Confirm + submit ─────────────────────────────────────────────────
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

// Find the selected location object for the confirm dialog
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
            <p v-if="asset.current_location" :class="locationTypeClass('')" style="font-size:0.875rem">
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
                    <SelectItem
                      v-for="loc in locs"
                      :key="loc.id"
                      :value="String(loc.id)"
                    >
                      {{ loc.name }}
                      <span v-if="loc.code" class="detail-field-muted" style="font-size:0.6875rem;margin-left:0.375rem">
                        {{ loc.code }}
                      </span>
                    </SelectItem>
                  </SelectGroup>
                </template>
              </SelectContent>
            </Select>
            <p v-if="validationErrors?.location_id" class="form-error">
              {{ validationErrors.location_id[0] }}
            </p>
          </div>

          <!-- Effective date -->
          <div class="form-field">
            <Label for="update-effective-date">Effective Date</Label>
            <Input
              id="update-effective-date"
              type="datetime-local"
              v-model="effectiveDate"
            />
          </div>

          <!-- Reason -->
          <div class="form-field">
            <Label for="update-reason">Reason <span class="field-optional">— optional</span></Label>
            <Input
              id="update-reason"
              v-model="reason"
              placeholder="E.g. reassigned to field, returned from maintenance…"
              maxlength="255"
            />
          </div>

          <!-- Notes -->
          <div class="form-field form-field-full">
            <Label for="update-notes">Notes <span class="field-optional">— optional</span></Label>
            <Textarea
              id="update-notes"
              v-model="notes"
              :rows="3"
              placeholder="Additional context about this location change…"
            />
          </div>
        </div>

        <!-- Compact location history timeline -->
        <div style="margin-top:1.25rem">
          <div v-if="historyLoading" class="detail-field-muted" style="text-align:center;padding:0.5rem">
            Loading history…
          </div>
          <div v-else-if="history.length > 0" class="compact-timeline">
            <p class="compact-timeline-title">Recent Location History</p>
            <div v-for="h in history" :key="h.id" class="compact-timeline-item">
              <span class="compact-timeline-date">{{ fmtDate(h.effective_at) }}</span>
              <span class="compact-timeline-summary">
                Moved{{ h.to_location_id ? ' to location' : '' }}
                <template v-if="h.reason"> — {{ h.reason }}</template>
              </span>
            </div>
          </div>
          <div v-else class="compact-timeline">
            <p class="compact-timeline-empty">No previous location changes recorded.</p>
          </div>
        </div>
      </div>

      <div class="create-sheet-footer">
        <Button variant="outline" :disabled="saving" @click="emit('close')">
          Cancel
        </Button>
        <Button :disabled="saving" @click="requestSave">
          Update Location
        </Button>
      </div>
    </SheetContent>
  </Sheet>

  <!-- Confirm dialog -->
  <Dialog v-model:open="confirmOpen">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Confirm Location Change</DialogTitle>
        <DialogDescription>
          Move <strong>{{ asset.asset_tag ?? asset.name }}</strong>
          from
          <strong>{{ asset.current_location?.name ?? 'No location' }}</strong>
          to
          <strong>{{ selectedLocation?.name ?? '—' }}</strong>?
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
```

**Validation:** `npm run build` — no errors. The sheet should open/close without console errors.

---

### Task 5: Create `LocationForm`

**File:** `frontend/src/components/locations/LocationForm.vue`

**Purpose:** Side sheet for creating and editing location definitions. Used by the Manage Locations tab (Admin only). Follows the same pattern as the asset edit sheet.

**Full code:**

```vue
<script setup lang="ts">
import { ref, watch } from 'vue'
import {
  Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription,
} from '@/components/ui/sheet'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import type { Location } from '@/types'

const props = defineProps<{
  open: boolean
  editing: Location | null
  locations: Location[]
}>()

const emit = defineEmits<{
  close: []
  save: [payload: {
    name: string
    type: string
    code: string | null
    parent_id: number | null
    description: string | null
    is_active: boolean
  }]
}>()

// ── Form state ───────────────────────────────────────────────────────
const name = ref('')
const locationType = ref('building')
const code = ref('')
const parentId = ref<string>('__none__')
const description = ref('')
const errorMessage = ref<string | null>(null)

const typeOptions = ['workshop', 'yard', 'workshop_yard', 'well_site', 'rig', 'building']

const isEdit = ref(false)

function resetForm() {
  name.value = ''
  locationType.value = 'building'
  code.value = ''
  parentId.value = '__none__'
  description.value = ''
  errorMessage.value = null
}

watch(() => props.open, (nowOpen) => {
  if (!nowOpen) return
  if (props.editing) {
    isEdit.value = true
    name.value = props.editing.name
    locationType.value = props.editing.type
    code.value = props.editing.code ?? ''
    parentId.value = props.editing.parent_id ? String(props.editing.parent_id) : '__none__'
    description.value = props.editing.description ?? ''
  } else {
    isEdit.value = false
    resetForm()
  }
})

// Parent location options (exclude self to prevent cycles)
const parentOptions = computed(() =>
  props.locations.filter((l) => l.id !== props.editing?.id),
)

// ── Submit ──────────────────────────────────────────────────────────
async function handleSave() {
  if (!name.value.trim()) {
    errorMessage.value = 'Location name is required.'
    return
  }
  if (!locationType.value) {
    errorMessage.value = 'Location type is required.'
    return
  }
  errorMessage.value = null
  emit('save', {
    name: name.value.trim(),
    type: locationType.value,
    code: code.value.trim() || null,
    parent_id: parentId.value === '__none__' ? null : Number(parentId.value),
    description: description.value.trim() || null,
    is_active: props.editing ? props.editing.is_active : true,
  })
}

const title = computed(() => isEdit.value ? 'Edit Location' : 'Create Location')
</script>

<template>
  <Sheet :open="open" :modal="false" @update:open="(v) => !v && emit('close')">
    <SheetContent side="right" class="create-sheet">
      <div class="create-sheet-header">
        <SheetHeader>
          <SheetTitle>{{ title }}</SheetTitle>
          <SheetDescription>Define a physical location for asset tracking.</SheetDescription>
        </SheetHeader>
      </div>

      <div class="create-sheet-body">
        <div v-if="errorMessage" class="error-state" role="alert">{{ errorMessage }}</div>

        <div class="sheet-form">
          <div class="form-field">
            <Label for="loc-name">Name <span class="field-required">*</span></Label>
            <Input id="loc-name" v-model="name" placeholder="E.g. Workshop, Rig A…" />
          </div>

          <div class="form-field">
            <Label for="loc-type">Type <span class="field-required">*</span></Label>
            <Select v-model="locationType">
              <SelectTrigger id="loc-type"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="t in typeOptions" :key="t" :value="t">
                  {{ t.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase()) }}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div class="form-field">
            <Label for="loc-code">Code <span class="field-optional">— optional</span></Label>
            <Input id="loc-code" v-model="code" placeholder="E.g. WS, RA…" />
          </div>

          <div class="form-field">
            <Label for="loc-parent">Parent Location <span class="field-optional">— optional</span></Label>
            <Select v-model="parentId">
              <SelectTrigger id="loc-parent">
                <SelectValue placeholder="No parent (top-level)" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="__none__">No parent (top-level)</SelectItem>
                <SelectItem
                  v-for="loc in parentOptions"
                  :key="loc.id"
                  :value="String(loc.id)"
                >{{ loc.name }}</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div class="form-field form-field-full">
            <Label for="loc-desc">Description <span class="field-optional">— optional</span></Label>
            <Textarea id="loc-desc" v-model="description" :rows="3" placeholder="Describe the location…" />
          </div>
        </div>
      </div>

      <div class="create-sheet-footer">
        <Button variant="outline" @click="emit('close')">Cancel</Button>
        <Button @click="handleSave">{{ isEdit ? 'Save Changes' : 'Create Location' }}</Button>
      </div>
    </SheetContent>
  </Sheet>
</template>
```

**Validation:** `npm run build` — no errors.

---

### Task 6: Create `AssetLocationUpdateView`

**File:** `frontend/src/views/locations/AssetLocationUpdateView.vue`

**Purpose:** Tab 1 content — list of active assets with current location, location type badges, and "Update Location" per-row action that opens `UpdateLocationSheet`.

**Full code:**

```vue
<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppDataTable from '@/components/app/AppDataTable.vue'
import UpdateLocationSheet from '@/components/locations/UpdateLocationSheet.vue'
import { Button } from '@/components/ui/button'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import { useLocations } from '@/composables/useLocations'
import { locationTypeClass, locationTypeLabel, assetMaintenanceStatusClass, assetMaintenanceStatusLabel } from '@/lib/displayHelpers'
import type { AppColumnDef } from '@/lib/appTable'
import type { Asset } from '@/types'

const router = useRouter()
const { locations, locationsLoading, locationsError, loadLocations, assets, assetsLoading, loadAssets } = useLocations()

// ── Column definitions ──────────────────────────────────────────────
const columns: AppColumnDef<Asset>[] = [
  { field: 'asset_tag', header: 'Asset Tag', sortable: false },
  { field: 'name', header: 'Name', sortable: true, minWidth: 280 },
  { field: 'current_location', header: 'Current Location', sortable: false },
  { field: 'maintenance_status', header: 'Status', sortable: true },
]

// ── Location filter ─────────────────────────────────────────────────
const locationFilter = ref<number | null>(null)

const filteredAssets = computed<Asset[]>(() => {
  if (!locationFilter.value) return assets.value
  return assets.value.filter((a) => a.current_location?.id === locationFilter.value)
})

// ── Lazy load ────────────────────────────────────────────────────────
watch(assetsLoading, (loading) => {
  if (!loading && assets.value.length === 0) loadAssets()
})

// Initial load
loadLocations()
loadAssets()

// ── Selected asset for sheet ────────────────────────────────────────
const selectedAsset = ref<Asset | null>(null)
const sheetOpen = ref(false)

function openSheet(asset: Asset) {
  selectedAsset.value = asset
  sheetOpen.value = true
}

function onClose() {
  sheetOpen.value = false
  selectedAsset.value = null
}

function onSaved() {
  loadAssets(true)  // Refresh asset list
}

function goHistory(asset: Asset) {
  router.push(`/assets/${asset.id}?tab=location-history`)
}
</script>

<template>
  <div>
    <!-- Filter bar -->
    <div v-if="locations.length > 0" class="asset-filter-bar">
      <span class="detail-field-muted" style="font-size:0.8125rem">Filter by location:</span>
      <Select
        :model-value="locationFilter !== null ? String(locationFilter) : '__all__'"
        @update:model-value="(v) => { locationFilter = v === '__all__' ? null : Number(v) }"
      >
        <SelectTrigger class="asset-location-filter">
          <SelectValue placeholder="All locations" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="__all__">All locations</SelectItem>
          <SelectItem
            v-for="loc in locations"
            :key="loc.id"
            :value="String(loc.id)"
          >{{ loc.name }}</SelectItem>
        </SelectContent>
      </Select>
    </div>

    <div v-if="locationsError" class="error-state" role="alert">
      {{ locationsError }}
    </div>

    <AppDataTable
      :key="locationFilter"
      :rows="filteredAssets"
      :columns="columns"
      empty-text="No active assets found."
      label="Assets"
      :loading="assetsLoading"
    >
      <template #cell="{ column, row }">
        <!-- Asset tag / name link to detail -->
        <RouterLink
          v-if="column.field === 'asset_tag'"
          :to="`/assets/${row.id}`"
          class="table-link"
        >{{ row.asset_tag ?? '—' }}</RouterLink>

        <RouterLink
          v-else-if="column.field === 'name'"
          :to="`/assets/${row.id}`"
          class="table-link"
        >{{ row.name }}</RouterLink>

        <!-- Location with type badge -->
        <span v-else-if="column.field === 'current_location'" :class="locationTypeClass(row.current_location?.type)">
          {{ row.current_location?.name ?? '—' }}
        </span>

        <!-- Maintenance status badge -->
        <span
          v-else-if="column.field === 'maintenance_status'"
          :class="assetMaintenanceStatusClass(row.maintenance_status)"
        >{{ assetMaintenanceStatusLabel(row.maintenance_status) }}</span>
      </template>

      <!-- Per-row actions: Update Location + View History -->
      <template #row-actions="{ row }">
        <div class="data-card-actions" style="gap:0.5rem">
          <Button variant="outline" size="sm" @click="openSheet(row)">
            Update Location
          </Button>
          <Button variant="ghost" size="sm" @click="goHistory(row)">
            History
          </Button>
        </div>
      </template>
    </AppDataTable>

    <!-- Update Location Sheet -->
    <UpdateLocationSheet
      v-if="selectedAsset"
      :asset="selectedAsset"
      :locations="locations"
      :open="sheetOpen"
      @close="onClose"
      @saved="onSaved"
    />
  </div>
</template>
```

**Validation:** `npm run build` — no errors. Navigate to `/locations?tab=asset-location-update` to view.

---

### Task 7: Create `ManageLocationsView`

**File:** `frontend/src/views/locations/ManageLocationsView.vue`

**Purpose:** Tab 2 content — CRUD table for location definitions (Admin only). Uses the existing `GET/POST/PATCH /api/admin/locations` endpoints.

**Full code:**

```vue
<script setup lang="ts">
import { ref, watch } from 'vue'
import AppDataTable from '@/components/app/AppDataTable.vue'
import LocationForm from '@/components/locations/LocationForm.vue'
import { Button } from '@/components/ui/button'
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog'
import { useLocations } from '@/composables/useLocations'
import { toast } from 'vue-sonner'
import { locationTypeClass, locationTypeLabel } from '@/lib/displayHelpers'
import type { AppColumnDef } from '@/lib/appTable'
import type { Location } from '@/types'

const { locations, locationsLoading, loadLocations, saving, validationErrors, createLocation, updateLocation, toggleLocationActive } = useLocations()

// ── Column definitions ──────────────────────────────────────────────
const columns: AppColumnDef<Location>[] = [
  { field: 'name', header: 'Name', sortable: true, minWidth: 200 },
  { field: 'type', header: 'Type', sortable: true },
  { field: 'code', header: 'Code', sortable: false },
  { field: 'is_active', header: 'Status', sortable: true },
]

// ── Lazy load ───────────────────────────────────────────────────────
loadLocations()

// ── Create / Edit sheet ─────────────────────────────────────────────
const formOpen = ref(false)
const editingLocation = ref<Location | null>(null)

function openCreate() {
  editingLocation.value = null
  formOpen.value = true
}

function openEdit(loc: Location) {
  editingLocation.value = loc
  formOpen.value = true
}

function onCloseForm() {
  formOpen.value = false
  editingLocation.value = null
  validationErrors.value = null
}

// ── Save handler ────────────────────────────────────────────────────
async function onSave(payload: {
  name: string
  type: string
  code: string | null
  parent_id: number | null
  description: string | null
  is_active: boolean
}) {
  let result: Location | null
  if (editingLocation.value) {
    result = await updateLocation(editingLocation.value.id, payload)
  } else {
    result = await createLocation(payload)
  }
  if (result) {
    toast.success(editingLocation.value ? 'Location updated.' : 'Location created.')
    onCloseForm()
  }
}

// ── Activate / Deactivate ───────────────────────────────────────────
const toggleDialogOpen = ref(false)
const toggleTarget = ref<Location | null>(null)

function openToggle(loc: Location) {
  toggleTarget.value = loc
  toggleDialogOpen.value = true
}

async function confirmToggle() {
  if (!toggleTarget.value) return
  const ok = await toggleLocationActive(toggleTarget.value)
  if (ok) {
    toast.success(toggleTarget.value.is_active ? 'Location deactivated.' : 'Location reactivated.')
  }
  toggleDialogOpen.value = false
  toggleTarget.value = null
}
</script>

<template>
  <div>
    <div class="filter-bar" style="margin-bottom:0.75rem">
      <Button size="sm" @click="openCreate">Create Location</Button>
    </div>

    <AppDataTable
      :rows="locations"
      :columns="columns"
      empty-text="No locations defined."
      label="Locations"
      :loading="locationsLoading"
    >
      <template #cell="{ column, row }">
        <!-- Type with badge -->
        <span v-if="column.field === 'type'" :class="locationTypeClass(row.type)">
          {{ locationTypeLabel(row.type) }}
        </span>

        <!-- Active status badge -->
        <span
          v-else-if="column.field === 'is_active'"
          :class="row.is_active ? 'status-badge status-active' : 'status-badge status-inactive'"
        >{{ row.is_active ? 'Active' : 'Inactive' }}</span>

        <span v-else>{{ row[column.field as keyof Location] ?? '—' }}</span>
      </template>

      <template #row-actions="{ row }">
        <div class="data-card-actions" style="gap:0.5rem">
          <Button variant="outline" size="sm" @click="openEdit(row)">Edit</Button>
          <Button variant="ghost" size="sm" @click="openToggle(row)">
            {{ row.is_active ? 'Deactivate' : 'Activate' }}
          </Button>
        </div>
      </template>
    </AppDataTable>

    <!-- Create / Edit sheet -->
    <LocationForm
      :open="formOpen"
      :editing="editingLocation"
      :locations="locations"
      @close="onCloseForm"
      @save="onSave"
    />

    <!-- Activate / Deactivate confirm dialog -->
    <Dialog v-model:open="toggleDialogOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {{ toggleTarget?.is_active ? 'Deactivate' : 'Reactivate' }} Location
          </DialogTitle>
          <DialogDescription v-if="toggleTarget">
            {{ toggleTarget.is_active
              ? `Are you sure you want to deactivate "${toggleTarget.name}"? It will no longer appear in location pickers.`
              : `Reactivate "${toggleTarget.name}"? It will appear in location pickers again.` }}
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" @click="toggleDialogOpen = false">Cancel</Button>
          <Button :disabled="saving" @click="confirmToggle">
            {{ saving ? 'Saving…' : (toggleTarget?.is_active ? 'Deactivate' : 'Reactivate') }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>
```

**Validation:** `npm run build` — no errors.

---

### Task 8: Create `LocationsView` (tab wrapper)

**File:** `frontend/src/views/locations/LocationsView.vue`

**Purpose:** Parent view container with tab navigation between the two tabs.

**Full code:**

```vue
<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppLayout from '@/components/app/AppLayout.vue'
import AssetLocationUpdateView from './AssetLocationUpdateView.vue'
import ManageLocationsView from './ManageLocationsView.vue'
import { useAuthStore } from '@/stores/auth.store'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

// ── Tab definitions ─────────────────────────────────────────────────
const tabDefs = computed(() => {
  const tabs: { key: string; label: string; visible: boolean }[] = [
    { key: 'asset-location-update', label: 'Asset Location Update', visible: true },
  ]
  if (auth.isAdmin) {
    tabs.push({ key: 'manage-locations', label: 'Manage Locations', visible: true })
  }
  return tabs
})

const activeTab = computed(() => {
  const q = route.query.tab as string | undefined
  if (q && tabDefs.value.some((t) => t.visible && t.key === q)) return q
  return 'asset-location-update'
})

watch(activeTab, (newTab) => {
  if (route.query.tab !== newTab) {
    router.replace({ query: { tab: newTab } })
  }
})
</script>

<template>
  <AppLayout>
    <div class="page-section">
      <div class="page-header">
        <div class="page-heading">
          <h1 class="page-title">Locations</h1>
          <p class="page-subtitle">Track and manage physical asset locations</p>
        </div>
      </div>

      <nav class="view-tabs">
        <RouterLink
          v-for="tab in tabDefs"
          :key="tab.key"
          :to="{ query: { tab: tab.key } }"
          :class="['view-tab', activeTab === tab.key ? 'view-tab-active' : 'view-tab-normal']"
        >{{ tab.label }}</RouterLink>
      </nav>

      <template v-if="activeTab === 'asset-location-update'">
        <AssetLocationUpdateView />
      </template>
      <template v-else-if="activeTab === 'manage-locations' && auth.isAdmin">
        <ManageLocationsView />
      </template>
    </div>
  </AppLayout>
</template>
```

**Validation:** `npm run build` — no errors.

---

### Task 9: Add `/locations` route

**File:** `frontend/src/router/index.ts`

**Change:** Add the Locations route before the Admin routes. Insert after the Parts routes (around line ~90).

```ts
// Insert after the Parts routes block:

    {
      path: '/locations',
      name: 'locations',
      component: () => import('@/views/locations/LocationsView.vue'),
    },
```

**Validation:** `npm run build` — no errors. Navigate to `/locations` in browser — should render, redirect to `?tab=asset-location-update`.

---

### Task 10: Add Locations sidebar item

**File:** `frontend/src/components/app/AppSidebar.vue`

**Change 1:** Add `MapPin` to the lucide-vue import.

```ts
import {
  LayoutDashboard, ClipboardList, Wrench, HardDrive, Package, Settings,
  Shield, ChevronUp, MapPin,
} from '@lucide/vue'
```

**Change 2:** Add Locations nav item definition. Insert after the Parts Management item (~line 78) and before Admin:

```ts
  {
    label: 'Locations',
    icon: MapPin,
    to: () => '/locations?tab=asset-location-update',
    isActiveFor: (p) => p === '/locations' || p.startsWith('/locations/'),
    visibleTo: (r) => r.isAdminOrManager || r.isLogistics,
  },
```

**Validation:** `npm run build` — no errors. Login as Logistics, Manager, or Admin — the "Locations" sidebar item should appear between "Parts Management" and "Admin". Login as Technician or Requester — should not appear.

---

## Testing Checklist

| Test | Method |
|---|---|
| Sidebar visibility | Login as each role (Admin, Manager, Logistics, Tech, Requester). Only Admin/Manager/Logistics should see "Locations" item. |
| Tab visibility | Admin sees both tabs. Manager/Logistics see only "Asset Location Update." |
| Asset list loads | Active assets appear in the table with correct location badges. |
| Location filter | Selecting a location from the filter bar narrows the list correctly. |
| UpdateLocationSheet opens | Click "Update Location" — sheet opens, current location shown, history timeline loads last 3 entries. |
| Location picker groups by type | Dropdown shows location types as group labels. |
| Confirm dialog | "Update Location" → confirm dialog shows correct from/to. |
| Successful update | Confirm → toast shows success message → asset list refreshes → history refreshes. |
| Backend rejection | Attempt updating inactive asset → shows backend error message. |
| Manage Locations CRUD | Admin creates a location → appears in table. Edit → updates. Deactivate → toggles status. |
| Backend test | `php artisan test tests/Feature/Assets/LocationWorkflowTest.php` — all tests pass (must still pass after any backend changes). |
| Build | `cd frontend && npm run build` — no TS errors, no CSS errors, clean output. |
| CSS classes | Scan compiled CSS for `.location-type-*`, `.compact-timeline*` — all present. |

---

## Risk Notes

1. **Backend location list gap:** If `GET /api/locations` is not added, Manager/Logistics see "Location list not available for your role." in the picker. The location filter bar also won't render. The rest of the feature works — assets display, location badges render, UpdateLocationSheet opens (but the location dropdown is empty for non-Admin).

2. **`effective_at` field:** The `UpdateAssetLocation` Action hardcodes `now()` as the effective date. The `effectiveDate` form field in `UpdateLocationSheet` is currently not sent to the API. If the backend needs to accept a custom effective date, this requires a backend change. For now, the field is display-only (shows when the change takes effect — always "now").

3. **Route conflict:** The existing `/settings/locations` route (stub `LocationsView.vue`) should be removed or redirected to keep only one Locations entry point.

4. **CSS class collisions:** The new CSS classes use unique prefixes (`location-type-`, `compact-timeline`) — no risk of collision with existing classes.
