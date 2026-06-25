<script setup lang="ts">
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import type { User } from '@/types'

const props = defineProps<{
  open: boolean
  user: User | null
  loading: boolean
}>()

const emit = defineEmits<{
  confirm: []
  cancel: []
}>()

const isDeactivating = () => props.user?.is_active ?? false
</script>

<template>
  <Dialog :open="open" @update:open="(v) => !v && emit('cancel')">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>
          {{ isDeactivating() ? 'Deactivate User' : 'Reactivate User' }}
        </DialogTitle>
        <DialogDescription v-if="user">
          <template v-if="isDeactivating()">
            Deactivate <strong>{{ user.name }}</strong>? Their account will be locked immediately.
          </template>
          <template v-else>
            Reactivate <strong>{{ user.name }}</strong>? They will be able to sign in again.
          </template>
        </DialogDescription>
      </DialogHeader>

      <div v-if="isDeactivating()" class="confirmation-warning">
        All active sessions and API tokens for this user will be invalidated immediately.
      </div>

      <DialogFooter>
        <Button variant="outline" :disabled="loading" @click="emit('cancel')">Cancel</Button>
        <Button
          :variant="isDeactivating() ? 'destructive' : 'default'"
          :disabled="loading"
          @click="emit('confirm')"
        >
          <template v-if="loading">
            {{ isDeactivating() ? 'Deactivating…' : 'Reactivating…' }}
          </template>
          <template v-else>
            {{ isDeactivating() ? 'Deactivate' : 'Reactivate' }}
          </template>
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
