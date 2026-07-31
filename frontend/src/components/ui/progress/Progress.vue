<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{ value: number; variant?: 'default' | 'soon' | 'due' }>(), {
  variant: 'default',
})

const width = computed(() => `${Math.min(100, Math.max(0, props.value))}%`)
const fillColor = computed(() =>
  props.variant === 'due'
    ? 'bg-destructive'
    : props.variant === 'soon'
      ? 'bg-warning'
      : 'bg-primary',
)
</script>

<template>
  <div
    class="relative h-2 w-full overflow-hidden rounded-full bg-muted"
    role="progressbar"
    :aria-valuenow="Math.round(value)"
    aria-valuemin="0"
    aria-valuemax="100"
  >
    <div class="h-full rounded-full transition-all" :class="fillColor" :style="{ width }" />
  </div>
</template>
