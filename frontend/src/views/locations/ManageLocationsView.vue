<script setup lang="ts">
import { ref, onMounted } from 'vue'
import AppDataTable from '@/components/app/AppDataTable.vue'
import LocationForm from '@/components/locations/LocationForm.vue'
import { Button } from '@/components/ui/button'
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog'
import { useLocations } from '@/composables/useLocations'
import { toast } from 'vue-sonner'
import { locationTypeClass, locationTypeLabel } from '@/lib/displayHelpers'
import { Pencil, ToggleLeft, ToggleRight } from '@lucide/vue'
import type { AppColumnDef } from '@/lib/appTable'
import type { Location } from '@/types'

const {
  locations, locationsLoading, loadLocations,
  saving, validationErrors,
  createLocation, updateLocation, toggleLocationActive,
} = useLocations()

// ── Column definitions ────────────────────────────────────────────────────────
const columns: AppColumnDef<Location>[] = [
  { field: 'name',      header: 'Name',   sortable: true,  minWidth: 200 },
  { field: 'type',      header: 'Type',   sortable: true,  align: 'center' },
  { field: 'code',      header: 'Code',   sortable: false, align: 'center' },
  { field: 'is_active', header: 'Status', sortable: true,  align: 'center' },
  { field: 'actions',   header: '',       sortable: false, minWidth: 80, align: 'center' },
]

onMounted(() => loadLocations())

// ── Create / Edit sheet ───────────────────────────────────────────────────────
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

// ── Activate / Deactivate ─────────────────────────────────────────────────────
const toggleDialogOpen = ref(false)
const toggleTarget = ref<Location | null>(null)

function openToggle(loc: Location) {
  toggleTarget.value = loc
  toggleDialogOpen.value = true
}

async function confirmToggle() {
  if (!toggleTarget.value) return
  const wasActive = toggleTarget.value.is_active
  const ok = await toggleLocationActive(toggleTarget.value)
  if (ok) {
    toast.success(wasActive ? 'Location deactivated.' : 'Location reactivated.')
  }
  toggleDialogOpen.value = false
  toggleTarget.value = null
}
</script>

<template>
  <div>
    <div class="filter-bar">
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
        <span v-if="column.field === 'type'" :class="locationTypeClass(row.type)">
          {{ locationTypeLabel(row.type) }}
        </span>

        <span
          v-else-if="column.field === 'is_active'"
          :class="row.is_active ? 'status-badge status-active' : 'status-badge status-inactive'"
        >{{ row.is_active ? 'Active' : 'Inactive' }}</span>

        <!-- Per-row actions -->
        <div v-else-if="column.field === 'actions'" class="table-row-actions">
          <Button variant="outline" size="icon-sm" :aria-label="`Edit ${row.name}`" @click="openEdit(row)">
            <Pencil />
          </Button>
          <Button variant="ghost" size="icon-sm" :aria-label="`${row.is_active ? 'Deactivate' : 'Activate'} ${row.name}`" @click="openToggle(row)">
            <ToggleRight v-if="row.is_active" />
            <ToggleLeft v-else />
          </Button>
        </div>

        <span v-else>{{ row[column.field as keyof Location] ?? '—' }}</span>
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
