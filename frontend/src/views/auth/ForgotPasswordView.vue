<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useForgotPassword } from '@/composables/useForgotPassword'

const { email, loading, error, sent, submit } = useForgotPassword()
</script>

<template>
  <div class="atms-auth-page">
    <div class="atms-auth-card">
      <div class="atms-auth-logo">
        <img src="@/assets/logo.svg" alt="ATMS" height="40" />
      </div>

      <h1 class="atms-auth-title">Reset your password</h1>

      <div v-if="sent" class="atms-auth-success" role="status">
        If that email is registered, you will receive a password reset link shortly.
      </div>

      <form v-else class="atms-auth-form" novalidate @submit.prevent="submit">
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

        <div v-if="error" class="error-state" role="alert">{{ error }}</div>

        <Button type="submit" class="atms-auth-submit" :disabled="loading">
          {{ loading ? 'Sending…' : 'Send reset link' }}
        </Button>

        <p class="atms-auth-back">
          <RouterLink to="/login">Back to sign in</RouterLink>
        </p>
      </form>
    </div>
  </div>
</template>
