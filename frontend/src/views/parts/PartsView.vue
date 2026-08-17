<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppLayout from '@/components/app/AppLayout.vue'
import AppDataTable from '@/components/app/AppDataTable.vue'
import IdentityFilters from '@/components/app/IdentityFilters.vue'
import PartIdentity from '@/components/app/PartIdentity.vue'
import { useParts } from '@/composables/useParts'
import { usePartsCsvRoundTrip } from '@/composables/usePartsCsvRoundTrip'
import { useAuthStore } from '@/stores/auth.store'
import { Button } from '@/components/ui/button'
import { DownloadIcon, UploadIcon } from '@lucide/vue'
import { FileInput } from '@/components/ui/file-input'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useIdentityFilters } from '@/composables/useIdentityFilters'
import { partColumns } from '@/lib/partColumns'
import { partStatusClass, partStatusLabel } from '@/lib/displayHelpers'
import type { Part } from '@/types'

const router = useRouter()

const { all } = useParts()
const auth = useAuthStore()
const fileInputRef = ref<InstanceType<typeof FileInput> | null>(null)

const {
  uploadOpen,
  uploadFile,
  uploading,
  uploadErrors,
  hiddenErrorCount,
  canSubmit,
  openUpload,
  chooseFile,
  downloadCsv,
  submitUpload,
} = usePartsCsvRoundTrip()

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
const identityFilters = useIdentityFilters(() => all.rows.value)
</script>

<template>
  <AppLayout>
    <div class="page-section">
      <div class="page-header">
        <div class="page-heading">
          <h1 class="page-title">Parts Reference</h1>
          <p class="page-subtitle">ERP-synced spare parts catalogue</p>
        </div>
        <!-- Page-level actions, not table filters. They were briefly in the
             table toolbar and got lost among four filter controls — a stock
             reconciliation is something you come to this page to do, so it
             belongs beside the title. -->
        <div v-if="auth.isAdmin" class="page-actions">
          <Button variant="outline" @click="downloadCsv">
            <DownloadIcon class="button-icon" />
            Download CSV
          </Button>
          <Button @click="openUpload">
            <UploadIcon class="button-icon" />
            Upload quantities
          </Button>
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
          <!-- Rendered explicitly: AppDataTable's #cell slot replaces the default
               output entirely, so a column with no branch here shows blank. -->
          <span v-if="column.field === 'erp_part_code'" class="identity-mono">
            {{ row.erp_part_code ?? '—' }}
          </span>

          <PartIdentity v-else-if="column.field === 'name'" :part="row" stacked show-stock />

          <span v-else-if="column.field === 'unit_of_measure'">
            {{ row.unit_of_measure ?? '—' }}
          </span>

          <span v-else-if="column.field === 'available_quantity'">
            <span v-if="row.available_quantity <= 0" class="status-badge status-inactive"
              >Out of stock</span
            >
            <template v-else>{{ row.available_quantity }}</template>
          </span>

          <span v-else-if="column.field === 'is_active'" :class="partStatusClass(row.is_active)">
            {{ partStatusLabel(row.is_active) }}
          </span>
        </template>
      </AppDataTable>
    </div>

    <!-- RQ3 stock correction. The confirmation text is the point: an operator
         who does not know the next ERP sync overwrites this will not understand
         why their numbers moved back. -->
    <Dialog v-model:open="uploadOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Upload corrected quantities</DialogTitle>
          <DialogDescription>
            This replaces the available quantity for every part listed in the file. The next ERP
            sync will overwrite these quantities again — this corrects the figure in the meantime.
          </DialogDescription>
        </DialogHeader>

        <div class="form-field">
          <!-- FileInput is a hidden <input type="file"> that exposes open() and
               emits `change`; it renders nothing on its own, so the visible
               trigger is this Button. Same pattern as the WO attachment dialog. -->
          <div class="file-choose-row">
            <Button variant="outline" :disabled="uploading" @click="fileInputRef?.open()">
              <UploadIcon class="button-icon" />
              Choose file
            </Button>
            <span v-if="uploadFile" class="file-choose-name">{{ uploadFile.name }}</span>
            <span v-else class="file-choose-empty">No file chosen</span>
          </div>
          <FileInput ref="fileInputRef" accept=".csv,text/csv" @change="chooseFile" />
          <p class="form-help">
            Use the downloaded CSV. Rows are matched on the <strong>part_id</strong> column, with
            Part Number checked against it — if either column is edited or re-sorted apart from the
            other, the file is rejected rather than applied to the wrong parts.
          </p>
        </div>

        <div v-if="uploadErrors.length > 0" class="upload-errors" role="alert">
          <p class="upload-errors-title">
            The file was rejected and nothing was changed. Fix these and upload again:
          </p>
          <ul>
            <li v-for="error in uploadErrors" :key="error">{{ error }}</li>
          </ul>
          <p v-if="hiddenErrorCount > 0" class="upload-errors-more">
            … and {{ hiddenErrorCount }} more.
          </p>
        </div>

        <DialogFooter>
          <Button variant="outline" :disabled="uploading" @click="uploadOpen = false">Back</Button>
          <Button :disabled="!canSubmit" @click="submitUpload">
            {{ uploading ? 'Applying…' : 'Apply quantities' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </AppLayout>
</template>
