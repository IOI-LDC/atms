<script setup lang="ts">
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useActivate } from '@/composables/useActivate'

const { token, password, passwordConfirm, loading, error, fieldErrors, success, submit } = useActivate()
</script>

<template>
  <div class="atms-auth-page">
    <div class="atms-auth-card">
      <div class="atms-auth-logo">
        <img src="@/assets/logo.svg" alt="ATMS" height="40" />
      </div>

      <h1 class="atms-auth-title">Activate your account</h1>
      <p class="atms-auth-subtitle">Set a password to get started</p>

      <div v-if="success" class="atms-auth-success" role="status">
        Account activated. Redirecting to sign in…
      </div>

      <form v-else class="atms-auth-form" novalidate @submit.prevent="submit">
        <div class="form-field">
          <Label for="password">Password</Label>
          <Input
            id="password"
            v-model="password"
            type="password"
            autocomplete="new-password"
            required
            :disabled="loading || !token"
          />
          <p v-if="fieldErrors.password?.[0]" class="form-error">{{ fieldErrors.password[0] }}</p>
        </div>

        <div class="form-field">
          <Label for="password-confirm">Confirm password</Label>
          <Input
            id="password-confirm"
            v-model="passwordConfirm"
            type="password"
            autocomplete="new-password"
            required
            :disabled="loading || !token"
          />
          <p v-if="fieldErrors.password_confirmation?.[0]" class="form-error">
            {{ fieldErrors.password_confirmation[0] }}
          </p>
        </div>

        <div v-if="error" class="error-state" role="alert">{{ error }}</div>

        <Button type="submit" class="atms-auth-submit" :disabled="loading || !token">
          {{ loading ? 'Activating…' : 'Activate account' }}
        </Button>
      </form>
    </div>
  </div>
</template>
