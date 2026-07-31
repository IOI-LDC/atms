<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import AppLayout from '@/components/app/AppLayout.vue'
import AppDataTable from '@/components/app/AppDataTable.vue'
import IdentityFilters from '@/components/app/IdentityFilters.vue'
import PartIdentity from '@/components/app/PartIdentity.vue'
import { useParts } from '@/composables/useParts'
import { useIdentityFilters } from '@/composables/useIdentityFilters'
import { partColumns } from '@/lib/partColumns'
import { partStatusClass, partStatusLabel } from '@/lib/displayHelpers'
import type { Part } from '@/types'

const router = useRouter()

const { all } = useParts()

onMounted(() => {
  all.load()
})

// Part Number / Size / Maintenance Category are filtered from the toolbar via
// useIdentityFilters, not as column header filters — the identity renders as
// one package with no dedicated columns to hang a header filter on.

function goDetail(payload: { row: Part }) {
  router.push(`/parts/${payload.row.id}`)
}

// Serial / Size / Category filters live in the toolbar rather than as columns —
// the identity renders as one package and the values are not duplicated.
const identityFilters = useIdentityFilters(
  () => all.rows.value,
)
</script>

<template>
  <AppLayout>
    <div class="page-section">
      <div class="page-header">
        <div class="page-heading">
          <h1 class="page-title">Parts Reference</h1>
          <p class="page-subtitle">ERP-synced spare parts catalogue</p>
        </div>
      </div>

      <AppDataTable
        :rows="identityFilters.filteredRows.value"
        :columns="partColumns"
        empty-text="No parts found."
        label="Parts"
        :loading="all.loading.value"
        @row-click="goDetail"
      >
        <template #toolbar>
          <IdentityFilters
            v-model:part-number="identityFilters.partNumberQuery.value"
            v-model:size="identityFilters.sizeValue.value"
            v-model:category="identityFilters.categoryValue.value"
            :size-options="identityFilters.sizeOptions.value"
            :category-options="identityFilters.categoryOptions.value"
            :show-serial="false"
            :show-part-number="true"
          />
        </template>
        <template #cell="{ column, row }">
          <PartIdentity
            v-if="column.field === 'name'"
            :part="row"
            stacked
            show-stock
          />

          <span v-else-if="column.field === 'unit_of_measure'">
            {{ row.unit_of_measure ?? '—' }}
          </span>

          <span v-else-if="column.field === 'available_quantity'">
            <span v-if="row.available_quantity <= 0" class="status-badge status-inactive">Out of stock</span>
            <template v-else>{{ row.available_quantity }}</template>
          </span>

          <span v-else-if="column.field === 'is_active'" :class="partStatusClass(row.is_active)">
            {{ partStatusLabel(row.is_active) }}
          </span>
        </template>
      </AppDataTable>
    </div>
  </AppLayout>
</template>
