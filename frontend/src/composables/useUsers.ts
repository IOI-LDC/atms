import { ref, computed } from 'vue'
import api, { ApiError } from '@/lib/api'
import { fetchList } from '@/lib/dataTableSource'
import type { Employee, User, Role } from '@/types'

export function useUsers() {
  // ── Roles ──────────────────────────────────────────────────────────────────
  const roles = ref<Role[]>([])
  const rolesLoading = ref(false)

  const assignableRoles = computed(() => roles.value.filter((r) => r.code !== 'service'))

  async function loadRoles(force = false) {
    if (roles.value.length > 0 && !force) return
    rolesLoading.value = true
    try {
      const res = await api.get<{ data: Role[] }>('/admin/roles')
      roles.value = res.data ?? []
    } catch {
      roles.value = []
    } finally {
      rolesLoading.value = false
    }
  }

  // ── Employees ──────────────────────────────────────────────────────────────
  const employees = ref<Employee[]>([])
  const employeesLoading = ref(false)
  const employeesError = ref<string | null>(null)

  async function loadEmployees(force = false) {
    if (employees.value.length > 0 && !force) return
    employeesLoading.value = true
    employeesError.value = null
    try {
      employees.value = await fetchList<Employee>('/admin/employees', { sort: 'name:asc' })
    } catch {
      employees.value = []
      employeesError.value = 'Failed to load employees.'
    } finally {
      employeesLoading.value = false
    }
  }

  // ── Users ──────────────────────────────────────────────────────────────────
  const users = ref<User[]>([])
  const usersLoading = ref(false)
  const usersError = ref<string | null>(null)

  // Set of emp_ids that are already provisioned — cross-reference without eager-load
  const provisionedEmpIds = computed(() => {
    const set = new Set<string>()
    for (const u of users.value) {
      if (u.emp_id) set.add(u.emp_id)
    }
    return set
  })

  async function loadUsers(force = false) {
    if (users.value.length > 0 && !force) return
    usersLoading.value = true
    usersError.value = null
    try {
      const res = await api.get<{ data: User[] }>('/admin/users')
      users.value = res.data ?? []
    } catch {
      users.value = []
      usersError.value = 'Failed to load users.'
    } finally {
      usersLoading.value = false
    }
  }

  async function refreshAll() {
    await Promise.all([loadEmployees(true), loadUsers(true)])
  }

  // ── Provision employee as user ─────────────────────────────────────────────
  const provisioning = ref(false)
  const provisionErrors = ref<Record<string, string[]> | null>(null)

  async function provisionUser(empId: string, roleId: number): Promise<boolean> {
    provisioning.value = true
    provisionErrors.value = null
    try {
      await api.post('/admin/employees/provision-user', { emp_id: empId, role_id: roleId })
      await refreshAll()
      return true
    } catch (e) {
      if (e instanceof ApiError && e.validationErrors) provisionErrors.value = e.validationErrors
      return false
    } finally {
      provisioning.value = false
    }
  }

  // ── Update user ────────────────────────────────────────────────────────────
  const saving = ref(false)
  const validationErrors = ref<Record<string, string[]> | null>(null)

  interface UserPayload {
    name?: string
    email?: string
    role_id?: number
  }

  async function updateUser(id: number, payload: UserPayload): Promise<boolean> {
    saving.value = true
    validationErrors.value = null
    try {
      await api.patch(`/admin/users/${id}`, payload)
      await loadUsers(true)
      return true
    } catch (e) {
      if (e instanceof ApiError && e.validationErrors) validationErrors.value = e.validationErrors
      return false
    } finally {
      saving.value = false
    }
  }

  // ── Deactivate / Reactivate ────────────────────────────────────────────────
  const toggling = ref(false)

  async function deactivateUser(id: number): Promise<boolean> {
    toggling.value = true
    try {
      await api.post(`/admin/users/${id}/deactivate`)
      await loadUsers(true)
      return true
    } catch {
      return false
    } finally {
      toggling.value = false
    }
  }

  async function reactivateUser(id: number): Promise<boolean> {
    toggling.value = true
    try {
      await api.post(`/admin/users/${id}/reactivate`)
      await loadUsers(true)
      return true
    } catch {
      return false
    } finally {
      toggling.value = false
    }
  }

  // ── Reset password ─────────────────────────────────────────────────────────
  const resettingPassword = ref(false)
  const passwordErrors = ref<Record<string, string[]> | null>(null)

  async function resetPassword(
    id: number,
    password: string,
    passwordConfirmation: string,
  ): Promise<boolean> {
    resettingPassword.value = true
    passwordErrors.value = null
    try {
      await api.post(`/admin/users/${id}/reset-password`, {
        password,
        password_confirmation: passwordConfirmation,
      })
      return true
    } catch (e) {
      if (e instanceof ApiError && e.validationErrors) passwordErrors.value = e.validationErrors
      return false
    } finally {
      resettingPassword.value = false
    }
  }

  return {
    roles,
    assignableRoles,
    rolesLoading,
    loadRoles,
    employees,
    employeesLoading,
    employeesError,
    loadEmployees,
    users,
    usersLoading,
    usersError,
    loadUsers,
    provisionedEmpIds,
    provisioning,
    provisionErrors,
    provisionUser,
    saving,
    validationErrors,
    updateUser,
    toggling,
    deactivateUser,
    reactivateUser,
    resettingPassword,
    passwordErrors,
    resetPassword,
  }
}
