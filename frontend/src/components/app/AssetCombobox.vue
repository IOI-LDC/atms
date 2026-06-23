<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import { CheckIcon, ChevronsUpDownIcon, SearchIcon } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { useAssetSearch } from '@/composables/useAssetSearch'
import type { Asset } from '@/types'

/**
 * Searchable asset picker (shadcn-vue Combobox pattern: Popover + search Input
 * + option list). Debounced backend search lives in useAssetSearch; this
 * component only owns popover open-state and the v-model selection.
 *
 * Selection shape mirrors what the create-MR flow needs: { id, label }.
 */
const model = defineModel<{ id: number; label: string } | null>({ required: true })

const { query, results, busy, search, loadInitial, reset } = useAssetSearch()

const open     = ref(false)
const searchEl = ref<HTMLElement | null>(null)
const listEl   = ref<HTMLElement | null>(null)

// External clear (e.g. form reset) → wipe local search state.
watch(model, (value) => { if (!value) reset() })

// On open: preload a default page so the list isn't empty, then focus search.
// On close: clear search so reopening is fresh.
// (A ref on the <Input> component resolves to its instance, not the DOM node,
// so we focus the inner <input> via the wrapper.)
watch(open, (value) => {
  if (value) {
    loadInitial()
    nextTick(() => searchEl.value?.querySelector('input')?.focus())
  } else {
    reset()
  }
})

function choose(asset: Asset) {
  model.value = { id: asset.id, label: `${asset.name} (${asset.erp_asset_code})` }
  open.value = false
}

function focusOptionAt(index: number) {
  const buttons = listEl.value?.querySelectorAll('button')
  if (buttons && buttons[index]) (buttons[index] as HTMLButtonElement).focus()
}

// Arrow-down from the search box jumps to the first result.
function onSearchKeydown(event: KeyboardEvent) {
  if (event.key === 'ArrowDown') {
    event.preventDefault()
    focusOptionAt(0)
  }
}

// Arrow / Home / End roving between options (event target = focused option).
function onListKeydown(event: KeyboardEvent) {
  const list = listEl.value
  if (!list) return
  const buttons = Array.from(list.querySelectorAll('button')) as HTMLButtonElement[]
  if (buttons.length === 0) return
  const current = buttons.indexOf(event.target as HTMLButtonElement)
  if (current === -1) return
  if (event.key === 'ArrowDown') { event.preventDefault(); buttons[Math.min(current + 1, buttons.length - 1)]?.focus() }
  else if (event.key === 'ArrowUp') { event.preventDefault(); buttons[Math.max(current - 1, 0)]?.focus() }
  else if (event.key === 'Home') { event.preventDefault(); buttons[0]?.focus() }
  else if (event.key === 'End') { event.preventDefault(); buttons[buttons.length - 1]?.focus() }
}
</script>

<template>
  <Popover v-model:open="open">
    <PopoverTrigger as-child>
      <Button
        id="asset"
        variant="outline"
        role="combobox"
        aria-haspopup="listbox"
        :aria-expanded="open"
        class="asset-combobox-trigger"
      >
        <span :class="model ? 'asset-combobox-value' : 'asset-combobox-placeholder'">
          {{ model ? model.label : 'Select an asset…' }}
        </span>
        <ChevronsUpDownIcon class="asset-combobox-caret" />
      </Button>
    </PopoverTrigger>

    <PopoverContent class="asset-combobox-panel" align="start" :side-offset="4">
      <div ref="searchEl" class="asset-combobox-search">
        <SearchIcon class="asset-combobox-search-icon" />
        <Input
          v-model="query"
          placeholder="Search by name or ERP code…"
          autocomplete="off"
          class="asset-combobox-search-input"
          @input="search"
          @keydown="onSearchKeydown"
        />
      </div>

      <div ref="listEl" role="listbox" aria-label="Assets" class="asset-combobox-list" @keydown="onListKeydown">
        <div v-if="busy" class="asset-combobox-empty">Searching…</div>
        <template v-else-if="results.length > 0">
          <Button
            v-for="asset in results"
            :key="asset.id"
            variant="ghost"
            role="option"
            :aria-selected="model?.id === asset.id"
            class="asset-combobox-option"
            @click="choose(asset)"
          >
            <CheckIcon v-if="model?.id === asset.id" class="asset-combobox-check" />
            <span class="asset-combobox-option-name">{{ asset.name }}</span>
            <span class="asset-combobox-option-code">{{ asset.erp_asset_code }}</span>
          </Button>
        </template>
        <div v-else class="asset-combobox-empty">
          {{ query.trim() ? 'No assets match your search.' : 'No assets available.' }}
        </div>
      </div>
    </PopoverContent>
  </Popover>
</template>
