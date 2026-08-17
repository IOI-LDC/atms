<script setup lang="ts">
import type { PartIdentity } from '@/types'

/**
 * The badge run of a part identity, without the name.
 *
 * Counterpart to AssetIdentityBadges — used where the name is rendered
 * separately (a page heading, a link) so the badges can still follow it.
 * {@link PartIdentity} renders this alongside the name, keeping badge markup in
 * one place.
 *
 * Badge order is deliberate: ERP part code first (the "No." LDC quotes), then
 * the supplier part number. Two codes side by side are easy to confuse, so the
 * one people actually use leads.
 */
withDefaults(
  defineProps<{
    part: PartIdentity | null | undefined
    /** Show the out-of-stock badge when the available balance is zero. */
    showStock?: boolean
  }>(),
  { showStock: false },
)
</script>

<template>
  <span
    v-if="
      part &&
      (part.erp_part_code ||
        part.size ||
        part.maintenance_category ||
        (showStock && part.available_quantity <= 0))
    "
    class="identity-badges"
  >
    <!-- The Part Number. Reads first because it is what people look a part up
         by. `part_number` used to sit beside it holding a supplier's code on 3
         of 734 parts; it was retired 2026-08-17 as a source of confusion. -->
    <span v-if="part.erp_part_code" class="identity-badge identity-badge-part-code">
      {{ part.erp_part_code }}
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
