<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { toast } from 'vue-sonner'
import AppDataTable from '@/components/app/AppDataTable.vue'
import CreateUserDialog from '@/components/admin/CreateUserDialog.vue'
import EditUserSheet from '@/components/admin/EditUserSheet.vue'
import ResetPasswordDialog from '@/components/admin/ResetPasswordDialog.vue'
import UserStatusDialog from '@/components/admin/UserStatusDialog.vue'
import { Button } from '@/components/ui/button'
import { useUsers } from '@/composables/useUsers'
import { useAuthStore } from '@/stores/auth.store'
import { roleClass, roleLabel, userStatusClass, userStatusLabel } from '@/lib/displayHelpers'
import { Pencil, KeyRound, ToggleLeft, ToggleRight, UserPlus } from '@lucide/vue'
import type { AppColumnDef } from '@/lib/appTable'
import type { User } from '@/types'

const auth = useAuthStore()

const {
  assignableRoles,
  loadRoles,
  users,
  usersLoading,
  usersError,
  loadUsers,
  creating,
  createErrors,
  createUser,
  saving,
  validationErrors,
  updateUser,
  toggling,
  deactivateUser,
  reactivateUser,
  resettingPassword,
  passwordErrors,
  resetPassword,
} = useUsers()

// ── Column definitions ────────────────────────────────────────────────────────
const userColumns: AppColumnDef<User>[] = [
  { field: 'name', header: 'Name', sortable: true },
  { field: 'email', header: 'Email', sortable: true },
  { field: 'role', header: 'Role', sortable: false },
  { field: 'status', header: 'Status', sortable: false, align: 'center' },
  { field: 'actions', header: '', sortable: false, align: 'center', minWidth: 200 },
]

// ── Initial load ──────────────────────────────────────────────────────────────
onMounted(() => {
  loadRoles()
  loadUsers()
})

// ── Self-action guard ─────────────────────────────────────────────────────────
function isSelf(user: User) {
  return user.id === auth.user?.id
}

// ── Create user ───────────────────────────────────────────────────────────────
const createOpen = ref(false)

function closeCreate() {
  createOpen.value = false
  createErrors.value = null
}

async function onCreateConfirm(payload: { name: string; email: string; role_id: number }) {
  const ok = await createUser(payload)
  if (ok) {
    toast.success('User created. Activation email queued.')
    closeCreate()
  }
  // validation errors surface inline via createErrors prop on the dialog
}

// ── Edit user ─────────────────────────────────────────────────────────────────
const editTarget = ref<User | null>(null)
const editOpen = ref(false)

function openEdit(user: User) {
  validationErrors.value = null
  editTarget.value = user
  editOpen.value = true
}

function closeEdit() {
  editOpen.value = false
  editTarget.value = null
  validationErrors.value = null
}

async function onEditConfirm(payload: { name: string; email: string; role_id: number }) {
  if (!editTarget.value) return
  const ok = await updateUser(editTarget.value.id, payload)
  if (ok) {
    toast.success('User updated successfully.')
    closeEdit()
  }
  // validation errors surface inline via validationErrors prop on the sheet
}

// ── Reset password ────────────────────────────────────────────────────────────
const resetPwTarget = ref<User | null>(null)
const resetPwOpen = ref(false)

function openResetPw(user: User) {
  resetPwTarget.value = user
  resetPwOpen.value = true
}

function closeResetPw() {
  resetPwOpen.value = false
  resetPwTarget.value = null
}

async function onResetPwConfirm(password: string, passwordConfirmation: string) {
  if (!resetPwTarget.value) return
  const ok = await resetPassword(resetPwTarget.value.id, password, passwordConfirmation)
  if (ok) {
    toast.success('Password reset. All sessions have been invalidated.')
    closeResetPw()
  } else {
    const msg = passwordErrors.value
      ? Object.values(passwordErrors.value).flat().join(' ')
      : 'Failed to reset password.'
    toast.error(msg)
  }
}

// ── Activate / Deactivate ─────────────────────────────────────────────────────
const statusTarget = ref<User | null>(null)
const statusOpen = ref(false)

function openStatus(user: User) {
  statusTarget.value = user
  statusOpen.value = true
}

function closeStatus() {
  statusOpen.value = false
  statusTarget.value = null
}

async function onStatusConfirm() {
  if (!statusTarget.value) return
  const user = statusTarget.value
  const ok = user.is_active ? await deactivateUser(user.id) : await reactivateUser(user.id)
  if (ok) {
    toast.success(user.is_active ? `${user.name} deactivated.` : `${user.name} reactivated.`)
    closeStatus()
  } else {
    toast.error('Failed to update account status.')
  }
}
</script>

<template>
  <div class="page-content">
    <!-- ── System Users ───────────────────────────────────────────────────── -->
    <div class="data-card">
      <div class="data-card-header">
        <div>
          <h2 class="data-card-title">System Users</h2>
          <p class="data-card-description">
            Active and inactive ATMS accounts. You cannot modify your own account.
          </p>
        </div>
        <Button variant="outline" aria-label="Add a new user" @click="createOpen = true">
          <UserPlus />
          Add User
        </Button>
      </div>
      <div class="data-card-content">
        <div v-if="usersError" class="error-state" role="alert">{{ usersError }}</div>
        <AppDataTable
          :rows="users"
          :columns="userColumns"
          empty-text="No users found."
          label="Users"
          :loading="usersLoading"
        >
          <template #cell="{ column, row }">
            <span v-if="column.field === 'name'" class="table-cell-primary">
              {{ row.name }}
              <span v-if="isSelf(row)" class="user-you-badge">you</span>
            </span>

            <span v-else-if="column.field === 'email'">{{ row.email }}</span>

            <span v-else-if="column.field === 'role'" :class="roleClass(row.role.code)">
              {{ roleLabel(row.role.code) }}
            </span>

            <span v-else-if="column.field === 'status'" :class="userStatusClass(row)">
              {{ userStatusLabel(row) }}
            </span>

            <div v-else-if="column.field === 'actions'" class="table-row-actions">
              <Button
                variant="outline"
                size="icon-sm"
                :disabled="isSelf(row)"
                :aria-label="`Edit ${row.name}`"
                @click="openEdit(row)"
              >
                <Pencil />
              </Button>
              <Button
                variant="ghost"
                size="icon-sm"
                :disabled="isSelf(row)"
                :aria-label="`Reset password for ${row.name}`"
                @click="openResetPw(row)"
              >
                <KeyRound />
              </Button>
              <Button
                variant="ghost"
                size="icon-sm"
                :disabled="isSelf(row)"
                :aria-label="`${row.is_active ? 'Deactivate' : 'Reactivate'} ${row.name}`"
                @click="openStatus(row)"
              >
                <ToggleRight v-if="row.is_active" />
                <ToggleLeft v-else />
              </Button>
            </div>
          </template>
        </AppDataTable>
      </div>
    </div>
  </div>

  <!-- ── Dialogs / Sheets ───────────────────────────────────────────────── -->
  <CreateUserDialog
    :open="createOpen"
    :roles="assignableRoles"
    :loading="creating"
    :validation-errors="createErrors"
    @confirm="onCreateConfirm"
    @cancel="closeCreate"
  />

  <EditUserSheet
    :open="editOpen"
    :user="editTarget"
    :roles="assignableRoles"
    :saving="saving"
    :validation-errors="validationErrors"
    @confirm="onEditConfirm"
    @cancel="closeEdit"
  />

  <ResetPasswordDialog
    :open="resetPwOpen"
    :user="resetPwTarget"
    :loading="resettingPassword"
    :validation-errors="passwordErrors"
    @confirm="onResetPwConfirm"
    @cancel="closeResetPw"
  />

  <UserStatusDialog
    :open="statusOpen"
    :user="statusTarget"
    :loading="toggling"
    @confirm="onStatusConfirm"
    @cancel="closeStatus"
  />
</template>
