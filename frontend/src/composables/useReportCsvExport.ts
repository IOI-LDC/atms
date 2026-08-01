import { ref } from 'vue'
import api from '@/lib/api'

/**
 * Downloads a report as CSV.
 *
 * Every report exports through its own endpoint with `?format=csv`, so the file
 * is produced by the same query, filters and authorization as the table on
 * screen — there is no second code path that could disagree with it. Callers
 * pass the filters they already built for the on-screen load, which is what
 * keeps the two in step.
 *
 * Only the reports whose endpoint implements `format=csv` should surface an
 * Export control; the rest would download a JSON body under a .csv name.
 */
export function useReportCsvExport() {
  const exporting = ref(false)
  const exportError = ref<string | null>(null)

  async function exportCsv(path: string, filters: Record<string, unknown> = {}): Promise<void> {
    exporting.value = true
    exportError.value = null
    try {
      await api.download(path, { ...filters, format: 'csv' })
    } catch {
      exportError.value = 'Failed to export. Please try again.'
    } finally {
      exporting.value = false
    }
  }

  return { exporting, exportError, exportCsv }
}
