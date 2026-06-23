import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api, { ApiError } from '@/lib/api'

export function useResetPassword() {
  const route  = useRoute()
  const router = useRouter()

  const token = route.query.token as string | undefined
  const email = route.query.email as string | undefined

  const password        = ref('')
  const passwordConfirm = ref('')
  const loading         = ref(false)
  const error           = ref<string | null>(null)
  const fieldErrors     = ref<Record<string, string[]>>({})
  const success         = ref(false)

  async function submit() {
    error.value = null
    fieldErrors.value = {}

    if (password.value !== passwordConfirm.value) {
      fieldErrors.value = { password_confirmation: ['Passwords do not match.'] }
      return
    }

    loading.value = true
    try {
      await api.post('/auth/reset-password', {
        token,
        email,
        password:              password.value,
        password_confirmation: passwordConfirm.value,
      })
      success.value = true
      setTimeout(() => router.push('/login'), 2000)
    } catch (err) {
      if (err instanceof ApiError) {
        if (err.validationErrors) fieldErrors.value = err.validationErrors
        else error.value = err.message
      } else {
        error.value = 'An unexpected error occurred.'
      }
    } finally {
      loading.value = false
    }
  }

  return { password, passwordConfirm, loading, error, fieldErrors, success, submit }
}
