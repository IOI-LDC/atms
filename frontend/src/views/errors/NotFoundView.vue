<script setup lang="ts">
import { useRouter } from 'vue-router'
import { Button } from '@/components/ui/button'

const router = useRouter()
</script>

<template>
  <div class="atms-auth-page">
    <div class="atms-auth-card">
      <p class="atms-error-code">404</p>
      <h1 class="atms-auth-title">Page not found</h1>
      <p class="atms-auth-subtitle">
        The page you requested does not exist.
      </p>
      <Button @click="router.push('/dashboard')">Go to Dashboard</Button>
    </div>
  </div>
</template>
