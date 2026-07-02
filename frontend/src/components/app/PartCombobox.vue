<script setup lang="ts">
import { onMounted } from 'vue'
import { SearchIcon } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { usePartSearch } from '@/composables/usePartSearch'
import type { Part } from '@/types'

/**
 * Inline part picker: search Input + bounded scrollable result list, directly
 * in the form (no Popover/trigger). This lives inside the WO "Add part"
 * Dialog — a small single-purpose picker where the search box should just be
 * visible immediately, not hidden behind a click-to-open trigger. A Popover
 * here would also nest one Reka UI overlay inside another (Dialog), which
 * breaks focus/keyboard handling — same class of issue documented on the
 * `:modal="false"` Sheets elsewhere in this codebase.
 *
 * Once a part is chosen, the search view swaps for a compact summary with a
 * "Change" button. Selection shape: { id, label }.
 */
defineProps<{ inputId?: string }>()
const model = defineModel<{ id: number; label: string } | null>({ required: true })

const { query, results, busy, search, loadInitial } = usePartSearch()

// The Dialog unmounts this component on close (Reka UI default), so a fresh
// mount always means a fresh open — no need to reset on external state changes.
onMounted(() => loadInitial())

function choose(part: Part) {
  model.value = { id: part.id, label: `${part.name} (${part.erp_part_code})` }
}

function changeSelection() {
  model.value = null
  loadInitial()
}
</script>

<template>
  <div class="part-picker">
    <div v-if="model" class="part-picker-selected">
      <span class="part-picker-selected-name">{{ model.label }}</span>
      <Button type="button" variant="ghost" size="sm" @click="changeSelection">Change</Button>
    </div>

    <template v-else>
      <div class="part-picker-search">
        <SearchIcon class="part-picker-search-icon" />
        <Input
          :id="inputId"
          v-model="query"
          placeholder="Search by name or ERP code…"
          autocomplete="off"
          class="part-picker-search-input"
          @input="search"
        />
      </div>

      <div role="listbox" aria-label="Parts" class="part-picker-list">
        <div v-if="busy" class="part-picker-empty">Searching…</div>
        <template v-else-if="results.length > 0">
          <Button
            v-for="part in results"
            :key="part.id"
            type="button"
            variant="ghost"
            role="option"
            class="part-picker-option"
            @click="choose(part)"
          >
            <span class="part-picker-option-name">{{ part.name }}</span>
            <span class="part-picker-option-code">{{ part.erp_part_code }}</span>
          </Button>
        </template>
        <div v-else class="part-picker-empty">
          {{ query.trim() ? 'No parts match your search.' : 'No parts available.' }}
        </div>
      </div>
    </template>
  </div>
</template>
