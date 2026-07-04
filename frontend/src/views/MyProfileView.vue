<script setup lang="ts">
import { RouterLink } from 'vue-router'
import AppLayout from '@/components/app/AppLayout.vue'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { ChevronRight } from '@lucide/vue'
import { useUserProfile } from '@/composables/useUserProfile'
import { fmtDate } from '@/lib/displayHelpers'
import { FEATURE_CHANGE_PASSWORD } from '@/lib/features'

const { user, displayName, roleName, isEmailVerified, isActive, summaryPoints, quickActions } =
  useUserProfile()
</script>

<template>
  <AppLayout>
    <div class="page-section">
      <div class="page-header">
        <div class="page-heading">
          <h1 class="page-title">My Profile</h1>
          <p class="page-subtitle">Your account details, role, and access</p>
        </div>
      </div>

      <div v-if="user" class="profile-content">
        <!-- Account details -->
        <div class="data-card">
          <div class="data-card-header">
            <h2 class="data-card-title">Account</h2>
          </div>
          <div class="detail-card-content">
            <div class="detail-grid">
              <div class="detail-field">
                <span class="detail-field-label">Name</span>
                <p class="detail-field-value">{{ displayName }}</p>
              </div>
              <div class="detail-field">
                <span class="detail-field-label">Email</span>
                <p class="detail-field-value">{{ user.email }}</p>
              </div>
              <div class="detail-field">
                <span class="detail-field-label">Email verification</span>
                <p class="detail-field-value">
                  <span
                    :class="[
                      'status-badge',
                      isEmailVerified ? 'status-closed' : 'status-cancelled',
                    ]"
                  >
                    {{ isEmailVerified ? 'Verified' : 'Not verified' }}
                  </span>
                </p>
              </div>
              <div class="detail-field">
                <span class="detail-field-label">Employee ID</span>
                <p class="detail-field-value">{{ user.emp_id ?? '—' }}</p>
              </div>
              <div class="detail-field">
                <span class="detail-field-label">Role</span>
                <p class="detail-field-value">{{ roleName }}</p>
              </div>
              <div class="detail-field">
                <span class="detail-field-label">Account status</span>
                <p class="detail-field-value">
                  <span :class="['status-badge', isActive ? 'status-closed' : 'status-rejected']">
                    {{ isActive ? 'Active' : 'Inactive' }}
                  </span>
                </p>
              </div>
              <div class="detail-field">
                <span class="detail-field-label">Member since</span>
                <p class="detail-field-value">{{ fmtDate(user.created_at) }}</p>
              </div>
              <div class="detail-field">
                <span class="detail-field-label">Last updated</span>
                <p class="detail-field-value">{{ fmtDate(user.updated_at) }}</p>
              </div>
            </div>

            <p class="detail-banner">
              <template v-if="FEATURE_CHANGE_PASSWORD">
                You can change your password from the account menu. Editing your name or email is
                not available yet — contact an administrator if any detail is incorrect.
              </template>
              <template v-else>
                Self-service editing — change password, update name or email — is not available yet.
                Contact an administrator if any detail above is incorrect.
              </template>
            </p>
          </div>
        </div>

        <!-- Role & access -->
        <div class="data-card">
          <div class="data-card-header">
            <h2 class="data-card-title">Role &amp; Access</h2>
            <Badge variant="secondary">{{ roleName }}</Badge>
          </div>
          <div class="detail-card-content">
            <div v-if="summaryPoints.length > 0" class="detail-section">
              <p class="detail-section-title">What your role can do</p>
              <ul class="profile-capability-list">
                <li v-for="point in summaryPoints" :key="point" class="profile-capability-item">
                  {{ point }}
                </li>
              </ul>
            </div>

            <div v-if="quickActions.length > 0" class="detail-section">
              <p class="detail-section-title">Quick links</p>
              <div class="quick-actions-grid">
                <Button
                  v-for="action in quickActions"
                  :key="action.label"
                  variant="outline"
                  class="profile-quick-action"
                  as-child
                >
                  <RouterLink :to="action.to">
                    <component :is="action.icon" />
                    <span>{{ action.label }}</span>
                    <ChevronRight class="profile-quick-action-chevron" />
                  </RouterLink>
                </Button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-else class="data-card">
        <div class="empty-state">
          <p class="empty-state-title">Account unavailable</p>
          <p class="empty-state-description">Your account details could not be loaded.</p>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
