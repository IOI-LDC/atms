<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { computed, ref } from 'vue'
import { parseDate, type DateValue } from '@internationalized/date'
import { CalendarIcon } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Calendar } from '@/components/ui/calendar'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { cn } from '@/lib/utils'

const props = withDefaults(
  defineProps<{
    /** Selected date as an ISO `YYYY-MM-DD` string (empty when unset). */
    modelValue?: string | null
    id?: string
    placeholder?: string
    /** Earliest selectable date (ISO `YYYY-MM-DD`). */
    min?: string | null
    /** Latest selectable date (ISO `YYYY-MM-DD`). */
    max?: string | null
    disabled?: boolean
    /** Show an inline "Clear" action inside the popover. */
    clearable?: boolean
    class?: HTMLAttributes['class']
  }>(),
  {
    modelValue: '',
    placeholder: 'Pick a date',
    min: null,
    max: null,
    disabled: false,
    clearable: true,
  },
)

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const open = ref(false)

function toDateValue(value?: string | null): DateValue | undefined {
  if (!value) {
    return undefined
  }
  try {
    return parseDate(value)
  } catch {
    return undefined
  }
}

const dateValue = computed<DateValue | undefined>(() => toDateValue(props.modelValue))
const minValue = computed<DateValue | undefined>(() => toDateValue(props.min))
const maxValue = computed<DateValue | undefined>(() => toDateValue(props.max))

// Show the canonical yyyy-MM-dd value directly — matches the app-wide date format.
const displayLabel = computed(() =>
  dateValue.value ? dateValue.value.toString() : props.placeholder,
)

function onSelect(value: DateValue | undefined) {
  emit('update:modelValue', value ? value.toString() : '')
  if (value) {
    open.value = false
  }
}

function clear() {
  emit('update:modelValue', '')
  open.value = false
}
</script>

<template>
  <Popover v-model:open="open">
    <PopoverTrigger as-child :disabled="disabled">
      <Button
        :id="id"
        variant="outline"
        :disabled="disabled"
        :data-empty="!dateValue"
        :class="
          cn(
            'w-full justify-start gap-2 font-normal data-[empty=true]:text-muted-foreground',
            props.class,
          )
        "
      >
        <CalendarIcon class="size-4 opacity-70" />
        <span>{{ displayLabel }}</span>
      </Button>
    </PopoverTrigger>
    <PopoverContent class="w-auto p-0" align="start">
      <Calendar
        :model-value="dateValue"
        :min-value="minValue"
        :max-value="maxValue"
        initial-focus
        @update:model-value="onSelect"
      />
      <div v-if="clearable && dateValue" class="flex justify-end border-t p-2">
        <Button variant="ghost" size="sm" @click="clear">Clear</Button>
      </div>
    </PopoverContent>
  </Popover>
</template>
