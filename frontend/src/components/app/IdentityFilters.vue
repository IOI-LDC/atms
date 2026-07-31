<script setup lang="ts">
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import type { FilterOption } from '@/lib/dataTableSource'

/**
 * Serial Number / Part Number / Size / Category filter controls for a table
 * toolbar.
 *
 * These live in the toolbar rather than as column header filters because the
 * asset and part identities render as a single package — there is no dedicated
 * S/N or Size column to hang a header filter on, and duplicating the value into
 * one purely to filter it was explicitly rejected.
 *
 * Filtering itself belongs to `useIdentityFilters`; this is presentation only.
 * `__all__` is the sentinel for "no filter" — reka-ui rejects an empty-string
 * SelectItem value.
 */
withDefaults(
  defineProps<{
    sizeOptions: FilterOption[]
    categoryOptions: FilterOption[]
    /** Show the Serial Number box (assets). */
    showSerial?: boolean
    /** Show the Part Number box (parts). */
    showPartNumber?: boolean
  }>(),
  {
    showSerial: true,
    showPartNumber: false,
  },
)

const serial = defineModel<string>('serial', { default: '' })
const partNumber = defineModel<string>('partNumber', { default: '' })
const size = defineModel<string>('size', { default: '' })
const category = defineModel<string>('category', { default: '' })
</script>

<template>
  <div class="identity-filter-group">
    <Input
      v-if="showSerial"
      v-model="serial"
      class="identity-filter-text"
      placeholder="Serial number"
      aria-label="Filter by serial number"
      autocomplete="off"
    />

    <Input
      v-if="showPartNumber"
      v-model="partNumber"
      class="identity-filter-text"
      placeholder="Part number"
      aria-label="Filter by part number"
      autocomplete="off"
    />

    <Select
      :model-value="size || '__all__'"
      @update:model-value="(v) => (size = String(v) === '__all__' ? '' : String(v))"
    >
      <SelectTrigger class="identity-filter-select" aria-label="Filter by size">
        <SelectValue placeholder="All sizes" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="__all__">All sizes</SelectItem>
        <SelectItem v-for="opt in sizeOptions" :key="opt.value" :value="opt.value">
          {{ opt.label }}
        </SelectItem>
      </SelectContent>
    </Select>

    <Select
      :model-value="category || '__all__'"
      @update:model-value="(v) => (category = String(v) === '__all__' ? '' : String(v))"
    >
      <SelectTrigger class="identity-filter-select" aria-label="Filter by maintenance category">
        <SelectValue placeholder="All categories" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="__all__">All categories</SelectItem>
        <SelectItem v-for="opt in categoryOptions" :key="opt.value" :value="opt.value">
          {{ opt.label }}
        </SelectItem>
      </SelectContent>
    </Select>
  </div>
</template>
