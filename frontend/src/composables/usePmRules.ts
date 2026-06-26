import { ref } from 'vue'
import api, { ApiError } from '@/lib/api'
import { fetchList } from '@/lib/dataTableSource'
import type { PmRule, AssetPmAssignment, UsageReadingType, MaintenanceRequest } from '@/types'

export interface PmRulePayload {
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

/** Result of POST /pm-rules/evaluate-all (structured counts). */
export interface EvaluateAllResult {
  evaluated: number
  generated: number
}

export function usePmRules() {
  // ── Template list ───────────────────────────────────────────────────────────
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

  /** Active templates only — backs the Asset Detail "Assign Rule" picker. */
  async function loadActiveTemplates(): Promise<PmRule[]> {
    try {
      return await fetchList<PmRule>('/pm-rules', { is_active: 1, sort: 'name:asc' })
    } catch {
      return []
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

  // ── Single template (detail page) ───────────────────────────────────────────
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

  // ── Create / Update templates ────────────────────────────────────────────────
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
   * Multi-level setup: create each template sequentially. Each template is
   * independent — a failure on one does not roll back the others. Returns a
   * per-row result so the form can show which levels succeeded.
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

  // ── Template lifecycle actions ──────────────────────────────────────────────
  const acting = ref(false)

  async function deactivateRule(id: number): Promise<ActionResult> {
    acting.value = true
    try {
      await api.post(`/pm-rules/${id}/deactivate`)
      return { ok: true }
    } catch (e) {
      // 409 = an assignment for this template has an active MR/WO chain.
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

  // ── Evaluate all active assignments ─────────────────────────────────────────
  const evaluating = ref(false)

  async function evaluateAll(): Promise<{ ok: boolean; message?: string; result?: EvaluateAllResult }> {
    evaluating.value = true
    try {
      const res = await api.post<EvaluateAllResult>('/pm-rules/evaluate-all')
      return { ok: true, result: res }
    } catch (e) {
      const message = e instanceof ApiError ? e.message : 'Failed to evaluate assignments.'
      return { ok: false, message }
    } finally {
      evaluating.value = false
    }
  }

  // ── Assignments (per-asset, Asset Detail) ────────────────────────────────────
  const assignments = ref<AssetPmAssignment[]>([])
  const assignmentsLoading = ref(false)
  const assignmentsError = ref<string | null>(null)

  async function loadAssignments(assetId: number, opts: { showInactive?: boolean } = {}) {
    assignmentsLoading.value = true
    assignmentsError.value = null
    try {
      const params: Record<string, string | number | boolean> = opts.showInactive ? { is_active: 'all' } : {}
      assignments.value = await fetchList<AssetPmAssignment>(
        `/assets/${assetId}/pm-assignments`,
        params,
      )
    } catch {
      assignments.value = []
      assignmentsError.value = 'Failed to load PM assignments.'
    } finally {
      assignmentsLoading.value = false
    }
  }

  async function assignRule(assetId: number, pmRuleId: number): Promise<AssetPmAssignment | null> {
    saving.value = true
    validationErrors.value = null
    try {
      const res = await api.post<{ data: AssetPmAssignment }>(`/assets/${assetId}/pm-assignments`, {
        pm_rule_id: pmRuleId,
      })
      return res.data
    } catch (e) {
      if (e instanceof ApiError && e.validationErrors) validationErrors.value = e.validationErrors
      return null
    } finally {
      saving.value = false
    }
  }

  async function deactivateAssignment(assetId: number, assignmentId: number): Promise<ActionResult> {
    acting.value = true
    try {
      await api.post(`/assets/${assetId}/pm-assignments/${assignmentId}/deactivate`)
      return { ok: true }
    } catch (e) {
      const message = e instanceof ApiError ? e.message : 'Failed to deactivate assignment.'
      return { ok: false, message }
    } finally {
      acting.value = false
    }
  }

  async function reactivateAssignment(assetId: number, assignmentId: number): Promise<ActionResult> {
    acting.value = true
    try {
      await api.post(`/assets/${assetId}/pm-assignments/${assignmentId}/reactivate`)
      return { ok: true }
    } catch (e) {
      const message = e instanceof ApiError ? e.message : 'Failed to reactivate assignment.'
      return { ok: false, message }
    } finally {
      acting.value = false
    }
  }

  async function evaluateAssignment(assetId: number, assignmentId: number): Promise<ActionResult> {
    evaluating.value = true
    try {
      const res = await api.post<{ message: string; data?: MaintenanceRequest }>(
        `/assets/${assetId}/pm-assignments/${assignmentId}/evaluate`,
      )
      return { ok: true, message: res.message, data: res.data }
    } catch (e) {
      const message = e instanceof ApiError ? e.message : 'Failed to evaluate assignment.'
      return { ok: false, message }
    } finally {
      evaluating.value = false
    }
  }

  return {
    // templates
    rules, rulesLoading, rulesError, loadRules, loadActiveTemplates,
    readingTypes, loadReadingTypes,
    rule, ruleLoading, ruleError, notFound, forbidden, loadRule,
    mrHistory, mrHistoryLoading, loadMrHistory,
    saving, validationErrors, createRule, createRulesBatch, updateRule,
    acting, deactivateRule, reactivateRule,
    evaluating, evaluateAll,
    // assignments
    assignments, assignmentsLoading, assignmentsError, loadAssignments,
    assignRule, deactivateAssignment, reactivateAssignment, evaluateAssignment,
  }
}
