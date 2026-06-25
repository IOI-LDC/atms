<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { toast } from 'vue-sonner'
import AppDataTable from '@/components/app/AppDataTable.vue'
import ListItemSheet from '@/components/admin/ListItemSheet.vue'
import { Button } from '@/components/ui/button'
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog'
import { useLists, LIST_GROUPS, LIST_SECTIONS } from '@/composables/useLists'
import { Pencil, ToggleLeft, ToggleRight, Trash2, Plus } from '@lucide/vue'
import type { AppColumnDef } from '@/lib/appTable'
import type { ListItem } from '@/composables/useLists'
import type { MasterDataItem, UsageReadingType, FaSubclassTypeCode } from '@/types'

const {
  activeGroupKey, activeGroup, items, loading, error,
  loadActive, selectGroup,
  saving, validationErrors,
  createItem, updateItem, toggleActive, deleteItem,
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
      { field: 'label',     header: 'Label',  sortable: true },
      { field: 'value',     header: 'Value',  sortable: true },
      { field: 'sort_order', header: 'Sort',   sortable: true, align: 'center' },
      { field: 'is_active', header: 'Status', sortable: true, align: 'center' },
      { field: 'actions',   header: '',       sortable: false, align: 'center', minWidth: 80 },
    ]
  }
  if (activeGroup.value.kind === 'reading_types') {
    return [
      { field: 'name',      header: 'Name',   sortable: true },
      { field: 'unit',      header: 'Unit',   sortable: true, align: 'center' },
      { field: 'is_active', header: 'Status', sortable: true, align: 'center' },
      { field: 'actions',   header: '',       sortable: false, align: 'center', minWidth: 80 },
    ]
  }
  return [
    { field: 'fa_subclass_code',     header: 'Subclass Code',    sortable: true },
    { field: 'type_code',            header: 'Type Code',        sortable: true, align: 'center' },
    { field: 'description',          header: 'Description',      sortable: false },
    { field: 'has_no_physical_size', header: 'No Physical Size', sortable: false, align: 'center' },
    { field: 'actions',              header: '',                 sortable: false, align: 'center', minWidth: 80 },
  ]
})

const panelSubtitle = computed(() => {
  switch (activeGroup.value.kind) {
    case 'master_data':  return 'Lookup values selectable on records across the system.'
    case 'reading_types': return 'Meter / usage reading types used by assets and PM rules.'
    case 'fa_subclass':   return 'ERP fixed-asset subclass classification reference.'
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
  const ok = editing.value
    ? await updateItem(editing.value, payload)
    : await createItem(payload)
  if (ok) {
    toast.success(editing.value ? 'Item updated.' : 'Item created.')
    closeSheet()
  }
}

// ── Toggle active (master_data + reading_types) ───────────────────────────────
const toggleOpen = ref(false)
const toggleTarget = ref<MasterDataItem | UsageReadingType | null>(null)

function openToggle(item: MasterDataItem | UsageReadingType) {
  toggleTarget.value = item
  toggleOpen.value = true
}

async function confirmToggle() {
  if (!toggleTarget.value) return
  const wasActive = toggleTarget.value.is_active
  const ok = await toggleActive(toggleTarget.value)
  if (ok) toast.success(wasActive ? 'Item deactivated.' : 'Item reactivated.')
  toggleOpen.value = false
  toggleTarget.value = null
}

// ── Delete (fa_subclass only) ─────────────────────────────────────────────────
const deleteOpen = ref(false)
const deleteTarget = ref<FaSubclassTypeCode | null>(null)

function openDelete(item: FaSubclassTypeCode) {
  deleteTarget.value = item
  deleteOpen.value = true
}

async function confirmDelete() {
  if (!deleteTarget.value) return
  const ok = await deleteItem(deleteTarget.value)
  if (ok) toast.success('Subclass code deleted.')
  else toast.error('Failed to delete — it may be referenced by existing assets.')
  deleteOpen.value = false
  deleteTarget.value = null
}

// ── Cell helpers ──────────────────────────────────────────────────────────────
function asMaster(row: ListItem) { return row as MasterDataItem }
function asReading(row: ListItem) { return row as UsageReadingType }
function asFa(row: ListItem) { return row as FaSubclassTypeCode }

function itemLabel(row: ListItem): string {
  switch (activeGroup.value.kind) {
    case 'reading_types':
      return asReading(row).name
    case 'fa_subclass':
      return asFa(row).fa_subclass_code
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
        >{{ g.label }}</Button>
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
          <span v-if="column.field === 'label'" class="table-cell-primary">{{ asMaster(row).label }}</span>
          <span v-else-if="column.field === 'value'" class="atms-erp-code">{{ asMaster(row).value }}</span>
          <span v-else-if="column.field === 'sort_order'" class="table-cell-secondary">
            {{ asMaster(row).sort_order ?? '—' }}
          </span>

          <!-- Reading types -->
          <span v-else-if="column.field === 'name'" class="table-cell-primary">{{ asReading(row).name }}</span>
          <span v-else-if="column.field === 'unit'" class="table-cell-secondary">{{ asReading(row).unit }}</span>

          <!-- FA subclass -->
          <span v-else-if="column.field === 'fa_subclass_code'" class="table-cell-primary">
            {{ asFa(row).fa_subclass_code }}
          </span>
          <span v-else-if="column.field === 'type_code'" class="atms-erp-code">{{ asFa(row).type_code }}</span>
          <span v-else-if="column.field === 'description'" class="table-cell-truncate">
            {{ asFa(row).description ?? '—' }}
          </span>
          <span v-else-if="column.field === 'has_no_physical_size'" class="table-cell-secondary">
            {{ asFa(row).has_no_physical_size ? 'Yes' : 'No' }}
          </span>

          <!-- Status badge (master_data + reading_types) -->
          <span
            v-else-if="column.field === 'is_active'"
            :class="asMaster(row).is_active ? 'status-badge status-active' : 'status-badge status-inactive'"
          >{{ asMaster(row).is_active ? 'Active' : 'Inactive' }}</span>

          <!-- Actions -->
          <div v-else-if="column.field === 'actions'" class="table-row-actions">
            <Button variant="outline" size="icon-sm" :aria-label="`Edit ${itemLabel(row)}`" @click="openEdit(row)">
              <Pencil />
            </Button>

            <Button
              v-if="activeGroup.kind === 'fa_subclass'"
              variant="ghost"
              size="icon-sm"
              :aria-label="`Delete ${asFa(row).fa_subclass_code}`"
              @click="openDelete(asFa(row))"
            >
              <Trash2 />
            </Button>
            <Button
              v-else
              variant="ghost"
              size="icon-sm"
              :aria-label="`${asMaster(row).is_active ? 'Deactivate' : 'Reactivate'} ${itemLabel(row)}`"
              @click="openToggle(asMaster(row))"
            >
              <ToggleRight v-if="asMaster(row).is_active" />
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
        <DialogTitle>{{ toggleTarget?.is_active ? 'Deactivate Item' : 'Reactivate Item' }}</DialogTitle>
        <DialogDescription v-if="toggleTarget">
          {{ toggleTarget.is_active
            ? 'Deactivated items no longer appear in dropdowns but remain on existing records.'
            : 'Reactivate this item so it appears in dropdowns again.' }}
        </DialogDescription>
      </DialogHeader>
      <DialogFooter>
        <Button variant="outline" @click="toggleOpen = false">Cancel</Button>
        <Button :disabled="saving" @click="confirmToggle">
          {{ saving ? 'Saving…' : (toggleTarget?.is_active ? 'Deactivate' : 'Reactivate') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <!-- ── Delete confirm (FA subclass) ────────────────────────────────────── -->
  <Dialog v-model:open="deleteOpen">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Delete Subclass Code</DialogTitle>
        <DialogDescription v-if="deleteTarget">
          Permanently delete <strong>{{ deleteTarget.fa_subclass_code }}</strong>? This cannot be undone.
        </DialogDescription>
      </DialogHeader>
      <DialogFooter>
        <Button variant="outline" @click="deleteOpen = false">Cancel</Button>
        <Button variant="destructive" :disabled="saving" @click="confirmDelete">
          {{ saving ? 'Deleting…' : 'Delete' }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
