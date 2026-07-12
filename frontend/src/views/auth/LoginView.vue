<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth.store'
import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  CalendarClock,
  CheckCircle2,
  ClipboardList,
  Gauge,
  History,
  ShieldCheck,
  Wrench,
} from '@lucide/vue'

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
          <h2 class="auth-brand-title">Maintenance Operations, Connected</h2>
          <p class="auth-brand-subtitle">
            Manage asset records, maintenance requests, work orders, and preventive maintenance in
            one controlled workspace.
          </p>
        </div>

        <section class="auth-workflow-diagram" aria-labelledby="auth-workflow-heading">
          <div class="auth-workflow-header">
            <h3 id="auth-workflow-heading">ATMS Maintenance Workflow</h3>
            <p>From maintenance need to verified asset history</p>
          </div>

          <div class="auth-workflow-sources">
            <article class="auth-workflow-source">
              <span class="auth-workflow-source-icon"><ClipboardList /></span>
              <span class="auth-workflow-copy">
                <strong>Corrective MR</strong>
                <small>User-submitted issue with an optional meter reading</small>
              </span>
            </article>
            <article class="auth-workflow-source">
              <span class="auth-workflow-source-icon"><CalendarClock /></span>
              <span class="auth-workflow-copy">
                <strong>Preventive MR</strong>
                <small>System-generated from a date or meter trigger</small>
              </span>
            </article>
          </div>

          <div class="auth-workflow-merge" aria-hidden="true">
            <span>Pending Review</span>
          </div>

          <div class="auth-workflow-approval">
            <ShieldCheck />
            <span class="auth-workflow-copy">
              <strong>Approve &amp; Create Work Order</strong>
              <small>Manager review converts the MR into an assigned execution record</small>
            </span>
          </div>

          <div class="auth-workflow-connector" aria-hidden="true"></div>

          <div class="auth-workflow-work-order">
            <div class="auth-workflow-work-order-title">
              <Wrench />
              <span>
                <strong>Work Order</strong>
                <small>Controlled execution lifecycle</small>
              </span>
            </div>

            <ol class="auth-workflow-lifecycle">
              <li>
                <span>01</span>
                <strong>Open &amp; Assigned</strong>
                <small>Technician selected</small>
              </li>
              <li>
                <span>02</span>
                <strong>In Progress</strong>
                <small>Work and parts recorded</small>
              </li>
              <li>
                <Gauge />
                <strong>Completed</strong>
                <small>Optional meter update</small>
              </li>
              <li>
                <CheckCircle2 />
                <strong>Closed</strong>
                <small>PM baseline updated</small>
              </li>
            </ol>
          </div>

          <div class="auth-workflow-connector" aria-hidden="true"></div>

          <div class="auth-workflow-history">
            <History />
            <span class="auth-workflow-copy">
              <strong>Maintenance History</strong>
              <small>The closed Work Order remains an immutable asset record</small>
            </span>
          </div>

          <div class="auth-workflow-footer">
            <span><ShieldCheck /> Role-based decisions</span>
            <span><History /> Audited activity</span>
          </div>
        </section>
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
          <span class="auth-mobile-title">ATMS</span>
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
