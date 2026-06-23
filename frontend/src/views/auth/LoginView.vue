<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth.store'
import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

const router = useRouter()
const route  = useRoute()
const auth   = useAuthStore()

const email    = ref('')
const password = ref('')
const error    = ref<string | null>(null)
const loading  = ref(false)

async function handleSubmit() {
  error.value = null
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    const redirect = (route.query.redirect as string) || '/dashboard'
    router.push(redirect)
  } catch (err) {
    if (err instanceof ApiError) {
      error.value = err.message
    } else {
      error.value = 'An unexpected error occurred. Please try again.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="atms-auth-page">
    <div class="atms-auth-card">
      <div class="atms-auth-logo">
        <img src="@/assets/logo.svg" alt="ATMS" height="40" />
      </div>

      <h1 class="atms-auth-title">Sign in to ATMS</h1>
      <p class="atms-auth-subtitle">Asset Maintenance Tracking System</p>

      <form class="atms-auth-form" @submit.prevent="handleSubmit" novalidate>
        <div class="form-field">
          <Label for="email">Email address</Label>
          <Input
            id="email"
            v-model="email"
            type="email"
            autocomplete="email"
            required
            :disabled="loading"
            placeholder="you@example.com"
          />
        </div>

        <div class="form-field">
          <div class="atms-auth-password-row">
            <Label for="password">Password</Label>
            <RouterLink to="/forgot-password" class="atms-auth-forgot-link">
              Forgot password?
            </RouterLink>
          </div>
          <Input
            id="password"
            v-model="password"
            type="password"
            autocomplete="current-password"
            required
            :disabled="loading"
          />
        </div>

        <div v-if="error" class="error-state" role="alert">
          {{ error }}
        </div>

        <Button type="submit" class="atms-auth-submit" :disabled="loading">
          {{ loading ? 'Signing in…' : 'Sign in' }}
        </Button>
      </form>
    </div>
  </div>
</template>
