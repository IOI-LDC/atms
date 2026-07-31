<script setup lang="ts">
import { useRouter, type RouteLocationRaw } from 'vue-router'
import { RadarIcon, ArrowLeftIcon } from '@lucide/vue'
import { Button } from '@/components/ui/button'

defineProps<{
  /** Human label for the missing record, e.g. "Work order". */
  entityLabel: string
  /** Identifier the user was looking for (echoed from the URL), shown as a chip. */
  identifier?: string | number
  /** Label for the primary "browse all" button. */
  backLabel: string
  /** Route the primary button navigates to (the "search all" list). */
  backTo: RouteLocationRaw
}>()

const router = useRouter()
</script>

<template>
  <section class="detail-notfound" aria-live="polite">
    <div class="detail-notfound-radar" aria-hidden="true">
      <span class="detail-notfound-pulse"></span>
      <span class="detail-notfound-pulse"></span>
      <span class="detail-notfound-pulse"></span>
      <span class="detail-notfound-sweep"></span>
      <span class="detail-notfound-core">
        <RadarIcon class="detail-notfound-icon" />
      </span>
    </div>

    <p v-if="identifier != null && identifier !== ''" class="detail-notfound-code">
      #{{ identifier }}
    </p>

    <h1 class="detail-notfound-title">{{ entityLabel }} not found</h1>
    <p class="detail-notfound-description">
      We scanned the register but couldn't pick up this {{ entityLabel.toLowerCase() }} on the
      grid. It may have been removed, closed out, or the link is simply out of date.
    </p>

    <div class="detail-notfound-actions">
      <Button @click="router.push(backTo)">{{ backLabel }}</Button>
      <Button variant="ghost" @click="router.push('/dashboard')">
        <ArrowLeftIcon class="icon-sm" />
        Back to dashboard
      </Button>
    </div>
  </section>
</template>
