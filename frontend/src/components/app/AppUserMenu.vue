<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import ChangePasswordDialog from './ChangePasswordDialog.vue'
import {
  ChevronDown,
  KeyRound,
  LogOut,
  ScrollText,
  Settings,
  SlidersHorizontal,
  User,
} from '@lucide/vue'
import { useAuthStore } from '@/stores/auth.store'
import { FEATURE_CHANGE_PASSWORD } from '@/lib/features'

const auth = useAuthStore()
const router = useRouter()
const changePasswordOpen = ref(false)

async function handleLogout() {
  await auth.logout()
  await router.push('/login')
}
</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button
        variant="ghost"
        class="app-user-menu-trigger"
        :aria-label="`User menu for ${auth.user?.name ?? 'user'}`"
      >
        <Avatar class="app-user-menu-avatar">
          <AvatarFallback>{{ auth.userInitials }}</AvatarFallback>
        </Avatar>
        <span class="app-user-menu-info">
          <span class="app-user-menu-name">{{ auth.user?.name }}</span>
          <span class="app-user-menu-role">{{ auth.user?.role?.name }}</span>
        </span>
        <ChevronDown class="app-user-menu-chevron" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end" class="app-user-menu-content">
      <DropdownMenuLabel>{{ auth.user?.email }}</DropdownMenuLabel>
      <DropdownMenuSeparator />

      <!-- My Profile — every authenticated role -->
      <DropdownMenuItem as-child>
        <RouterLink to="/profile">
          <User />
          <span>My Profile</span>
        </RouterLink>
      </DropdownMenuItem>

      <!-- Change password — gated until PATCH /auth/password ships -->
      <DropdownMenuItem v-if="FEATURE_CHANGE_PASSWORD" @click="changePasswordOpen = true">
        <KeyRound />
        <span>Change password</span>
      </DropdownMenuItem>

      <!-- Settings — Admin only (System + Audit Logs) -->
      <template v-if="auth.isAdmin">
        <DropdownMenuSeparator />
        <DropdownMenuSub>
          <DropdownMenuSubTrigger>
            <Settings />
            <span>Settings</span>
          </DropdownMenuSubTrigger>
          <DropdownMenuSubContent>
            <DropdownMenuItem as-child>
              <RouterLink to="/settings/system">
                <SlidersHorizontal />
                <span>System</span>
              </RouterLink>
            </DropdownMenuItem>
            <DropdownMenuItem as-child>
              <RouterLink to="/settings/audit-logs">
                <ScrollText />
                <span>Audit Logs</span>
              </RouterLink>
            </DropdownMenuItem>
          </DropdownMenuSubContent>
        </DropdownMenuSub>
      </template>

      <DropdownMenuSeparator />
      <DropdownMenuItem @click="handleLogout">
        <LogOut />
        <span>Sign out</span>
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <ChangePasswordDialog v-if="FEATURE_CHANGE_PASSWORD" v-model:open="changePasswordOpen" />
</template>
