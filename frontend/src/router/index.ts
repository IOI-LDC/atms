import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth.store'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior: () => ({ top: 0 }),
  routes: [
    // ── Public auth routes ────────────────────────────────────────────────────
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/auth/LoginView.vue'),
      meta: { public: true },
    },
    {
      path: '/activate',
      name: 'activate',
      component: () => import('@/views/auth/ActivateView.vue'),
      meta: { public: true },
    },
    {
      path: '/forgot-password',
      name: 'forgot-password',
      component: () => import('@/views/auth/ForgotPasswordView.vue'),
      meta: { public: true },
    },
    {
      path: '/reset-password',
      name: 'reset-password',
      component: () => import('@/views/auth/ResetPasswordView.vue'),
      meta: { public: true },
    },

    // ── Root redirect ─────────────────────────────────────────────────────────
    {
      path: '/',
      redirect: '/dashboard',
    },

    // ── Dashboard ─────────────────────────────────────────────────────────────
    {
      path: '/dashboard',
      name: 'dashboard',
      component: () => import('@/views/DashboardView.vue'),
    },

    // ── Maintenance Requests ──────────────────────────────────────────────────
    {
      path: '/maintenance',
      name: 'maintenance',
      component: () => import('@/views/work-orders/WorkOrdersView.vue'),
    },
    {
      path: '/maintenance/requests/:requestId',
      name: 'maintenance-request-detail',
      component: () => import('@/views/work-orders/MaintenanceRequestDetailView.vue'),
    },

    // ── Work Orders ───────────────────────────────────────────────────────────
    {
      path: '/work-orders',
      name: 'work-orders',
      component: () => import('@/views/work-orders/WorkOrdersListView.vue'),
    },
    {
      path: '/work-orders/:workOrderId',
      name: 'work-order-detail',
      component: () => import('@/views/work-orders/WorkOrderDetailView.vue'),
    },

    // ── Assets ────────────────────────────────────────────────────────────────
    {
      path: '/assets',
      name: 'assets',
      component: () => import('@/views/assets/AssetsView.vue'),
    },
    {
      path: '/assets/:assetId',
      name: 'asset-detail',
      component: () => import('@/views/assets/AssetDetailView.vue'),
    },

    // ── Parts ─────────────────────────────────────────────────────────────────
    {
      path: '/parts',
      name: 'parts',
      component: () => import('@/views/parts/PartsView.vue'),
    },
    {
      path: '/parts/:partId',
      name: 'part-detail',
      component: () => import('@/views/parts/PartDetailView.vue'),
    },

    // ── Locations ─────────────────────────────────────────────────────────────
    {
      path: '/locations',
      name: 'locations',
      component: () => import('@/views/locations/LocationsView.vue'),
    },

    // ── User Manual ───────────────────────────────────────────────────────────
    {
      path: '/user-manual',
      name: 'user-manual',
      component: () => import('@/views/UserManualView.vue'),
    },

    // ── Admin ─────────────────────────────────────────────────────────────────
    {
      path: '/admin',
      redirect: '/admin/lists',
    },
    {
      path: '/admin/lists',
      name: 'admin-lists',
      component: () => import('@/views/admin/AdminView.vue'),
      meta: { requiresAdmin: true },
    },
    {
      path: '/admin/pm-rules',
      name: 'admin-pm-rules',
      component: () => import('@/views/admin/AdminView.vue'),
      // PM template configuration is Admin-only per plan §8. The detail route
      // below stays Admin+Manager because Managers reach it via deep links from
      // Asset Detail (where they manage assignments).
      meta: { requiresAdmin: true },
    },
    {
      path: '/admin/pm-rules/:ruleId',
      name: 'pm-rule-detail',
      component: () => import('@/views/pm-rules/PmRuleDetailView.vue'),
      meta: { requiresAdminOrManager: true },
    },
    {
      path: '/admin/users',
      name: 'admin-users',
      component: () => import('@/views/admin/AdminView.vue'),
      meta: { requiresAdmin: true },
    },

    // ── Settings ──────────────────────────────────────────────────────────────
    {
      path: '/settings/locations',
      redirect: { path: '/locations', query: { tab: 'manage-locations' } },
    },
    {
      path: '/settings/system',
      name: 'settings-system',
      component: () => import('@/views/admin/SystemSettingsView.vue'),
      meta: { requiresAdmin: true },
    },
    {
      path: '/settings/audit-logs',
      name: 'settings-audit-logs',
      component: () => import('@/views/admin/AuditLogsView.vue'),
      meta: { requiresAdmin: true },
    },

    // ── Legacy redirects (old /settings/... bookmarks) ────────────────────────
    {
      path: '/settings/users',
      redirect: '/admin/users',
    },
    {
      path: '/settings/lists',
      redirect: '/admin/lists',
    },
    {
      path: '/settings/pm-rules',
      redirect: '/admin/pm-rules',
    },
    {
      path: '/settings/pm-rules/:ruleId',
      redirect: (to) => `/admin/pm-rules/${to.params.ruleId}`,
    },
    {
      path: '/pm-rules',
      redirect: '/admin/pm-rules',
    },
    {
      path: '/pm-rules/:ruleId',
      redirect: (to) => `/admin/pm-rules/${to.params.ruleId}`,
    },

    // ── Errors ────────────────────────────────────────────────────────────────
    {
      path: '/403',
      name: 'forbidden',
      component: () => import('@/views/errors/ForbiddenView.vue'),
      meta: { public: true },
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/views/errors/NotFoundView.vue'),
      meta: { public: true },
    },
  ],
})

router.beforeEach(async (to) => {
  if (to.meta.public) return true

  const auth = useAuthStore()

  // Always await the probe when unauthenticated. fetchCurrentUser is
  // single-flight, so concurrent navigations share one /auth/me call rather
  // than one of them skipping the fetch and redirecting to login prematurely.
  if (!auth.isAuthenticated) {
    const ok = await auth.fetchCurrentUser()
    if (!ok) return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.meta.requiresAdmin && !auth.isAdmin) {
    return { name: 'forbidden' }
  }

  if (to.meta.requiresAdminOrManager && !auth.isAdminOrManager) {
    return { name: 'forbidden' }
  }

  return true
})

export default router
