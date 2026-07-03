<script setup lang="ts">
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import { ChevronDown } from '@lucide/vue'
import { useAuthStore } from '@/stores/auth.store'

const auth = useAuthStore()

async function handleLogout() {
  await auth.logout()
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
    <DropdownMenuContent align="end">
      <DropdownMenuLabel>{{ auth.user?.email }}</DropdownMenuLabel>
      <DropdownMenuSeparator />
      <DropdownMenuItem @click="handleLogout">Sign out</DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
