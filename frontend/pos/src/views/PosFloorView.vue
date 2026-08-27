<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import StubPanel from '@/components/StubPanel.vue'
import { usePosShortcuts } from '@/composables/usePosShortcuts'
import { useAuthStore } from '@/stores/auth'
import { usePosStore } from '@/stores/pos'

const auth = useAuthStore()
const pos = usePosStore()
const router = useRouter()

const searchQuery = ref('')
const editingTerminal = ref(false)
const terminalDraft = ref(pos.terminalLabel)

usePosShortcuts()

onMounted(() => {
  document.getElementById('pos-search')?.focus()
})

function startEditTerminal() {
  terminalDraft.value = pos.terminalLabel
  editingTerminal.value = true
}

function saveTerminal() {
  pos.setTerminalLabel(terminalDraft.value)
  editingTerminal.value = false
}

async function changeStore() {
  pos.clearStore()
  await router.push('/select-store')
}

async function signOut() {
  await auth.logout()
  pos.clearStore()
  await router.replace('/login')
}
</script>

<template>
  <div class="flex h-full min-h-0 flex-col">
    <!-- Top chrome -->
    <header
      class="flex flex-shrink-0 flex-wrap items-center gap-3 bg-[var(--chrome)] px-4 py-2.5 text-[13px] text-[var(--chrome-fg)]"
    >
      <strong class="font-display text-sm">{{ pos.selectedStore?.name ?? 'Store' }}</strong>

      <span class="flex items-center gap-1.5">
        <span class="text-white/60">Terminal</span>
        <template v-if="editingTerminal">
          <input
            v-model="terminalDraft"
            class="min-h-11 rounded border border-white/30 bg-white/10 px-2 text-[var(--chrome-fg)] outline-none"
            aria-label="Terminal label"
            @keydown.enter="saveTerminal"
            @keydown.esc.stop="editingTerminal = false"
          />
          <button
            type="button"
            class="min-h-11 rounded border border-white/20 px-2 hover:bg-white/10"
            @click="saveTerminal"
          >
            Save
          </button>
        </template>
        <button
          v-else
          type="button"
          class="min-h-11 rounded px-1.5 hover:bg-white/10"
          title="Edit terminal label"
          @click="startEditTerminal"
        >
          {{ pos.terminalLabel }}
        </button>
      </span>

      <span>{{ auth.displayName }}</span>
      <span class="text-white/60">Session: —</span>

      <span class="flex-1" />

      <span
        class="inline-flex items-center gap-1.5 rounded border border-[var(--ember)] bg-[var(--online)] px-2.5 py-1 text-xs font-semibold tracking-wide text-white"
        title="Sync status"
      >
        <span class="inline-block h-1.5 w-1.5 rounded-full bg-white" aria-hidden="true" />
        ONLINE
      </span>

      <button
        type="button"
        class="min-h-11 rounded border border-white/20 px-3 hover:bg-white/10"
        @click="changeStore"
      >
        Change store
      </button>
      <button
        type="button"
        class="min-h-11 rounded border border-white/20 px-3 hover:bg-white/10"
        @click="signOut"
      >
        Sign out
      </button>
    </header>

    <!-- Workspace: search + cart -->
    <div class="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[1fr_360px]">
      <section class="flex min-h-0 flex-col gap-3 p-3">
        <div class="flex flex-wrap gap-2">
          <input
            id="pos-search"
            v-model="searchQuery"
            type="search"
            autofocus
            placeholder="Scan or search (F2) — SKU, barcode, name"
            class="min-h-12 min-w-[12rem] flex-1 rounded-[var(--radius)] border-2 border-[var(--primary)] bg-[var(--panel)] px-3.5 text-base outline-none"
            autocomplete="off"
          />
          <button
            type="button"
            class="min-h-12 rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] px-4 text-sm"
            @click="pos.openStub('customer')"
          >
            Customer F4
          </button>
          <button
            type="button"
            class="min-h-12 rounded-[var(--radius)] border border-[var(--ember)] bg-[var(--panel)] px-4 text-sm text-[var(--ember)]"
            @click="pos.openStub('discount')"
          >
            Discount F8
          </button>
        </div>

        <div class="flex flex-wrap gap-2">
          <span
            class="rounded border border-[var(--primary)] bg-[var(--panel)] px-3 py-1.5 text-sm font-semibold text-[var(--primary)]"
          >
            All
          </span>
          <span class="rounded border border-[var(--line)] bg-[var(--panel)] px-3 py-1.5 text-sm text-[var(--muted)]">
            Categories soon
          </span>
        </div>

        <div
          class="grid flex-1 content-start gap-2.5 overflow-auto rounded-[var(--radius)] border border-dashed border-[var(--line)] bg-[var(--panel)]/50 p-6 text-center text-[var(--muted)]"
        >
          <p class="text-sm">Scan or search to start</p>
          <p class="text-xs">Product results arrive in story 4.4</p>
        </div>
      </section>

      <aside
        class="flex min-h-0 flex-col border-t border-[var(--line)] bg-[var(--panel)] lg:border-t-0 lg:border-l"
      >
        <div class="flex items-center justify-between border-b border-[var(--line)] px-3.5 py-3">
          <h2 class="font-display text-base font-semibold">Cart</h2>
          <span class="text-xs text-[var(--muted)]">0 lines</span>
        </div>

        <button
          type="button"
          class="mx-3.5 mt-2.5 flex min-h-11 items-center justify-between rounded-[var(--radius)] bg-[var(--surface)] px-3 py-2.5 text-left text-sm"
          @click="pos.openStub('customer')"
        >
          <span>
            <strong>Walk-in</strong>
            <span class="mt-0.5 block text-xs text-[var(--muted)]">F4 to select account</span>
          </span>
          <span class="text-[var(--muted)]">Change</span>
        </button>

        <div class="flex flex-1 items-center justify-center px-3.5 text-sm text-[var(--muted)]">
          Empty cart
        </div>

        <div class="border-t border-[var(--line)] p-3.5">
          <div class="mb-1.5 flex justify-between text-sm text-[var(--muted)]">
            <span>Subtotal</span>
            <span class="font-mono-pos">—</span>
          </div>
          <div class="mb-1.5 flex justify-between text-sm text-[var(--muted)]">
            <span>Tax</span>
            <span class="font-mono-pos">—</span>
          </div>
          <div class="mb-3.5 flex items-baseline justify-between">
            <label class="text-sm font-semibold">Total</label>
            <div class="font-display text-[40px] font-bold leading-none tracking-tight text-[var(--primary)]">
              —
            </div>
          </div>

          <div class="grid grid-cols-2 gap-2">
            <button
              type="button"
              class="min-h-11 rounded-[var(--radius)] border border-[var(--line)] bg-[var(--surface)] text-sm disabled:opacity-50"
              disabled
            >
              Hold
            </button>
            <button
              type="button"
              class="min-h-11 rounded-[var(--radius)] border border-[var(--line)] bg-[var(--surface)] text-sm text-[var(--danger)] disabled:opacity-50"
              disabled
            >
              Void
            </button>
            <button
              type="button"
              class="font-display col-span-2 min-h-14 rounded-[var(--radius)] bg-[var(--primary)] text-lg font-semibold text-[var(--primary-fg)] hover:opacity-95"
              title="Payment stub (real pay gating in later stories)"
              @click="pos.openStub('payment')"
            >
              Pay · F9
            </button>
          </div>
        </div>

        <div
          class="border-t border-[var(--ember)]/40 bg-[var(--ember)]/5 px-3.5 py-2 text-[11px] text-[var(--ember)]"
        >
          F2 search · F4 customer · F8 discount · F9 pay · Esc cancel
        </div>
      </aside>
    </div>

    <StubPanel />
  </div>
</template>
