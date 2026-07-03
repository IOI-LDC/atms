import { computed } from 'vue'
import type { Component } from 'vue'
import { ClipboardList, HardDrive, MapPin, Wrench } from '@lucide/vue'
import { useAuthStore } from '@/stores/auth.store'

export interface QuickAction {
  label: string
  icon: Component
  to: { path: string; query?: Record<string, string> }
}

/**
 * Role-driven quick-action shortcuts for the dashboard: Assets, New MR,
 * Locations, Work Orders — each shown only to roles whose sidebar can reach
 * that screen (see AppSidebar.vue), with the same per-role default tab so a
 * quick action always lands where the nav item would. Icons mirror the
 * sidebar's so the shortcut is visually recognizable as the same destination.
 */
export function useQuickActions() {
  const auth = useAuthStore()

  const actions = computed<QuickAction[]>(() => {
    const isHuman = auth.isAdmin || auth.isManager || auth.isTechnician || auth.isLogistics || auth.isRequester
    if (!isHuman) return []  // service / unrecognized role → no human quick actions

    const list: QuickAction[] = []

    if (auth.isAdminOrManager || auth.isTechnician || auth.isLogistics) {
      list.push({ label: 'Assets', icon: HardDrive, to: { path: '/assets', query: { tab: 'all-assets' } } })
    }

    list.push({
      label: 'New MR',
      icon: ClipboardList,
      to: {
        path: '/maintenance',
        query: { tab: auth.isAdminOrManager ? 'all-requests' : 'my-requests', action: 'new' },
      },
    })

    if (auth.isAdminOrManager || auth.isLogistics) {
      list.push({ label: 'Locations', icon: MapPin, to: { path: '/locations', query: { tab: 'asset-location-update' } } })
    }

    if (auth.isAdminOrManager || auth.isTechnician) {
      list.push({
        label: 'Work Orders',
        icon: Wrench,
        to: { path: '/work-orders', query: { tab: auth.isAdminOrManager ? 'all' : 'my-work-orders' } },
      })
    }

    return list
  })

  return { actions }
}
