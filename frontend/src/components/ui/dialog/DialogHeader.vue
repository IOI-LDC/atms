<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes['class']
}>()
</script>

<template>
  <!--
    Full-bleed band: the negative x/top margins cancel DialogContent's p-6 so the
    divider spans the full panel width (same trick as DialogFooter). pe-12 keeps
    the title clear of the absolutely-positioned close button.
  -->
  <div
    data-slot="dialog-header"
    :class="cn('-mx-6 -mt-6 flex flex-col gap-1.5 border-b ps-6 pe-12 pt-5 pb-4', props.class)"
  >
    <slot />
  </div>
</template>
