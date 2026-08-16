<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeftIcon, PrinterIcon } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { useWorkOrderDetail } from '@/composables/useWorkOrderDetail'
import { useAuthStore } from '@/stores/auth.store'
import { fmtDate } from '@/lib/displayHelpers'

/**
 * Printable Part Request — the temporary paper process the warehouse works from
 * until Phase 3 Store Management provides formal issuance tracking.
 *
 * Dedicated print markup rather than the AssetIdentity/PartIdentity components:
 * it consumes the same identity data contract, but a bordered A4 form needs
 * table cells with fixed widths, not inline badge runs.
 *
 * Structure: the branding and asset bands are plain blocks, only the line-item
 * column headers live in `<thead>`, and the approval boxes follow the table so
 * they land on the final page. No page-counting JavaScript.
 *
 * The bands were originally inside a colspan `<th>` in a `table-header-group`
 * thead so they would repeat per page. That made browsers reserve the band's
 * full height on every potential page and emit a blank second page, so on a
 * genuine overflow page the column headers now repeat but the asset band does
 * not — an acceptable trade at ~25 lines per page.
 *
 * `erp_part_code` is printed on the part lines — the warehouse looks items up by
 * it. This was once an exception to a blanket "ERP codes stay out of printed
 * forms" rule; RQ4 retired that rule for the part code specifically, since it is
 * the identifier LDC's team actually quotes. `erp_asset_code` still appears
 * nowhere: it is an internal ERP key, which the part code is not.
 */
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const id = computed(() => Number(route.params.workOrderId))
const { record, loading, error, notFound, forbidden, parts, load } = useWorkOrderDetail()

// Captured once on mount, not recomputed — the printed sheet should carry the
// moment it was produced, even if the tab is left open.
const requestDate = ref(fmtDate(new Date().toISOString()))

/** Department and Reason are fixed constants on this form, not WO data. */
const DEPARTMENT = 'Maintenance'
const REASON = 'Maintenance'

watch(
  id,
  (newId) => {
    if (newId) load(newId)
  },
  { immediate: true },
)

onMounted(() => {
  document.title = `Part Request ${route.params.workOrderId}`
})

function goBack() {
  router.back()
}

/** `window` is not exposed to template scope, so the trigger lives here. */
function doPrint() {
  window.print()
}
</script>

<template>
  <div class="print-page">
    <!-- Screen-only toolbar; hidden by @media print -->
    <div class="print-toolbar">
      <Button variant="ghost" size="sm" @click="goBack">
        <ArrowLeftIcon class="detail-back-icon" />
        Back
      </Button>
      <Button size="sm" :disabled="!record" @click="doPrint">
        <PrinterIcon class="detail-back-icon" />
        Print
      </Button>
    </div>

    <div v-if="loading" class="loading-state">Loading work order…</div>
    <div v-else-if="notFound" class="error-state" role="alert">Work order not found.</div>
    <div v-else-if="forbidden" class="permission-state">
      You don't have permission to view this work order.
    </div>
    <div v-else-if="error" class="error-state" role="alert">{{ error }}</div>

    <template v-else-if="record">
      <!-- Branding band. Kept OUTSIDE the table on purpose: nesting it in a
           colspan <th> inside a `table-header-group` thead made browsers reserve
           the whole band's height on every potential page, which produced a
           spurious blank second page. -->
      <div class="pr-band">
        <div class="pr-brand">
          <img src="@/assets/logo.svg" alt="LDC" class="pr-logo" />
        </div>
        <div class="pr-title">PART REQUEST</div>
        <table class="pr-ref">
          <tbody>
            <tr>
              <th>WO NO.</th>
              <td>{{ record.number }}</td>
            </tr>
            <tr>
              <th>MR NO.</th>
              <td>{{ record.maintenance_request?.number ?? '—' }}</td>
            </tr>
            <tr>
              <th>DATE</th>
              <td>{{ requestDate }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Asset identity + fixed fields -->
      <table class="pr-meta">
        <tbody>
          <tr>
            <th>ASSET</th>
            <td colspan="3">{{ record.asset?.name ?? '—' }}</td>
          </tr>
          <tr>
            <th>ASSET TAG</th>
            <td>{{ record.asset?.asset_tag ?? '—' }}</td>
            <th>SERIAL NO.</th>
            <td>{{ record.asset?.serial_number ?? '—' }}</td>
          </tr>
          <tr>
            <th>SIZE</th>
            <td>{{ record.asset?.size ?? '—' }}</td>
            <th>CATEGORY</th>
            <td>{{ record.asset?.maintenance_category?.name ?? '—' }}</td>
          </tr>
          <tr>
            <th>DEPARTMENT</th>
            <td>{{ DEPARTMENT }}</td>
            <th>REASON</th>
            <td>{{ REASON }}</td>
          </tr>
        </tbody>
      </table>

      <table class="pr-doc">
        <!-- Column widths MUST live here: `table-layout: fixed` derives them
             from the first row, so widths on the header cells are ignored. -->
        <colgroup>
          <col class="pr-w-item" />
          <col class="pr-w-desc" />
          <col class="pr-w-size" />
          <col class="pr-w-qty" />
          <col class="pr-w-pn" />
          <col class="pr-w-erp" />
        </colgroup>
        <thead>
          <!-- Only the column headers live here, so they repeat on a genuine
               overflow page without dragging the branding band's height along. -->
          <tr class="pr-cols">
            <th class="pr-col-item">#</th>
            <th class="pr-col-desc">PART DESCRIPTION</th>
            <th class="pr-col-size">SIZE</th>
            <th class="pr-col-qty">QTY</th>
            <th class="pr-col-pn">SPN</th>
            <th class="pr-col-erp">CODE</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="(line, index) in parts" :key="line.id" class="pr-row">
            <td class="pr-center">{{ index + 1 }}</td>
            <td>
              {{ line.part.name }}
              <span v-if="line.notes" class="pr-note">— {{ line.notes }}</span>
            </td>
            <td class="pr-center">{{ line.part.size ?? '—' }}</td>
            <td class="pr-center">{{ line.quantity }}</td>
            <td class="pr-center">{{ line.part.part_number ?? '—' }}</td>
            <td class="pr-center">{{ line.part.erp_part_code ?? '—' }}</td>
          </tr>

          <!-- One ruled blank for a handwritten addition. Unnumbered, because a
               number would imply a line that was requested and then left empty. -->
          <tr class="pr-row pr-row-blank">
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
          </tr>
        </tbody>
      </table>

      <!-- Five approval boxes. Requestor and its date are filled from the user
           producing the printout; the rest are signed by hand. -->
      <div class="pr-approvals">
        <div class="pr-approval">
          <div class="pr-approval-title">REQUESTOR</div>
          <div class="pr-approval-field">
            <span class="pr-approval-label">NAME</span>
            <span class="pr-approval-value">{{ auth.user?.name ?? '' }}</span>
          </div>
          <div class="pr-approval-field">
            <span class="pr-approval-label">DATE</span>
            <span class="pr-approval-value">{{ requestDate }}</span>
          </div>
          <div class="pr-approval-sign">SIGNATURE</div>
        </div>

        <div v-for="box in ['REVIEWER', 'APPROVER', 'ISSUED BY — WAREHOUSE', 'RECEIVED BY']" :key="box" class="pr-approval">
          <div class="pr-approval-title">{{ box }}</div>
          <div class="pr-approval-field">
            <span class="pr-approval-label">NAME</span>
            <span class="pr-approval-value"></span>
          </div>
          <div class="pr-approval-field">
            <span class="pr-approval-label">DATE</span>
            <span class="pr-approval-value"></span>
          </div>
          <div class="pr-approval-sign">SIGNATURE</div>
        </div>
      </div>
    </template>
  </div>
</template>
