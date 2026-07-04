/**
 * Feature flags. Each flag reads a Vite build-time env var (set in `.env` /
 * `.env.production`) and can be flipped by rebuilding the SPA (`npm run build`).
 * Vite inlines env vars at build time, so a rebuild is required for any change
 * to take effect.
 */

/**
 * Self-service "Change password" in the user dropdown + the
 * `useChangePassword` composable. Backend `POST /api/auth/change-password` is
 * live, so the flag defaults ON. Set `VITE_FEATURE_CHANGE_PASSWORD=false` to
 * disable it in an environment where the endpoint isn't reachable yet.
 */
export const FEATURE_CHANGE_PASSWORD: boolean =
  import.meta.env.VITE_FEATURE_CHANGE_PASSWORD !== 'false'
