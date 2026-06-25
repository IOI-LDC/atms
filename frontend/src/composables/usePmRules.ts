import { ref } from 'vue'
import api, { ApiError } from '@/lib/api'
import { fetchList } from '@/lib/dataTableSource'
import type { PmRule, UsageReadingType, MaintenanceRequest } from '@/types'

export interface PmRulePayload {
  asset_id?: number
  name?: string
  description?: string | null
  trigger_type?: string
  interval_days?: number | null
  interval_reading?: number | null
  usage_reading_type_id?: number | null
  maintenance_level?: string | null
}

export interface ActionResult {
  ok: boolean
  message?: string
  /** For evaluate: the generated MR, when one was created. */
  data?: MaintenanceRequest
}

export function usePmRules() {
  // ── List ────────────────────────────────────────────────────────────────────
  const rules = ref<PmRule[]>([])
  const rulesLoading = ref(false)
  const rulesError = ref<string | null>(null)

  async function loadRules(force = false) {
    if (rules.value.length > 0 && !force) return
    rulesLoading.value = true
    rulesError.value = null
    try {
      rules.value = await fetchList<PmRule>('/pm-rules', { sort: 'created_at:desc' })
    } catch {
      rules.value = []
      rulesError.value = 'Failed to load PM rules.'
    } finally {
      rulesLoading.value = false
    }
  }

  // ── Reading types (for the create form — Admin-only endpoint) ────────────────
  const readingTypes = ref<UsageReadingType[]>([])

  async function loadReadingTypes(force = false) {
    if (readingTypes.value.length > 0 && !force) return
    try {
      const res = await api.get<{ data: UsageReadingType[] }>('/admin/usage-reading-types')
      readingTypes.value = (res.data ?? []).filter((t) => t.is_active)
    } catch {
      readingTypes.value = []
    }
  }

  // ── Single rule (detail page) ────────────────────────────────────────────────
  const rule = ref<PmRule | null>(null)
  const ruleLoading = ref(false)
  const ruleError = ref<string | null>(null)
  const notFound = ref(false)
  const forbidden = ref(false)

  async function loadRule(id: number) {
    ruleLoading.value = true
    ruleError.value = null
    notFound.value = false
    forbidden.value = false
    try {
      const res = await api.get<{ data: PmRule }>(`/pm-rules/${id}`)
      rule.value = res.data
    } catch (e) {
      rule.value = null
      if (e instanceof ApiError && e.status === 404) notFound.value = true
      else if (e instanceof ApiError && e.status === 403) forbidden.value = true
      else ruleError.value = 'Failed to load PM rule.'
    } finally {
      ruleLoading.value = false
    }
  }

  // ── MR history (detail page) ─────────────────────────────────────────────────
  const mrHistory = ref<MaintenanceRequest[]>([])
  const mrHistoryLoading = ref(false)

  async function loadMrHistory(ruleId: number) {
    mrHistoryLoading.value = true
    try {
      mrHistory.value = await fetchList<MaintenanceRequest>('/maintenance-requests', {
        pm_rule_id: ruleId,
        sort: 'created_at:desc',
      })
    } catch {
      mrHistory.value = []
    } finally {
      mrHistoryLoading.value = false
    }
  }

  // ── Create / Update ──────────────────────────────────────────────────────────
  const saving = ref(false)
  const validationErrors = ref<Record<string, string[]> | null>(null)

  async function createRule(payload: PmRulePayload): Promise<PmRule | null> {
    saving.value = true
    validationErrors.value = null
    try {
      const res = await api.post<{ data: PmRule }>('/pm-rules', payload)
      return res.data
    } catch (e) {
      if (e instanceof ApiError && e.validationErrors) validationErrors.value = e.validationErrors
      return null
    } finally {
      saving.value = false
    }
  }

  /**
   * Multi-level setup: create each rule sequentially. Each rule is independent —
   * a failure on one does not roll back the others. Returns a per-row result so
   * the form can show which levels succeeded and which need a retry.
   */
  async function createRulesBatch(
    payloads: PmRulePayload[],
  ): Promise<{ index: number; ok: boolean; errors?: Record<string, string[]>; message?: string }[]> {
    saving.value = true
    const results: { index: number; ok: boolean; errors?: Record<string, string[]>; message?: string }[] = []
    for (let i = 0; i < payloads.length; i++) {
      try {
        await api.post('/pm-rules', payloads[i])
        results.push({ index: i, ok: true })
      } catch (e) {
        if (e instanceof ApiError) {
          results.push({ index: i, ok: false, errors: e.validationErrors ?? undefined, message: e.message })
        } else {
          results.push({ index: i, ok: false, message: 'Failed to create rule.' })
        }
      }
    }
    saving.value = false
    return results
  }

  async function updateRule(id: number, payload: PmRulePayload): Promise<PmRule | null> {
    saving.value = true
    validationErrors.value = null
    try {
      const res = await api.patch<{ data: PmRule }>(`/pm-rules/${id}`, payload)
      return res.data
    } catch (e) {
      if (e instanceof ApiError && e.validationErrors) validationErrors.value = e.validationErrors
      return null
    } finally {
      saving.value = false
    }
  }

  // ── Lifecycle actions ────────────────────────────────────────────────────────
  const acting = ref(false)

  async function deactivateRule(id: number): Promise<ActionResult> {
    acting.value = true
    try {
      await api.post(`/pm-rules/${id}/deactivate`)
      return { ok: true }
    } catch (e) {
      // 409 = active MR/WO chain blocks deactivation.
      const message = e instanceof ApiError ? e.message : 'Failed to deactivate rule.'
      return { ok: false, message }
    } finally {
      acting.value = false
    }
  }

  async function reactivateRule(id: number): Promise<ActionResult> {
    acting.value = true
    try {
      await api.post(`/pm-rules/${id}/reactivate`)
      return { ok: true }
    } catch (e) {
      const message = e instanceof ApiError ? e.message : 'Failed to reactivate rule.'
      return { ok: false, message }
    } finally {
      acting.value = false
    }
  }

  const evaluating = ref(false)

  async function evaluateRule(id: number): Promise<ActionResult> {
    evaluating.value = true
    try {
      const res = await api.post<{ message: string; data?: MaintenanceRequest }>(`/pm-rules/${id}/evaluate`)
      return { ok: true, message: res.message, data: res.data }
    } catch (e) {
      const message = e instanceof ApiError ? e.message : 'Failed to evaluate rule.'
      return { ok: false, message }
    } finally {
      evaluating.value = false
    }
  }

  async function evaluateAll(): Promise<ActionResult> {
    evaluating.value = true
    try {
      const res = await api.post<{ message: string }>('/pm-rules/evaluate')
      return { ok: true, message: res.message }
    } catch (e) {
      const message = e instanceof ApiError ? e.message : 'Failed to evaluate rules.'
      return { ok: false, message }
    } finally {
      evaluating.value = false
    }
  }

  return {
    rules, rulesLoading, rulesError, loadRules,
    readingTypes, loadReadingTypes,
    rule, ruleLoading, ruleError, notFound, forbidden, loadRule,
    mrHistory, mrHistoryLoading, loadMrHistory,
    saving, validationErrors, createRule, createRulesBatch, updateRule,
    acting, deactivateRule, reactivateRule,
    evaluating, evaluateRule, evaluateAll,
  }
}
