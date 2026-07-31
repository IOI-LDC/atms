<script setup lang="ts">
import { Button } from '@/components/ui/button'

/**
 * "Load more" footer for the cursor-paginated reports (R-8, R-14). Mirrors the
 * audit-log list pattern: one page held at a time, appended on demand. Renders
 * nothing when there is no next page.
 */
defineProps<{
  hasMore: boolean
  loading: boolean
}>()

defineEmits<{ more: [] }>()
</script>

<template>
  <div v-if="hasMore" class="report-load-more">
    <Button variant="outline" :disabled="loading" @click="$emit('more')">
      {{ loading ? 'Loading…' : 'Load more' }}
    </Button>
  </div>
</template>
