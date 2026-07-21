<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import { CheckIcon, ChevronsUpDownIcon, XIcon } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { useAssetSearch } from '@/composables/useAssetSearch'
import type { Asset } from '@/types'

/**
 * Searchable asset picker (shadcn-vue Combobox pattern: Popover + search Input).
 * Refactored to use the trigger itself as the search input, eliminating the
 * duplicate/redundant input inside the popover dropdown.
 *
 * Selection shape: { id, label }.
 */
const props = defineProps<{
  inputId?: string
  disablePortal?: boolean
  /** Restrict results to a maintenance_status (`enrolled` | `withdrawn`). Omit = all. */
  maintenanceStatus?: string
}>()

const model = defineModel<{ id: number; label: string } | null>({ required: true })

const { query, results, busy, search, loadInitial, reset } = useAssetSearch({
  maintenanceStatus: props.maintenanceStatus,
})

const open = ref(false)
const listEl = ref<HTMLElement | null>(null)
const displayQuery = ref('')

// Sync selected model to displayed text in the input
watch(
  model,
  (val) => {
    if (val) {
      displayQuery.value = val.label
    } else {
      displayQuery.value = ''
    }
  },
  { immediate: true },
)

// On popover close, clear search states and restore display to active selection
watch(open, (value) => {
  if (!value) {
    reset()
    if (model.value) {
      displayQuery.value = model.value.label
    } else {
      displayQuery.value = ''
    }
  } else {
    loadInitial()
  }
})

function onFocus(event: FocusEvent) {
  // Select all text on focus to make overwriting/searching immediate and clean
  nextTick(() => {
    ;(event.target as HTMLInputElement).select()
  })
}

function onInput(event: Event) {
  const val = (event.target as HTMLInputElement).value
  displayQuery.value = val
  query.value = val
  if (model.value) {
    model.value = null // clear active selection to filter fresh
  }
  open.value = true
  search()
}

function clear() {
  model.value = null
  query.value = ''
  displayQuery.value = ''
  open.value = false
  reset()
}

function choose(asset: Asset) {
  model.value = { id: asset.id, label: `${asset.name} (${asset.erp_asset_code})` }
  open.value = false
}

function focusOptionAt(index: number) {
  const buttons = listEl.value?.querySelectorAll('button')
  if (buttons && buttons[index]) (buttons[index] as HTMLButtonElement).focus()
}

// Arrow-down from the input field moves focus to the first result option
function onSearchKeydown(event: KeyboardEvent) {
  if (event.key === 'ArrowDown') {
    event.preventDefault()
    open.value = true
    nextTick(() => {
      focusOptionAt(0)
    })
  }
}

// Roving focus keyboard navigation between options in the list
function onListKeydown(event: KeyboardEvent) {
  const list = listEl.value
  if (!list) return
  const buttons = Array.from(list.querySelectorAll('button')) as HTMLButtonElement[]
  if (buttons.length === 0) return
  const current = buttons.indexOf(event.target as HTMLButtonElement)
  if (current === -1) return
  if (event.key === 'ArrowDown') {
    event.preventDefault()
    buttons[Math.min(current + 1, buttons.length - 1)]?.focus()
  } else if (event.key === 'ArrowUp') {
    event.preventDefault()
    buttons[Math.max(current - 1, 0)]?.focus()
  } else if (event.key === 'Home') {
    event.preventDefault()
    buttons[0]?.focus()
  } else if (event.key === 'End') {
    event.preventDefault()
    buttons[buttons.length - 1]?.focus()
  }
}
</script>

<template>
  <Popover v-model:open="open">
    <PopoverTrigger as-child>
      <div class="combobox-container">
        <Input
          :id="inputId"
          v-model="displayQuery"
          placeholder="Select an asset…"
          autocomplete="off"
          class="combobox-input"
          @focus="onFocus"
          @input="onInput"
          @keydown="onSearchKeydown"
        />
        <div class="combobox-actions">
          <Button
            v-if="model"
            type="button"
            variant="ghost"
            size="icon-xs"
            aria-label="Clear selection"
            @click="clear"
          >
            <XIcon />
          </Button>
          <ChevronsUpDownIcon class="combobox-caret" />
        </div>
      </div>
    </PopoverTrigger>

    <PopoverContent
      class="asset-combobox-panel"
      align="start"
      :side-offset="4"
      :disable-portal="disablePortal"
    >
      <div
        ref="listEl"
        role="listbox"
        aria-label="Assets"
        class="asset-combobox-list"
        @keydown="onListKeydown"
      >
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
          {{ displayQuery.trim() ? 'No assets match your search.' : 'No assets available.' }}
        </div>
      </div>
    </PopoverContent>
  </Popover>
</template>
