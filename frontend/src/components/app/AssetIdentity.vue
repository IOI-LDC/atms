<script setup lang="ts">
import AssetIdentityBadges from '@/components/app/AssetIdentityBadges.vue'
import type { AssetIdentity } from '@/types'

/**
 * The single way an asset is identified anywhere in the app.
 *
 * Renders the name as normal text followed by value-only badges for Serial
 * Number, Size and Maintenance Category. The values are never concatenated into
 * one string, badges carry no "SN:" / "Size:" / "Category:" prefix, and a
 * missing value produces no badge rather than an empty placeholder.
 *
 * Asset Tag is deliberately not a badge — pass `showTag` to render it as
 * secondary text. It never falls back to an ERP code, which the API no longer
 * sends to ordinary users.
 */
withDefaults(
  defineProps<{
    asset: AssetIdentity | null | undefined
    /** Stack the name above its badges, for narrow contexts like cards. */
    stacked?: boolean
    /** Render Asset Tag as secondary text after the badges. */
    showTag?: boolean
    /** Text shown when there is no asset at all. */
    fallback?: string
  }>(),
  {
    stacked: false,
    showTag: false,
    fallback: '—',
  },
)
</script>

<template>
  <span v-if="!asset" class="identity-name">{{ fallback }}</span>
  <span v-else class="identity" :class="{ 'identity-stacked': stacked }">
    <span class="identity-name">{{ asset.name }}</span>

    <AssetIdentityBadges :asset="asset" :show-tag="showTag" />
  </span>
</template>
