import { ref } from 'vue'
import api from '@/lib/api'
import type { Asset, CursorPage } from '@/types'

export function useAssetSearch() {
  const query    = ref('')
  const results  = ref<Asset[]>([])
  const busy     = ref(false)
  const selected = ref<{ id: number; label: string } | null>(null)
  let   timer    = 0

  function onInput() {
    selected.value = null
    clearTimeout(timer)
    if (!query.value.trim()) { results.value = []; return }
    timer = window.setTimeout(async () => {
      busy.value = true
      try {
        const res = await api.get<CursorPage<Asset>>('/assets', { search: query.value, per_page: 8 })
        results.value = res.data
      } catch { results.value = [] }
      finally { busy.value = false }
    }, 280)
  }

  function select(a: Asset) {
    selected.value = { id: a.id, label: a.name }
    query.value    = `${a.name} (${a.erp_asset_code})`
    results.value  = []
  }

  function reset() {
    query.value    = ''
    results.value  = []
    selected.value = null
    clearTimeout(timer)
  }

  return { query, results, busy, selected, onInput, select, reset }
}
