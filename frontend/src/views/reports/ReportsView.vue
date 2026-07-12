<script setup lang="ts">
import AppLayout from '@/components/app/AppLayout.vue'
import { useReportCatalog } from '@/composables/useReportCatalog'

const { themes, statusMeta } = useReportCatalog()
</script>

<template>
  <AppLayout>
    <div class="page-section">
      <div class="page-header">
        <div class="page-heading">
          <h1 class="page-title">Reports</h1>
          <p class="page-subtitle">
            Read-only operational reports over ATMS data. Each report opens with a shared filter bar
            and CSV export once its data is ready — the catalogue below is what's planned.
          </p>
        </div>
      </div>

      <section v-for="theme in themes" :key="theme.key" class="report-theme">
        <header class="report-theme-header">
          <component :is="theme.icon" class="report-theme-icon" aria-hidden="true" />
          <h2 class="report-theme-title">{{ theme.title }}</h2>
          <span class="report-theme-count">{{ theme.items.length }}</span>
        </header>

        <div class="card-grid">
          <article v-for="item in theme.items" :key="item.id" class="data-card report-card">
            <div class="data-card-header">
              <h3 class="data-card-title">
                <span class="report-card-id">{{ item.id }}</span>
                {{ item.title }}
              </h3>
              <span :class="['status-badge', statusMeta[item.status].badgeClass]">
                {{ statusMeta[item.status].label }}
              </span>
            </div>
            <div class="data-card-content">
              <p class="data-card-description">{{ item.question }}</p>
              <p v-if="item.note" class="report-card-note">{{ item.note }}</p>
            </div>
          </article>
        </div>
      </section>
    </div>
  </AppLayout>
</template>
