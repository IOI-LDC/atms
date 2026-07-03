<script setup lang="ts">
import { ref, watch } from 'vue'
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import type { User } from '@/types'

const props = defineProps<{
  open: boolean
  user: User | null
  loading: boolean
  validationErrors: Record<string, string[]> | null
}>()

const emit = defineEmits<{
  confirm: [password: string, passwordConfirmation: string]
  cancel: []
}>()

const password = ref('')
const passwordConfirmation = ref('')
const localError = ref('')

watch(() => props.open, (nowOpen) => {
  if (nowOpen) {
    password.value = ''
    passwordConfirmation.value = ''
    localError.value = ''
  }
})

function handleConfirm() {
  if (password.value.length < 8) {
    localError.value = 'Password must be at least 8 characters.'
    return
  }
  if (password.value !== passwordConfirmation.value) {
    localError.value = 'Passwords do not match.'
    return
  }
  localError.value = ''
  emit('confirm', password.value, passwordConfirmation.value)
}
</script>

<template>
  <Dialog :open="open" @update:open="(v) => !v && emit('cancel')">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Reset Password</DialogTitle>
        <DialogDescription v-if="user">
          Set a new password for <strong>{{ user.name }}</strong>.
        </DialogDescription>
      </DialogHeader>

      <div class="confirmation-warning">
        All active sessions and API tokens for this user will be invalidated immediately.
      </div>

      <div class="form-field">
        <Label for="reset-pw-new">New Password <span class="field-required">*</span></Label>
        <Input
          id="reset-pw-new"
          v-model="password"
          type="password"
          placeholder="Min. 8 characters"
          autocomplete="new-password"
        />
        <p v-if="validationErrors?.password" class="form-error">
          {{ validationErrors.password[0] }}
        </p>
      </div>

      <div class="form-field">
        <Label for="reset-pw-confirm">Confirm Password <span class="field-required">*</span></Label>
        <Input
          id="reset-pw-confirm"
          v-model="passwordConfirmation"
          type="password"
          placeholder="Repeat password"
          autocomplete="new-password"
        />
      </div>

      <p v-if="localError" class="form-error">{{ localError }}</p>

      <DialogFooter>
        <Button variant="outline" :disabled="loading" @click="emit('cancel')">Cancel</Button>
        <Button variant="destructive" :disabled="loading" @click="handleConfirm">
          {{ loading ? 'Resetting…' : 'Reset Password' }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
