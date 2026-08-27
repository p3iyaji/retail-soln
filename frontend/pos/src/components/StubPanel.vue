<script setup lang="ts">
import { computed, nextTick, watch } from 'vue'
import { usePosStore, type StubPanel } from '@/stores/pos'

const pos = usePosStore()

const titles: Record<Exclude<StubPanel, null>, string> = {
  customer: 'Customer lookup',
  discount: 'Discount',
  payment: 'Payment',
}

const storyHints: Record<Exclude<StubPanel, null>, string> = {
  customer: 'Coming in a later story',
  discount: 'Line discount engine deferred — use price override when permitted',
  payment: 'Coming in story 4.5+',
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
        class="w-full max-w-md rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] shadow-lg"
      >
        <div class="flex items-center justify-between border-b border-[var(--line)] px-4 py-3">
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
        <div class="px-4 py-6 text-[var(--muted)]">
          <p>{{ hint }}</p>
          <p class="mt-2 text-sm">Shell stub — no customer / discount / payment completion yet.</p>
        </div>
      </div>
    </div>
  </Teleport>
</template>
