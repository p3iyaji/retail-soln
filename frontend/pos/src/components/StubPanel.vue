<script setup lang="ts">
import { computed, nextTick, watch } from 'vue'
import PaymentPanel from '@/components/PaymentPanel.vue'
import HeldPanel from '@/components/HeldPanel.vue'
import ReturnPanel from '@/components/ReturnPanel.vue'
import SessionPanel from '@/components/SessionPanel.vue'
import { usePosStore, type StubPanel } from '@/stores/pos'

const pos = usePosStore()

const titles: Record<Exclude<StubPanel, null>, string> = {
  customer: 'Customer lookup',
  discount: 'Discount',
  payment: 'Payment',
  held: 'Held sales',
  session: 'Register session',
  return: 'Return & refund',
}

const storyHints: Record<Exclude<StubPanel, null>, string> = {
  customer: 'Coming in a later story',
  discount: 'Line discount engine deferred — use price override when permitted',
  payment: 'Tender cash/card to complete the sale',
  held: 'Tap a sale to resume it in the cart',
  session: 'Open, manage cash, or close your register session',
  return: 'Look up a completed sale and issue a partial or full refund',
}

const open = computed(() => pos.activeStub != null)
const title = computed(() => (pos.activeStub ? titles[pos.activeStub] : ''))
const hint = computed(() => (pos.activeStub ? storyHints[pos.activeStub] : ''))

function onBackdrop() {
  pos.closeStub()
}

watch(
  () => pos.activeStub,
  async (panel) => {
    if (!panel) return
    await nextTick()
    const root = document.querySelector('[data-stub-panel]')
    const focusable = root?.querySelector<HTMLElement>(
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
    )
    focusable?.focus()
  },
)
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      role="dialog"
      aria-modal="true"
      :aria-label="title"
      @click.self="onBackdrop"
    >
      <div
        data-stub-panel
        class="flex max-h-[min(90vh,640px)] w-full max-w-md flex-col rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] shadow-lg"
      >
        <div class="flex flex-shrink-0 items-center justify-between border-b border-[var(--line)] px-4 py-3">
          <h2 class="font-display text-lg font-semibold">{{ title }}</h2>
          <button
            type="button"
            class="min-h-11 min-w-11 rounded-[var(--radius)] text-[var(--muted)] hover:bg-[var(--surface)]"
            aria-label="Close"
            @click="pos.closeStub()"
          >
            Esc
          </button>
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto">
          <PaymentPanel v-if="pos.activeStub === 'payment'" />
          <HeldPanel v-else-if="pos.activeStub === 'held'" />
          <SessionPanel v-else-if="pos.activeStub === 'session'" />
        <ReturnPanel v-else-if="pos.activeStub === 'return'" />
          <div v-else class="px-4 py-6 text-[var(--muted)]">
            <p>{{ hint }}</p>
            <p class="mt-2 text-sm">Shell stub — no customer / discount completion yet.</p>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
