<script setup lang="ts">
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useResetPassword } from '@/composables/useResetPassword'

const { password, passwordConfirm, loading, error, fieldErrors, success, submit } =
  useResetPassword()
</script>

<template>
  <div class="atms-auth-page">
    <div class="atms-auth-card">
      <div class="atms-auth-logo">
        <img src="@/assets/logo.svg" alt="ATMS" height="40" />
      </div>

      <h1 class="atms-auth-title">Set new password</h1>

      <div v-if="success" class="atms-auth-success" role="status">
        Password updated. Redirecting to sign in…
      </div>

      <form v-else class="atms-auth-form" novalidate @submit.prevent="submit">
        <div class="form-field">
          <Label for="password">New password</Label>
          <Input
            id="password"
            v-model="password"
            type="password"
            autocomplete="new-password"
            required
            :disabled="loading"
          />
          <p v-if="fieldErrors.password?.[0]" class="form-error">{{ fieldErrors.password[0] }}</p>
        </div>

        <div class="form-field">
          <Label for="password-confirm">Confirm new password</Label>
          <Input
            id="password-confirm"
            v-model="passwordConfirm"
            type="password"
            autocomplete="new-password"
            required
            :disabled="loading"
          />
          <p v-if="fieldErrors.password_confirmation?.[0]" class="form-error">
            {{ fieldErrors.password_confirmation[0] }}
          </p>
        </div>

        <div v-if="error" class="error-state" role="alert">{{ error }}</div>

        <Button type="submit" class="atms-auth-submit" :disabled="loading">
          {{ loading ? 'Updating…' : 'Update password' }}
        </Button>
      </form>
    </div>
  </div>
</template>
