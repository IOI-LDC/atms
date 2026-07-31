<script setup lang="ts">
import PartIdentityBadges from '@/components/app/PartIdentityBadges.vue'
import type { PartIdentity } from '@/types'

/**
 * The single way a part is identified anywhere in the app.
 *
 * Name as normal text, then value-only badges for supplier Part Number, Size
 * and Maintenance Category — same rules as AssetIdentity: no concatenation, no
 * label prefixes, no badge when the value is missing.
 *
 * Out-of-stock reflects the ERP availability snapshot, not a live ATMS balance:
 * recording consumption never decrements it. The picker disables such an option
 * and the backend rejects it on submit.
 */
withDefaults(
  defineProps<{
    part: PartIdentity | null | undefined
    /** Stack the name above its badges, for narrow contexts like dropdown rows. */
    stacked?: boolean
    /** Show the out-of-stock badge when the ERP snapshot is zero. */
    showStock?: boolean
    fallback?: string
  }>(),
  {
    stacked: false,
    showStock: false,
    fallback: '—',
  },
)
</script>

<template>
  <span v-if="!part" class="identity-name">{{ fallback }}</span>
  <span v-else class="identity" :class="{ 'identity-stacked': stacked }">
    <span class="identity-name">{{ part.name }}</span>

    <PartIdentityBadges :part="part" :show-stock="showStock" />
  </span>
</template>
