<script setup lang="ts">
import { nextTick, ref, toRef, watch } from 'vue'
import { ChevronsUpDownIcon, XIcon, MapPin } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { useAssetLocator } from '@/composables/useAssetLocator'
import { assetKindLabel } from '@/lib/displayHelpers'
import type { Asset } from '@/types'

/**
 * Asset picker for the Logistics "Find & Move" view. Same shadcn-vue Combobox
 * pattern as the New MR AssetCombobox (Popover + search Input as trigger), but
 * scoped to a location and with richer rows (tag · serial · kind · location).
 * Emits the full selected Asset; the caller decides what to do with it.
 */
const props = defineProps<{
  locationId: number | null
  inputId?: string
}>()

const emit = defineEmits<{ select: [Asset] }>()

const { query, results, busy, search, loadInitial, reset } = useAssetLocator(toRef(props, 'locationId'))

const open = ref(false)
const displayQuery = ref('')
const listEl = ref<HTMLElement | null>(null)

watch(open, (value) => {
  if (!value) {
    reset()
    displayQuery.value = ''
  } else {
    loadInitial()
  }
})

// Re-scope the visible results if the location changes while the popover is open.
watch(
  () => props.locationId,
  () => {
    if (open.value) loadInitial()
  },
)

function onFocus(event: FocusEvent) {
  nextTick(() => {
    ;(event.target as HTMLInputElement).select()
  })
}

function onInput(event: Event) {
  const val = (event.target as HTMLInputElement).value
  displayQuery.value = val
  query.value = val
  open.value = true
  search()
}

function clearInput() {
  query.value = ''
  displayQuery.value = ''
  reset()
  open.value = false
}

function choose(asset: Asset) {
  emit('select', asset)
  // Reset the field so the user can look up another asset immediately.
  query.value = ''
  displayQuery.value = ''
  open.value = false
  reset()
}

function focusOptionAt(index: number) {
  const buttons = listEl.value?.querySelectorAll('button')
  if (buttons && buttons[index]) (buttons[index] as HTMLButtonElement).focus()
}

function onSearchKeydown(event: KeyboardEvent) {
  if (event.key === 'ArrowDown') {
    event.preventDefault()
    open.value = true
    nextTick(() => focusOptionAt(0))
  }
}

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
          placeholder="Search by asset name or code…"
          autocomplete="off"
          class="combobox-input"
          @focus="onFocus"
          @input="onInput"
          @keydown="onSearchKeydown"
        />
        <div class="combobox-actions">
          <Button
            v-if="displayQuery"
            type="button"
            variant="ghost"
            size="icon-xs"
            aria-label="Clear search"
            @click="clearInput"
          >
            <XIcon />
          </Button>
          <ChevronsUpDownIcon class="combobox-caret" />
        </div>
      </div>
    </PopoverTrigger>

    <PopoverContent class="asset-combobox-panel" align="start" :side-offset="4">
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
            class="asset-locator-option"
            @click="choose(asset)"
          >
            <span class="asset-locator-name">{{ asset.name }}</span>
            <span class="asset-locator-meta">
              <span class="asset-locator-tag">{{ asset.asset_tag ?? asset.erp_asset_code }}</span>
              <span v-if="asset.serial_number" class="asset-locator-sn"
                >SN {{ asset.serial_number }}</span
              >
              <span v-if="asset.asset_kind" class="asset-locator-kind">{{
                assetKindLabel(asset.asset_kind)
              }}</span>
            </span>
            <span class="asset-locator-loc">
              <MapPin class="asset-locator-loc-icon" aria-hidden="true" />
              {{ asset.current_location?.name ?? 'No location' }}
            </span>
          </Button>
        </template>
        <div v-else class="asset-combobox-empty">
          {{ displayQuery.trim() ? 'No assets match your search.' : 'No assets at this location.' }}
        </div>
      </div>
    </PopoverContent>
  </Popover>
</template>
