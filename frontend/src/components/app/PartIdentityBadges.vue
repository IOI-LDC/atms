<script setup lang="ts">
import type { PartIdentity } from '@/types'

/**
 * The badge run of a part identity, without the name.
 *
 * Counterpart to AssetIdentityBadges — used where the name is rendered
 * separately (a page heading, a link) so the badges can still follow it.
 * {@link PartIdentity} renders this alongside the name, keeping badge markup in
 * one place.
 */
withDefaults(
  defineProps<{
    part: PartIdentity | null | undefined
    /** Show the out-of-stock badge when the ERP snapshot is zero. */
    showStock?: boolean
  }>(),
  { showStock: false },
)
</script>

<template>
  <span
    v-if="
      part &&
      (part.part_number || part.size || part.maintenance_category || (showStock && part.available_quantity <= 0))
    "
    class="identity-badges"
  >
    <span v-if="part.part_number" class="identity-badge identity-badge-part-number">
      {{ part.part_number }}
    </span>
    <span v-if="part.size" class="identity-badge identity-badge-size">{{ part.size }}</span>
    <span v-if="part.maintenance_category" class="identity-badge identity-badge-category">
      {{ part.maintenance_category.name }}
    </span>
    <span
      v-if="showStock && part.available_quantity <= 0"
      class="identity-badge identity-badge-out-of-stock"
    >
      Out of stock
    </span>
  </span>
</template>
