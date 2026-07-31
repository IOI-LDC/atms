import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useUiStore = defineStore('ui', () => {
  const mobileNavOpen = ref(false)
  const sidebarCollapsed = ref(false)

  function openMobileNav() {
    mobileNavOpen.value = true
  }
  function closeMobileNav() {
    mobileNavOpen.value = false
  }
  function toggleMobileNav() {
    mobileNavOpen.value = !mobileNavOpen.value
  }
  function toggleSidebar() {
    sidebarCollapsed.value = !sidebarCollapsed.value
  }

  return {
    mobileNavOpen,
    openMobileNav,
    closeMobileNav,
    toggleMobileNav,
    sidebarCollapsed,
    toggleSidebar,
  }
})
