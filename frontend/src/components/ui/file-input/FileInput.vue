<script setup lang="ts">
import { ref } from 'vue'

const props = defineProps<{
  multiple?: boolean
  accept?: string
}>()

const emit = defineEmits<{
  change: [files: File[]]
}>()

const input = ref<HTMLInputElement | null>(null)

function open() {
  input.value?.click()
}

function onChange(e: Event) {
  const el = e.target as HTMLInputElement
  if (!el.files) return
  emit('change', Array.from(el.files))
  el.value = ''
}

defineExpose({ open })
</script>

<template>
  <input
    ref="input"
    type="file"
    :multiple="props.multiple"
    :accept="props.accept"
    class="hidden-file-input"
    @change="onChange"
  />
</template>
