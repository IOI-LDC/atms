# Vue 3.5 Idioms & Composable-First Patterns

Reference for Guardrails 1 and 5. Load when writing logic, composables, stores, or using 3.5 features.

## Composable-First Rules

- **Name** `useXxx` (camelCase, `use` prefix). One concern per composable.
- **Return** `{ state, actions }` — refs/getters for state, plain functions for actions. Keep the public surface small.
- **Pure logic only.** A composable may own reactive state, watchers, timers, async, and browser APIs. It must NOT render DOM or import `.vue` components.
- **Cleanup** with `onScopeDispose` (preferred) / `onUnmounted` — clear intervals, listeners, and abort controllers. Never leak.
- **Sharing state:** `provide`/`inject` for a localized service bound to a subtree; Pinia for app-wide shared state (auth/session/settings). Never duplicate the same reactive state in two places.
- **When NOT to extract:** a single one-off handler with no reuse and no complexity can stay in the component. The moment it has logic, state, reuse, or a test — extract it.

### Composable shape

```ts
// useWorkOrders.ts
import { ref, shallowRef } from 'vue'
import type { WorkOrder, WorkOrderFilter } from '@/types'

export function useWorkOrders() {
  const items = shallowRef<WorkOrder[]>([])   // shallowRef for large lists
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetch() { /* … */ }
  function applyFilter(f: WorkOrderFilter) { /* … */ }

  onScopeDispose(() => { /* abort in-flight, clear listeners */ })

  return { items, loading, error, fetch, applyFilter }   // { state, actions }
}
```

The component only wires it:

```vue
<script setup lang="ts">
import { useWorkOrders } from '@/composables/useWorkOrders'
const { items, loading, fetch } = useWorkOrders()
</script>
```

## Vue 3.5 Idiom Cheatsheet

**`<script setup>` + TS generics** for props/emits; reactive prop destructuring with defaults is stable in 3.5.

```ts
const props = defineProps<{ title: string; count?: number }>()
const emit = defineEmits<{ select: [id: string]; change: [value: number] }>()
```

**`defineModel<T>()`** for two-way binding without a manual emit:

```ts
const model = defineModel<string>({ required: true })
```

**`useTemplateRef('name')`** (3.5) instead of `ref(null)` + `ref="name"`.

**`useId()`** for stable unique ids (label ↔ control a11y associations).

**`defineSlots`** to type slot props; **`defineExpose`** only when a parent truly needs imperative access.

**`shallowRef`** for large collections / objects you replace wholesale — avoids deep-reactivity cost.

**`watchEffect`** auto-tracks dependencies; **`watch`** with explicit source(s) for precise control and old/new values.

**Discriminated unions** for status drive both types and `status-*` classes:

```ts
type WorkOrderStatus = 'open' | 'in-progress' | 'completed' | 'closed' | 'cancelled'
```

## State-Management Discipline

- Pinia = **auth, session, settings, and genuinely cross-component state**. That's it.
- Component-local UI state (open/closed, active tab, form draft) stays in the component.
- Feature data + its fetching/filtering live in a composable, not a store, unless multiple unrelated views need the same source.
- A store read by only one component is a smell — move it into a composable.
- Never store values you can derive; use getters/`computed` instead.

## TypeScript Discipline

- Type props, emits, model, and slots. No `any` in public signatures.
- Prefer `interface` / `type` and string-literal unions over loose `Record` or `any`.
- Co-locate domain types under `types/` (or `@/types`) and import via the `@/*` alias.
- Use string-literal unions for statuses/enums (tree-shakeable, template-friendly) rather than runtime enums.
