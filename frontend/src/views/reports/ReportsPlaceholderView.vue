<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { ArrowRight } from '@lucide/vue'
import AppLayout from '@/components/app/AppLayout.vue'
import { useReportCatalog } from '@/composables/useReportCatalog'

const { mustTierThemes } = useReportCatalog()
</script>

<template>
  <AppLayout>
    <div class="page-section">
      <div class="page-header">
        <div class="page-heading">
          <h1 class="page-title">Reports</h1>
          <p class="page-subtitle">
            Read-only operational reports over ATMS data. The core Must-tier reports are available
            now; the full suite follows LDC's requirements.
          </p>
        </div>
      </div>

      <!-- ── Pass 1 Must-tier reports grouped by theme ────────────────────── -->
      <section v-for="theme in mustTierThemes" :key="theme.key" class="report-theme">
        <header class="report-theme-header">
          <component :is="theme.icon" class="report-theme-icon" aria-hidden="true" />
          <h2 class="report-theme-title">{{ theme.title }}</h2>
          <span class="report-theme-count">{{ theme.items.length }}</span>
        </header>

        <div class="card-grid">
          <RouterLink
            v-for="item in theme.items"
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
    </div>
  </AppLayout>
</template>
