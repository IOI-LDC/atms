import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api, { resetCsrf } from '@/lib/api'
import type { User, RoleCode } from '@/types'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const loading = ref(false)

  const isAuthenticated = computed(() => !!user.value)
  const role = computed<RoleCode | null>(() => user.value?.role?.code ?? null)

  const isAdmin = computed(() => role.value === 'administrator')
  const isManager = computed(() => role.value === 'maintenance_manager')
  const isAdminOrManager = computed(() => isAdmin.value || isManager.value)
  const isTechnician = computed(() => role.value === 'technician')
  const isLogistics = computed(() => role.value === 'logistics')
  const isRequester = computed(() => role.value === 'requester')

  const userInitials = computed(() => {
    if (!user.value?.name) return '?'
    return user.value.name
      .split(' ')
      .map((n) => n[0])
      .join('')
      .slice(0, 2)
      .toUpperCase()
  })

  // Single-flight: concurrent navigations (or a redirect racing a push) must
  // share ONE /auth/me probe. Without this, a second guard run that fires while
  // the first is in flight would skip the fetch and wrongly redirect to login.
  let inflight: Promise<boolean> | null = null

  async function fetchCurrentUser(): Promise<boolean> {
    if (inflight) return inflight
    loading.value = true
    inflight = (async () => {
      try {
        // skipAuthRedirect: a 401 here is the expected "logged out" signal.
        // Let the router guard redirect to login with the intended destination
        // instead of the api client hard-redirecting to a bare /login.
        const data = await api.get<{ user: User }>('/auth/me', undefined, {
          skipAuthRedirect: true,
        })
        user.value = data.user
        return true
      } catch {
        user.value = null
        return false
      } finally {
        loading.value = false
        inflight = null
      }
    })()
    return inflight
  }

  async function login(email: string, password: string, remember = false): Promise<void> {
    const data = await api.post<{ user: User }>('/auth/login', { email, password, remember })
    user.value = data.user
  }

  async function logout(): Promise<void> {
    try {
      await api.post('/auth/logout')
    } finally {
      user.value = null
      resetCsrf()
    }
  }

  /**
   * Clears local session state WITHOUT a network call. Use when the server
   * has already invalidated the session (e.g. after a successful change-password,
   * which wipes all sessions/tokens and forces re-login).
   */
  function clearLocalSession(): void {
    user.value = null
    resetCsrf()
  }

  return {
    user,
    loading,
    isAuthenticated,
    role,
    isAdmin,
    isManager,
    isAdminOrManager,
    isTechnician,
    isLogistics,
    isRequester,
    userInitials,
    fetchCurrentUser,
    login,
    logout,
    clearLocalSession,
  }
})
