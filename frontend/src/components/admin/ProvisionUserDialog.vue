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
import { Label } from '@/components/ui/label'
import type { Employee, Role } from '@/types'

const props = defineProps<{
  open: boolean
  employee: Employee | null
  roles: Role[]
  loading: boolean
}>()

const emit = defineEmits<{
  confirm: [roleId: number]
  cancel: []
}>()

const selectedRoleId = ref('')
const roleError = ref('')

watch(
  () => props.open,
  (nowOpen) => {
    if (nowOpen) {
      selectedRoleId.value = ''
      roleError.value = ''
    }
  },
)

function handleConfirm() {
  if (!selectedRoleId.value) {
    roleError.value = 'Please select a role.'
    return
  }
  roleError.value = ''
  emit('confirm', Number(selectedRoleId.value))
}
</script>

<template>
  <Dialog :open="open" @update:open="(v) => !v && emit('cancel')">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Provision as User</DialogTitle>
        <DialogDescription v-if="employee">
          Create a system account for
          <strong>{{ employee.name }}</strong> ({{ employee.emp_id }}).
        </DialogDescription>
      </DialogHeader>

      <div class="form-field">
        <Label for="provision-role">Role <span class="field-required">*</span></Label>
        <Select v-model="selectedRoleId">
          <SelectTrigger id="provision-role">
            <SelectValue placeholder="Select a role…" />
          </SelectTrigger>
          <SelectContent disable-portal>
            <SelectItem v-for="role in roles" :key="role.id" :value="String(role.id)">{{
              role.name
            }}</SelectItem>
          </SelectContent>
        </Select>
        <p v-if="roleError" class="form-error">{{ roleError }}</p>
      </div>

      <div class="confirmation-warning">
        An activation email will be sent to the employee's address. The link expires in 24 hours.
        There is currently no resend option — if the link expires, contact backend support.
      </div>

      <DialogFooter>
        <Button variant="outline" :disabled="loading" @click="emit('cancel')">Cancel</Button>
        <Button :disabled="loading" @click="handleConfirm">
          {{ loading ? 'Provisioning…' : 'Provision' }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
