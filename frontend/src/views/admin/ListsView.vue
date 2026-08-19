<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { toast } from 'vue-sonner'
import AppDataTable from '@/components/app/AppDataTable.vue'
import ListItemSheet from '@/components/admin/ListItemSheet.vue'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog'
import { useLists, LIST_GROUPS, LIST_SECTIONS } from '@/composables/useLists'
import { Pencil, ToggleLeft, ToggleRight, Plus } from '@lucide/vue'
import type { AppColumnDef } from '@/lib/appTable'
import type { ListItem } from '@/composables/useLists'
import type { MasterDataItem, UsageReadingType, MaintenanceCategoryOption } from '@/types'

const {
  activeGroupKey,
  activeGroup,
  items,
  loading,
  error,
  loadActive,
  selectGroup,
  saving,
  validationErrors,
  mutationError,
  createItem,
  updateItem,
  toggleActive,
} = useLists()

// ── Rail sections ─────────────────────────────────────────────────────────────
const railSections = LIST_SECTIONS.map((section) => ({
  section,
  groups: LIST_GROUPS.filter((g) => g.section === section),
}))

// ── Columns per kind ──────────────────────────────────────────────────────────
const columns = computed<AppColumnDef<ListItem>[]>(() => {
  if (activeGroup.value.kind === 'master_data') {
    return [
      { field: 'label', header: 'Label', sortable: true },
      { field: 'value', header: 'Value', sortable: true },
      { field: 'sort_order', header: 'Sort', sortable: true, align: 'center' },
      { field: 'is_active', header: 'Status', sortable: true, align: 'center' },
      { field: 'actions', header: '', sortable: false, align: 'center', minWidth: 80 },
    ]
  }
  if (activeGroup.value.kind === 'reading_types') {
    return [
      { field: 'name', header: 'Name', sortable: true },
      { field: 'unit', header: 'Unit', sortable: true, align: 'center' },
      { field: 'is_active', header: 'Status', sortable: true, align: 'center' },
      { field: 'actions', header: '', sortable: false, align: 'center', minWidth: 80 },
    ]
  }
  return [
    { field: 'name', header: 'Name', sortable: true },
    { field: 'code', header: 'Code', sortable: true, align: 'center' },
    { field: 'is_active', header: 'Status', sortable: true, align: 'center' },
    { field: 'actions', header: '', sortable: false, align: 'center', minWidth: 80 },
  ]
})

const panelSubtitle = computed(() => {
  switch (activeGroup.value.kind) {
    case 'master_data':
      return 'Lookup values selectable on records across the system.'
    case 'reading_types':
      return 'Meter / usage reading types used by assets and PM rules.'
    case 'maintenance_category':
      return 'Controlled category vocabulary shared by assets and parts.'
  }
})

onMounted(() => loadActive())

// ── Create / Edit sheet ───────────────────────────────────────────────────────
const sheetOpen = ref(false)
const editing = ref<ListItem | null>(null)

function openCreate() {
  editing.value = null
  validationErrors.value = null
  sheetOpen.value = true
}

function openEdit(item: ListItem) {
  editing.value = item
  validationErrors.value = null
  sheetOpen.value = true
}

function closeSheet() {
  sheetOpen.value = false
  editing.value = null
  validationErrors.value = null
}

async function onSave(payload: Record<string, unknown>) {
  const ok = editing.value ? await updateItem(editing.value, payload) : await createItem(payload)
  if (ok) {
    toast.success(editing.value ? 'Item updated.' : 'Item created.')
    closeSheet()
  }
}

// ── Toggle active (all list kinds) ────────────────────────────────────────────
const toggleOpen = ref(false)
const toggleTarget = ref<ListItem | null>(null)

function openToggle(item: ListItem) {
  toggleTarget.value = item
  toggleOpen.value = true
}

async function confirmToggle() {
  if (!toggleTarget.value) return
  const wasActive = itemIsActive(toggleTarget.value)
  const ok = await toggleActive(toggleTarget.value)

  // Closes on success only. It used to close either way, so the API refusing to
  // deactivate a default row — the one refusal this dialog can actually provoke
  // — looked exactly like it having worked: dialog gone, list unchanged, no
  // message anywhere. The error is surfaced in the dialog instead.
  if (!ok) {
    toast.error(mutationError.value ?? 'That change could not be applied.')

    return
  }

  toast.success(wasActive ? 'Item deactivated.' : 'Item reactivated.')
  toggleOpen.value = false
  toggleTarget.value = null
}

// ── Cell helpers ──────────────────────────────────────────────────────────────
function asMaster(row: ListItem) {
  return row as MasterDataItem
}
function asReading(row: ListItem) {
  return row as UsageReadingType
}
function asCategory(row: ListItem) {
  return row as MaintenanceCategoryOption
}

/** Reading types and maintenance categories both expose a display `name`. */
function itemName(row: ListItem): string {
  return (row as UsageReadingType | MaintenanceCategoryOption).name
}

/** Every list kind carries an `is_active` flag (optional on categories). */
function itemIsActive(row: ListItem): boolean {
  return Boolean((row as MasterDataItem | UsageReadingType | MaintenanceCategoryOption).is_active)
}

function itemLabel(row: ListItem): string {
  switch (activeGroup.value.kind) {
    case 'reading_types':
      return asReading(row).name
    case 'maintenance_category':
      return asCategory(row).name
    default:
      return asMaster(row).label
  }
}
</script>

<template>
  <div class="lists-layout">
    <!-- ── Group rail ──────────────────────────────────────────────────────── -->
    <nav class="lists-rail" aria-label="List groups">
      <div v-for="rs in railSections" :key="rs.section" class="lists-rail-section">
        <p class="lists-rail-section-title">{{ rs.section }}</p>
        <Button
          v-for="g in rs.groups"
          :key="g.key"
          variant="ghost"
          :class="['lists-rail-item', activeGroupKey === g.key ? 'lists-rail-item-active' : '']"
          :aria-current="activeGroupKey === g.key ? 'true' : undefined"
          @click="selectGroup(g.key)"
          >{{ g.label }}</Button
        >
      </div>
    </nav>

    <!-- ── Active group panel ──────────────────────────────────────────────── -->
    <section class="lists-panel">
      <div class="lists-panel-header">
        <div class="lists-panel-heading">
          <h2 class="lists-panel-title">{{ activeGroup.label }}</h2>
          <p class="lists-panel-subtitle">{{ panelSubtitle }}</p>
        </div>
        <Button size="sm" @click="openCreate">
          <Plus />
          Add Item
        </Button>
      </div>

      <div v-if="error" class="error-state" role="alert">{{ error }}</div>

      <AppDataTable
        :key="activeGroupKey"
        :rows="items"
        :columns="columns"
        empty-text="No items defined yet."
        label="Items"
        :loading="loading"
      >
        <template #cell="{ column, row }">
          <!-- Master data -->
          <span v-if="column.field === 'label'" class="table-cell-primary">{{
            asMaster(row).label
          }}</span>
          <span v-else-if="column.field === 'value'" class="atms-erp-code">{{
            asMaster(row).value
          }}</span>
          <span v-else-if="column.field === 'sort_order'" class="table-cell-secondary">
            {{ asMaster(row).sort_order ?? '—' }}
          </span>

          <!-- Reading types + maintenance categories (shared name) -->
          <span v-else-if="column.field === 'name'" class="table-cell-primary">{{
            itemName(row)
          }}</span>
          <span v-else-if="column.field === 'unit'" class="table-cell-secondary">{{
            asReading(row).unit
          }}</span>

          <!-- Maintenance category code -->
          <span v-else-if="column.field === 'code'" class="atms-erp-code">{{
            asCategory(row).code
          }}</span>

          <!-- Status badge (all kinds) -->
          <span
            v-else-if="column.field === 'is_active'"
            :class="
              itemIsActive(row) ? 'status-badge status-active' : 'status-badge status-inactive'
            "
            >{{ itemIsActive(row) ? 'Active' : 'Inactive' }}</span
          >

          <!-- Actions -->
          <div v-else-if="column.field === 'actions'" class="table-row-actions">
            <Button
              variant="outline"
              size="icon-sm"
              :aria-label="`Edit ${itemLabel(row)}`"
              @click="openEdit(row)"
            >
              <Pencil />
            </Button>

            <Button
              variant="ghost"
              size="icon-sm"
              :aria-label="`${itemIsActive(row) ? 'Deactivate' : 'Reactivate'} ${itemLabel(row)}`"
              @click="openToggle(row)"
            >
              <ToggleRight v-if="itemIsActive(row)" />
              <ToggleLeft v-else />
            </Button>
          </div>
        </template>
      </AppDataTable>
    </section>
  </div>

  <!-- ── Create / Edit sheet ─────────────────────────────────────────────── -->
  <ListItemSheet
    :open="sheetOpen"
    :group="activeGroup"
    :editing="editing"
    :saving="saving"
    :validation-errors="validationErrors"
    @close="closeSheet"
    @save="onSave"
  />

  <!-- ── Toggle active confirm ───────────────────────────────────────────── -->
  <Dialog v-model:open="toggleOpen">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>{{
          toggleTarget && itemIsActive(toggleTarget) ? 'Deactivate Item' : 'Reactivate Item'
        }}</DialogTitle>
        <DialogDescription v-if="toggleTarget">
          {{
            itemIsActive(toggleTarget)
              ? 'Deactivated items no longer appear in dropdowns but remain on existing records.'
              : 'Reactivate this item so it appears in dropdowns again.'
          }}
        </DialogDescription>
      </DialogHeader>
      <DialogFooter>
        <Button variant="outline" @click="toggleOpen = false">Cancel</Button>
        <Button :disabled="saving" @click="confirmToggle">
          {{
            saving
              ? 'Saving…'
              : toggleTarget && itemIsActive(toggleTarget)
                ? 'Deactivate'
                : 'Reactivate'
          }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
