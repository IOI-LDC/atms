<script setup lang="ts">
import { ref, watch } from 'vue'
import {
  Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription,
} from '@/components/ui/sheet'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import type { User, Role } from '@/types'

const props = defineProps<{
  open: boolean
  user: User | null
  roles: Role[]
  saving: boolean
  validationErrors: Record<string, string[]> | null
}>()

const emit = defineEmits<{
  confirm: [payload: { name: string; email: string; role_id: number }]
  cancel: []
}>()

const name = ref('')
const email = ref('')
const roleId = ref('')

// Populate whenever the target user changes (covers re-opening on a different
// row). Sheet visibility is driven separately by `open`.
watch(
  () => props.user,
  (user) => {
    if (!user) return
    name.value = user.name
    email.value = user.email
    roleId.value = String(user.role.id)
  },
  { immediate: true },
)

function handleConfirm() {
  if (!name.value.trim() || !email.value.trim() || !roleId.value) return
  emit('confirm', {
    name: name.value.trim(),
    email: email.value.trim(),
    role_id: Number(roleId.value),
  })
}
</script>

<template>
  <Sheet :open="open" :modal="false" @update:open="(v) => !v && emit('cancel')">
    <SheetContent side="right" class="create-sheet">
      <div class="create-sheet-header">
        <SheetHeader>
          <SheetTitle>Edit User</SheetTitle>
          <SheetDescription v-if="user">{{ user.email }}</SheetDescription>
        </SheetHeader>
      </div>

      <div class="create-sheet-body">
        <div class="sheet-form">
          <div class="form-field">
            <Label for="edit-user-name">Name <span class="field-required">*</span></Label>
            <Input id="edit-user-name" v-model="name" placeholder="Full name" />
            <p v-if="validationErrors?.name" class="form-error">
              {{ validationErrors.name[0] }}
            </p>
          </div>

          <div class="form-field">
            <Label for="edit-user-email">Email <span class="field-required">*</span></Label>
            <Input id="edit-user-email" v-model="email" type="email" placeholder="email@example.com" />
            <p v-if="validationErrors?.email" class="form-error">
              {{ validationErrors.email[0] }}
            </p>
          </div>

          <div class="form-field">
            <Label for="edit-user-role">Role <span class="field-required">*</span></Label>
            <Select
              :model-value="roleId"
              @update:model-value="(v) => { roleId = v ? String(v) : '' }"
            >
              <SelectTrigger id="edit-user-role">
                <SelectValue placeholder="Select a role…" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="role in roles"
                  :key="role.id"
                  :value="String(role.id)"
                >{{ role.name }}</SelectItem>
              </SelectContent>
            </Select>
            <p v-if="validationErrors?.role_id" class="form-error">
              {{ validationErrors.role_id[0] }}
            </p>
          </div>
        </div>
      </div>

      <div class="create-sheet-footer">
        <Button variant="outline" :disabled="saving" @click="emit('cancel')">Cancel</Button>
        <Button :disabled="saving" @click="handleConfirm">
          {{ saving ? 'Saving…' : 'Save Changes' }}
        </Button>
      </div>
    </SheetContent>
  </Sheet>
</template>
