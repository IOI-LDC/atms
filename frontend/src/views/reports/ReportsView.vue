<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { ArrowRight, CircleCheck } from '@lucide/vue'
import AppLayout from '@/components/app/AppLayout.vue'
import { useReportCatalog } from '@/composables/useReportCatalog'

const { availableReports, plannedThemes, statusMeta } = useReportCatalog()
</script>

<template>
  <AppLayout>
    <div class="page-section">
      <div class="page-header">
        <div class="page-heading">
          <h1 class="page-title">Reports</h1>
          <p class="page-subtitle">
            Read-only operational reports over ATMS data. Available reports open with a shared filter
            bar; more are on the way.
          </p>
        </div>
      </div>

      <!-- ── Available now ──────────────────────────────────────────────────── -->
      <section v-if="availableReports.length" class="report-theme">
        <header class="report-theme-header">
          <CircleCheck class="report-theme-icon" aria-hidden="true" />
          <h2 class="report-theme-title">Available now</h2>
          <span class="report-theme-count">{{ availableReports.length }}</span>
        </header>

        <div class="card-grid">
          <RouterLink
            v-for="item in availableReports"
            :key="item.id"
            :to="`/reports/${item.slug}`"
            class="data-card report-card report-card-available"
          >
            <div class="data-card-header">
              <h3 class="data-card-title">
                <span class="report-card-id">{{ item.id }}</span>
                {{ item.title }}
              </h3>
            </div>
            <div class="data-card-content">
              <p class="data-card-description">{{ item.question }}</p>
              <span class="report-card-open">
                Open report <ArrowRight class="report-card-open-icon" aria-hidden="true" />
              </span>
            </div>
          </RouterLink>
        </div>
      </section>

      <!-- ── Planned (grouped by theme; deferred hidden) ────────────────────── -->
      <template v-if="plannedThemes.length">
        <p class="report-section-label">Planned</p>

        <section v-for="theme in plannedThemes" :key="theme.key" class="report-theme">
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
      </template>
    </div>
  </AppLayout>
</template>
