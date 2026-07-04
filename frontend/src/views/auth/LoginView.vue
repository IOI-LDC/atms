<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth.store'
import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()

const email = ref('')
const password = ref('')
const remember = ref(false)
const error = ref<string | null>(null)
const loading = ref(false)

async function handleSubmit() {
  error.value = null
  loading.value = true
  try {
    await auth.login(email.value, password.value, remember.value)
    // Only honour internal absolute paths; reject protocol-relative (//host)
    // and external URLs to avoid an open-redirect after login.
    const target = route.query.redirect
    const redirect =
      typeof target === 'string' && target.startsWith('/') && !target.startsWith('//')
        ? target
        : '/dashboard'
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
  <div class="auth-layout">
    <!-- Left Panel: Brand & Operational Visuals (Desktop only) -->
    <div class="auth-brand-panel">
      <div class="auth-brand-content">
        <div class="auth-brand-header">
          <img src="@/assets/logo.svg" alt="ATMS" class="auth-brand-logo" />
          <span class="auth-brand-name">ATMS</span>
        </div>
        <div class="auth-brand-hero">
          <h2 class="auth-brand-title">Enterprise Reliability at Your Fingertips</h2>
          <p class="auth-brand-subtitle">
            ATMS orchestrates asset lifecycles, work orders, and compliance data into a unified
            operational control hub.
          </p>
        </div>

        <!-- Live KPI Card Mockup (for premium enterprise aesthetics) -->
        <div class="auth-kpi-showcase">
          <div class="auth-kpi-card">
            <div class="auth-kpi-header">
              <span class="auth-kpi-tag">Operations Status</span>
              <div class="auth-kpi-status-dot"></div>
            </div>
            <div class="auth-kpi-metric">
              <span class="auth-kpi-number">98.4%</span>
              <span class="auth-kpi-label">PM Compliance</span>
            </div>
            <div class="auth-kpi-grid">
              <div class="auth-kpi-item">
                <span class="auth-kpi-val">2.4h</span>
                <span class="auth-kpi-lbl">Avg MTTR</span>
              </div>
              <div class="auth-kpi-item">
                <span class="auth-kpi-val">12</span>
                <span class="auth-kpi-lbl">Active WOs</span>
              </div>
            </div>
          </div>
        </div>
      </div>
      <!-- Background glowing gradient circles -->
      <div class="auth-brand-glow-1"></div>
      <div class="auth-brand-glow-2"></div>
    </div>

    <!-- Right Panel: Login Form -->
    <div class="auth-form-panel">
      <div class="auth-form-container">
        <!-- Mobile logo/header (Hidden on Desktop) -->
        <div class="auth-mobile-header">
          <img src="@/assets/logo.svg" alt="ATMS" class="auth-mobile-logo" />
          <h1 class="auth-mobile-title">ATMS</h1>
        </div>

        <div class="auth-form-header">
          <h1 class="auth-form-title">Sign in to your account</h1>
          <p class="auth-form-desc">
            Enter your credentials to access the Maintenance Control Center.
          </p>
        </div>

        <form class="auth-form-form" @submit.prevent="handleSubmit" novalidate>
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
            <div class="auth-form-password-label">
              <Label for="password">Password</Label>
              <RouterLink to="/forgot-password" class="auth-form-forgot">
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

          <div class="auth-form-remember-row">
            <Checkbox id="remember" v-model="remember" :disabled="loading" />
            <Label for="remember" class="auth-form-remember-label">Remember me for 30 days</Label>
          </div>

          <div v-if="error" class="error-state" role="alert">
            {{ error }}
          </div>

          <Button type="submit" class="auth-form-submit" :disabled="loading">
            {{ loading ? 'Signing in…' : 'Sign in to ATMS' }}
          </Button>
        </form>
      </div>
    </div>
  </div>
</template>
