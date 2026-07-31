<script setup lang="ts">
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useChangePassword } from '@/composables/useChangePassword'

defineProps<{
  open: boolean
}>()
const emit = defineEmits<{
  'update:open': [value: boolean]
}>()

const { password, passwordConfirm, loading, fieldErrors, error, reset, submit } =
  useChangePassword()

function close(): void {
  reset()
  emit('update:open', false)
}

async function handleChange(): Promise<void> {
  // On success the composable wipes the session and routes to /login, so the
  // dialog unmounts with the rest of the authenticated layout; we only close
  // it ourselves when submission fails (to reveal inline errors).
  await submit()
}
</script>

<template>
  <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Change password</DialogTitle>
        <DialogDescription>
          Set a new password. You will be signed out and asked to sign in again.
        </DialogDescription>
      </DialogHeader>

      <div class="form-field">
        <Label for="cp-new">New password</Label>
        <Input
          id="cp-new"
          v-model="password"
          type="password"
          placeholder="Min. 8 characters"
          autocomplete="new-password"
          :disabled="loading"
        />
        <p v-if="fieldErrors.password?.[0]" class="form-error">{{ fieldErrors.password[0] }}</p>
      </div>

      <div class="form-field">
        <Label for="cp-confirm">Confirm new password</Label>
        <Input
          id="cp-confirm"
          v-model="passwordConfirm"
          type="password"
          autocomplete="new-password"
          :disabled="loading"
        />
        <p v-if="fieldErrors.password_confirmation?.[0]" class="form-error">
          {{ fieldErrors.password_confirmation[0] }}
        </p>
      </div>

      <div v-if="error" class="error-state" role="alert">{{ error }}</div>

      <DialogFooter>
        <Button type="button" variant="outline" :disabled="loading" @click="close">Cancel</Button>
        <Button type="button" :disabled="loading" @click="handleChange">
          {{ loading ? 'Changing…' : 'Change password' }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
