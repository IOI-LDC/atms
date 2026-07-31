<script setup lang="ts">
import type { SelectContentEmits, SelectContentProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import { computed } from 'vue'
import { reactiveOmit } from '@vueuse/core'
import { SelectContent, SelectPortal, SelectViewport, useForwardPropsEmits } from 'reka-ui'
import { cn } from '@/lib/utils'
import { SelectScrollDownButton, SelectScrollUpButton } from '.'

defineOptions({
  inheritAttrs: false,
})

const props = withDefaults(
  defineProps<
    SelectContentProps & {
      class?: HTMLAttributes['class']
      /**
       * Render the dropdown inline instead of teleporting it to <body>. Set this
       * when the Select lives inside a Dialog/Sheet: keeping the content within
       * the overlay's DOM stops reka-ui's focus-scope from applying aria-hidden
       * to the (focused) trigger's ancestor, which the browser blocks and warns
       * about. See https://w3c.github.io/aria/#aria-hidden.
       *
       * NOTE: inline rendering forces `position="popper"`. The default
       * `item-aligned` positioning assumes the content is mounted at <body> and
       * mispositions (jumps to the top-left/right) when rendered inline; popper
       * anchors to the trigger via floating-ui and works in both modes.
       */
      disablePortal?: boolean
    }
  >(),
  {
    position: 'item-aligned',
    align: 'center',
  },
)
const emits = defineEmits<SelectContentEmits>()

const delegatedProps = reactiveOmit(props, 'class', 'disablePortal', 'position')

const forwarded = useForwardPropsEmits(delegatedProps, emits)

// item-aligned positioning only works when teleported to <body>; inline it
// collapses to a corner. Force popper whenever the portal is disabled.
const resolvedPosition = computed(() => (props.disablePortal ? 'popper' : props.position))
</script>

<template>
  <SelectPortal :disabled="disablePortal">
    <SelectContent
      data-slot="select-content"
      v-bind="{ ...$attrs, ...forwarded }"
      :position="resolvedPosition"
      :data-align-trigger="resolvedPosition === 'item-aligned'"
      :class="
        cn(
          'bg-popover text-popover-foreground data-open:animate-in data-closed:animate-out data-closed:fade-out-0 data-open:fade-in-0 data-closed:zoom-out-95 data-open:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 ring-foreground/10 min-w-36 rounded-[3px] p-1 shadow-md ring-1 duration-100 data-[side=inline-start]:slide-in-from-right-2 data-[side=inline-end]:slide-in-from-left-2 cn-menu-translucent relative z-50 max-h-(--reka-select-content-available-height) origin-(--reka-select-content-transform-origin) overflow-x-hidden overflow-y-auto data-[align-trigger=true]:animate-none',
          resolvedPosition === 'popper' &&
            'data-[side=bottom]:translate-y-1 data-[side=left]:-translate-x-1 data-[side=right]:translate-x-1 data-[side=top]:-translate-y-1',
          props.class,
        )
      "
    >
      <SelectScrollUpButton />
      <SelectViewport
        :data-position="resolvedPosition"
        :class="
          cn(
            'data-[position=popper]:h-[var(--reka-select-trigger-height)] data-[position=popper]:w-full data-[position=popper]:min-w-[var(--reka-select-trigger-width)]',
          )
        "
      >
        <slot />
      </SelectViewport>
      <SelectScrollDownButton />
    </SelectContent>
  </SelectPortal>
</template>
