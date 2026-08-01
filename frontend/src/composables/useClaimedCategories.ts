import { computed, type Ref } from 'vue'
import type { WoFormTemplate } from '@/types'

/**
 * Categories already claimed by another *active* template, mapped to the name
 * of the template holding them. Only one active form may serve a category, so
 * the category pickers show these as unavailable ("taken by X") rather than
 * hiding them — a labelled, disabled row tells the user what to do next, a
 * missing row does not. The backend 422/409 stays the backstop for two admins
 * racing.
 *
 * `editingId` excludes the template being edited from its own claim map, so its
 * categories stay selectable while it is open.
 */
export function useClaimedCategories(
  templates: Ref<WoFormTemplate[]>,
  editingId: Ref<number | null>,
) {
  return computed(() => {
    const map = new Map<number, string>()
    for (const template of templates.value) {
      if (!template.is_active || template.id === editingId.value) continue
      for (const category of template.maintenance_categories ?? []) {
        map.set(category.id, template.name)
      }
    }
    return map
  })
}
