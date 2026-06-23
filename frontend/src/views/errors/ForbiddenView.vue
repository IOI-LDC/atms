<script setup lang="ts">
import { useRouter } from 'vue-router'
import { Button } from '@/components/ui/button'

const router = useRouter()
</script>

<template>
  <div class="atms-auth-page">
    <div class="atms-auth-card">
      <p class="atms-error-code">403</p>
      <h1 class="atms-auth-title">Access denied</h1>
      <p class="atms-auth-subtitle">
        You do not have permission to view this page.
      </p>
      <Button @click="router.push('/dashboard')">Go to Dashboard</Button>
    </div>
  </div>
</template>
