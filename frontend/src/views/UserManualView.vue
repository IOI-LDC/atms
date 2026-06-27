<script setup lang="ts">
import AppLayout from '@/components/app/AppLayout.vue'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { BookOpen, Printer, Search } from '@lucide/vue'
import { useUserManual } from '@/composables/useUserManual'

const { html, filteredToc, searchQuery, activeId, scrollToSection, printManual } = useUserManual()
</script>

<template>
  <AppLayout>
    <div class="manual-page">
      <!-- Hero: title + print -->
      <header class="manual-hero">
        <div class="manual-hero-heading">
          <span class="manual-hero-icon" aria-hidden="true">
            <BookOpen />
          </span>
          <div>
            <h1 class="page-title">User Manual</h1>
            <p class="page-subtitle">
              Complete guide to maintenance requests, work orders, assets and parts in ATMS.
            </p>
          </div>
        </div>
        <Button variant="outline" @click="printManual">
          <Printer />
          Print
        </Button>
      </header>

      <div class="manual-grid">
        <!-- Table of contents -->
        <nav class="manual-toc" aria-label="Table of contents">
          <div class="manual-toc-search">
            <Search class="manual-toc-search-icon" aria-hidden="true" />
            <Input
              v-model="searchQuery"
              type="search"
              placeholder="Search topics…"
              aria-label="Search the user manual"
            />
          </div>

          <ul class="manual-toc-list">
            <li v-for="section in filteredToc" :key="section.id">
              <Button
                variant="ghost"
                class="manual-toc-link"
                :class="{ 'manual-toc-link-active': activeId === section.id }"
                @click="scrollToSection(section.id)"
              >
                {{ section.title }}
              </Button>
              <ul v-if="section.children.length" class="manual-toc-sublist">
                <li v-for="child in section.children" :key="child.id">
                  <Button
                    variant="ghost"
                    class="manual-toc-sublink"
                    :class="{ 'manual-toc-sublink-active': activeId === child.id }"
                    @click="scrollToSection(child.id)"
                  >
                    {{ child.title }}
                  </Button>
                </li>
              </ul>
            </li>
          </ul>

          <p v-if="!filteredToc.length" class="manual-toc-empty">No topics found</p>
        </nav>

        <!-- Rendered manual -->
        <article class="manual-content" v-html="html" />
      </div>
    </div>
  </AppLayout>
</template>
