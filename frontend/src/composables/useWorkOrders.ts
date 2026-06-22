import { ref } from 'vue'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth.store'
import type { WorkOrder, CursorPage } from '@/types'

function mkList<T>() {
  return {
    items:       ref<T[]>([]),
    loading:     ref(false),
    loadingMore: ref(false),
    error:       ref<string | null>(null),
    nextCursor:  ref<string | null>(null),
    fetched:     false,
  }
}

export function useWorkOrders() {
  const auth = useAuthStore()

  // ── My Work Orders (Technician) ───────────────────────────────────────────────

  const myWo = mkList<WorkOrder>()

  async function loadMyWorkOrders(append = false, force = false) {
    if (!append && !force && myWo.fetched) return
    if (!append) { myWo.items.value = []; myWo.nextCursor.value = null }
    const busy = append ? myWo.loadingMore : myWo.loading
    busy.value = true; myWo.error.value = null
    try {
      const p: Record<string, unknown> = { assigned_to: auth.user?.id, sort: 'created_at:desc', per_page: 25 }
      if (append && myWo.nextCursor.value) p.cursor = myWo.nextCursor.value
      const res = await api.get<CursorPage<WorkOrder>>('/work-orders', p)
      myWo.items.value = append ? [...myWo.items.value, ...res.data] : res.data
      myWo.nextCursor.value = res.meta.next_cursor
      myWo.fetched = true
    } catch { myWo.error.value = 'Failed to load your work orders.' }
    finally { myWo.loading.value = false }
  }

  // ── All ───────────────────────────────────────────────────────────────────────

  const all = mkList<WorkOrder>()

  async function loadAll(append = false, force = false) {
    if (!append && !force && all.fetched) return
    if (!append) { all.items.value = []; all.nextCursor.value = null }
    const busy = append ? all.loadingMore : all.loading
    busy.value = true; all.error.value = null
    try {
      const p: Record<string, unknown> = { sort: 'created_at:desc', per_page: 25 }
      if (append && all.nextCursor.value) p.cursor = all.nextCursor.value
      const res = await api.get<CursorPage<WorkOrder>>('/work-orders', p)
      all.items.value = append ? [...all.items.value, ...res.data] : res.data
      all.nextCursor.value = res.meta.next_cursor
      all.fetched = true
    } catch { all.error.value = 'Failed to load work orders.' }
    finally { busy.value = false }
  }

  // ── Active (open + in_progress) ───────────────────────────────────────────────

  const active = mkList<WorkOrder>()

  async function loadActive(append = false, force = false) {
    if (!append && !force && active.fetched) return
    if (!append) { active.items.value = []; active.nextCursor.value = null }
    const busy = append ? active.loadingMore : active.loading
    busy.value = true; active.error.value = null
    try {
      const p: Record<string, unknown> = { sort: 'created_at:desc', per_page: 25 }
      if (append && active.nextCursor.value) p.cursor = active.nextCursor.value
      const res = await api.get<CursorPage<WorkOrder>>('/work-orders', p)
      const rows = res.data.filter(w => w.status === 'open' || w.status === 'in_progress')
      active.items.value = append ? [...active.items.value, ...rows] : rows
      active.nextCursor.value = res.meta.next_cursor
      active.fetched = true
    } catch { active.error.value = 'Failed to load active work orders.' }
    finally { busy.value = false }
  }

  // ── Completed (awaiting manager closure) ──────────────────────────────────────

  const completed = mkList<WorkOrder>()

  async function loadCompleted(append = false, force = false) {
    if (!append && !force && completed.fetched) return
    if (!append) { completed.items.value = []; completed.nextCursor.value = null }
    const busy = append ? completed.loadingMore : completed.loading
    busy.value = true; completed.error.value = null
    try {
      const p: Record<string, unknown> = { status: 'completed', sort: 'created_at:desc', per_page: 25 }
      if (append && completed.nextCursor.value) p.cursor = completed.nextCursor.value
      const res = await api.get<CursorPage<WorkOrder>>('/work-orders', p)
      completed.items.value = append ? [...completed.items.value, ...res.data] : res.data
      completed.nextCursor.value = res.meta.next_cursor
      completed.fetched = true
    } catch { completed.error.value = 'Failed to load completed work orders.' }
    finally { busy.value = false }
  }

  // ── Closed ────────────────────────────────────────────────────────────────────

  const closed = mkList<WorkOrder>()

  async function loadClosed(append = false, force = false) {
    if (!append && !force && closed.fetched) return
    if (!append) { closed.items.value = []; closed.nextCursor.value = null }
    const busy = append ? closed.loadingMore : closed.loading
    busy.value = true; closed.error.value = null
    try {
      const p: Record<string, unknown> = { status: 'closed', sort: 'created_at:desc', per_page: 25 }
      if (append && closed.nextCursor.value) p.cursor = closed.nextCursor.value
      const res = await api.get<CursorPage<WorkOrder>>('/work-orders', p)
      closed.items.value = append ? [...closed.items.value, ...res.data] : res.data
      closed.nextCursor.value = res.meta.next_cursor
      closed.fetched = true
    } catch { closed.error.value = 'Failed to load closed work orders.' }
    finally { busy.value = false }
  }

  return { myWo, loadMyWorkOrders, all, loadAll, active, loadActive, completed, loadCompleted, closed, loadClosed }
}
