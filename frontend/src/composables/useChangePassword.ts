import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { toast } from 'vue-sonner'
import api, { ApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth.store'

/**
 * Self-service password change for the signed-in user.
 *
 * ── Backend contract (implemented & tested) ───────────────────────────────
 *   Method & URL : POST /api/auth/change-password
 *   Middleware   : auth:sanctum + EnsureTokenAbilities
 *   Gate         : Gate::authorize('changePassword', User::class)
 *   Request body : { password: string, password_confirmation: string }
 *                  (No current password required.)
 *   Success      : 200 { message: "Password changed. Please log in again." }
 *   Side effect  : ALL sessions + API tokens for the user are invalidated,
 *                  forcing a fresh sign-in. Audit row: user.password_changed.
 *   Validation   : 422 { message, errors: {
 *                          password?: string[],
 *                          password_confirmation?: string[]
 *                        } }
 *   Rules        : new password min 8 chars, confirmation must match.
 *
 * On success the composable surfaces the server message as a toast, clears the
 * (now dead) local session, and redirects to /login so the user can sign back
 * in with the new password.
 */
export function useChangePassword() {
  const router = useRouter()
  const auth = useAuthStore()

  const password = ref('')
  const passwordConfirm = ref('')
  const loading = ref(false)
  const fieldErrors = ref<Record<string, string[]>>({})
  const error = ref<string | null>(null)

  /** Clears all fields and errors — call when the dialog opens or closes. */
  function reset(): void {
    password.value = ''
    passwordConfirm.value = ''
    fieldErrors.value = {}
    error.value = null
  }

  /**
   * Submits the change. On success the session is wiped and the user is
   * redirected to /login, so this never returns `true` to a still-rendered
   * dialog — `false` (with `fieldErrors`/`error` populated) is returned on any
   * failure. User input is preserved on failure per the form-state guardrail.
   */
  async function submit(): Promise<boolean> {
    error.value = null
    fieldErrors.value = {}

    if (password.value !== passwordConfirm.value) {
      fieldErrors.value = { password_confirmation: ['Passwords do not match.'] }
      return false
    }

    loading.value = true
    try {
      const res = await api.post<{ message?: string }>('/auth/change-password', {
        password: password.value,
        password_confirmation: passwordConfirm.value,
      })
      // The server has invalidated every session/token. Clear local state and
      // force a re-login, surfacing the server's message via the toast (which
      // stays mounted at the app root across the route change).
      toast.success(res.message ?? 'Password changed. Please log in again.')
      auth.clearLocalSession()
      await router.push('/login')
      return true
    } catch (err) {
      if (err instanceof ApiError) {
        if (err.validationErrors) fieldErrors.value = err.validationErrors
        else error.value = err.message
      } else {
        error.value = 'An unexpected error occurred.'
      }
      return false
    } finally {
      loading.value = false
    }
  }

  return {
    password,
    passwordConfirm,
    loading,
    fieldErrors,
    error,
    reset,
    submit,
  }
}
