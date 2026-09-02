import { ref, computed } from 'vue'
import { toast } from 'vue-sonner'
import api, { ApiError } from '@/lib/api'

/**
 * RQ3 — the offline stock-reconciliation round trip.
 *
 * Download the parts list, VLOOKUP the ERP's quantities onto it in Excel, upload
 * the result. Administrator only; the backend gate is authoritative.
 *
 * The upload is **all-or-nothing**: a rejected file changes nothing and comes
 * back with line-numbered errors, so the operator's next move is always "fix the
 * spreadsheet and retry" rather than "work out which half landed".
 */
/**
 * @param onApplied Run after a successful upload. The caller owns the parts
 *   list, so reloading it is its job — but forgetting to call it leaves the
 *   table showing the quantities the upload just replaced, which reads as the
 *   upload having silently failed.
 */
export function usePartsCsvRoundTrip(onApplied?: () => void | Promise<void>) {
  const uploadOpen = ref(false)
  const uploadFile = ref<File | null>(null)
  const uploading = ref(false)
  const downloading = ref(false)

  /** Line-numbered rejection messages from the last attempt. */
  const uploadErrors = ref<string[]>([])
  /** Total rejections — may exceed `uploadErrors.length`, which the API caps at 40. */
  const uploadErrorCount = ref(0)

  const canSubmit = computed(() => uploadFile.value !== null && !uploading.value)
  const hiddenErrorCount = computed(() =>
    Math.max(0, uploadErrorCount.value - uploadErrors.value.length),
  )

  /** `FileInput` emits an array; this feature takes exactly one file. */
  function chooseFile(files: File[]) {
    uploadFile.value = files[0] ?? null
    // A newly chosen file makes the previous rejection list stale and confusing.
    uploadErrors.value = []
    uploadErrorCount.value = 0
  }

  function openUpload() {
    uploadFile.value = null
    uploadErrors.value = []
    uploadErrorCount.value = 0
    uploadOpen.value = true
  }

  /**
   * Goes through `api.download`, never a bare navigation.
   *
   * A `window.location.href = '/api/…'` resolves against the *SPA* origin. If
   * the SPA and API are ever split back onto separate hosts (see the
   * cross-origin case in `VITE_API_ORIGIN`'s docs), the SPA host has no `/api`
   * proxy and its catch-all returns `index.html` — the operator would get the
   * app's own HTML saved as a spreadsheet. `api.download` applies
   * `VITE_API_ORIGIN` and surfaces a failure as an ApiError instead of
   * replacing the page with it. Production is same-origin today, but this
   * stays defensive since nothing here should assume that won't change again.
   */
  async function downloadCsv(): Promise<void> {
    downloading.value = true
    try {
      await api.download('/parts/export-csv')
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to download the parts list.')
    } finally {
      downloading.value = false
    }
  }

  async function submitUpload(): Promise<void> {
    if (!uploadFile.value) return

    uploading.value = true
    uploadErrors.value = []
    uploadErrorCount.value = 0

    const form = new FormData()
    form.append('file', uploadFile.value)

    try {
      const res = await api.upload<{ data: { rows: number; updated: number; unchanged: number } }>(
        '/parts/import-quantities',
        form,
      )
      toast.success(
        `${res.data.updated} quantit${res.data.updated === 1 ? 'y' : 'ies'} updated, ` +
          `${res.data.unchanged} unchanged.`,
      )
      uploadOpen.value = false

      // Deliberately outside this try. The import has already committed; a
      // failed table reload is a stale view, not a failed upload, and reporting
      // it as one would send the operator back to re-upload a file that already
      // landed.
      try {
        await onApplied?.()
      } catch {
        toast.error(
          'Quantities were applied, but the table could not be refreshed. Reload the page.',
        )
      }
    } catch (e) {
      // Kept in the dialog rather than a toast: the list is long, numbered, and
      // the operator needs to read it against the file they still have open.
      // ApiError exposes the decoded payload as `data`. The rejection list is a
      // flat string array here, not the field-keyed map `validationErrors`
      // returns, so it is read directly.
      if (e instanceof ApiError && Array.isArray(e.data.errors)) {
        uploadErrors.value = e.data.errors as string[]
        uploadErrorCount.value =
          (e.data.error_count as number | undefined) ?? uploadErrors.value.length
      } else {
        toast.error(e instanceof ApiError ? e.message : 'Failed to upload the file.')
      }
    } finally {
      uploading.value = false
    }
  }

  return {
    uploadOpen,
    uploadFile,
    uploading,
    downloading,
    uploadErrors,
    uploadErrorCount,
    hiddenErrorCount,
    canSubmit,
    openUpload,
    chooseFile,
    downloadCsv,
    submitUpload,
  }
}
