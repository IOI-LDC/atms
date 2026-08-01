<script setup lang="ts">
import { computed, ref } from 'vue'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import type { MaintenanceCategoryOption } from '@/types'

/**
 * Searchable multi-select over the Maintenance Category vocabulary.
 *
 * Shared by the two screens that route behaviour by category — WO Form
 * templates and PM rules — because the list runs to a couple of dozen entries
 * and a plain checkbox column becomes unusable at that length.
 *
 * `claimedBy` marks categories another record already holds. They are shown
 * disabled with the holder's name rather than hidden: "taken by X" tells the
 * user what to do next, a missing row does not.
 */
const props = withDefaults(
  defineProps<{
    categories: MaintenanceCategoryOption[]
    idPrefix: string
    claimedBy?: Map<number, string>
  }>(),
  { claimedBy: undefined },
)

const selected = defineModel<number[]>({ required: true })

const search = ref('')

/**
 * Matches for the current search, **selected first**.
 *
 * The vocabulary runs to a couple of dozen entries in a fixed-height box, so an
 * alphabetical list buries what this record already covers below the fold — you
 * open a form and cannot see its own categories without scrolling. Pinning them
 * to the top makes the current selection the first thing read.
 *
 * `sort` is stable, so ties keep the incoming alphabetical order.
 */
const visible = computed(() => {
  const term = search.value.trim().toLowerCase()
  const matches = term
    ? props.categories.filter((c) => c.name.toLowerCase().includes(term))
    : props.categories

  return [...matches].sort((a, b) => Number(isSelected(b.id)) - Number(isSelected(a.id)))
})

function isSelected(id: number): boolean {
  return selected.value.includes(id)
}

function isBlocked(id: number): boolean {
  return (props.claimedBy?.has(id) ?? false) && !isSelected(id)
}

function toggle(id: number, checked: boolean | 'indeterminate') {
  selected.value =
    checked === true
      ? [...selected.value, id]
      : selected.value.filter((existing) => existing !== id)
}
</script>

<template>
  <Input :id="`${idPrefix}-search`" v-model="search" placeholder="Search categories…" />

  <div class="category-picker">
    <p v-if="categories.length === 0" class="category-picker-empty">
      No maintenance categories exist yet.
    </p>
    <p v-else-if="visible.length === 0" class="category-picker-empty">
      No categories match that search.
    </p>
    <div
      v-for="category in visible"
      :key="category.id"
      class="category-option"
      :class="{ 'category-option-selected': isSelected(category.id) }"
    >
      <Checkbox
        :id="`${idPrefix}-${category.id}`"
        :model-value="isSelected(category.id)"
        :disabled="isBlocked(category.id)"
        @update:model-value="(v) => toggle(category.id, v)"
      />
      <Label :for="`${idPrefix}-${category.id}`" class="category-option-name">
        {{ category.name }}
      </Label>
      <span v-if="claimedBy?.has(category.id)" class="category-option-claim">
        {{ claimedBy.get(category.id) }}
      </span>
    </div>
  </div>
</template>
