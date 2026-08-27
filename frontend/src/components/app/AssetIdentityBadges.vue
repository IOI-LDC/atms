<script setup lang="ts">
import type { AssetIdentity } from '@/types'

/**
 * The badge run of an asset identity, without the name.
 *
 * Used where the name is rendered separately — typically wrapped in a
 * RouterLink — so the badges can still follow it. {@link AssetIdentity} renders
 * this alongside the name, keeping badge markup defined in exactly one place.
 *
 * Value-only, no label prefixes, and a missing value produces no badge.
 */
withDefaults(
  defineProps<{
    asset: AssetIdentity | null | undefined
    /** Render Asset Tag as secondary text after the badges. */
    showTag?: boolean
  }>(),
  { showTag: false },
)
</script>

<template>
  <span
    v-if="
      asset &&
      (asset.serial_number ||
        asset.size ||
        asset.maintenance_category ||
        (showTag && asset.asset_tag))
    "
    class="identity-badges"
  >
    <span v-if="asset.serial_number" class="identity-badge identity-badge-serial">
      {{ asset.serial_number }}
    </span>
    <span v-if="asset.size" class="identity-badge identity-badge-size">{{ asset.size }}</span>
    <span v-if="asset.maintenance_category" class="identity-badge identity-badge-category">
      {{ asset.maintenance_category.name }}
    </span>
    <span v-if="showTag && asset.asset_tag" class="identity-tag">{{ asset.asset_tag }}</span>
    <slot />
  </span>
</template>
