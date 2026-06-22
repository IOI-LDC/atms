import { ref } from 'vue'
import api, { ApiError } from '@/lib/api'

export function useForgotPassword() {
  const email   = ref('')
  const loading = ref(false)
  const error   = ref<string | null>(null)
  const sent    = ref(false)

  async function submit() {
    error.value = null
    loading.value = true
    try {
      await api.post('/auth/forgot-password', { email: email.value })
      sent.value = true
    } catch (err) {
      error.value = err instanceof ApiError ? err.message : 'An unexpected error occurred.'
    } finally {
      loading.value = false
    }
  }

  return { email, loading, error, sent, submit }
}
