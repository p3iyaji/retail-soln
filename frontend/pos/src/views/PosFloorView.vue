<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import StubPanel from '@/components/StubPanel.vue'
import { usePosShortcuts } from '@/composables/usePosShortcuts'
import { activeVariants, type SearchProduct, type SearchVariant } from '@/lib/cartPricing'
import { decimalCmp, isDecimalString } from '@/lib/decimal'
import { useAuthStore } from '@/stores/auth'
import { usePosStore } from '@/stores/pos'

const auth = useAuthStore()
const pos = usePosStore()
const router = useRouter()

const editingTerminal = ref(false)
const terminalDraft = ref(pos.terminalLabel)
const overrideLineKey = ref<string | null>(null)
const overrideDraft = ref('')
const toast = ref<string | null>(null)
let toastTimer: ReturnType<typeof setTimeout> | null = null

usePosShortcuts()

const localSearch = computed({
  get: () => pos.searchQuery,
  set: (v: string) => pos.setSearchQuery(v),
})

/** Banner only — cart/search errors; toast reserved for local UI validation. */
const flashMessage = computed(() => pos.cartError ?? pos.searchError)

watch(overrideLineKey, async (key) => {
  if (!key) return
  await nextTick()
  document.getElementById('pos-override-price')?.focus()
})

onMounted(async () => {
  document.getElementById('pos-search')?.focus()
  window.addEventListener('keydown', onGlobalKeydown, true)
  await pos.loadCategories()
  await pos.runSearch('', { immediate: true, allowAutoAdd: false })
})

onUnmounted(() => {
  window.removeEventListener('keydown', onGlobalKeydown, true)
  if (toastTimer) clearTimeout(toastTimer)
})

function onGlobalKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape' && overrideLineKey.value) {
    event.preventDefault()
    event.stopImmediatePropagation()
    closeOverride()
  }
}

function showToast(message: string) {
  toast.value = message
  if (toastTimer) clearTimeout(toastTimer)
  toastTimer = setTimeout(() => {
    toast.value = null
  }, 3500)
}

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

async function onSearchEnter() {
  if (pos.searchResults.length === 0) return
  await pos.addFirstHit()
  document.getElementById('pos-search')?.focus()
}

function openOverride(lineKey: string, currentUnit: string) {
  if (!pos.allowPriceOverride) return
  overrideLineKey.value = lineKey
  overrideDraft.value = currentUnit
}

function closeOverride() {
  overrideLineKey.value = null
  overrideDraft.value = ''
}

async function applyOverride() {
  const key = overrideLineKey.value
  if (key == null) return
  const raw = overrideDraft.value.trim()
  if (!isDecimalString(raw) || decimalCmp(raw, '0') <= 0) {
    showToast('Enter a price greater than zero (up to 4 decimal places)')
    return
  }
  await pos.setPriceOverride(key, raw)
  closeOverride()
  document.getElementById('pos-search')?.focus()
}

async function clearOverride(lineKey: string) {
  await pos.clearPriceOverride(lineKey)
}

function productVariants(product: SearchProduct): SearchVariant[] {
  return activeVariants(product)
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

    <div
      v-if="flashMessage || toast"
      class="bg-[var(--ember)]/15 px-4 py-2 text-sm text-[var(--ember)]"
      role="status"
    >
      {{ flashMessage || toast }}
    </div>

    <!-- Workspace: search + cart -->
    <div class="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[1fr_360px]">
      <section class="flex min-h-0 flex-col gap-3 p-3">
        <div class="flex flex-wrap gap-2">
          <input
            id="pos-search"
            v-model="localSearch"
            type="search"
            autofocus
            placeholder="Scan or search (F2) — SKU, barcode, name"
            class="min-h-12 min-w-[12rem] flex-1 rounded-[var(--radius)] border-2 border-[var(--primary)] bg-[var(--panel)] px-3.5 text-base outline-none"
            autocomplete="off"
            @keydown.enter.prevent="onSearchEnter"
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

        <div class="flex flex-wrap items-center gap-2">
          <button
            type="button"
            class="min-h-11 rounded border px-3 py-1.5 text-sm font-semibold"
            :class="
              pos.categoryId == null
                ? 'border-[var(--primary)] bg-[var(--panel)] text-[var(--primary)]'
                : 'border-[var(--line)] bg-[var(--panel)] text-[var(--muted)]'
            "
            @click="pos.setCategoryFilter(null)"
          >
            All
          </button>
          <button
            v-for="cat in pos.categories"
            :key="cat.id"
            type="button"
            class="min-h-11 rounded border px-3 py-1.5 text-sm"
            :class="
              pos.categoryId === cat.id
                ? 'border-[var(--primary)] bg-[var(--panel)] font-semibold text-[var(--primary)]'
                : 'border-[var(--line)] bg-[var(--panel)] text-[var(--muted)]'
            "
            @click="pos.setCategoryFilter(cat.id)"
          >
            {{ cat.name }}
          </button>
          <span
            v-if="pos.searchElapsedMs != null"
            class="ml-auto font-mono-pos text-xs text-[var(--muted)]"
            title="Search API elapsed"
          >
            {{ pos.searchElapsedMs.toFixed(1) }}ms
            <span v-if="pos.searchLoading"> · …</span>
          </span>
        </div>

        <div
          class="grid flex-1 content-start gap-2.5 overflow-auto rounded-[var(--radius)] border border-dashed border-[var(--line)] bg-[var(--panel)]/50 p-3"
          :class="pos.searchResults.length ? 'sm:grid-cols-2 xl:grid-cols-3' : ''"
        >
          <template v-if="pos.searchResults.length">
            <article
              v-for="product in pos.searchResults"
              :key="product.id"
              class="rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] p-3"
            >
              <h3 class="font-display text-sm font-semibold">{{ product.name }}</h3>
              <p class="mt-0.5 font-mono-pos text-xs text-[var(--muted)]">
                {{ product.sku }}
                <span v-if="product.barcode"> · {{ product.barcode }}</span>
              </p>
              <div class="mt-2 flex flex-col gap-1.5">
                <button
                  v-for="variant in productVariants(product)"
                  :key="variant.id"
                  type="button"
                  class="flex min-h-11 items-center justify-between rounded border border-[var(--line)] bg-[var(--surface)] px-2.5 text-left text-sm hover:border-[var(--primary)]"
                  :disabled="pos.cartBusy"
                  @click="pos.addVariantToCart(product, variant)"
                >
                  <span>
                    <span class="font-medium">{{ variant.name || 'Default' }}</span>
                    <span class="mt-0.5 block font-mono-pos text-xs text-[var(--muted)]">{{
                      variant.sku
                    }}</span>
                  </span>
                  <span class="text-[var(--primary)]">Add</span>
                </button>
                <p
                  v-if="productVariants(product).length === 0"
                  class="text-xs text-[var(--muted)]"
                >
                  No active variants
                </p>
              </div>
            </article>
          </template>
          <div v-else class="col-span-full p-6 text-center text-[var(--muted)]">
            <p class="text-sm">{{ pos.searchLoading ? 'Searching…' : 'Scan or search to start' }}</p>
            <p v-if="!pos.searchLoading" class="mt-1 text-xs">Enter adds the first match</p>
          </div>
        </div>
      </section>

      <aside
        class="flex min-h-0 flex-col border-t border-[var(--line)] bg-[var(--panel)] lg:border-t-0 lg:border-l"
      >
        <div class="flex items-center justify-between border-b border-[var(--line)] px-3.5 py-3">
          <h2 class="font-display text-base font-semibold">Cart</h2>
          <span class="text-xs text-[var(--muted)]">{{ pos.lineCount }} lines</span>
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

        <div class="min-h-0 flex-1 overflow-auto px-3.5 py-2">
          <p v-if="pos.cartLines.length === 0" class="py-8 text-center text-sm text-[var(--muted)]">
            Empty cart
          </p>
          <ul v-else class="flex flex-col gap-2">
            <li
              v-for="line in pos.cartLines"
              :key="line.lineKey"
              class="rounded-[var(--radius)] border border-[var(--line)] bg-[var(--surface)] p-2.5"
            >
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <p class="truncate text-sm font-semibold">{{ line.label }}</p>
                  <p class="font-mono-pos text-xs text-[var(--muted)]">
                    {{ line.sku }}
                    <span v-if="line.barcode"> · {{ line.barcode }}</span>
                  </p>
                </div>
                <button
                  type="button"
                  class="min-h-11 min-w-11 text-[var(--danger)]"
                  aria-label="Remove line"
                  @click="pos.removeLine(line.lineKey)"
                >
                  ×
                </button>
              </div>

              <div class="mt-2 flex flex-wrap items-center gap-2">
                <div class="inline-flex items-center rounded border border-[var(--line)] bg-[var(--panel)]">
                  <button
                    type="button"
                    class="min-h-11 min-w-11 text-lg"
                    aria-label="Decrease quantity"
                    :disabled="pos.cartBusy"
                    @click="pos.bumpQty(line.lineKey, -1)"
                  >
                    −
                  </button>
                  <span class="font-mono-pos min-w-10 text-center text-sm">{{ line.qty }}</span>
                  <button
                    type="button"
                    class="min-h-11 min-w-11 text-lg"
                    aria-label="Increase quantity"
                    :disabled="pos.cartBusy"
                    @click="pos.bumpQty(line.lineKey, 1)"
                  >
                    +
                  </button>
                </div>

                <div class="ml-auto text-right">
                  <p class="font-mono-pos text-sm font-semibold">{{ line.lineTotal }}</p>
                  <p class="font-mono-pos text-xs text-[var(--muted)]">
                    @ {{ line.unitPrice }}
                    <span v-if="line.priceOverride"> · override</span>
                  </p>
                  <p class="text-xs text-[var(--muted)]">
                    Tax {{ line.taxAmount }}
                    <span v-if="line.discountAmount !== '0.0000'">
                      · disc {{ line.discountAmount }}</span
                    >
                  </p>
                </div>
              </div>

              <div v-if="pos.allowPriceOverride" class="mt-1.5 flex gap-2">
                <button
                  type="button"
                  class="min-h-11 text-xs text-[var(--primary)] underline"
                  @click="openOverride(line.lineKey, line.priceOverride ?? line.unitPrice)"
                >
                  Override price
                </button>
                <button
                  v-if="line.priceOverride"
                  type="button"
                  class="min-h-11 text-xs text-[var(--muted)] underline"
                  @click="clearOverride(line.lineKey)"
                >
                  Clear override
                </button>
              </div>
            </li>
          </ul>
        </div>

        <div class="border-t border-[var(--line)] p-3.5">
          <div class="mb-1.5 flex justify-between text-sm text-[var(--muted)]">
            <span>Subtotal</span>
            <span class="font-mono-pos">{{ pos.cartLines.length ? pos.cartTotals.subtotal : '—' }}</span>
          </div>
          <div
            v-if="pos.cartLines.length && pos.cartTotals.discount !== '0.0000'"
            class="mb-1.5 flex justify-between text-sm text-[var(--muted)]"
          >
            <span>Discount</span>
            <span class="font-mono-pos">−{{ pos.cartTotals.discount }}</span>
          </div>
          <div class="mb-1.5 flex justify-between text-sm text-[var(--muted)]">
            <span>Tax</span>
            <span class="font-mono-pos">{{ pos.cartLines.length ? pos.cartTotals.tax : '—' }}</span>
          </div>
          <div class="mb-3.5 flex items-baseline justify-between">
            <label class="text-sm font-semibold">Total</label>
            <div class="font-display text-[40px] font-bold leading-none tracking-tight text-[var(--primary)]">
              {{ pos.cartLines.length ? pos.cartTotals.total : '—' }}
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
              class="font-display col-span-2 min-h-14 rounded-[var(--radius)] bg-[var(--primary)] text-lg font-semibold text-[var(--primary-fg)] hover:opacity-95 disabled:opacity-60"
              title="Payment stub (real pay gating in later stories)"
              :disabled="pos.cartLines.length === 0"
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

    <!-- Price override (max one modal deep) -->
    <Teleport to="body">
      <div
        v-if="overrideLineKey"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        role="dialog"
        aria-modal="true"
        aria-label="Price override"
        @click.self="closeOverride"
      >
        <div class="w-full max-w-sm rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] shadow-lg">
          <div class="flex items-center justify-between border-b border-[var(--line)] px-4 py-3">
            <h2 class="font-display text-lg font-semibold">Override unit price</h2>
            <button
              type="button"
              class="min-h-11 min-w-11 rounded-[var(--radius)] text-[var(--muted)] hover:bg-[var(--surface)]"
              aria-label="Close"
              @click="closeOverride"
            >
              Esc
            </button>
          </div>
          <div class="space-y-3 px-4 py-4">
            <label class="block text-sm">
              Unit price
              <input
                id="pos-override-price"
                v-model="overrideDraft"
                type="text"
                inputmode="decimal"
                class="mt-1 min-h-12 w-full rounded border border-[var(--line)] px-3 font-mono-pos outline-none focus:border-[var(--primary)]"
                @keydown.enter.prevent="applyOverride"
                @keydown.esc.stop="closeOverride"
              />
            </label>
            <button
              type="button"
              class="min-h-12 w-full rounded-[var(--radius)] bg-[var(--primary)] font-semibold text-[var(--primary-fg)]"
              :disabled="pos.cartBusy"
              @click="applyOverride"
            >
              Apply
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
