<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import AppLayout from '@/components/app/AppLayout.vue'
import AppDataTable from '@/components/app/AppDataTable.vue'
import { useParts } from '@/composables/useParts'
import { partColumns, toCategoryFilterOptions } from '@/lib/partColumns'
import { partStatusClass, partStatusLabel } from '@/lib/displayHelpers'
import type { Part } from '@/types'

const router = useRouter()

const { all } = useParts()

onMounted(() => {
  all.load()
})

// Category options are live data (not hardcoded) — derived from whatever the
// backend actually returns, so this stays correct once real ERP categories
// replace the seed set. Same pattern as fa_subclass_code in AssetsView.vue.
const filterOptions = computed(() => ({
  category: toCategoryFilterOptions(all.rows.value),
}))

function goDetail(payload: { row: Part }) {
  router.push(`/parts/${payload.row.id}`)
}
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
        :rows="all.rows.value"
        :columns="partColumns"
        :filter-options="filterOptions"
        empty-text="No parts found."
        label="Parts"
        :loading="all.loading.value"
        @row-click="goDetail"
      >
        <template #cell="{ column, row }">
          <span v-if="column.field === 'erp_part_code'" class="atms-erp-code">
            {{ row.erp_part_code }}
          </span>

          <span v-else-if="column.field === 'name'" class="table-cell-primary">
            {{ row.name }}
          </span>

          <span v-else-if="column.field === 'category'">
            {{ row.category ?? '—' }}
          </span>

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
