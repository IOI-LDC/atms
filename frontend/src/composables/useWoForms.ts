import { ref } from 'vue'
import api, { ApiError } from '@/lib/api'
import { fetchList } from '@/lib/dataTableSource'
import type { WoFormTemplate, WoFormTemplateField, WoFormFieldType, FaSubclassTypeCode } from '@/types'

export interface WoFormTemplatePayload {
  name?: string
  fa_subclass_code?: string
}

export interface WoFormFieldPayload {
  label?: string
  field_type?: WoFormFieldType
  has_pre_post?: boolean
  unit?: string | null
  is_required?: boolean
  sort_order?: number
}

export interface ActionResult {
  ok: boolean
  message?: string
}

export function useWoForms() {
  // ── Template list ─────────────────────────────────────────────────────────────
  const templates = ref<WoFormTemplate[]>([])
  const templatesLoading = ref(false)
  const templatesError = ref<string | null>(null)

  async function loadTemplates(force = false) {
    if (templates.value.length > 0 && !force) return
    templatesLoading.value = true
    templatesError.value = null
    try {
      templates.value = await fetchList<WoFormTemplate>('/admin/wo-forms/templates', { sort: 'created_at:desc' })
    } catch {
      templates.value = []
      templatesError.value = 'Failed to load WO Form templates.'
    } finally {
      templatesLoading.value = false
    }
  }

  // ── Single template (fields manager) ────────────────────────────────────────
  const template = ref<WoFormTemplate | null>(null)
  const templateLoading = ref(false)

  async function loadTemplate(id: number) {
    templateLoading.value = true
    try {
      const res = await api.get<{ data: WoFormTemplate }>(`/admin/wo-forms/templates/${id}`)
      template.value = res.data
    } catch {
      template.value = null
    } finally {
      templateLoading.value = false
    }
  }

  // ── FA subclasses (create/edit picker) ──────────────────────────────────────
  const faSubclasses = ref<FaSubclassTypeCode[]>([])

  // This endpoint returns a plain { data: [...] } array (not cursor-paginated),
  // matching the useLists.ts convention — fetchList() would break here.
  async function loadFaSubclasses(force = false) {
    if (faSubclasses.value.length > 0 && !force) return
    try {
      const res = await api.get<{ data: FaSubclassTypeCode[] }>('/admin/fa-subclass-type-codes')
      faSubclasses.value = res.data ?? []
    } catch {
      faSubclasses.value = []
    }
  }

  // ── Create / Update template metadata ───────────────────────────────────────
  const saving = ref(false)
  const validationErrors = ref<Record<string, string[]> | null>(null)

  async function createTemplate(payload: WoFormTemplatePayload): Promise<WoFormTemplate | null> {
    saving.value = true
    validationErrors.value = null
    try {
      const res = await api.post<{ data: WoFormTemplate }>('/admin/wo-forms/templates', payload)
      return res.data
    } catch (e) {
      if (e instanceof ApiError && e.validationErrors) validationErrors.value = e.validationErrors
      return null
    } finally {
      saving.value = false
    }
  }

  async function updateTemplate(id: number, payload: WoFormTemplatePayload): Promise<WoFormTemplate | null> {
    saving.value = true
    validationErrors.value = null
    try {
      const res = await api.patch<{ data: WoFormTemplate }>(`/admin/wo-forms/templates/${id}`, payload)
      return res.data
    } catch (e) {
      if (e instanceof ApiError && e.validationErrors) validationErrors.value = e.validationErrors
      return null
    } finally {
      saving.value = false
    }
  }

  // ── Template lifecycle ───────────────────────────────────────────────────────
  const acting = ref(false)

  async function deactivateTemplate(id: number): Promise<ActionResult> {
    acting.value = true
    try {
      await api.post(`/admin/wo-forms/templates/${id}/deactivate`)
      return { ok: true }
    } catch (e) {
      return { ok: false, message: e instanceof ApiError ? e.message : 'Failed to deactivate template.' }
    } finally {
      acting.value = false
    }
  }

  async function reactivateTemplate(id: number): Promise<ActionResult> {
    acting.value = true
    try {
      await api.post(`/admin/wo-forms/templates/${id}/reactivate`)
      return { ok: true }
    } catch (e) {
      return { ok: false, message: e instanceof ApiError ? e.message : 'Failed to reactivate template.' }
    } finally {
      acting.value = false
    }
  }

  // ── Field management (per-field, immediate) ─────────────────────────────────
  const fieldSaving = ref(false)
  const fieldErrors = ref<Record<string, string[]> | null>(null)

  async function addField(templateId: number, payload: WoFormFieldPayload): Promise<WoFormTemplateField | null> {
    fieldSaving.value = true
    fieldErrors.value = null
    try {
      const res = await api.post<{ data: WoFormTemplateField }>(`/admin/wo-forms/templates/${templateId}/fields`, payload)
      return res.data
    } catch (e) {
      if (e instanceof ApiError && e.validationErrors) fieldErrors.value = e.validationErrors
      return null
    } finally {
      fieldSaving.value = false
    }
  }

  async function updateField(templateId: number, fieldId: number, payload: WoFormFieldPayload): Promise<WoFormTemplateField | null> {
    fieldSaving.value = true
    fieldErrors.value = null
    try {
      const res = await api.patch<{ data: WoFormTemplateField }>(`/admin/wo-forms/templates/${templateId}/fields/${fieldId}`, payload)
      return res.data
    } catch (e) {
      if (e instanceof ApiError && e.validationErrors) fieldErrors.value = e.validationErrors
      return null
    } finally {
      fieldSaving.value = false
    }
  }

  async function deleteField(templateId: number, fieldId: number): Promise<ActionResult> {
    fieldSaving.value = true
    try {
      await api.delete(`/admin/wo-forms/templates/${templateId}/fields/${fieldId}`)
      return { ok: true }
    } catch (e) {
      return { ok: false, message: e instanceof ApiError ? e.message : 'Failed to remove field.' }
    } finally {
      fieldSaving.value = false
    }
  }

  async function reorderFields(templateId: number, fieldIds: number[]): Promise<WoFormTemplateField[] | null> {
    fieldSaving.value = true
    try {
      const res = await api.post<{ data: WoFormTemplateField[] }>(
        `/admin/wo-forms/templates/${templateId}/fields/reorder`,
        { field_ids: fieldIds },
      )
      return res.data
    } catch {
      return null
    } finally {
      fieldSaving.value = false
    }
  }

  return {
    // Templates
    templates, templatesLoading, templatesError, loadTemplates,
    template, templateLoading, loadTemplate,
    faSubclasses, loadFaSubclasses,
    saving, validationErrors, createTemplate, updateTemplate,
    acting, deactivateTemplate, reactivateTemplate,
    // Fields
    fieldSaving, fieldErrors, addField, updateField, deleteField, reorderFields,
  }
}
