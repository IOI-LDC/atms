<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { toast } from 'vue-sonner'
import AppDataTable from '@/components/app/AppDataTable.vue'
import ProvisionUserDialog from '@/components/admin/ProvisionUserDialog.vue'
import EditUserSheet from '@/components/admin/EditUserSheet.vue'
import ResetPasswordDialog from '@/components/admin/ResetPasswordDialog.vue'
import UserStatusDialog from '@/components/admin/UserStatusDialog.vue'
import { Button } from '@/components/ui/button'
import { useUsers } from '@/composables/useUsers'
import { useAuthStore } from '@/stores/auth.store'
import { roleClass, roleLabel, userStatusClass, userStatusLabel } from '@/lib/displayHelpers'
import { Pencil, KeyRound, ToggleLeft, ToggleRight, UserPlus } from '@lucide/vue'
import type { AppColumnDef } from '@/lib/appTable'
import type { Employee, User } from '@/types'

const auth = useAuthStore()

const {
  assignableRoles, loadRoles,
  employees, employeesLoading, employeesError, loadEmployees,
  users, usersLoading, usersError, loadUsers,
  provisionedEmpIds,
  provisioning, provisionErrors, provisionUser,
  saving, validationErrors, updateUser,
  toggling, deactivateUser, reactivateUser,
  resettingPassword, passwordErrors, resetPassword,
} = useUsers()

// ── Column definitions ────────────────────────────────────────────────────────
const employeeColumns: AppColumnDef<Employee>[] = [
  { field: 'name',       header: 'Name',        sortable: true },
  { field: 'emp_id',     header: 'Employee ID',  sortable: true },
  { field: 'email',      header: 'Email',        sortable: true },
  { field: 'department', header: 'Department',   sortable: true },
  { field: 'job_title',  header: 'Job Title',    sortable: true },
  { field: 'actions',    header: '',             sortable: false, align: 'center', minWidth: 150 },
]

const userColumns: AppColumnDef<User>[] = [
  { field: 'name',    header: 'Name',    sortable: true },
  { field: 'email',   header: 'Email',   sortable: true },
  { field: 'role',    header: 'Role',    sortable: false },
  { field: 'status',  header: 'Status',  sortable: false, align: 'center' },
  { field: 'actions', header: '',        sortable: false, align: 'center', minWidth: 200 },
]

// ── Initial load ──────────────────────────────────────────────────────────────
onMounted(() => {
  loadRoles()
  loadEmployees()
  loadUsers()
})

// ── Self-action guard ─────────────────────────────────────────────────────────
function isSelf(user: User) {
  return user.id === auth.user?.id
}

// ── Provision ─────────────────────────────────────────────────────────────────
const provisionTarget = ref<Employee | null>(null)
const provisionOpen = ref(false)

function openProvision(employee: Employee) {
  provisionTarget.value = employee
  provisionOpen.value = true
}

function closeProvision() {
  provisionOpen.value = false
  provisionTarget.value = null
}

async function onProvisionConfirm(roleId: number) {
  if (!provisionTarget.value) return
  const target = provisionTarget.value
  if (!target.emp_id) {
    toast.error('This employee has no Employee ID and cannot be provisioned.')
    return
  }
  const ok = await provisionUser(target.emp_id, roleId)
  if (ok) {
    toast.success(`${target.name} provisioned. Activation email queued.`)
    closeProvision()
  } else {
    const msg = provisionErrors.value
      ? Object.values(provisionErrors.value).flat().join(' ')
      : 'Failed to provision — this employee may already have an account.'
    toast.error(msg)
  }
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
  const ok = user.is_active
    ? await deactivateUser(user.id)
    : await reactivateUser(user.id)
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
    <!-- ── Employee Directory ────────────────────────────────────────────── -->
    <div class="data-card">
      <div class="data-card-header">
        <div>
          <h2 class="data-card-title">Employee Directory</h2>
          <p class="data-card-description">
            Employees imported from SharePoint. Provision to create a system account.
          </p>
        </div>
        <Button disabled aria-label="Import employees from SharePoint">
          Import from SharePoint
        </Button>
      </div>
      <div class="data-card-content">
        <div v-if="employeesError" class="error-state" role="alert">{{ employeesError }}</div>
        <AppDataTable
          :rows="employees"
          :columns="employeeColumns"
          empty-text="No employees found."
          label="Employees"
          :loading="employeesLoading"
        >
          <template #cell="{ column, row }">
            <span v-if="column.field === 'name'" class="table-cell-primary">{{ row.name }}</span>

            <span v-else-if="column.field === 'emp_id'" class="atms-erp-code">
              {{ row.emp_id ?? '—' }}
            </span>

            <span v-else-if="column.field === 'email'">{{ row.email ?? '—' }}</span>

            <span v-else-if="column.field === 'department'" class="table-cell-secondary">
              {{ row.department ?? '—' }}
            </span>

            <span v-else-if="column.field === 'job_title'" class="table-cell-secondary">
              {{ row.job_title ?? '—' }}
            </span>

            <div v-else-if="column.field === 'actions'" class="table-row-actions">
              <span
                v-if="row.emp_id && provisionedEmpIds.has(row.emp_id)"
                class="status-badge status-active"
              >Provisioned</span>
              <Button
                v-else
                variant="outline"
                size="icon-sm"
                :aria-label="`Provision ${row.name} as a user`"
                @click="openProvision(row)"
              >
                <UserPlus />
              </Button>
            </div>
          </template>
        </AppDataTable>
      </div>
    </div>

    <!-- ── System Users ───────────────────────────────────────────────────── -->
    <div class="data-card">
      <div class="data-card-header">
        <div>
          <h2 class="data-card-title">System Users</h2>
          <p class="data-card-description">
            Active and inactive ATMS accounts. You cannot modify your own account.
          </p>
        </div>
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
  <ProvisionUserDialog
    :open="provisionOpen"
    :employee="provisionTarget"
    :roles="assignableRoles"
    :loading="provisioning"
    @confirm="onProvisionConfirm"
    @cancel="closeProvision"
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
