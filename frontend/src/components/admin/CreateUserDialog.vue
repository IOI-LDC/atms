<script setup lang="ts">
import { ref, watch } from 'vue'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import type { Role } from '@/types'

const props = defineProps<{
  open: boolean
  roles: Role[]
  loading: boolean
  validationErrors: Record<string, string[]> | null
}>()

const emit = defineEmits<{
  confirm: [payload: { name: string; email: string; role_id: number }]
  cancel: []
}>()

const name = ref('')
const email = ref('')
const roleId = ref('')
const formError = ref('')

watch(
  () => props.open,
  (nowOpen) => {
    if (nowOpen) {
      name.value = ''
      email.value = ''
      roleId.value = ''
      formError.value = ''
    }
  },
)

function handleConfirm() {
  if (!name.value.trim() || !email.value.trim() || !roleId.value) {
    formError.value = 'Please fill in all fields.'
    return
  }
  formError.value = ''
  emit('confirm', {
    name: name.value.trim(),
    email: email.value.trim(),
    role_id: Number(roleId.value),
  })
}
</script>

<template>
  <Dialog :open="open" @update:open="(v) => !v && emit('cancel')">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Create User</DialogTitle>
        <DialogDescription>Add a new ATMS system account.</DialogDescription>
      </DialogHeader>

      <div class="form-field">
        <Label for="create-user-name">Name <span class="field-required">*</span></Label>
        <Input id="create-user-name" v-model="name" placeholder="Full name" />
        <p v-if="validationErrors?.name" class="form-error">
          {{ validationErrors.name[0] }}
        </p>
      </div>

      <div class="form-field">
        <Label for="create-user-email">Email <span class="field-required">*</span></Label>
        <Input
          id="create-user-email"
          v-model="email"
          type="email"
          placeholder="email@example.com"
        />
        <p v-if="validationErrors?.email" class="form-error">
          {{ validationErrors.email[0] }}
        </p>
      </div>

      <div class="form-field">
        <Label for="create-user-role">Role <span class="field-required">*</span></Label>
        <Select v-model="roleId">
          <SelectTrigger id="create-user-role">
            <SelectValue placeholder="Select a role…" />
          </SelectTrigger>
          <SelectContent disable-portal>
            <SelectItem v-for="role in roles" :key="role.id" :value="String(role.id)">{{
              role.name
            }}</SelectItem>
          </SelectContent>
        </Select>
        <p v-if="validationErrors?.role_id" class="form-error">
          {{ validationErrors.role_id[0] }}
        </p>
      </div>

      <div class="confirmation-warning">
        An activation email will be sent to
        <strong v-if="email.trim()">{{ email.trim() }}</strong
        ><span v-else>the entered email address</span>. The link expires in 24 hours.
      </div>

      <p v-if="formError" class="form-error">{{ formError }}</p>

      <DialogFooter>
        <Button variant="outline" :disabled="loading" @click="emit('cancel')">Cancel</Button>
        <Button :disabled="loading" @click="handleConfirm">
          {{ loading ? 'Creating…' : 'Create User' }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
