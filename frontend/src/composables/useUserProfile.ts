import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth.store'
import { useQuickActions } from './useQuickActions'
import type { QuickAction } from './useQuickActions'
import type { RoleCode } from '@/types'

/**
 * Role-specific capability summaries shown on the My Profile screen. Mirrors
 * the sidebar visibility rules in AppSidebar.vue so the summary never promises
 * access a role does not have. `service` is a non-human role and is excluded.
 */
const ROLE_SUMMARY: Record<Exclude<RoleCode, 'service'>, string[]> = {
  administrator: [
    'Full system administration',
    'Manage users, PM rules, and master data (lists & dropdowns)',
    'Configure WO forms and system settings',
    'Review audit logs',
    'Approve maintenance requests and work orders',
  ],
  maintenance_manager: [
    'Approve and oversee maintenance requests',
    'Manage work orders and assignments',
    'Manage PM assignments on assets',
    'View PM templates and update asset locations',
  ],
  technician: [
    'Execute assigned work orders',
    'Log parts used and meter readings',
    'View assets and parts',
    'Submit maintenance requests',
  ],
  logistics: [
    'Update asset locations',
    'Book and unbook assets',
    'View assets and submit maintenance requests',
  ],
  requester: ['Submit maintenance requests', 'Track the status of your requests'],
}

export function useUserProfile() {
  const auth = useAuthStore()
  const { actions: quickActions } = useQuickActions()

  const user = computed(() => auth.user)

  /** Display name, falling back to a placeholder when the session is loading. */
  const displayName = computed(() => auth.user?.name ?? '—')

  /** Role display name (e.g. "Maintenance Manager"). */
  const roleName = computed(() => auth.user?.role?.name ?? '—')

  /** Whether the user's email address has been verified. */
  const isEmailVerified = computed(() => !!auth.user?.email_verified_at)

  /** Whether the account is active in the directory. */
  const isActive = computed(() => !!auth.user?.is_active)

  /** Capability bullets for the signed-in role; empty for unrecognised roles. */
  const summaryPoints = computed<string[]>(() => {
    const code = auth.role
    if (!code || code === 'service') return []
    return ROLE_SUMMARY[code] ?? []
  })

  return {
    user,
    displayName,
    roleName,
    isEmailVerified,
    isActive,
    summaryPoints,
    quickActions: computed<QuickAction[]>(() => quickActions.value),
  }
}
