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
    // The client-facing /dashboard shows a placeholder until LDC provides their
    // own requirements — the real (assumption-based) dashboard is kept intact and
    // reachable internally at /dashboard-real (not linked in the sidebar).
    {
      path: '/dashboard',
      name: 'dashboard',
      component: () => import('@/views/DashboardPlaceholderView.vue'),
    },
    {
      path: '/dashboard-real',
      name: 'dashboard-real',
      component: () => import('@/views/DashboardView.vue'),
    },

    // ── My Profile (account details, role & access) ──────────────────────────
    {
      path: '/profile',
      name: 'profile',
      component: () => import('@/views/MyProfileView.vue'),
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
    // Primary Locations experience: search-first "Find & Move" view with tabs
    // (Asset Location Update + admin-only Manage Locations).
    {
      path: '/locations',
      name: 'locations',
      component: () => import('@/views/locations/LogisticsLocationView.vue'),
    },
    // Legacy tabbed Locations view (original Asset Location Update flow), kept
    // reachable at /locations2 during transition.
    {
      path: '/locations2',
      name: 'locations2',
      component: () => import('@/views/locations/LocationsView.vue'),
    },

    // ── Reports ───────────────────────────────────────────────────────────────
    // The client-facing /reports shows a placeholder until LDC provides their own
    // requirements — the real reports index is kept intact and reachable internally
    // at /reports-real (not linked in the sidebar). Individual /reports/:slug pages
    // below remain unchanged and continue to work.
    {
      path: '/reports',
      name: 'reports',
      component: () => import('@/views/reports/ReportsPlaceholderView.vue'),
    },
    {
      path: '/reports-real',
      name: 'reports-real',
      component: () => import('@/views/reports/ReportsView.vue'),
    },
    // Per-report pages (Pass 1 Must tier). Any authenticated role may view —
    // reports are org-wide program views (backend Gate: viewDashboard).
    {
      path: '/reports/asset-status-distribution',
      name: 'report-asset-status-distribution',
      component: () => import('@/views/reports/OperationalStatusReport.vue'),
    },
    {
      path: '/reports/assets-by-location',
      name: 'report-assets-by-location',
      component: () => import('@/views/reports/AssetsByLocationReport.vue'),
    },
    {
      path: '/reports/pm-compliance',
      name: 'report-pm-compliance',
      component: () => import('@/views/reports/PmComplianceReport.vue'),
    },
    {
      path: '/reports/overdue-pm',
      name: 'report-overdue-pm',
      component: () => import('@/views/reports/OverduePmReport.vue'),
    },
    {
      path: '/reports/wo-backlog',
      name: 'report-wo-backlog',
      component: () => import('@/views/reports/WorkOrderBacklogReport.vue'),
    },
    {
      path: '/reports/upcoming-pm-schedule',
      name: 'report-upcoming-pm-schedule',
      component: () => import('@/views/reports/UpcomingPmReport.vue'),
    },
    // Pass 2 (stable-contract subset)
    {
      path: '/reports/mtbf-failure-rate',
      name: 'report-mtbf-failure-rate',
      component: () => import('@/views/reports/MtbfReport.vue'),
    },
    {
      path: '/reports/mttr',
      name: 'report-mttr',
      component: () => import('@/views/reports/MttrReport.vue'),
    },
    {
      path: '/reports/bad-actor-analysis',
      name: 'report-bad-actor-analysis',
      component: () => import('@/views/reports/BadActorReport.vue'),
    },
    {
      path: '/reports/asset-booking',
      name: 'report-asset-booking',
      component: () => import('@/views/reports/BookingReport.vue'),
    },
    {
      path: '/reports/parts-consumption',
      name: 'report-parts-consumption',
      component: () => import('@/views/reports/PartsConsumptionReport.vue'),
    },
    {
      path: '/reports/meter-reading-progression',
      name: 'report-meter-reading-progression',
      component: () => import('@/views/reports/MeterProgressionReport.vue'),
    },
    {
      path: '/reports/pm-suppression-register',
      name: 'report-pm-suppression-register',
      component: () => import('@/views/reports/PmSuppressionReport.vue'),
    },
    // Pass 2 (reworked subset)
    {
      path: '/reports/pm-coverage',
      name: 'report-pm-coverage',
      component: () => import('@/views/reports/PmCoverageReport.vue'),
    },
    {
      path: '/reports/technician-workload',
      name: 'report-technician-workload',
      component: () => import('@/views/reports/TechnicianWorkloadReport.vue'),
    },
    {
      path: '/reports/throughput',
      name: 'report-throughput',
      component: () => import('@/views/reports/ThroughputReport.vue'),
    },
    {
      path: '/reports/asset-movement-log',
      name: 'report-asset-movement-log',
      component: () => import('@/views/reports/AssetMovementReport.vue'),
    },
    {
      path: '/reports/wo-form-results',
      name: 'report-wo-form-results',
      component: () => import('@/views/reports/FormResultsReport.vue'),
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
    {
      path: '/admin/wo-forms',
      name: 'admin-wo-forms',
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
